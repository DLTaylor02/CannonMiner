<?php
declare(strict_types=1);

namespace CannonMiner;

use GuzzleHttp\Client;
use PDO;
use RuntimeException;

final class Collector
{
    public function __construct(private PDO $pdo, private Settings $settings) {}

    public function collectAll(): array
    {
        if ($this->settings->get('google_data_storage_authorized', 'no') !== 'yes') {
            throw new RuntimeException('Collection is disabled until an administrator confirms that their Google agreement authorizes persistent traffic-data storage.');
        }
        $key = $this->settings->get('google_maps_api_key', '');
        if ($key === '') {
            throw new RuntimeException('Set google_maps_api_key in Settings before collecting data.');
        }
        $client = new Client(['base_uri' => 'https://maps.googleapis.com', 'timeout' => 20]);
        $segments = $this->pdo->query('SELECT * FROM segments WHERE enabled ORDER BY name')->fetchAll();
        $insert = $this->pdo->prepare(
            'INSERT INTO measurements (segment_id,duration_seconds,duration_in_traffic_seconds,distance_meters,raw_payload) VALUES (?,?,?,?,?::jsonb)'
        );
        $results = [];
        foreach ($segments as $segment) {
            $response = $client->get('/maps/api/directions/json', ['query' => [
                'origin' => $segment['origin'], 'destination' => $segment['destination'],
                'mode' => $segment['travel_mode'], 'departure_time' => time(), 'key' => $key,
            ]]);
            $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            if (($payload['status'] ?? null) !== 'OK') {
                throw new RuntimeException("Google Maps failed for {$segment['name']}: " . ($payload['status'] ?? 'unknown'));
            }
            $leg = $payload['routes'][0]['legs'][0];
            $insert->execute([$segment['id'], $leg['duration']['value'], $leg['duration_in_traffic']['value'] ?? null,
                $leg['distance']['value'], json_encode($payload, JSON_THROW_ON_ERROR)]);
            $results[] = $segment['name'];
        }
        return $results;
    }
}
