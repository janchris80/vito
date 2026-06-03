<?php

namespace App\Jobs\Tunnel;

use App\Actions\Tunnel\CloudflareTunnel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class TunnelRemoveJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public string $hostname,
    ) {
        $this->onQueue('ssh');
    }

    public function handle(CloudflareTunnel $tunnel): void
    {
        Log::info("TunnelRemoveJob: Removing {$this->hostname}");

        $tunnel->removeDnsRecord($this->hostname);
        $tunnel->removeIngressRule($this->hostname);

        Log::info("TunnelRemoveJob: {$this->hostname} removed successfully");
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("TunnelRemoveJob failed for {$this->hostname}: {$exception->getMessage()}");
    }
}
