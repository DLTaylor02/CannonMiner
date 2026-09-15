<?php
declare(strict_types=1);

namespace CannonMiner;

use PDO;

final class RouteGraph
{
    private const METERS_PER_MILE=1609.344;
    private ?array $segments=null;

    public function __construct(private PDO $pdo){}

    public function segments():array
    {
        if($this->segments!==null)return$this->segments;
        $rows=$this->pdo->query(<<<'SQL'
            SELECT s.id,s.name,s.start_node,s.end_node,s.origin,s.destination,s.timezone,
              percentile_cont(.5) WITHIN GROUP (ORDER BY m.distance_meters)::float AS distance_meters,
              percentile_cont(.5) WITHIN GROUP (ORDER BY m.duration_seconds)::float AS normal_seconds,
              (SELECT latest.raw_payload #>> '{routes,0,overview_polyline,points}' FROM measurements latest
               WHERE latest.segment_id=s.id AND latest.raw_payload #>> '{routes,0,overview_polyline,points}' IS NOT NULL
               ORDER BY latest.collected_at DESC,latest.id DESC LIMIT 1) AS polyline
            FROM segments s JOIN measurements m ON m.segment_id=s.id
            WHERE s.enabled AND m.distance_meters>0 AND m.duration_seconds>0
            GROUP BY s.id ORDER BY s.name
        SQL)->fetchAll();
        $this->segments=[];foreach($rows as $row)$this->segments[$row['name']]=[
            'id'=>(int)$row['id'],'name'=>$row['name'],'start'=>$row['start_node'],'end'=>$row['end_node'],
            'origin'=>$row['origin'],'destination'=>$row['destination'],'timezone'=>$row['timezone'],
            'distance_meters'=>(float)$row['distance_meters'],'distance_miles'=>(float)$row['distance_meters']/self::METERS_PER_MILE,
            'normal_seconds'=>(float)$row['normal_seconds'],'polyline'=>$row['polyline']?:null,
        ];
        return$this->segments;
    }

    public function onward(string $node):array
    {
        $segments=$this->segments();$reverse=[];foreach($segments as $segment)$reverse[$segment['end']][]=$segment['start'];
        $reachable=['portofino'=>true];$pending=['portofino'];while($pending){$next=array_pop($pending);foreach($reverse[$next]??[] as $previous)if(!isset($reachable[$previous])){$reachable[$previous]=true;$pending[]=$previous;}}
        return array_values(array_filter($segments,static fn(array $segment):bool=>$segment['start']===$node&&isset($reachable[$segment['end']])));
    }

    public function segment(string $name):?array{return$this->segments()[$name]??null;}
}
