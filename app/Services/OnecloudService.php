<?php

namespace App\Services;

use GuzzleHttp\Client;
use RuntimeException;

class OnecloudService
{
    private Client $client;

    public function __construct(string $token)
    {
        $this->client = new Client([
            'base_uri' => 'https://api.1cloud.ru',
            'timeout'  => 15,
            'headers'  => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
            ],
        ]);
    }

    /**
     * Find a DNS zone by domain name (e.g. "our24.ru").
     */
    public function findZone(string $domainName): array
    {
        $zones = $this->get('/dns');

        foreach ($zones as $zone) {
            $name = $zone['Name'] ?? $zone['name'] ?? '';
            if (rtrim($name, '.') === rtrim($domainName, '.')) {
                return $zone;
            }
        }

        throw new RuntimeException("DNS zone not found for domain: {$domainName}");
    }

    /**
     * Add an A record to a DNS zone.
     */
    public function addARecord(int $zoneId, string $name, string $ip, int $ttl = 300): array
    {
        return $this->post("/dns/{$zoneId}/record", [
            'Name' => $name,
            'Type' => 'A',
            'IP'   => $ip,
            'TTL'  => $ttl,
        ]);
    }

    private function get(string $endpoint): array
    {
        try {
            $response = $this->client->get($endpoint);
        } catch (\Exception $e) {
            throw new RuntimeException("1cloud API GET request failed: " . $e->getMessage());
        }

        $body = json_decode($response->getBody()->getContents(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("1cloud API returned invalid JSON");
        }

        return $body;
    }

    private function post(string $endpoint, array $body): array
    {
        try {
            $response = $this->client->post($endpoint, [
                'json' => $body,
            ]);
        } catch (\Exception $e) {
            throw new RuntimeException("1cloud API POST request failed: " . $e->getMessage());
        }

        $data = json_decode($response->getBody()->getContents(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("1cloud API returned invalid JSON");
        }

        return $data;
    }
}
