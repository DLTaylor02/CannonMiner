<?php
declare(strict_types=1);

namespace CannonMiner;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

final class Router
{
    private const METERS_PER_MILE = 1609.344;

    public function __construct(private PDO $pdo, private Settings $settings) {}

    public function nodes(): array
    {
        $rows = $this->pdo->query('SELECT start_node AS node FROM segments WHERE enabled UNION SELECT end_node FROM segments WHERE enabled ORDER BY node')->fetchAll();
        return array_column($rows, 'node');
    }

    public function explore(string $start, string $end, float $mph, string $profile, float $maxRisk): array
    {
        if ($mph <= 0 || $maxRisk < 0 || $maxRisk > 1 || !in_array($profile, ['balanced', 'fastest', 'reliability'], true)) {
            throw new RuntimeException('Invalid routing options.');
        }
        $segments = $this->loadSegments();
        $routes = $this->candidateRoutes($segments, $start, $end, (int) $this->settings->get('candidate_routes', '25'), $mph);
        if ($routes === []) {
            throw new RuntimeException("No route exists from {$start} to {$end}.");
        }
        $timezone = new DateTimeZone($this->settings->get('timezone', 'America/New_York'));
        $departures = $this->departurePatterns($segments, $timezone);
        $evaluations = [];
        foreach ($routes as $route) {
            foreach ($departures as $departure) {
                $evaluations[] = $this->evaluate($route, $departure, $timezone, $mph);
            }
        }
        usort($evaluations, static function (array $a, array $b) use ($profile, $maxRisk): int {
            $aEligible = $a['risk'] <= $maxRisk; $bEligible = $b['risk'] <= $maxRisk;
            if ($aEligible !== $bEligible) return $aEligible ? -1 : 1;
            return $profile === 'reliability'
                ? [$a['risk'], $a['expected_seconds']] <=> [$b['risk'], $b['expected_seconds']]
                : [$a['expected_seconds'], $a['risk']] <=> [$b['expected_seconds'], $b['risk']];
        });
        return array_slice($evaluations, 0, 3);
    }

    public function trends(): array
    {
        $statement = $this->pdo->query(<<<'SQL'
            SELECT s.name, extract(isodow from m.collected_at)::int AS weekday,
                   extract(hour from m.collected_at)::int AS hour,
                   count(*)::int AS observations,
                   avg(greatest(0, m.duration_in_traffic_seconds-m.duration_seconds)) AS avg_delay,
                   percentile_cont(0.9) WITHIN GROUP (ORDER BY greatest(0, m.duration_in_traffic_seconds-m.duration_seconds)) AS p90_delay
            FROM measurements m JOIN segments s ON s.id=m.segment_id
            WHERE m.duration_in_traffic_seconds IS NOT NULL
            GROUP BY s.name, weekday, hour HAVING count(*) >= 2
            ORDER BY avg_delay DESC LIMIT 30
        SQL);
        return $statement->fetchAll();
    }

    private function loadSegments(): array
    {
        $rows = $this->pdo->query(<<<'SQL'
            SELECT s.*, m.collected_at, m.duration_seconds, m.duration_in_traffic_seconds, m.distance_meters
            FROM segments s LEFT JOIN measurements m ON m.segment_id=s.id
            WHERE s.enabled ORDER BY s.name, m.collected_at
        SQL)->fetchAll();
        $segments = [];
        foreach ($rows as $row) {
            $name = $row['name'];
            $segments[$name] ??= ['name' => $name, 'start' => $row['start_node'], 'end' => $row['end_node'],
                'origin' => $row['origin'], 'destination' => $row['destination'], 'samples' => []];
            if ($row['collected_at'] && $row['duration_in_traffic_seconds'] && $row['distance_meters']) {
                $segments[$name]['samples'][] = ['at' => new DateTimeImmutable($row['collected_at']),
                    'duration' => (float) $row['duration_seconds'], 'traffic' => (float) $row['duration_in_traffic_seconds'],
                    'distance' => (float) $row['distance_meters']];
            }
        }
        return array_filter($segments, static fn(array $segment): bool => $segment['samples'] !== []);
    }

    private function candidateRoutes(array $segments, string $start, string $end, int $limit, float $mph): array
    {
        $adjacent = [];
        foreach ($segments as $segment) $adjacent[$segment['start']][] = $segment;
        $queue = [[[], $start]]; $routes = [];
        while ($queue && count($routes) < 1000) {
            [$path, $node] = array_shift($queue);
            if ($node === $end && $path) { $routes[] = $path; continue; }
            $visited = array_merge([$start], array_column($path, 'end'));
            foreach ($adjacent[$node] ?? [] as $segment) {
                if (!in_array($segment['end'], $visited, true)) $queue[] = [[...$path, $segment], $segment['end']];
            }
        }
        usort($routes, function (array $a, array $b) use ($mph): int {
            $score = function (array $route) use ($mph): float {
                $total = 0.0;
                foreach ($route as $segment) {
                    $distance = $this->median(array_column($segment['samples'], 'distance'));
                    $delay = array_sum(array_map(static fn(array $s): float => max(0, $s['traffic']-$s['duration']), $segment['samples'])) / count($segment['samples']);
                    $total += $distance / ($mph * self::METERS_PER_MILE / 3600) + $delay;
                }
                return $total;
            };
            return $score($a) <=> $score($b);
        });
        return array_slice($routes, 0, max(1, $limit));
    }

    private function departurePatterns(array $segments, DateTimeZone $timezone): array
    {
        $dates = [];
        foreach ($segments as $segment) foreach ($segment['samples'] as $sample) {
            $local = $sample['at']->setTimezone($timezone); $key = $local->format('n-N');
            if (!isset($dates[$key]) || $local > $dates[$key]) $dates[$key] = $local;
        }
        $result = [];
        foreach ($dates as $date) for ($slot = 0; $slot < 48; $slot++) {
            $result[] = $date->setTime(0, 0)->modify('+' . ($slot * 30) . ' minutes');
        }
        return $result ?: [new DateTimeImmutable('now', $timezone)];
    }

    private function evaluate(array $route, DateTimeImmutable $departure, DateTimeZone $timezone, float $mph): array
    {
        $arrival = $departure; $drive = $delay = $distance = $support = 0.0; $risks = [];
        foreach ($route as $segment) {
            $prediction = $this->predict($segment['samples'], $arrival, $timezone);
            $seconds = $prediction['distance'] / ($mph * self::METERS_PER_MILE / 3600);
            $drive += $seconds; $delay += $prediction['delay']; $distance += $prediction['distance'];
            $support += $prediction['support']; $risks[] = $prediction['risk'];
            $arrival = $arrival->modify('+' . (int) round($seconds + $prediction['delay']) . ' seconds');
        }
        $nodes = array_merge([$route[0]['start']], array_column($route, 'end'));
        return ['route' => implode(' -> ', $nodes), 'departure' => $departure, 'drive_seconds' => $drive,
            'congestion_seconds' => $delay, 'expected_seconds' => $drive + $delay,
            'risk' => 1 - array_product(array_map(static fn(float $risk): float => 1 - $risk, $risks)),
            'distance_miles' => $distance / self::METERS_PER_MILE, 'observations' => (int) $support,
            'map_url' => $this->mapUrl($route)];
    }

    private function predict(array $samples, DateTimeImmutable $arrival, DateTimeZone $timezone): array
    {
        $delays = array_map(static fn(array $s): float => max(0, $s['traffic'] - $s['duration']), $samples);
        $global = array_sum($delays) / count($delays); $local = [];
        foreach ($samples as $index => $sample) {
            $observed = $sample['at']->setTimezone($timezone);
            $gap = abs(((int)$observed->format('G') * 60 + (int)$observed->format('i')) - ((int)$arrival->format('G') * 60 + (int)$arrival->format('i')));
            $gap = min($gap, 1440 - $gap);
            if ($observed->format('N') === $arrival->format('N') && $gap <= 90) $local[] = $delays[$index];
        }
        $share = count($local) / (count($local) + 10); $localMean = $local ? array_sum($local) / count($local) : $global;
        $threshold = max(120.0, $this->median(array_column($samples, 'duration')) * .05);
        $population = $local ?: $delays; $slow = count(array_filter($population, static fn(float $v): bool => $v >= $threshold));
        return ['delay' => $share * $localMean + (1-$share) * $global, 'distance' => $this->median(array_column($samples, 'distance')),
            'support' => count($local), 'risk' => $slow / count($population)];
    }

    private function median(array $values): float
    {
        sort($values, SORT_NUMERIC); $count = count($values); $middle = intdiv($count, 2);
        return $count % 2 ? (float)$values[$middle] : ((float)$values[$middle-1] + (float)$values[$middle]) / 2;
    }

    private function mapUrl(array $route): string
    {
        $origin = $route[0]['origin']; $destination = $route[array_key_last($route)]['destination'];
        $waypoints = array_map(static fn(array $s): string => $s['destination'], array_slice($route, 0, -1));
        return 'https://www.google.com/maps/embed/v1/directions?' . http_build_query(['key' => $this->settings->get('google_maps_api_key',''),
            'origin' => $origin, 'destination' => $destination, 'waypoints' => implode('|', $waypoints), 'mode' => 'driving']);
    }
}
