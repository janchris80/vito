<?php

namespace App\Console\Commands;

use App\Jobs\Tunnel\TunnelRemoveJob;
use Illuminate\Console\Command;

class TunnelRemoveCommand extends Command
{
    protected $signature = 'tunnel:remove {hostname}';

    protected $description = 'Remove a hostname from Cloudflare Tunnel (DNS CNAME + ingress route)';

    public function handle(): int
    {
        $hostname = $this->argument('hostname');

        $this->info("Removing {$hostname} from Cloudflare Tunnel...");
        $this->newLine();

        dispatch(new TunnelRemoveJob($hostname));

        $this->info('Job dispatched. Check worker logs for progress.');

        return self::SUCCESS;
    }
}
