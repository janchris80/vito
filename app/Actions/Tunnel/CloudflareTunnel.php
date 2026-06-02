<?php

namespace App\Actions\Tunnel;

use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CloudflareTunnel
{
    private string $apiToken;

    private string $zoneId;

    private string $accountId;

    private string $tunnelId;

    private string $domain;

    public function __construct()
    {
        $this->apiToken = config('services.cloudflare.api_token', '');
        $this->zoneId = config('services.cloudflare.zone_id', '');
        $this->accountId = config('services.cloudflare.account_id', '');
        $this->tunnelId = config('services.cloudflare.tunnel_id', '');
        $this->domain = config('services.cloudflare.domain', 'jcodev.online');
    }

    public function isConfigured(): bool
    {
        return ! empty($this->apiToken)
            && ! empty($this->zoneId)
            && ! empty($this->accountId)
            && ! empty($this->tunnelId);
    }

    public function setup(Site $site): void
    {
        if (! $this->isConfigured()) {
            Log::warning('Cloudflare tunnel not configured, skipping for '.$site->domain);

            return;
        }

        $this->createDnsRecord($site->domain);
        $this->addIngressRule($site->domain);
    }

    public function remove(Site $site): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        $this->removeDnsRecord($site->domain);
        $this->removeIngressRule($site->domain);
    }

    private function createDnsRecord(string $hostname): void
    {
        $tunnelHostname = $this->tunnelId.'.cfargotunnel.com';

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$this->apiToken,
            'Content-Type' => 'application/json',
        ])->post("https://api.cloudflare.com/client/v4/zones/{$this->zoneId}/dns_records", [
            'type' => 'CNAME',
            'name' => $hostname,
            'content' => $tunnelHostname,
            'ttl' => 1,
            'proxied' => true,
        ]);

        if (! $response->successful() || ! $response->json('success')) {
            $error = $response->json('errors.0.message') ?? 'Unknown error';

            if (str_contains($error, '81057') || str_contains($error, 'already exists')) {
                $this->updateDnsRecord($hostname, $tunnelHostname);

                return;
            }

            Log::error('Failed to create Cloudflare DNS record', [
                'hostname' => $hostname,
                'error' => $error,
            ]);

            return;
        }

        Log::info('Cloudflare DNS record created', ['hostname' => $hostname]);
    }

    private function updateDnsRecord(string $hostname, string $tunnelHostname): void
    {
        $records = Http::withHeaders([
            'Authorization' => 'Bearer '.$this->apiToken,
        ])->get("https://api.cloudflare.com/client/v4/zones/{$this->zoneId}/dns_records", [
            'type' => 'CNAME',
            'name' => $hostname,
        ])->json('result', []);

        if (empty($records)) {
            return;
        }

        $recordId = $records[0]['id'];

        Http::withHeaders([
            'Authorization' => 'Bearer '.$this->apiToken,
            'Content-Type' => 'application/json',
        ])->put("https://api.cloudflare.com/client/v4/zones/{$this->zoneId}/dns_records/{$recordId}", [
            'type' => 'CNAME',
            'name' => $hostname,
            'content' => $tunnelHostname,
            'ttl' => 1,
            'proxied' => true,
        ]);

        Log::info('Cloudflare DNS record updated', ['hostname' => $hostname]);
    }

    private function removeDnsRecord(string $hostname): void
    {
        $records = Http::withHeaders([
            'Authorization' => 'Bearer '.$this->apiToken,
        ])->get("https://api.cloudflare.com/client/v4/zones/{$this->zoneId}/dns_records", [
            'type' => 'CNAME',
            'name' => $hostname,
        ])->json('result', []);

        if (empty($records)) {
            return;
        }

        $recordId = $records[0]['id'];

        Http::withHeaders([
            'Authorization' => 'Bearer '.$this->apiToken,
        ])->delete("https://api.cloudflare.com/client/v4/zones/{$this->zoneId}/dns_records/{$recordId}");

        Log::info('Cloudflare DNS record removed', ['hostname' => $hostname]);
    }

    private function addIngressRule(string $hostname): void
    {
        $configResponse = Http::withHeaders([
            'Authorization' => 'Bearer '.$this->apiToken,
        ])->get("https://api.cloudflare.com/client/v4/accounts/{$this->accountId}/cfd_tunnel/{$this->tunnelId}/configurations");

        if (! $configResponse->successful()) {
            Log::error('Failed to fetch Cloudflare tunnel config');

            return;
        }

        $currentIngress = $configResponse->json('result.config.ingress', []);

        $exists = collect($currentIngress)->contains('hostname', $hostname);
        if ($exists) {
            Log::info('Cloudflare tunnel ingress rule already exists', ['hostname' => $hostname]);

            return;
        }

        $newIngress = collect($currentIngress)
            ->filter(fn ($rule) => ($rule['service'] ?? '') !== 'http_status:404')
            ->values()
            ->push([
                'hostname' => $hostname,
                'service' => 'http://localhost:80',
                'originRequest' => [],
            ])
            ->push([
                'service' => 'http_status:404',
            ])
            ->toArray();

        $updateResponse = Http::withHeaders([
            'Authorization' => 'Bearer '.$this->apiToken,
            'Content-Type' => 'application/json',
        ])->put("https://api.cloudflare.com/client/v4/accounts/{$this->accountId}/cfd_tunnel/{$this->tunnelId}/configurations", [
            'config' => [
                'ingress' => $newIngress,
            ],
        ]);

        if (! $updateResponse->successful() || ! $updateResponse->json('success')) {
            Log::error('Failed to update Cloudflare tunnel config', [
                'hostname' => $hostname,
                'error' => $updateResponse->json('errors.0.message'),
            ]);

            return;
        }

        Log::info('Cloudflare tunnel ingress rule added', ['hostname' => $hostname]);
    }

    private function removeIngressRule(string $hostname): void
    {
        $configResponse = Http::withHeaders([
            'Authorization' => 'Bearer '.$this->apiToken,
        ])->get("https://api.cloudflare.com/client/v4/accounts/{$this->accountId}/cfd_tunnel/{$this->tunnelId}/configurations");

        if (! $configResponse->successful()) {
            return;
        }

        $currentIngress = $configResponse->json('result.config.ingress', []);

        $newIngress = collect($currentIngress)
            ->filter(fn ($rule) => ($rule['hostname'] ?? '') !== $hostname)
            ->values()
            ->toArray();

        $hasCatchAll = collect($newIngress)->contains('service', 'http_status:404');
        if (! $hasCatchAll) {
            $newIngress[] = ['service' => 'http_status:404'];
        }

        Http::withHeaders([
            'Authorization' => 'Bearer '.$this->apiToken,
            'Content-Type' => 'application/json',
        ])->put("https://api.cloudflare.com/client/v4/accounts/{$this->accountId}/cfd_tunnel/{$this->tunnelId}/configurations", [
            'config' => [
                'ingress' => $newIngress,
            ],
        ]);

        Log::info('Cloudflare tunnel ingress rule removed', ['hostname' => $hostname]);
    }
}
