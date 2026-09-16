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
        if(!$this->traffic->hasEvidenceForDate($departure))throw new RuntimeException('No directly supported traffic data is available for the selected departure day. Choose another date.');
        $capacity=max(1,min(100,(float)($input['capacity']??20)));$mpg=max(1,min(100,(float)($input['mpg']??20)));$speed=max(20,min(250,(float)($input['speed']??110)));
        $selected=array_map('strval',(array)($input['drivers']??[]));$initialDriver=(string)($input['initial_driver']??'self');$drivers=[];
        foreach($roster as $candidate)if($candidate['required']||in_array($candidate['id'],$selected,true))$drivers[]=$candidate+['role'=>$candidate['id']===$initialDriver?'driver':'rest','fatigue'=>0.0,'rest_seconds'=>0.0,'locked_rest'=>false];
        if(count($drivers)>3)throw new RuntimeException('Choose no more than two additional drivers.');
        if(!in_array($initialDriver,array_column($drivers,'id'),true))throw new RuntimeException('Choose an initial driver from the selected roster.');
        $seed=random_int(1,2147483647);$choices=$this->choicePayload('redball');if(!$choices)throw new RuntimeException('No supported route begins at Red Ball.');
        $state=['node'=>'redball','route'=>[],'traveled_segments'=>[],'choices'=>$choices,'current_segment'=>null,'drivers'=>$drivers,'driver_copilot'=>false,'pending_decision'=>['type'=>'route'],
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
        if(in_array($state['pending_decision']['type']??'',['event','confirmation'],true)&&!in_array($action,['acknowledge_event','fuel_change_driver'],true))throw new RuntimeException('Acknowledge the active event first.');
        if($action==='choose_segment'&&$status==='awaiting_route'){$name=(string)($payload['segment']??'');if(!in_array($name,array_column($state['choices'],'name'),true))throw new RuntimeException('Choose an available onward segment.');$state['pending_decision']=null;$status=$this->startSegment($state,$name,$at,$id,(int)$simulation['random_seed']);}
        elseif($action==='pause'&&$status==='running'){$status='paused';$state['user_paused']=true;$this->event($id,$at,'paused');}
        elseif($action==='resume'&&$status==='paused'&&empty($state['pending_decision'])){$status='running';$state['user_paused']=false;$state['vehicle']['current_speed']=$this->segmentSpeed($state);$this->event($id,$at,'resumed');}
        elseif($action==='target_speed'){$state['vehicle']['target_speed']=max(20,min(250,(float)($payload['speed']??$state['vehicle']['target_speed'])));if($state['current_segment']){$weatherCaps=['fog'=>45.0,'rain'=>80.0,'ice'=>35.0,'snow'=>65.0];$state['current_segment']['cruising_speed']=min($state['vehicle']['target_speed'],$weatherCaps[$state['current_segment']['weather']??'']??INF);if($status==='running')$state['vehicle']['current_speed']=$this->segmentSpeed($state);}$this->event($id,$at,'target_speed_changed',['speed'=>$state['vehicle']['target_speed']]);}
        elseif($action==='fuel'&&$status==='running'){$resumeSpeed=(float)$state['vehicle']['current_speed'];$deceleration=(int)ceil($resumeSpeed/5);$this->markMapEvent($state,'fuel_stop');$fuel=$this->fuelStop($state,$id,$at);$service=$fuel['seconds'];$acceleration=(int)ceil($resumeSpeed/5);$total=$deceleration+$service+$acceleration;$at=$at->modify('+'.$total.' seconds');$state['vehicle']['current_speed']=0;$status='paused';$state['pending_decision']=['type'=>'confirmation','title'=>'Fuel stop complete','message'=>sprintf("The vehicle slowed for %d seconds, dispensed %.1f gallons at %.1f GPM, spent 5 minutes paying, and needs %d seconds to return to speed.\n\n%d minutes %d seconds were added to the run.",$deceleration,$fuel['gallons'],$fuel['gpm'],$acceleration,intdiv($total,60),$total%60),'resume_speed'=>$resumeSpeed,'fuel_stop'=>true];}
        elseif($action==='fuel_change_driver'&&$status==='paused'&&($state['pending_decision']['fuel_stop']??false)){$resumeSpeed=(float)$state['pending_decision']['resume_speed'];$status='awaiting_driver';$state['pending_decision']=['type'=>'driver','forced'=>false,'fuel_stop'=>true,'resume_speed'=>$resumeSpeed];}
        elseif($action==='copilot'){$this->assignCopilot($state,(string)($payload['copilot']??''),$id,$at);}
        elseif($action==='request_driver_change'&&$status==='running'){$resumeSpeed=(float)$state['vehicle']['current_speed'];$status='awaiting_driver';$state['pending_decision']=['type'=>'driver','forced'=>false,'deceleration_seconds'=>(int)ceil(max(0,$resumeSpeed)/5),'resume_speed'=>$resumeSpeed];$this->event($id,$at,'driver_change_requested');}
        elseif($action==='cancel_driver_change'&&$status==='awaiting_driver'&&!($state['pending_decision']['forced']??false)){$status='running';$state['vehicle']['current_speed']=(float)($state['pending_decision']['resume_speed']??$this->segmentSpeed($state));$state['pending_decision']=null;$this->event($id,$at,'driver_change_cancelled');}
        elseif($action==='change_driver'&&$status==='awaiting_driver'){$duringFuel=(bool)($state['pending_decision']['fuel_stop']??false);$deceleration=(int)($state['pending_decision']['deceleration_seconds']??0);$resumeSpeed=$duringFuel?(float)$state['pending_decision']['resume_speed']:(float)($state['pending_decision']['resume_speed']??$this->segmentSpeed($state));$this->changeDriver($state,(string)($payload['driver']??''),$id,$at);$this->markMapEvent($state,'driver_change');if($duringFuel){$state['vehicle']['current_speed']=$resumeSpeed;$status='running';$state['pending_decision']=null;$this->event($id,$at,'driver_change_complete',['seconds'=>0,'during_fuel'=>true]);}else{$acceleration=(int)ceil($resumeSpeed/5);$service=120;$total=$deceleration+$service+$acceleration;$at=$at->modify('+'.$total.' seconds');$state['vehicle']['stopped_seconds']+=$service;$state['vehicle']['current_speed']=0;$status='paused';$state['pending_decision']=['type'=>'confirmation','title'=>'Driver change complete','message'=>sprintf("The vehicle slowed for %d seconds, the driver change took 120 seconds while stopped, and returning to speed takes %d seconds.\n\n%d minutes %d seconds were added to the run.",$deceleration,$acceleration,intdiv($total,60),$total%60),'resume_speed'=>$resumeSpeed];$this->event($id,$at,'driver_change_complete',['seconds'=>$total]);}}
        elseif($action==='acknowledge_event'&&$status==='paused'&&in_array($state['pending_decision']['type']??'',['event','confirmation'],true)){$pending=$state['pending_decision'];$penalty=(int)($pending['penalty_seconds']??0);$stationary=(int)($pending['stationary_seconds']??0);$eventType=(string)($pending['event']??'');if(in_array($eventType,['weather_event','road_event','police_event','flat_tire'],true))$this->markMapEvent($state,'obstacle_response');if($penalty>0)$at=$at->modify('+'.$penalty.' seconds');$state['vehicle']['stopped_seconds']+=$stationary;$state['pending_decision']=null;$status='running';$state['vehicle']['current_speed']=isset($pending['resume_speed'])?(float)$pending['resume_speed']:$this->segmentSpeed($state);$this->event($id,$at,'event_acknowledged',['seconds'=>$penalty,'event'=>$eventType]);}
        else throw new RuntimeException('That action is not available right now.');
        $this->save($id,$state,$status,$at);$this->pdo->commit();
        }catch(\Throwable $error){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$error;}
    }

    private function startSegment(array &$state,string $name,DateTimeImmutable $at,string $id,int $seed):string
    {
        $segment=$this->graph->segment($name);if(!$segment||$segment['start']!==$state['node'])throw new RuntimeException('That segment is not a valid continuation.');
        $driver=$this->role($state,'driver');$copilot=$this->role($state,'copilot');if(!$driver)throw new RuntimeException('Assign a driver before departing.');
        $conditions=$this->traffic->conditions($segment,$at,$seed+count($state['route']));$speed=max(20,(float)$state['vehicle']['target_speed']);$obstacleSeed=$seed+count($state['route'])*17;$obstacle=$this->obstacle($obstacleSeed,$driver,$copilot,$speed);$trafficCap=$trafficCapSeconds=null;
        if($conditions['delay_seconds']>=60){$fullSegmentSpeed=$segment['distance_miles']/(($segment['distance_miles']/$speed)+$conditions['delay_seconds']/3600);$trafficCap=max(5,min(65,$speed*.75,$fullSegmentSpeed));if($trafficCap<$speed)$trafficCapSeconds=$conditions['delay_seconds']/(1-$trafficCap/$speed);}
        $drive=$segment['distance_miles']/$speed*3600+$conditions['delay_seconds'];$trigger=.1+(hexdec(substr(hash('sha256','position:'.$obstacleSeed),0,8))%801)/1000;
        $state['current_segment']=$segment+['elapsed_seconds'=>0.0,'total_seconds'=>$drive,'distance_traveled_miles'=>0.0,'progress_fraction'=>0.0,'speed_trace'=>[],'map_events'=>[],'cruising_speed'=>$speed,'traffic_speed_cap'=>$trafficCap,'traffic_cap_seconds'=>$trafficCapSeconds,'traffic_cap_lifted'=>false,'traffic_delay_seconds'=>$conditions['delay_seconds'],'obstacle_delay_seconds'=>$obstacle['delay'],'traffic_source'=>$conditions['source'],'traffic_samples'=>$conditions['samples'],'status_icon'=>null,'weather'=>null,'mpg_multiplier'=>1.0,'pending_obstacle'=>$obstacle['type']?($obstacle+['trigger_fraction'=>$trigger]):null];
        $state['choices']=[];$state['started']=true;$state['vehicle']['current_speed']=$trafficCap??$speed;$state['route'][]=$name;
        $this->event($id,$at,'segment_entered',['segment'=>$name,'destination'=>$segment['end'],'traffic_source'=>$conditions['source'],'samples'=>$conditions['samples']]);
        if($conditions['delay_seconds']>=60)$this->event($id,$at,'traffic_delay',['seconds'=>(int)round($conditions['delay_seconds']),'speed_cap'=>round((float)$trafficCap,1)]);
        return'running';
    }

    private function advanceVehicle(array &$state,float $delta,string $id,DateTimeImmutable $at,string &$status,int $seed):void
    {
        $segment=&$state['current_segment'];$speed=max(.1,$this->segmentSpeed($state));$state['vehicle']['current_speed']=$speed;$distance=max(.001,(float)$segment['distance_miles']);$traveled=(float)($segment['distance_traveled_miles']??($distance*min(1,$segment['elapsed_seconds']/max(1,$segment['total_seconds']))));$remainingMiles=max(0,$distance-$traveled);$hasObstacle=!empty($segment['pending_obstacle']);$obstacleAt=$hasObstacle?$distance*(float)$segment['pending_obstacle']['trigger_fraction']:INF;if($hasObstacle&&$traveled+.001>=$obstacleAt){$this->activateObstacle($state,$id,$at,$status);return;}$untilObstacleSeconds=$hasObstacle?max(0,($obstacleAt-$traveled)/$speed*3600):INF;$used=min($delta,$remainingMiles/$speed*3600,$untilObstacleSeconds);$miles=min($remainingMiles,$speed*$used/3600);$from=$traveled/$distance;$to=min(1,($traveled+$miles)/$distance);
        $effectiveMpg=$state['vehicle']['mpg']*max(.1,(float)($segment['mpg_multiplier']??1));$state['vehicle']['fuel']-=$miles/$effectiveMpg;$state['vehicle']['distance_miles']+=$miles;$segment['distance_traveled_miles']=$traveled+$miles;$segment['progress_fraction']=$to;$this->traceSpeed($segment,$from,$to,$speed,(float)$state['vehicle']['target_speed']);$segment['elapsed_seconds']+=$used;
        if($segment['traffic_speed_cap']!==null&&$segment['elapsed_seconds']>=(float)$segment['traffic_cap_seconds']){$state['vehicle']['current_speed']=(float)$segment['cruising_speed'];if(!($segment['traffic_cap_lifted']??false)){$segment['traffic_cap_lifted']=true;$this->event($id,$at,'traffic_cap_lifted',['speed'=>round((float)$segment['cruising_speed'],1)]);}}
        if($hasObstacle&&$segment['distance_traveled_miles']+.001>=$obstacleAt){$this->activateObstacle($state,$id,$at,$status);return;}
        if($state['vehicle']['fuel']<=0){$state['vehicle']['fuel']=0;$state['vehicle']['current_speed']=0;$state['failure_reason']='The vehicle ran out of fuel before reaching Portofino Marina.';$status='failed';$this->event($id,$at,'out_of_fuel');return;}
        $fuelShare=$state['vehicle']['fuel']/$state['vehicle']['capacity'];if($fuelShare<=.25&&!($state['fuel_warning']??false)){$percent=(int)round($fuelShare*100);$state['fuel_warning']=true;$state['pending_decision']=['type'=>'event','event'=>'fuel_warning','percent'=>$percent];$status='paused';$this->event($id,$at,'fuel_warning',['percent'=>$percent]);return;}
        if($segment['distance_traveled_miles']+.001<$distance)return;
        $state['node']=$segment['end'];$state['vehicle']['current_speed']=0;$state['traveled_segments'][]=['name'=>$segment['name'],'start'=>$segment['start'],'end'=>$segment['end'],'polyline'=>$segment['polyline'],'speed_trace'=>$segment['speed_trace']??[],'map_events'=>$segment['map_events']??[]];$this->event($id,$at,'segment_completed',['segment'=>$segment['name'],'node'=>$state['node']]);$state['current_segment']=null;
        if($state['node']==='portofino'){$state['end_reason']='The crew reached Portofino Marina, CA.';$status='completed';$this->event($id,$at,'arrived_portofino',['distance_miles'=>$state['vehicle']['distance_miles']]);return;}
        $state['choices']=$this->choicePayload($state['node']);if(count($state['choices'])===1){try{$status=$this->startSegment($state,$state['choices'][0]['name'],$at,$id,$seed+count($state['route']));}catch(RuntimeException $error){$status='failed';$state['failure_reason']='The run ended because supported traffic data was unavailable: '.$error->getMessage();$this->event($id,$at,'traffic_unavailable',['message'=>$error->getMessage()]);}}else{$status='awaiting_route';$state['pending_decision']=['type'=>'route'];$this->event($id,$at,'route_choice_required',['node'=>$state['node']]);}
    }

    private function advanceFatigue(array &$state,float $delta,string $id,DateTimeImmutable $at,string &$status):void
    {
        foreach($state['drivers'] as &$driver){$before=$driver['fatigue'];
            if($driver['role']==='driver'||$driver['role']==='copilot'){$hours=1+$driver['endurance']*.15;$multiplier=$driver['role']==='copilot'?.5:(!empty($state['driver_copilot'])?2:1);$rate=100/($hours*3600)*$multiplier;$driver['fatigue']=min(100,$driver['fatigue']+$delta*$rate);$driver['rest_seconds']=0;}
            else{$driver['rest_seconds']+=$delta;$recovery=(2+10*min(1,$driver['rest_seconds']/7200))/3600;$driver['fatigue']=max(0,$driver['fatigue']-$delta*$recovery);if(($driver['locked_rest']??false)&&$driver['fatigue']<=50)$driver['locked_rest']=false;}
            foreach([25,50,75,100] as $threshold)if($before<$threshold&&$driver['fatigue']>=$threshold)$this->event($id,$at,'fatigue_threshold',['driver'=>$driver['name'],'fatigue'=>$threshold]);
            if($driver['fatigue']>=100&&$driver['role']!=='rest'){$wasDriver=$driver['role']==='driver';$driver['role']='rest';$driver['locked_rest']=true;$driver['rest_seconds']=0;if($wasDriver){$state['driver_copilot']=false;$state['vehicle']['current_speed']=0;$status='awaiting_driver';$state['pending_decision']=['type'=>'driver','forced'=>true];$this->event($id,$at,'driver_exhausted',['driver'=>$driver['name']]);}else{$this->event($id,$at,'copilot_exhausted',['driver'=>$driver['name']]);}}
        }unset($driver);
    }

    private function activateObstacle(array &$state,string $id,DateTimeImmutable $at,string &$status):void
    {
        $segment=&$state['current_segment'];$obstacle=$segment['pending_obstacle'];$segment['pending_obstacle']=null;$mapType=$obstacle['type']==='weather_event'?'weather_'.$obstacle['weather']:$obstacle['type'];$this->markMapEvent($state,$mapType);
        if($obstacle['type']==='weather_event'){$segment['weather']=$obstacle['weather'];$segment['status_icon']=$obstacle['icon'];$segment['mpg_multiplier']=(float)$obstacle['mpg_multiplier'];if($obstacle['speed_cap']!==null){$new=min((float)$state['vehicle']['target_speed'],(float)$obstacle['speed_cap']);$remainingMiles=max(0,(float)$segment['distance_miles']-(float)$segment['distance_traveled_miles']);$segment['total_seconds']=$segment['elapsed_seconds']+$remainingMiles/max(1,$new)*3600;$segment['cruising_speed']=$new;}}
        $payload=['delay_seconds'=>(int)$obstacle['delay'],'mitigated'=>$obstacle['mitigated'],'jailed'=>$obstacle['jailed'],'weather'=>$obstacle['weather'],'speed_cap'=>$obstacle['speed_cap'],'mpg_multiplier'=>$obstacle['mpg_multiplier']];$this->event($id,$at,$obstacle['type'],$payload);
        if($obstacle['jailed']){$state['vehicle']['current_speed']=0;$state['failure_reason']='The crew was taken to jail before reaching Portofino Marina.';$status='failed';$this->event($id,$at,'crew_jailed');return;}
        $penalty=in_array($obstacle['type'],['police_event','flat_tire','road_event'],true)?(int)$obstacle['delay']:0;$stationary=$obstacle['type']==='police_event'?1800:($obstacle['type']==='flat_tire'?900:0);$state['vehicle']['current_speed']=0;$state['pending_decision']=['type'=>'event','event'=>$obstacle['type'],'delay_seconds'=>(int)$obstacle['delay'],'penalty_seconds'=>$penalty,'stationary_seconds'=>$stationary,'mitigated'=>$obstacle['mitigated'],'weather'=>$obstacle['weather'],'speed_cap'=>$obstacle['speed_cap'],'mpg_multiplier'=>$obstacle['mpg_multiplier']];$status='paused';
    }

    private function assignCopilot(array &$state,string $copilotId,string $id,DateTimeImmutable $at):void
    {
        $current=$this->role($state,'driver');if(!$current)throw new RuntimeException('A driver must be assigned first.');$state['driver_copilot']=false;
        if($copilotId===$current['id']){$others=array_values(array_filter($state['drivers'],static fn(array $driver):bool=>$driver['id']!==$current['id']));$available=array_filter($others,static fn(array $driver):bool=>!($driver['locked_rest']??false)&&$driver['fatigue']<100);if(count($state['drivers'])>1&&$available)throw new RuntimeException('The driver can co-pilot only during a solo run or when every teammate requires rest.');$state['driver_copilot']=true;}
        $found=$copilotId===''||$copilotId===$current['id'];foreach($state['drivers'] as &$driver){if($driver['role']==='driver')continue;if($driver['id']===$copilotId){if(($driver['locked_rest']??false)||$driver['fatigue']>=100)throw new RuntimeException('That teammate must continue resting.');$driver['role']='copilot';$found=true;}else$driver['role']='rest';}unset($driver);
        if(!$found)throw new RuntimeException('Choose a co-pilot from the roster.');$assigned=$copilotId===''?null:($this->role($state,'copilot')['name']??null);$this->event($id,$at,'copilot_changed',['copilot'=>$assigned,'driver_copilot'=>$state['driver_copilot']]);
    }

    private function changeDriver(array &$state,string $driverId,string $id,DateTimeImmutable $at):void
    {
        $current=$this->role($state,'driver');if($current&&$current['id']===$driverId)throw new RuntimeException('Choose a new driver.');$found=false;
        foreach($state['drivers'] as &$driver){if($driver['id']===$driverId){if(($driver['locked_rest']??false)||$driver['fatigue']>=100)throw new RuntimeException('That driver must rest until fatigue reaches 50%.');$driver['role']='driver';$found=true;}elseif($driver['role']==='driver'||$driver['role']==='copilot')$driver['role']='rest';}unset($driver);
        if(!$found)throw new RuntimeException('Choose an available driver.');$state['driver_copilot']=false;$assigned=$this->role($state,'driver');$this->event($id,$at,'driver_changed',['driver'=>$assigned['name']??$driverId]);
    }

    private function fuelStop(array &$state,string $id,DateTimeImmutable $at):array
    {
        $needed=max(0,$state['vehicle']['capacity']-$state['vehicle']['fuel']);if($needed<.01)throw new RuntimeException('The fuel tank is already full.');
        $gpm=max(.1,(float)$this->settings->get('cruising_fuel_rate_gpm','5'));$seconds=(int)round(300+$needed/$gpm*60);$state['vehicle']['fuel']=$state['vehicle']['capacity'];$state['vehicle']['stopped_seconds']+=$seconds;$state['fuel_warning']=false;
        foreach($state['drivers'] as &$driver)if($driver['role']==='rest')$driver['rest_seconds']=0;unset($driver);
        $this->event($id,$at,'fuel_stop',['gallons'=>round($needed,2),'seconds'=>$seconds,'gpm'=>$gpm]);return['seconds'=>$seconds,'gallons'=>$needed,'gpm'=>$gpm];
    }

    private function obstacle(int $seed,array $driver,?array $copilot,float $speed):array
    {
        $none=['type'=>null,'delay'=>0.0,'mitigated'=>false,'jailed'=>false,'speed_cap'=>null,'weather'=>null,'icon'=>null,'mpg_multiplier'=>1.0];$types=['weather_event','police_event','road_event','flat_tire'];$type=$types[hexdec(substr(hash('sha256','type:'.$seed),0,8))%count($types)];$roll=hexdec(substr(hash('sha256','occur:'.$seed),0,8))%1000;
        if($type==='weather_event'){$chance=180;if($roll>=$chance)return$none;$weatherTypes=[['name'=>'fog','speed'=>45.0,'icon'=>'🌫️','mpg'=>1.0],['name'=>'rain','speed'=>80.0,'icon'=>'🌧️','mpg'=>1.0],['name'=>'ice','speed'=>35.0,'icon'=>'🧊','mpg'=>1.0],['name'=>'snow','speed'=>65.0,'icon'=>'🌨️','mpg'=>1.0],['name'=>'tail_wind','speed'=>null,'icon'=>'💨','mpg'=>1.15],['name'=>'head_wind','speed'=>null,'icon'=>'🌬️','mpg'=>.85]];$weather=$weatherTypes[hexdec(substr(hash('sha256','weather:'.$seed),0,8))%count($weatherTypes)];return array_replace($none,['type'=>$type,'speed_cap'=>$weather['speed'],'weather'=>$weather['name'],'icon'=>$weather['icon'],'mpg_multiplier'=>$weather['mpg']]);}
        $driverSkill=(float)$driver['driving'];$driverFatigue=(float)$driver['fatigue'];if($driverFatigue>50)$driverSkill*=max(.5,1-($driverFatigue-50)/100);$copilotSkill=(float)($copilot['copilot']??0);$copilotFatigue=(float)($copilot['fatigue']??0);if($copilotFatigue>50)$copilotSkill*=max(.5,1-($copilotFatigue-50)/100);$chance=(int)round(240-$copilotSkill*1.5+($driverFatigue>=75?120:0));if($roll>=$chance)return$none;if($type==='police_event'){if($speed<=70)return$none;$jailed=hexdec(substr(hash('sha256','jail:'.$seed),0,8))%2===0;return array_replace($none,['type'=>$type,'delay'=>$jailed?0.0:1800.0,'jailed'=>$jailed]);}
        if($type==='flat_tire')return array_replace($none,['type'=>$type,'delay'=>900.0+2*ceil($speed/5)]);
        $mitigation=($driverSkill+$copilotSkill)/200;$base=120+($roll%481);$mitigated=$roll%100<round($mitigation*100);return array_replace($none,['type'=>$type,'delay'=>$base*($mitigated?.35:1),'mitigated'=>$mitigated]);
    }

    private function choicePayload(string $node):array{return array_map(static fn(array $segment):array=>['name'=>$segment['name'],'start'=>$segment['start'],'end'=>$segment['end'],'start_label'=>self::locationName($segment['start']),'end_label'=>self::locationName($segment['end']),'distance_miles'=>$segment['distance_miles']],$this->graph->onward($node));}
    private function traceSpeed(array &$segment,float $from,float $to,float $speed,float $target):void{$ratio=$target>0?max(0,min(1,$speed/$target)):0;$trace=&$segment['speed_trace'];$last=array_key_last($trace);if($last!==null&&abs((float)$trace[$last]['ratio']-$ratio)<.005&&abs((float)$trace[$last]['to']-$from)<.002){$trace[$last]['to']=$to;return;}$trace[]=['from'=>$from,'to'=>$to,'ratio'=>$ratio,'speed'=>$speed,'target'=>$target];}
    private function markMapEvent(array &$state,string $type):void{if(!$state['current_segment'])return;$segment=&$state['current_segment'];$fraction=(float)($segment['progress_fraction']??($segment['total_seconds']>0?max(0,min(1,$segment['elapsed_seconds']/$segment['total_seconds'])):0));$segment['map_events'][]=['type'=>$type,'fraction'=>$fraction];}
    private function segmentSpeed(array $state):float{$segment=$state['current_segment']??null;if(!$segment)return 0.0;$trafficActive=$segment['traffic_speed_cap']!==null&&$segment['traffic_speed_cap']<$segment['cruising_speed']&&$segment['elapsed_seconds']<(float)$segment['traffic_cap_seconds'];return(float)($trafficActive?$segment['traffic_speed_cap']:$segment['cruising_speed']);}
    private function role(array $state,string $role):?array{foreach($state['drivers'] as $driver)if($driver['role']===$role)return$driver;if($role==='copilot'&&!empty($state['driver_copilot']))return$this->role($state,'driver');return null;}
    private function event(string $id,DateTimeImmutable $at,string $type,array $payload=[]):void{$this->pdo->prepare('INSERT INTO simulation_events(simulation_id,simulated_at,type,payload) VALUES (?,?,?,?::jsonb)')->execute([$id,$at->format(DATE_ATOM),$type,json_encode($payload,JSON_THROW_ON_ERROR)]);}
    private function save(string $id,array $state,string $status,DateTimeImmutable $at):void{$finished=in_array($status,['completed','failed'],true)?',finished_at=now()':'';$sql="UPDATE simulations SET state=?::jsonb,status=?,simulated_at=?,updated_at=now(),last_tick_at=now(),version=version+1".$finished.' WHERE id=?';$this->pdo->prepare($sql)->execute([json_encode($state,JSON_THROW_ON_ERROR),$status,$at->format(DATE_ATOM),$id]);}
}
