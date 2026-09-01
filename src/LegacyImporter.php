<?php
declare(strict_types=1);

namespace CannonMiner;

use PDO;

final class LegacyImporter
{
    private const BATCH_SIZE = 500;

    public function __construct(private PDO $pdo) {}

    /** @return array{tables:int,rows:int,skipped:int} */
    public function import(callable $progress): array
    {
        $tables = $this->pdo->query(<<<'SQL'
            SELECT table_name
            FROM information_schema.tables
            WHERE table_schema='public' AND table_name LIKE 'segment\_%' ESCAPE '\'
            ORDER BY table_name
        SQL)->fetchAll(PDO::FETCH_COLUMN);

        $eligible = [];
        $skipped = 0;
        $segmentLookup = $this->pdo->prepare('SELECT id FROM segments WHERE name=?');
        foreach ($tables as $table) {
            $segmentLookup->execute([substr((string) $table, 8)]);
            $segmentId = $segmentLookup->fetchColumn();
            if ($segmentId === false) {
                $skipped++;
                continue;
            }
            $eligible[] = ['table' => (string) $table, 'segment_id' => (int) $segmentId];
        }

        $total = 0;
        foreach ($eligible as &$item) {
            $table = $this->quoteIdentifier($item['table']);
            $source = $this->pdo->quote($item['table']);
            $item['remaining'] = (int) $this->pdo->query(
                "SELECT count(*) FROM {$table} l LEFT JOIN legacy_measurement_imports i ON i.source_table={$source} AND i.source_id=l.id WHERE i.source_id IS NULL"
            )->fetchColumn();
            $total += $item['remaining'];
        }
        unset($item);

        $done = 0;
        $progress($done, $total, null);
        $insertMeasurement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO measurements
              (segment_id,collected_at,duration_seconds,duration_in_traffic_seconds,distance_meters,raw_payload)
            VALUES (?,?,?,?,?,?::jsonb) RETURNING id
        SQL);
        $trackImport = $this->pdo->prepare(
            'INSERT INTO legacy_measurement_imports(source_table,source_id,measurement_id) VALUES (?,?,?) ON CONFLICT DO NOTHING'
        );

        foreach ($eligible as $item) {
            if ($item['remaining'] === 0) {
                continue;
            }
            $table = $this->quoteIdentifier($item['table']);
            $source = $this->pdo->quote($item['table']);
            do {
                $rows = $this->pdo->query(<<<SQL
                    SELECT l.id,l.collected_at,l.duration_seconds,l.duration_in_traffic_seconds,l.distance_meters,l.raw_payload
                    FROM {$table} l
                    LEFT JOIN legacy_measurement_imports i ON i.source_table={$source} AND i.source_id=l.id
                    WHERE i.source_id IS NULL
                    ORDER BY l.id LIMIT 500
                SQL)->fetchAll();
                if ($rows === []) {
                    break;
                }
                $this->pdo->beginTransaction();
                try {
                    foreach ($rows as $row) {
                        $insertMeasurement->execute([$item['segment_id'], $row['collected_at'], $row['duration_seconds'],
                            $row['duration_in_traffic_seconds'], $row['distance_meters'], $row['raw_payload']]);
                        $trackImport->execute([$item['table'], $row['id'], $insertMeasurement->fetchColumn()]);
                    }
                    $this->pdo->commit();
                } catch (\Throwable $error) {
                    $this->pdo->rollBack();
                    throw $error;
                }
                $done += count($rows);
                $progress($done, $total, $item['table']);
            } while (count($rows) === self::BATCH_SIZE);
        }

        return ['tables' => count($eligible), 'rows' => $done, 'skipped' => $skipped];
    }

    private function quoteIdentifier(string $value): string
    {
        return '"' . str_replace('"', '""', $value) . '"';
    }
}
