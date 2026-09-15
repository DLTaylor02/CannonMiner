<?php
declare(strict_types=1);

namespace CannonMiner;

use DateTimeImmutable;
use PDO;
use RuntimeException;

final class Simulator
{
    private const NAMES=['Alex Mercer','Jordan Vale','Casey Holt','Morgan Reed','Taylor Knox','Riley Stone','Avery Lane','Cameron Pike','Drew Hart','Parker Shaw','Quinn Hayes','Skyler Dean'];
    public const LOCATIONS=['redball'=>'Redball Garage, NY','portofino'=>'Portofino Marina, CA','bar'=>'Barstow, CA','big'=>'Big Springs, NE','cole'=>'Columbus East, OH','coln'=>'Columbus North, OH','cov'=>'Cove Fort, UT','den'=>'Denver, CO','elr'=>'El Reno, OK','har'=>'Harrisburg, PA','nash'=>'Nashville, TN','stl'=>'St. Louis, IL','you'=>'Youngstown, OH'];

    public function __construct(private PDO $pdo,private RouteGraph $graph,private SimulationTrafficProvider $traffic,private Settings $settings){}

    public static function roster(string $username):array
    {
        $names=self::NAMES;shuffle($names);$drivers=[self::driver('self',$username,true)];
        for($i=0;$i<6;$i++)$drivers[]=self::driver('candidate-'.$i,$names[$i],false);
        return$drivers;
    }

    private static function driver(string $id,string $name,bool $required):array
    {
        return['id'=>$id,'name'=>$name,'required'=>$required,'endurance'=>random_int(45,95),'driving'=>random_int(45,95),'copilot'=>random_int(45,95)];
    }

    public static function locationName(string $node):string{return self::LOCATIONS[$node]??ucwords(str_replace('_',' ',$node));}
    public static function routeName(string $segment):string{$parts=explode('_to_',$segment,2);return count($parts)===2?self::locationName($parts[0]).' to '.self::locationName($parts[1]):$segment;}

    public function create(int $userId,array $input,array $roster):string
    {
        $mode=in_array($input['mode']??'',['realtime','arcade'],true)?$input['mode']:'arcade';
        $departureText=(string)($input['departure']??'');$departure=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$departureText,new \DateTimeZone('America/New_York'));
        if(!$departure||$departure->format('Y-m-d\TH:i')!==$departureText)throw new RuntimeException('Choose a valid departure date and time.');
        $capacity=max(1,min(100,(float)($input['capacity']??20)));$mpg=max(1,min(100,(float)($input['mpg']??20)));$speed=max(20,min(250,(float)($input['speed']??110)));
        $selected=array_map('strval',(array)($input['drivers']??[]));$initialDriver=(string)($input['initial_driver']??'self');$drivers=[];
        foreach($roster as $candidate)if($candidate['required']||in_array($candidate['id'],$selected,true))$drivers[]=$candidate+['role'=>$candidate['id']===$initialDriver?'driver':'rest','fatigue'=>0.0,'rest_seconds'=>0.0,'locked_rest'=>false];
        if(count($drivers)>3)throw new RuntimeException('Choose no more than two additional drivers.');
        if(!in_array($initialDriver,array_column($drivers,'id'),true))throw new RuntimeException('Choose an initial driver from the selected roster.');
        $seed=random_int(1,2147483647);$choices=$this->choicePayload('redball');if(!$choices)throw new RuntimeException('No supported route begins at Red Ball.');
        $state=['node'=>'redball','route'=>[],'choices'=>$choices,'current_segment'=>null,'drivers'=>$drivers,'driver_copilot'=>false,'pending_decision'=>['type'=>'route'],
            'vehicle'=>['capacity'=>$capacity,'fuel'=>$capacity,'mpg'=>$mpg,'target_speed'=>$speed,'current_speed'=>0.0,'distance_miles'=>0.0,'stopped_seconds'=>0.0],
            'started'=>false,'traffic_mode'=>$departure<new DateTimeImmutable('now')?'recorded':'projected'];
        $id=bin2hex(random_bytes(16));$this->pdo->beginTransaction();
        try{$this->pdo->prepare("INSERT INTO simulations(id,user_id,status,mode,departure_at,simulated_at,random_seed,state) VALUES (?,?,'awaiting_route',?,?,?,?,?::jsonb)")
                ->execute([$id,$userId,$mode,$departure->format(DATE_ATOM),$departure->format(DATE_ATOM),$seed,json_encode($state,JSON_THROW_ON_ERROR)]);
            $this->event($id,$departure,'simulation_created',['mode'=>$mode,'traffic_mode'=>$state['traffic_mode']]);$this->pdo->commit();return$id;
        }catch(\Throwable $error){$this->pdo->rollBack();throw$error;}
    }

    public function tick(string $id):void
    {
        $this->pdo->beginTransaction();try{$statement=$this->pdo->prepare("SELECT * FROM simulations WHERE id=? FOR UPDATE");$statement->execute([$id]);$simulation=$statement->fetch();
        if(!$simulation||$simulation['status']!=='running'){$this->pdo->commit();return;}
        $state=json_decode((string)$simulation['state'],true,512,JSON_THROW_ON_ERROR);$now=microtime(true);$last=(new DateTimeImmutable($simulation['last_tick_at']))->format('U.u');
        $wall=max(0,min(10,$now-(float)$last));$delta=$wall*($simulation['mode']==='arcade'?60:1);if($delta<.05){$this->pdo->commit();return;}
        $simulated=(new DateTimeImmutable($simulation['simulated_at']))->modify('+'.(int)round($delta).' seconds');$status=$simulation['status'];
        $this->advanceFatigue($state,$delta,$id,$simulated,$status);
        if($status==='running'&&$state['current_segment'])$this->advanceVehicle($state,$delta,$id,$simulated,$status,(int)$simulation['random_seed']);
        $this->save($id,$state,$status,$simulated);$this->pdo->commit();
        }catch(\Throwable $error){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$error;}
    }

    public function act(string $id,int $userId,string $action,array $payload):void
    {
        $this->pdo->beginTransaction();try{$statement=$this->pdo->prepare('SELECT * FROM simulations WHERE id=? FOR UPDATE');$statement->execute([$id]);$simulation=$statement->fetch();
        if(!$simulation||(int)$simulation['user_id']!==$userId){$this->pdo->rollBack();throw new RuntimeException('Simulation not found.');}
        if(in_array($simulation['status'],['completed','failed'],true)){ $this->pdo->rollBack();throw new RuntimeException('This simulation has ended.'); }
        $state=json_decode((string)$simulation['state'],true,512,JSON_THROW_ON_ERROR);$status=$simulation['status'];$at=new DateTimeImmutable($simulation['simulated_at']);
        if(($state['pending_decision']['type']??'')==='event'&&$action!=='acknowledge_event')throw new RuntimeException('Respond to the active delay event first.');
        if($action==='choose_segment'&&$status==='awaiting_route'){$name=(string)($payload['segment']??'');if(!in_array($name,array_column($state['choices'],'name'),true))throw new RuntimeException('Choose an available onward segment.');$state['pending_decision']=null;$status=$this->startSegment($state,$name,$at,$id,(int)$simulation['random_seed']);}
        elseif($action==='pause'&&$status==='running'){$status='paused';$state['vehicle']['current_speed']=0;$state['user_paused']=true;$this->event($id,$at,'paused');}
        elseif($action==='resume'&&$status==='paused'&&empty($state['pending_decision'])){$status='running';$state['user_paused']=false;$state['vehicle']['current_speed']=(float)($state['current_segment']['cruising_speed']??0);$this->event($id,$at,'resumed');}
        elseif($action==='target_speed'){$state['vehicle']['target_speed']=max(20,min(250,(float)($payload['speed']??$state['vehicle']['target_speed'])));$this->event($id,$at,'target_speed_changed',['speed'=>$state['vehicle']['target_speed']]);}
        elseif($action==='fuel'){$this->fuelStop($state,$id,$at);$at=$at->modify('+'.(int)$state['_last_stop_seconds'].' seconds');unset($state['_last_stop_seconds']);}
        elseif($action==='copilot'){$this->assignCopilot($state,(string)($payload['copilot']??''),$id,$at);}
        elseif($action==='request_driver_change'&&$status==='running'){$seconds=(int)ceil(max(0,(float)$state['vehicle']['current_speed'])/5);$state['vehicle']['current_speed']=0;$at=$at->modify('+'.$seconds.' seconds');$status='awaiting_driver';$state['pending_decision']=['type'=>'driver','forced'=>false];$this->event($id,$at,'driver_change_requested',['seconds'=>$seconds]);}
        elseif($action==='change_driver'&&$status==='awaiting_driver'){$this->changeDriver($state,(string)($payload['driver']??''),$id,$at);$status=$state['current_segment']?'running':'awaiting_route';$state['pending_decision']=$status==='awaiting_route'?['type'=>'route']:null;$resumeSpeed=(float)($state['current_segment']['cruising_speed']??0);$acceleration=(int)ceil($resumeSpeed/5);$at=$at->modify('+'.$acceleration.' seconds');$state['vehicle']['current_speed']=$resumeSpeed;$this->event($id,$at,'driver_change_complete',['seconds'=>$acceleration]);}
        elseif($action==='acknowledge_event'&&$status==='paused'&&($state['pending_decision']['type']??'')==='event'){$penalty=(int)($state['pending_decision']['penalty_seconds']??0);$stationary=(int)($state['pending_decision']['stationary_seconds']??0);if($penalty>0)$at=$at->modify('+'.$penalty.' seconds');$state['vehicle']['stopped_seconds']+=$stationary;$state['pending_decision']=null;$status='running';$state['vehicle']['current_speed']=(float)($state['current_segment']['cruising_speed']??0);$this->event($id,$at,'event_acknowledged',['seconds'=>$penalty]);}
        else throw new RuntimeException('That action is not available right now.');
        $this->save($id,$state,$status,$at);$this->pdo->commit();
        }catch(\Throwable $error){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$error;}
    }

    private function startSegment(array &$state,string $name,DateTimeImmutable $at,string $id,int $seed):string
    {
        $segment=$this->graph->segment($name);if(!$segment||$segment['start']!==$state['node'])throw new RuntimeException('That segment is not a valid continuation.');
        $driver=$this->role($state,'driver');$copilot=$this->role($state,'copilot');if(!$driver)throw new RuntimeException('Assign a driver before departing.');
        $conditions=$this->traffic->conditions($segment,$at,$seed+count($state['route']));$speed=max(20,$state['vehicle']['target_speed']*(.82+.18*$driver['driving']/100));$trafficCap=null;
        if($conditions['delay_seconds']>=60){$trafficCap=max(5,min(65,$segment['distance_miles']/(($segment['distance_miles']/$speed)+$conditions['delay_seconds']/3600)));$speed=min($speed,$trafficCap);}
        $obstacle=$this->obstacle($seed+count($state['route'])*17,$driver,$copilot,$speed);if($obstacle['speed_cap']!==null)$speed=min($speed,$obstacle['speed_cap']);$drive=$segment['distance_miles']/$speed*3600;$delay=in_array($obstacle['type'],['police_event','flat_tire'],true)?0.0:$obstacle['delay'];
        $state['current_segment']=$segment+['elapsed_seconds'=>0.0,'total_seconds'=>$drive+$delay,'cruising_speed'=>$speed,'traffic_speed_cap'=>$trafficCap,'traffic_delay_seconds'=>$conditions['delay_seconds'],'obstacle_delay_seconds'=>$obstacle['delay'],'traffic_source'=>$conditions['source'],'traffic_samples'=>$conditions['samples'],'status_icon'=>$obstacle['icon'],'weather'=>$obstacle['weather']];
        $state['choices']=[];$state['started']=true;$state['vehicle']['current_speed']=$speed;$state['route'][]=$name;
        $this->event($id,$at,'segment_entered',['segment'=>$name,'destination'=>$segment['end'],'traffic_source'=>$conditions['source'],'samples'=>$conditions['samples']]);
        if($conditions['delay_seconds']>=60)$this->event($id,$at,'traffic_delay',['seconds'=>(int)round($conditions['delay_seconds']),'speed_cap'=>round((float)$trafficCap,1)]);
        if($obstacle['type']){$payload=['delay_seconds'=>(int)$obstacle['delay'],'mitigated'=>$obstacle['mitigated'],'jailed'=>$obstacle['jailed'],'weather'=>$obstacle['weather'],'speed_cap'=>$obstacle['speed_cap']];$this->event($id,$at,$obstacle['type'],$payload);if($obstacle['jailed']){$state['vehicle']['current_speed']=0;$state['failure_reason']='The crew was taken to jail before reaching Portofino Marina.';$this->event($id,$at,'crew_jailed');return'failed';}$penalty=in_array($obstacle['type'],['police_event','flat_tire'],true)?(int)$obstacle['delay']:0;$stationary=$obstacle['type']==='police_event'?1800:($obstacle['type']==='flat_tire'?900:0);$state['vehicle']['current_speed']=0;$state['pending_decision']=['type'=>'event','event'=>$obstacle['type'],'delay_seconds'=>(int)$obstacle['delay'],'penalty_seconds'=>$penalty,'stationary_seconds'=>$stationary,'mitigated'=>$obstacle['mitigated'],'weather'=>$obstacle['weather'],'speed_cap'=>$obstacle['speed_cap']];return'paused';}
        return'running';
    }

    private function advanceVehicle(array &$state,float $delta,string $id,DateTimeImmutable $at,string &$status,int $seed):void
    {
        $segment=&$state['current_segment'];$remaining=max(0,$segment['total_seconds']-$segment['elapsed_seconds']);$used=min($delta,$remaining);$fraction=$segment['total_seconds']>0?$used/$segment['total_seconds']:1;
        $miles=$segment['distance_miles']*$fraction;$state['vehicle']['fuel']-=$miles/$state['vehicle']['mpg'];$state['vehicle']['distance_miles']+=$miles;$segment['elapsed_seconds']+=$used;
        $fuelShare=$state['vehicle']['fuel']/$state['vehicle']['capacity'];if($fuelShare<=.25&&!($state['fuel_warning']??false)){$state['fuel_warning']=true;$this->event($id,$at,'fuel_warning',['percent'=>(int)round($fuelShare*100)]);}
        if($state['vehicle']['fuel']<=0){$state['vehicle']['fuel']=0;$state['vehicle']['current_speed']=0;$state['failure_reason']='The vehicle ran out of fuel before reaching Portofino Marina.';$status='failed';$this->event($id,$at,'out_of_fuel');return;}
        if($segment['elapsed_seconds']+0.01<$segment['total_seconds'])return;
        $state['node']=$segment['end'];$state['vehicle']['current_speed']=0;$this->event($id,$at,'segment_completed',['segment'=>$segment['name'],'node'=>$state['node']]);$state['current_segment']=null;
        if($state['node']==='portofino'){$state['end_reason']='The crew reached Portofino Marina, CA.';$status='completed';$this->event($id,$at,'arrived_portofino',['distance_miles'=>$state['vehicle']['distance_miles']]);return;}
        $state['choices']=$this->choicePayload($state['node']);if(count($state['choices'])===1){try{$status=$this->startSegment($state,$state['choices'][0]['name'],$at,$id,$seed+count($state['route']));}catch(RuntimeException $error){$status='failed';$state['failure_reason']='The run ended because supported traffic data was unavailable: '.$error->getMessage();$this->event($id,$at,'traffic_unavailable',['message'=>$error->getMessage()]);}}else{$status='awaiting_route';$state['pending_decision']=['type'=>'route'];$this->event($id,$at,'route_choice_required',['node'=>$state['node']]);}
    }

    private function advanceFatigue(array &$state,float $delta,string $id,DateTimeImmutable $at,string &$status):void
    {
        foreach($state['drivers'] as &$driver){$before=$driver['fatigue'];
            if($driver['role']==='driver'||$driver['role']==='copilot'){$hours=4+$driver['endurance']*.12;$multiplier=$driver['role']==='copilot'?.5:(!empty($state['driver_copilot'])?2:1);$rate=100/($hours*3600)*$multiplier;$driver['fatigue']=min(100,$driver['fatigue']+$delta*$rate);$driver['rest_seconds']=0;}
            else{$driver['rest_seconds']+=$delta;$recovery=(2+10*min(1,$driver['rest_seconds']/7200))/3600;$driver['fatigue']=max(0,$driver['fatigue']-$delta*$recovery);if(($driver['locked_rest']??false)&&$driver['fatigue']<=50)$driver['locked_rest']=false;}
            foreach([25,50,75,100] as $threshold)if($before<$threshold&&$driver['fatigue']>=$threshold)$this->event($id,$at,'fatigue_threshold',['driver'=>$driver['name'],'fatigue'=>$threshold]);
            if($driver['fatigue']>=100&&$driver['role']!=='rest'){$wasDriver=$driver['role']==='driver';$driver['role']='rest';$driver['locked_rest']=true;$driver['rest_seconds']=0;if($wasDriver){$state['driver_copilot']=false;$state['vehicle']['current_speed']=0;$status='awaiting_driver';$state['pending_decision']=['type'=>'driver','forced'=>true];$this->event($id,$at,'driver_exhausted',['driver'=>$driver['name']]);}else{$this->event($id,$at,'copilot_exhausted',['driver'=>$driver['name']]);}}
        }unset($driver);
    }

    private function assignCopilot(array &$state,string $copilotId,string $id,DateTimeImmutable $at):void
    {
        $current=$this->role($state,'driver');if(!$current)throw new RuntimeException('A driver must be assigned first.');$state['driver_copilot']=false;
        if($copilotId===$current['id']){$others=array_values(array_filter($state['drivers'],static fn(array $driver):bool=>$driver['id']!==$current['id']));$available=array_filter($others,static fn(array $driver):bool=>!($driver['locked_rest']??false)&&$driver['fatigue']<100);if(count($state['drivers'])>1&&$available)throw new RuntimeException('The driver can co-pilot only during a solo run or when every teammate requires rest.');$state['driver_copilot']=true;}
        $found=$copilotId===''||$copilotId===$current['id'];foreach($state['drivers'] as &$driver){if($driver['role']==='driver')continue;if($driver['id']===$copilotId){if(($driver['locked_rest']??false)||$driver['fatigue']>=100)throw new RuntimeException('That teammate must continue resting.');$driver['role']='copilot';$found=true;}else$driver['role']='rest';}unset($driver);
        if(!$found)throw new RuntimeException('Choose a co-pilot from the roster.');$this->event($id,$at,'copilot_changed',['copilot'=>$copilotId?:null,'driver_copilot'=>$state['driver_copilot']]);
    }

    private function changeDriver(array &$state,string $driverId,string $id,DateTimeImmutable $at):void
    {
        $current=$this->role($state,'driver');if($current&&$current['id']===$driverId)throw new RuntimeException('Choose a new driver.');$found=false;
        foreach($state['drivers'] as &$driver){if($driver['id']===$driverId){if(($driver['locked_rest']??false)||$driver['fatigue']>=100)throw new RuntimeException('That driver must rest until fatigue reaches 50%.');$driver['role']='driver';$found=true;}elseif($driver['role']==='driver'||$driver['role']==='copilot')$driver['role']='rest';}unset($driver);
        if(!$found)throw new RuntimeException('Choose an available driver.');$state['driver_copilot']=false;$this->event($id,$at,'driver_changed',['driver'=>$driverId]);
    }

    private function fuelStop(array &$state,string $id,DateTimeImmutable $at):void
    {
        $needed=max(0,$state['vehicle']['capacity']-$state['vehicle']['fuel']);if($needed<.01)throw new RuntimeException('The fuel tank is already full.');
        $copilot=$this->role($state,'copilot');$skill=$copilot?(float)$copilot['copilot']:25;
        $gpm=max(.1,(float)$this->settings->get('cruising_fuel_rate_gpm','5'));$seconds=300+$needed/$gpm*60+(100-$skill)*1.2;$state['vehicle']['fuel']=$state['vehicle']['capacity'];$state['vehicle']['stopped_seconds']+=$seconds;$state['fuel_warning']=false;
        foreach($state['drivers'] as &$driver)if($driver['role']==='rest')$driver['rest_seconds']=0;unset($driver);$state['_last_stop_seconds']=$seconds;
        $this->event($id,$at,'fuel_stop',['gallons'=>round($needed,2),'seconds'=>(int)round($seconds)]);
    }

    private function obstacle(int $seed,array $driver,?array $copilot,float $speed):array
    {
        $none=['type'=>null,'delay'=>0.0,'mitigated'=>false,'jailed'=>false,'speed_cap'=>null,'weather'=>null,'icon'=>null];$types=['weather_event','police_event','road_event','flat_tire'];$type=$types[hexdec(substr(hash('sha256','type:'.$seed),0,8))%count($types)];$roll=hexdec(substr(hash('sha256','occur:'.$seed),0,8))%1000;
        if($type==='weather_event'){$chance=180;if($roll>=$chance)return$none;$weatherTypes=[['name'=>'fog','speed'=>45.0,'icon'=>'🌫️'],['name'=>'rain','speed'=>80.0,'icon'=>'🌧️'],['name'=>'ice','speed'=>35.0,'icon'=>'🧊'],['name'=>'snow','speed'=>65.0,'icon'=>'🌨️']];$weather=$weatherTypes[hexdec(substr(hash('sha256','weather:'.$seed),0,8))%count($weatherTypes)];return array_replace($none,['type'=>$type,'speed_cap'=>$weather['speed'],'weather'=>$weather['name'],'icon'=>$weather['icon']]);}
        $copilotSkill=(float)($copilot['copilot']??0);$chance=(int)round(240-$copilotSkill*1.5+($driver['fatigue']>=75?120:0));if($roll>=$chance)return$none;if($type==='police_event'){if($speed<=70)return$none;$jailed=hexdec(substr(hash('sha256','jail:'.$seed),0,8))%2===0;return array_replace($none,['type'=>$type,'delay'=>$jailed?0.0:1800.0,'jailed'=>$jailed]);}
        if($type==='flat_tire')return array_replace($none,['type'=>$type,'delay'=>900.0+2*ceil($speed/5)]);
        $mitigation=($driver['driving']+$copilotSkill)/200;$base=120+($roll%481);$mitigated=$roll%100<round($mitigation*100);return array_replace($none,['type'=>$type,'delay'=>$base*($mitigated?.35:1),'mitigated'=>$mitigated]);
    }

    private function choicePayload(string $node):array{return array_map(static fn(array $segment):array=>['name'=>$segment['name'],'start'=>$segment['start'],'end'=>$segment['end'],'start_label'=>self::locationName($segment['start']),'end_label'=>self::locationName($segment['end']),'distance_miles'=>$segment['distance_miles']],$this->graph->onward($node));}
    private function role(array $state,string $role):?array{foreach($state['drivers'] as $driver)if($driver['role']===$role)return$driver;if($role==='copilot'&&!empty($state['driver_copilot']))return$this->role($state,'driver');return null;}
    private function event(string $id,DateTimeImmutable $at,string $type,array $payload=[]):void{$this->pdo->prepare('INSERT INTO simulation_events(simulation_id,simulated_at,type,payload) VALUES (?,?,?,?::jsonb)')->execute([$id,$at->format(DATE_ATOM),$type,json_encode($payload,JSON_THROW_ON_ERROR)]);}
    private function save(string $id,array $state,string $status,DateTimeImmutable $at):void{$finished=in_array($status,['completed','failed'],true)?',finished_at=now()':'';$sql="UPDATE simulations SET state=?::jsonb,status=?,simulated_at=?,updated_at=now(),last_tick_at=now(),version=version+1".$finished.' WHERE id=?';$this->pdo->prepare($sql)->execute([json_encode($state,JSON_THROW_ON_ERROR),$status,$at->format(DATE_ATOM),$id]);}
}
