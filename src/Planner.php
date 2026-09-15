<?php
declare(strict_types=1);

namespace CannonMiner;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

final class Planner
{
    public const METHOD_VERSION = 2;

    public function __construct(private PDO $pdo) {}

    public function project(array $input, ?callable $progress = null): array
    {
        $progress ??= static function (): void {};
        $timezone = new DateTimeZone('America/New_York');
        $start = new DateTimeImmutable((string) $input['start_date'] . ' 00:00:00', $timezone);
        $end = new DateTimeImmutable((string) $input['end_date'] . ' 23:59:59', $timezone);
        if ($end < $start || $end->diff($start)->days > 366) throw new RuntimeException('Planning range must be between 1 and 366 days.');
        $speedTenths = (int) round((float) $input['speed'] * 10);
        $profile = (string) $input['profile'];
        $maxRisk = (float) $input['risk'];
        $hourStart = max(0, min(23, (int) ($input['hour_start'] ?? 0)));
        $hourEnd = max($hourStart, min(23, (int) ($input['hour_end'] ?? 23)));
        if (!in_array($profile, ['balanced','fastest','reliability'], true)) throw new RuntimeException('Invalid planning strategy.');

        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT j.id,j.created_at,(item.position-1)::int AS result_index,item.value AS result
            FROM analysis_jobs j
            CROSS JOIN LATERAL jsonb_array_elements(
              CASE WHEN jsonb_typeof(j.result)='array' THEN j.result ELSE '[]'::jsonb END
            ) WITH ORDINALITY AS item(value,position)
            WHERE j.status='complete' AND j.calculation_method_version=3
              AND round(((item.value->>'target_speed_mph')::numeric)*10)::int=?
              AND item.value->>'route' IS NOT NULL AND item.value->>'departure' IS NOT NULL
            ORDER BY j.created_at DESC
        SQL);
        $statement->execute([$speedTenths]);$rows=$statement->fetchAll();
        if (!$rows) throw new RuntimeException('No current historical calculations support that target speed.');
        $progress(1, 4, 'Grouping historical calculation evidence');

        $groups=[];
        foreach($rows as $row){
            $result=json_decode((string)$row['result'],true,512,JSON_THROW_ON_ERROR);
            $departure=new DateTimeImmutable((string)$result['departure']);
            $localDeparture=$departure->setTimezone($timezone);$pattern=$this->calendarPattern($localDeparture);
            $key=implode('|',[(string)$result['route'],$pattern.'-'.$localDeparture->format('H:i'),
                (string)round((float)$result['risk']*1000),(string)round((float)$result['expected_seconds']/60)]);
            if(isset($groups[$key])){$groups[$key]['matches']++;continue;}
            $groups[$key]=['route'=>(string)$result['route'],'pattern'=>$pattern,
                'time'=>$localDeparture->format('H:i'),'expected'=>(float)$result['expected_seconds'],
                'risk'=>(float)$result['risk'],'confidence'=>(float)($result['confidence']??0),'matches'=>1,
                'source_id'=>(string)$row['id'],'source_index'=>(int)$row['result_index'],'source_at'=>(string)$row['created_at'],
                'map_available'=>!empty($result['map_url']),'segment_risks'=>(array)($result['segment_risks']??[])];
        }
        $progress(2, 4, 'Projecting supported patterns onto future dates');
        $candidates=[];$cursor=$start->setTime(0,0);
        while($cursor<=$end){
            $pattern=$this->calendarPattern($cursor);
            foreach($groups as $group){
                if($group['pattern']!==$pattern)continue;
                $hour=(int)substr($group['time'],0,2);if($hour<$hourStart||$hour>$hourEnd)continue;
                $departure=$cursor->setTime($hour,(int)substr($group['time'],3,2));
                $key=$group['route'].'|'.$departure->format(DATE_ATOM);$candidates[$key][]=$group;
            }
            $cursor=$cursor->add(new DateInterval('P1D'));
        }
        $progress(3, 4, 'Ranking projected runs');
        $ranked=[];
        foreach($candidates as $candidateKey=>$evidence){
            $expected=array_column($evidence,'expected');$risks=array_column($evidence,'risk');sort($expected,SORT_NUMERIC);sort($risks,SORT_NUMERIC);
            $median=static function(array $values):float{$middle=intdiv(count($values),2);return count($values)%2?$values[$middle]:($values[$middle-1]+$values[$middle])/2;};
            $meanExpected=array_sum($expected)/count($expected);$meanRisk=array_sum($risks)/count($risks);
            $variance=static fn(array $values,float $mean):float=>array_sum(array_map(static fn(float $value):float=>($value-$mean)**2,$values))/count($values);
            $agreement=max(.25,1-min(.75,(sqrt($variance($risks,$meanRisk))/.15+sqrt($variance($expected,$meanExpected))/7200)/2));
            $evidenceConfidence=array_sum(array_column($evidence,'confidence'))/count($evidence);$support=count($evidence)/(count($evidence)+3);
            $latest=$evidence[0];foreach($evidence as $item)if($item['source_at']>$latest['source_at'])$latest=$item;
            $age=max(0,(time()-(new DateTimeImmutable($latest['source_at']))->getTimestamp())/86400);$recency=max(.5,exp(-$age/365));
            $confidence=pow(max(0,$evidenceConfidence)*$support*$agreement*$recency,.25);
            $actualDeparture=new DateTimeImmutable(substr($candidateKey,strpos($candidateKey,'|')+1));
            $p90=$expected[(int)floor(.9*(count($expected)-1))];
            $segmentEvidence=[];$segmentOrder=[];
            foreach($evidence as $item)foreach($item['segment_risks'] as $segment){
                $name=(string)($segment['name']??'');if($name==='')continue;
                if(!isset($segmentEvidence[$name])){$segmentEvidence[$name]=[];$segmentOrder[]=$name;}
                $segmentEvidence[$name][]=$segment;
            }
            $segmentRisks=[];
            foreach($segmentOrder as $name){
                $items=$segmentEvidence[$name];$segmentRiskValues=array_map(static fn(array $item):float=>(float)($item['risk']??0),$items);
                $offsetValues=array_map(static fn(array $item):float=>(float)($item['start_offset_seconds']??0),$items);sort($segmentRiskValues,SORT_NUMERIC);sort($offsetValues,SORT_NUMERIC);
                $offset=max(0,(int)round($median($offsetValues)));$first=$items[0];
                $segmentRisks[]=['name'=>$name,'risk'=>$median($segmentRiskValues),'start_time'=>$actualDeparture->modify('+'.$offset.' seconds')->format(DATE_ATOM),
                    'start_timezone'=>(string)($first['start_timezone']??'America/New_York'),'start_offset_seconds'=>$offset];
            }
            $ranked[]=['route'=>$evidence[0]['route'],'departure'=>$actualDeparture->format(DATE_ATOM),'expected_seconds'=>$median($expected),
                'upper_seconds'=>$p90,'risk'=>$median($risks),'confidence'=>$confidence,'evidence_groups'=>count($evidence),
                'matches'=>array_sum(array_column($evidence,'matches')),'latest_source_at'=>$latest['source_at'],'source_id'=>$latest['source_id'],
                'source_index'=>$latest['source_index'],'map_available'=>$latest['map_available'],'target_speed_mph'=>$speedTenths/10,
                'segment_risks'=>$segmentRisks];
        }
        if(!$ranked)throw new RuntimeException('No directly supported historical patterns fall within that future range.');
        $eligible=array_values(array_filter($ranked,static fn(array $item):bool=>$item['risk']<=$maxRisk));$pool=$eligible?:$ranked;
        $times=array_column($pool,'expected_seconds');$risks=array_column($pool,'risk');$minTime=min($times);$maxTime=max($times);$minRisk=min($risks);$maxPoolRisk=max($risks);
        foreach($pool as &$item)$item['_score']=match($profile){
            'fastest'=>$item['expected_seconds'],'reliability'=>$item['risk'],default=>(($item['expected_seconds']-$minTime)/max(1,$maxTime-$minTime)+($item['risk']-$minRisk)/max(.000001,$maxPoolRisk-$minRisk))};
        unset($item);usort($pool,static fn(array $a,array $b):int=>[$a['_score'],-$a['confidence']]<=>[$b['_score'],-$b['confidence']]);
        $selected=[array_shift($pool)];
        foreach($pool as $index=>$item)if(substr($item['departure'],0,10)!==substr($selected[0]['departure'],0,10)){$item['designation']='day_alternative';$selected[]=$item;unset($pool[$index]);break;}
        foreach($pool as $item)if($item['route']!==$selected[0]['route']||abs(strtotime($item['departure'])-strtotime($selected[0]['departure']))>=3600){$item['designation']='route_time_alternative';$selected[]=$item;break;}
        foreach($pool as $item){if(count($selected)>=3)break;if(!in_array($item,$selected,true))$selected[]=$item;}
        $selected=array_slice($selected,0,3);$selected[0]['designation']='recommended';$highestSegmentRisk=0.0;
        foreach($selected as $item)foreach($item['segment_risks'] as $segment)$highestSegmentRisk=max($highestSegmentRisk,$segment['risk']);
        foreach($selected as &$item){unset($item['_score']);$item['within_risk_limit']=$item['risk']<=$maxRisk;
            foreach($item['segment_risks'] as &$segment)$segment['color']=$this->riskColor($highestSegmentRisk>0?$segment['risk']/$highestSegmentRisk:0.0);
            unset($segment);
        }unset($item);
        $progress(4,4,'Complete');return$selected;
    }

    private function riskColor(float $risk):string
    {
        $risk=max(0.0,min(1.0,$risk));
        if($risk<=.5){$share=$risk*2;$from=[21,148,71];$to=[240,180,41];}
        else{$share=($risk-.5)*2;$from=[240,180,41];$to=[198,40,40];}
        $rgb=array_map(static fn(int $start,int $end):int=>(int)round($start+($end-$start)*$share),$from,$to);
        return sprintf('%02x%02x%02x',...$rgb);
    }

    private function calendarPattern(DateTimeImmutable $date):string
    {
        $occurrence=$date->modify('+7 days')->format('n')!==$date->format('n')?'last':(string)(int)ceil((int)$date->format('j')/7);
        return $date->format('n-N').'-'.$occurrence;
    }
}
