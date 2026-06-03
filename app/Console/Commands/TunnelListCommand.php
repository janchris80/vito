<?php

namespace App\Console\Commands;

use App\Actions\Tunnel\CloudflareTunnel;
use Illuminate\Console\Command;

class TunnelListCommand extends Command
{
    protected $signature = 'tunnel:list';

    protected $description = 'List all Cloudflare Tunnel ingress rules and DNS records';

    public function handle(): int
    {
        $tunnel = app(CloudflareTunnel::class);

        if (! $tunnel->isConfigured()) {
            $this->error('Cloudflare tunnel is not configured. Check .env for CLOUDFLARE_* variables.');

            return self::FAILURE;
        }

        $ingress = $tunnel->getIngressRules();

        $this->info('Cloudflare Tunnel Ingress Rules');
        $this->line(str_repeat('-', 80));

        $rows = [];
        foreach ($ingress as $rule) {
            $hostname = $rule['hostname'] ?? '-';
            $service = $rule['service'] ?? '-';
            $rows[] = [$hostname, $service];
        }

        $this->table(['Hostname', 'Service'], $rows);

        return self::SUCCESS;
    }
}
