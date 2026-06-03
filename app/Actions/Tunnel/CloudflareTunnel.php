<?php

namespace App\Actions\Tunnel;

use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CloudflareTunnel
{
    private string $apiToken;

    private string $globalApiKey;

    private string $email;

    private string $zoneId;

    private string $accountId;

    private string $tunnelId;

    private string $domain;

    public function __construct()
    {
        $this->apiToken = config('services.cloudflare.api_token', '');
        $this->globalApiKey = config('services.cloudflare.global_api_key', '');
        $this->email = config('services.cloudflare.email', '');
        $this->zoneId = config('services.cloudflare.zone_id', '');
        $this->accountId = config('services.cloudflare.account_id', '');
        $this->tunnelId = config('services.cloudflare.tunnel_id', '');
        $this->domain = config('services.cloudflare.domain', 'jcodev.online');
    }

    public function isConfigured(): bool
    {
        return ! empty($this->zoneId)
            && ! empty($this->accountId)
            && ! empty($this->tunnelId)
            && (! empty($this->apiToken) || (! empty($this->globalApiKey) && ! empty($this->email)));
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

    private function dnsHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->apiToken,
            'Content-Type' => 'application/json',
        ];
    }

    private function tunnelHeaders(): array
    {
        return [
            'X-Auth-Key' => $this->globalApiKey,
            'X-Auth-Email' => $this->email,
            'Content-Type' => 'application/json',
        ];
    }

    public function createDnsRecord(string $hostname): void
    {
        $tunnelHostname = $this->tunnelId.'.cfargotunnel.com';

        $response = Http::withHeaders($this->dnsHeaders())
            ->post("https://api.cloudflare.com/client/v4/zones/{$this->zoneId}/dns_records", [
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

    public function updateDnsRecord(string $hostname, string $tunnelHostname): void
    {
        $records = Http::withHeaders(['Authorization' => 'Bearer '.$this->apiToken])
            ->get("https://api.cloudflare.com/client/v4/zones/{$this->zoneId}/dns_records", [
                'type' => 'CNAME',
                'name' => $hostname,
            ])->json('result', []);

        if (empty($records)) {
            return;
        }

        $recordId = $records[0]['id'];

        Http::withHeaders($this->dnsHeaders())
            ->put("https://api.cloudflare.com/client/v4/zones/{$this->zoneId}/dns_records/{$recordId}", [
                'type' => 'CNAME',
                'name' => $hostname,
                'content' => $tunnelHostname,
                'ttl' => 1,
                'proxied' => true,
            ]);

        Log::info('Cloudflare DNS record updated', ['hostname' => $hostname]);
    }

    public function removeDnsRecord(string $hostname): void
    {
        $records = Http::withHeaders(['Authorization' => 'Bearer '.$this->apiToken])
            ->get("https://api.cloudflare.com/client/v4/zones/{$this->zoneId}/dns_records", [
                'type' => 'CNAME',
                'name' => $hostname,
            ])->json('result', []);

        if (empty($records)) {
            return;
        }

        $recordId = $records[0]['id'];

        Http::withHeaders(['Authorization' => 'Bearer '.$this->apiToken])
            ->delete("https://api.cloudflare.com/client/v4/zones/{$this->zoneId}/dns_records/{$recordId}");

        Log::info('Cloudflare DNS record removed', ['hostname' => $hostname]);
    }

    public function addIngressRule(string $hostname): void
    {
        $configResponse = Http::withHeaders($this->tunnelHeaders())
            ->get("https://api.cloudflare.com/client/v4/accounts/{$this->accountId}/cfd_tunnel/{$this->tunnelId}/configurations");

        if (! $configResponse->successful()) {
            Log::error('Failed to fetch Cloudflare tunnel config', [
                'error' => $configResponse->json('errors.0.message'),
            ]);

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
            ])
            ->push([
                'service' => 'http_status:404',
            ])
            ->toArray();

        $updateResponse = Http::withHeaders($this->tunnelHeaders())
            ->put("https://api.cloudflare.com/client/v4/accounts/{$this->accountId}/cfd_tunnel/{$this->tunnelId}/configurations", [
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

    public function removeIngressRule(string $hostname): void
    {
        $configResponse = Http::withHeaders($this->tunnelHeaders())
            ->get("https://api.cloudflare.com/client/v4/accounts/{$this->accountId}/cfd_tunnel/{$this->tunnelId}/configurations");

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

        $updateResponse = Http::withHeaders($this->tunnelHeaders())
            ->put("https://api.cloudflare.com/client/v4/accounts/{$this->accountId}/cfd_tunnel/{$this->tunnelId}/configurations", [
                'config' => [
                    'ingress' => $newIngress,
                ],
            ]);

        if (! $updateResponse->successful() || ! $updateResponse->json('success')) {
            Log::error('Failed to remove Cloudflare tunnel ingress rule', [
                'hostname' => $hostname,
                'error' => $updateResponse->json('errors.0.message'),
            ]);

            return;
        }
        Log::info('Cloudflare tunnel ingress rule removed', ['hostname' => $hostname]);
    }

    public function getIngressRules(): array
    {
        $configResponse = Http::withHeaders($this->tunnelHeaders())
            ->get("https://api.cloudflare.com/client/v4/accounts/{$this->accountId}/cfd_tunnel/{$this->tunnelId}/configurations");

        if (! $configResponse->successful()) {
            return [];
        }

        return $configResponse->json('result.config.ingress', []);
    }
}
