<?php
declare(strict_types=1);

namespace CannonMiner;

use DateTimeImmutable;
use PDO;
use RuntimeException;

final class SimulationTrafficProvider
{
    public function __construct(private PDO $pdo){}

    public function conditions(array $segment,DateTimeImmutable $at,int $seed):array
    {
        $now=new DateTimeImmutable('now');
        if($at<$now){
            $statement=$this->pdo->prepare(<<<'SQL'
                SELECT duration_seconds,duration_in_traffic_seconds,distance_meters,collected_at
                FROM measurements WHERE segment_id=? AND duration_seconds>0 AND duration_in_traffic_seconds>0
                  AND abs(extract(epoch FROM (collected_at-?::timestamptz)))<=7200
                ORDER BY abs(extract(epoch FROM (collected_at-?::timestamptz))),collected_at DESC LIMIT 1
            SQL);$instant=$at->format(DATE_ATOM);$statement->execute([$segment['id'],$instant,$instant]);$row=$statement->fetch();
            if(!$row)throw new RuntimeException('No directly recorded traffic exists within two hours of this past segment time.');
            return['delay_seconds'=>max(0,(float)$row['duration_in_traffic_seconds']-(float)$row['duration_seconds']),
                'distance_meters'=>(float)$row['distance_meters'],'source'=>'recorded','samples'=>1];
        }
        $statement=$this->pdo->prepare(<<<'SQL'
            SELECT greatest(0,duration_in_traffic_seconds-duration_seconds)::float AS delay,distance_meters
            FROM measurements
            WHERE segment_id=? AND duration_seconds>0 AND duration_in_traffic_seconds>0 AND distance_meters>0
              AND extract(month FROM collected_at AT TIME ZONE ?)=?
              AND extract(isodow FROM collected_at AT TIME ZONE ?)=?
              AND abs((extract(hour FROM collected_at AT TIME ZONE ?)*60+extract(minute FROM collected_at AT TIME ZONE ?))-?)<=60
            ORDER BY collected_at
        SQL);
        $zone=$segment['timezone'];$local=$at->setTimezone(new \DateTimeZone($zone));$minute=(int)$local->format('G')*60+(int)$local->format('i');
        $statement->execute([$segment['id'],$zone,(int)$local->format('n'),$zone,(int)$local->format('N'),$zone,$zone,$minute]);$rows=$statement->fetchAll();
        if(!$rows)throw new RuntimeException('No directly supported future traffic pattern exists for this segment and time.');
        $index=abs($seed)%count($rows);$row=$rows[$index];return['delay_seconds'=>(float)$row['delay'],
            'distance_meters'=>(float)$row['distance_meters'],'source'=>'projected','samples'=>count($rows)];
    }
}
