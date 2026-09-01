<?php
declare(strict_types=1);

namespace CannonMiner;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Random\Engine\PcgOneseq128XslRr64;
use Random\Randomizer;
use RuntimeException;

final class Router
{
    private const METERS_PER_MILE = 1609.344;
    private const RISK_SIMULATIONS = 1024;
    private const EMPIRICAL_POINTS = 64;
    private array $representativeDates = [];
    private array $predictionCache = [];

    public function __construct(private PDO $pdo, private Settings $settings) {}

    public function nodes(): array
    {
        $rows = $this->pdo->query('SELECT start_node AS node FROM segments WHERE enabled UNION SELECT end_node FROM segments WHERE enabled ORDER BY node')->fetchAll();
        return array_column($rows, 'node');
    }

    public function explore(string $start, string $end, float $mph, string $profile, float $maxRisk, ?callable $progress = null): array
    {
        if ($mph <= 0 || $maxRisk < 0 || $maxRisk > 1 || !in_array($profile, ['balanced','fastest','reliability'], true)) {
            throw new RuntimeException('Invalid routing options.');
        }
        $progress ??= static function (): void {};
        $timezone = new DateTimeZone($this->settings->get('timezone', 'America/New_York'));
        $this->representativeDates = []; $this->predictionCache = [];
        $progress(0,1,'Loading traffic observations');
        $segments = $this->loadSegments($timezone,$progress);
        $routes = $this->candidateRoutes($segments, $start, $end, max(1, (int)$this->settings->get('candidate_routes','25')), $mph);
        if ($routes === []) throw new RuntimeException("No route exists from {$start} to {$end}.");
        $departureInterval=max(5,min(60,(int)$this->settings->get('departure_interval_minutes','15')));
        $departures = $this->departurePatterns($timezone,$departureInterval);
        $routeWork=array_sum(array_map('count',$routes));
        $total = $routeWork*count($departures); $done = 0; $lastReported=0; $started = microtime(true);
        $bestEligible = []; $bestAll = [];
        $compare = static fn(array $a,array $b): int => $profile === 'reliability'
            ? [$a['risk'],$a['expected_seconds']] <=> [$b['risk'],$b['expected_seconds']]
            : [$a['expected_seconds'],$a['risk']] <=> [$b['expected_seconds'],$b['risk']];
        foreach ($routes as $route) foreach ($departures as $departure) {
            $evaluation = $this->evaluate($route,$departure,$timezone,$mph,$departureInterval);
            $this->retainBest($bestAll,$evaluation,$compare);
            if ($evaluation['risk'] <= $maxRisk) $this->retainBest($bestEligible,$evaluation,$compare);
            $done+=count($route);
            if ($done === $total || $done-$lastReported >= 25) {
                $elapsed = microtime(true)-$started; $eta = $done ? ($elapsed/$done)*($total-$done) : null;
                $progress($done,$total,'Scoring route segments and departure patterns',$eta);$lastReported=$done;
            }
        }
        $best = $bestEligible ?: $bestAll;
        $highestSegmentRisk=0.0;
        foreach($best as $evaluation)foreach($evaluation['segment_risks'] as $segmentRisk)$highestSegmentRisk=max($highestSegmentRisk,$segmentRisk['risk']);
        foreach ($best as &$evaluation) {
            foreach($evaluation['segment_risks'] as &$segmentRisk){
                $normalized=$highestSegmentRisk>0?$segmentRisk['risk']/$highestSegmentRisk:0.0;
                $segmentRisk['color']=$this->riskColor($normalized);
            }
            unset($segmentRisk);
            $evaluation['map_url'] = $this->mapUrl($evaluation['segments'],$evaluation['segment_risks']); unset($evaluation['segments']);
        }
        unset($evaluation);
        return $best;
    }

    public function trends(): array
    {
        return $this->pdo->query(<<<'SQL'
            SELECT s.name,s.timezone,
              extract(isodow from m.collected_at AT TIME ZONE s.timezone)::int AS weekday,
              extract(hour from m.collected_at AT TIME ZONE s.timezone)::int AS hour,
              count(*)::int AS observations,avg(greatest(0,m.duration_in_traffic_seconds-m.duration_seconds)) AS avg_delay,
              percentile_cont(.9) WITHIN GROUP (ORDER BY greatest(0,m.duration_in_traffic_seconds-m.duration_seconds)) AS p90_delay
            FROM measurements m JOIN segments s ON s.id=m.segment_id WHERE m.duration_in_traffic_seconds IS NOT NULL
            GROUP BY s.name,s.timezone,weekday,hour HAVING count(*)>=2 ORDER BY avg_delay DESC LIMIT 30
        SQL)->fetchAll();
    }

    private function loadSegments(DateTimeZone $timezone,callable $progress): array
    {
        $rows = $this->pdo->query(<<<'SQL'
            SELECT s.id,s.name,s.start_node,s.end_node,s.origin,s.destination,count(m.id)::int AS sample_count,
              avg(greatest(0,m.duration_in_traffic_seconds-m.duration_seconds))::float AS global_mean,
              percentile_cont(.5) WITHIN GROUP (ORDER BY m.distance_meters)::float AS distance,
              percentile_cont(.5) WITHIN GROUP (ORDER BY m.duration_seconds)::float AS normal_duration,
              (SELECT latest.raw_payload #>> '{routes,0,overview_polyline,points}'
               FROM measurements latest
               WHERE latest.segment_id=s.id
                 AND latest.raw_payload #>> '{routes,0,overview_polyline,points}' IS NOT NULL
               ORDER BY latest.collected_at DESC,latest.id DESC LIMIT 1) AS polyline
            FROM segments s JOIN measurements m ON m.segment_id=s.id
            WHERE s.enabled AND m.collected_at IS NOT NULL AND m.duration_in_traffic_seconds>0
              AND m.duration_seconds>0 AND m.distance_meters>0
            GROUP BY s.id ORDER BY s.name
        SQL)->fetchAll();
        $segments=[]; $byId=[];
        foreach ($rows as $row) {
            $name=(string)$row['name']; $byId[(int)$row['id']]=$name;
            $segments[$name]=['id'=>(int)$row['id'],'name'=>$name,'start'=>$row['start_node'],'end'=>$row['end_node'],
                'origin'=>$row['origin'],'destination'=>$row['destination'],'global_mean'=>(float)$row['global_mean'],
                'distance'=>(float)$row['distance'],'normal_duration'=>(float)$row['normal_duration'],
                'polyline'=>$row['polyline']?:null,'buckets'=>[],'all_delays'=>''];
        }
        if ($segments===[]) return [];
        $total=array_sum(array_map(static fn(array $row)=>(int)$row['sample_count'],$rows));$loaded=0;$started=microtime(true);
        $statement=$this->pdo->query(<<<'SQL'
            SELECT m.segment_id,m.collected_at,m.duration_seconds,m.duration_in_traffic_seconds
            FROM measurements m JOIN segments s ON s.id=m.segment_id WHERE s.enabled AND m.collected_at IS NOT NULL
              AND m.duration_in_traffic_seconds>0 AND m.duration_seconds>0 AND m.distance_meters>0 ORDER BY m.segment_id,m.collected_at
        SQL);
        while ($row=$statement->fetch()) {
            $name=$byId[(int)$row['segment_id']]??null; if ($name===null) continue;
            $at=(new DateTimeImmutable($row['collected_at']))->setTimezone($timezone);
            $delay=max(0.0,(float)$row['duration_in_traffic_seconds']-(float)$row['duration_seconds']);
            $key=(int)$at->format('n')*1000000+(int)$at->format('N')*10000+(int)$at->format('G')*60+(int)$at->format('i');
            $segments[$name]['buckets'][$key]=($segments[$name]['buckets'][$key]??'').pack('d',$delay);
            $segments[$name]['all_delays'].=pack('d',$delay);
            $loaded++;
            if($loaded===$total||$loaded%5000===0){$elapsed=microtime(true)-$started;$eta=$loaded?($elapsed/$loaded)*($total-$loaded):null;$progress($loaded,max(1,$total),'Loading traffic observations',$eta);}
            $dateKey=$at->format('n-N');
            if (!isset($this->representativeDates[$dateKey]) || $at>$this->representativeDates[$dateKey]) $this->representativeDates[$dateKey]=$at;
        }
        foreach ($segments as &$segment) {
            $values=$this->unpackDoubles($segment['all_delays']);
            $segment['delay_points']=$this->empiricalPoints($values); unset($segment['all_delays']);
        }
        unset($segment);
        return $segments;
    }

    private function candidateRoutes(array $segments,string $start,string $end,int $limit,float $mph): array
    {
        $adjacent=[];$reverse=[];
        foreach($segments as $segment){
            $segment['candidate_weight']=$segment['distance']/($mph*self::METERS_PER_MILE/3600)+$segment['global_mean'];
            $adjacent[$segment['start']][]=$segment;$reverse[$segment['end']][]=$segment['start'];
        }
        $canReach=[$end=>true];$pending=[$end];
        while($pending){$node=array_pop($pending);foreach($reverse[$node]??[] as $previous)if(!isset($canReach[$previous])){$canReach[$previous]=true;$pending[]=$previous;}}
        if(!isset($canReach[$start]))return[];

        $queue=new \SplPriorityQueue();$queue->setExtractFlags(\SplPriorityQueue::EXTR_DATA);
        $queue->insert(['node'=>$start,'path'=>[],'visited'=>[$start=>true],'cost'=>0.0],0.0);$routes=[];
        while(!$queue->isEmpty()&&count($routes)<$limit){
            $state=$queue->extract();
            if($state['node']===$end){if($state['path'])$routes[]=$state['path'];continue;}
            foreach($adjacent[$state['node']]??[] as $segment){
                $next=$segment['end'];if(isset($state['visited'][$next])||!isset($canReach[$next]))continue;
                $cost=$state['cost']+$segment['candidate_weight'];$visited=$state['visited'];$visited[$next]=true;
                $queue->insert(['node'=>$next,'path'=>[...$state['path'],$segment],'visited'=>$visited,'cost'=>$cost],-$cost);
            }
        }
        return$routes;
    }

    private function departurePatterns(DateTimeZone $timezone,int $intervalMinutes): array
    {
        $dates=$this->representativeDates; ksort($dates); $result=[];
        foreach($dates as $date)for($minute=0;$minute<1440;$minute+=$intervalMinutes)$result[]=$date->setTimezone($timezone)->setTime(0,0)->modify('+'.$minute.' minutes');
        usort($result,static fn(DateTimeImmutable $a,DateTimeImmutable $b):int=>$a<=>$b);
        return $result?:[new DateTimeImmutable('now',$timezone)];
    }

    private function prediction(array $segment,DateTimeImmutable $arrival,DateTimeZone $timezone,int $intervalMinutes): array
    {
        $local=$arrival->setTimezone($timezone);$minuteOfDay=(int)$local->format('G')*60+(int)$local->format('i');
        $bucketMinute=intdiv($minuteOfDay,$intervalMinutes)*$intervalMinutes;
        $cacheKey=$segment['name'].'|'.$local->format('N-n-').$bucketMinute;
        if(isset($this->predictionCache[$cacheKey]))return $this->unpackPrediction($this->predictionCache[$cacheKey]);
        $arrivalMinute=$bucketMinute; $arrivalMonth=(int)$local->format('n'); $weekday=(int)$local->format('N');
        $values=[];$weights=[];
        for($month=1;$month<=12;$month++)for($delta=-90;$delta<=90;$delta++){
            $minute=($arrivalMinute+$delta+1440)%1440; $key=$month*1000000+$weekday*10000+$minute;
            if(!isset($segment['buckets'][$key]))continue;
            $monthGap=abs($month-$arrivalMonth);$monthGap=min($monthGap,12-$monthGap);
            $weight=exp(-abs($delta)/45)*($monthGap<=1?1.0:.35);
            foreach($this->unpackDoubles($segment['buckets'][$key]) as $delay){$values[]=$delay;$weights[]=$weight;}
        }
        $nearby=count($values);$share=$nearby/($nearby+10.0);
        $localMean=$nearby?$this->weightedMean($values,$weights):$segment['global_mean'];
        $result=['mean'=>$share*$localMean+(1-$share)*$segment['global_mean'],
            'distance'=>$segment['distance'],'normal'=>$segment['normal_duration'],'nearby'=>$nearby,'share'=>$share,
            'local_points'=>$nearby?$this->empiricalPoints($values,$weights):[]];
        $this->predictionCache[$cacheKey]=$this->packPrediction($result);return$result;
    }

    private function evaluate(array $route,DateTimeImmutable $departure,DateTimeZone $timezone,float $mph,int $departureInterval): array
    {
        $arrival=$departure;$drive=$congestion=$distance=0.0;$support=0;$predictions=[];
        foreach($route as $segment){
            $prediction=$this->prediction($segment,$arrival,$timezone,$departureInterval);$seconds=$prediction['distance']/($mph*self::METERS_PER_MILE/3600);
            $drive+=$seconds;$congestion+=$prediction['mean'];$distance+=$prediction['distance'];$support+=$prediction['nearby'];
            $predictions[]=[$segment,$prediction,$seconds];$arrival=$arrival->modify('+'.(int)round($seconds+$prediction['mean']).' seconds');
        }
        $seedMaterial=implode('|',array_column($route,'name')).'|'.$departure->format('Y-m-d\TH:i:sP');
        $seed=unpack('q',substr(hash('sha256',$seedMaterial,true),0,8))[1];
        $random=new Randomizer(new PcgOneseq128XslRr64($seed));
        $totals=array_fill(0,self::RISK_SIMULATIONS,0.0);$slow=array_fill(0,self::RISK_SIMULATIONS,false);$segmentRisks=[];
        foreach($predictions as [$segment,$prediction,$seconds]){
            $draws=[];$global=$segment['delay_points'];$globalMax=count($global)-1;
            for($i=0;$i<self::RISK_SIMULATIONS;$i++)$draws[$i]=$global[$random->getInt(0,$globalMax)];
            $useLocal=[];$localCount=0;
            for($i=0;$i<self::RISK_SIMULATIONS;$i++){
                $useLocal[$i]=($random->getInt(0,1000000000)/1000000000)<$prediction['share']; if($useLocal[$i])$localCount++;
            }
            if($localCount&&$prediction['local_points']){
                $localMax=count($prediction['local_points'])-1;
                for($i=0;$i<self::RISK_SIMULATIONS;$i++)if($useLocal[$i])$draws[$i]=$prediction['local_points'][$random->getInt(0,$localMax)];
            }
            $threshold=max(120.0,$prediction['normal']*.05);
            $segmentEvents=0;
            for($i=0;$i<self::RISK_SIMULATIONS;$i++){
                $totals[$i]+=$draws[$i];
                if($draws[$i]>=$threshold){$slow[$i]=true;$segmentEvents++;}
            }
            $segmentRisk=$segmentEvents/self::RISK_SIMULATIONS;
            $segmentRisks[]=['name'=>$segment['name'],'risk'=>$segmentRisk];
        }
        $material=max(300.0,$drive*.02);$events=0;
        for($i=0;$i<self::RISK_SIMULATIONS;$i++)if($slow[$i]||$totals[$i]>=$material)$events++;
        $nodes=array_merge([$route[0]['start']],array_column($route,'end'));
        return ['route'=>implode(' -> ',$nodes),'departure'=>$departure,'drive_seconds'=>$drive,'congestion_seconds'=>$congestion,
            'expected_seconds'=>$drive+$congestion,'risk'=>$events/self::RISK_SIMULATIONS,'distance_miles'=>$distance/self::METERS_PER_MILE,
            'observations'=>$support,'segments'=>$route,'segment_risks'=>$segmentRisks];
    }

    private function empiricalPoints(array $values,?array $weights=null): array
    {
        $count=count($values);if($weights===null&&$count<=self::EMPIRICAL_POINTS)return array_map($this->float32(...),$values);
        $pairs=[];foreach($values as $i=>$value)$pairs[]=[$value,$weights[$i]??1.0];
        usort($pairs,static fn($a,$b)=>$a[0]<=>$b[0]);$cumulative=[];$running=0.0;
        if($weights===null){foreach($pairs as $i=>$pair)$cumulative[]=($i+.5)/$count;}
        else{$total=array_sum($weights);foreach($pairs as $pair){$running+=$pair[1];$cumulative[]=$running/$total;}}
        $result=[];for($i=0;$i<self::EMPIRICAL_POINTS;$i++)$result[]=$this->float32($this->interpolate(($i+.5)/self::EMPIRICAL_POINTS,$cumulative,$pairs));
        return $result;
    }

    private function interpolate(float $target,array $x,array $pairs): float
    {
        $count=count($x);if($target<=$x[0])return(float)$pairs[0][0];if($target>=$x[$count-1])return(float)$pairs[$count-1][0];
        $high=1;while($x[$high]<$target)$high++;$low=$high-1;$share=($target-$x[$low])/($x[$high]-$x[$low]);
        return(float)($pairs[$low][0]+$share*($pairs[$high][0]-$pairs[$low][0]));
    }

    private function unpackDoubles(string $packed): array { return $packed===''?[]:array_values(unpack('d*',$packed)); }
    private function float32(float $value): float { return unpack('gvalue',pack('g',$value))['value']; }
    private function packPrediction(array $value): string { return pack('ddddd',$value['mean'],$value['distance'],$value['normal'],(float)$value['nearby'],$value['share']).($value['local_points']?pack('d*',...$value['local_points']):''); }
    private function unpackPrediction(string $packed): array { $head=unpack('dmean/ddistance/dnormal/dnearby/dshare',$packed);return['mean'=>$head['mean'],'distance'=>$head['distance'],'normal'=>$head['normal'],'nearby'=>(int)$head['nearby'],'share'=>$head['share'],'local_points'=>$this->unpackDoubles(substr($packed,40))]; }
    private function weightedMean(array $values,array $weights): float { $sum=$weight=0.0;foreach($values as $i=>$v){$sum+=$v*$weights[$i];$weight+=$weights[$i];}return$sum/$weight; }
    private function retainBest(array &$best,array $item,callable $compare): void { $best[]=$item;usort($best,$compare);if(count($best)>3)array_pop($best); }

    private function riskColor(float $risk): string
    {
        $risk=max(0.0,min(1.0,$risk));
        if($risk<=.5){$share=$risk*2;$from=[21,148,71];$to=[240,180,41];}
        else{$share=($risk-.5)*2;$from=[240,180,41];$to=[198,40,40];}
        $rgb=array_map(static fn(int $start,int $end):int=>(int)round($start+($end-$start)*$share),$from,$to);
        return sprintf('%02x%02x%02x',...$rgb);
    }

    private function mapUrl(array $route,array $segmentRisks): ?string
    {
        $key=$this->settings->get('google_maps_api_key','');if($key==='')return null;
        $query=http_build_query(['size'=>'640x360','scale'=>2,'maptype'=>'roadmap','key'=>$key]);$paths=[];
        foreach($route as $index=>$segment){
            if(empty($segment['polyline']))continue;
            $color=$segmentRisks[$index]['color']??'159447';
            $paths[]='path='.rawurlencode("color:0x{$color}ff|weight:6|enc:{$segment['polyline']}");
        }
        return $paths?'https://maps.googleapis.com/maps/api/staticmap?'.$query.'&'.implode('&',$paths):null;
    }
}
