<?php

namespace App\Jobs\Tunnel;

use App\Actions\Tunnel\CloudflareTunnel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class TunnelAddJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public string $hostname,
        public int $port = 80,
    ) {
        $this->onQueue('ssh');
    }

    public function handle(CloudflareTunnel $tunnel): void
    {
        Log::info("TunnelAddJob: Adding {$this->hostname}");

        $tunnel->createDnsRecord($this->hostname);
        $tunnel->addIngressRule($this->hostname);

        Log::info("TunnelAddJob: {$this->hostname} added successfully");
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("TunnelAddJob failed for {$this->hostname}: {$exception->getMessage()}");
    }
}
