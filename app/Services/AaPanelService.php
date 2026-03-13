<?php

namespace App\Services;

use GuzzleHttp\Client;
use RuntimeException;

class AaPanelService
{
    private Client $client;
    private string $apiKey;

    public function __construct(string $baseUrl, string $apiKey)
    {
        $this->apiKey = $apiKey;
        $this->client = new Client([
            'base_uri' => rtrim($baseUrl, '/'),
            'timeout'  => 30,
            'verify'   => false, // aaPanel uses self-signed certificates
        ]);
    }

    public function createSite(string $domain, string $path, string $phpVersion = '82'): array
    {
        return $this->post('/site', [
            'webname' => json_encode(['domain' => $domain, 'domainlist' => [], 'count' => 0]),
            'path'    => $path,
            'type_id' => '0',
            'type'    => 'PHP',
            'version' => $phpVersion,
            'port'    => '80',
            'ps'      => $domain,
            'ftp'     => '0',
            'sql'     => '0',
            'codeing' => 'utf-8',
        ]);
    }

    public function applySsl(string $domain): array
    {
        return $this->post('/acme', [
            'action'        => 'apply_cert_api',
            'domains'       => json_encode([['name' => $domain, 'owner' => $domain]]),
            'auth_type'     => 'http',
            'auto_wildcard' => '0',
        ]);
    }

    private function buildAuthParams(): array
    {
        $timestamp = time();

        return [
            'request_time'  => $timestamp,
            'request_token' => md5($timestamp . md5($this->apiKey)),
        ];
    }

    private function post(string $endpoint, array $params): array
    {
        $formParams = array_merge($this->buildAuthParams(), $params);

        try {
            $response = $this->client->post($endpoint, [
                'form_params' => $formParams,
            ]);
        } catch (\Exception $e) {
            throw new RuntimeException("aaPanel HTTP request failed: " . $e->getMessage());
        }

        $body = json_decode($response->getBody()->getContents(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("aaPanel returned invalid JSON");
        }

        if (isset($body['status']) && $body['status'] === false) {
            $message = $body['msg'] ?? 'Unknown error';
            throw new RuntimeException("aaPanel API error: " . $message);
        }

        return $body;
    }
}
