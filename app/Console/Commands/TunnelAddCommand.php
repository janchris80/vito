<?php

namespace App\Console\Commands;

use App\Jobs\Tunnel\TunnelAddJob;
use Illuminate\Console\Command;

class TunnelAddCommand extends Command
{
    protected $signature = 'tunnel:add {hostname} {--port=80 : The local port to forward to}';

    protected $description = 'Add a hostname to Cloudflare Tunnel (DNS CNAME + ingress route)';

    public function handle(): int
    {
        $hostname = $this->argument('hostname');
        $port = $this->option('port');

        $this->info("Adding {$hostname} to Cloudflare Tunnel...");
        $this->newLine();

        dispatch(new TunnelAddJob($hostname, (int) $port));

        $this->info('Job dispatched. Check worker logs for progress.');
        $this->info('Run "php artisan tunnel:list" to verify.');

        return self::SUCCESS;
    }
}
