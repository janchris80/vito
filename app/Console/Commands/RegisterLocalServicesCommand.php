<?php

namespace App\Console\Commands;

use App\Enums\ServiceStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class RegisterLocalServicesCommand extends Command
{
    protected $signature = 'services:register-local {--server= : Server ID to register services for}';

    protected $description = 'Detect locally installed services and register them in VitoDeploy without running install scripts';

    public function handle(): int
    {
        $serverId = $this->option('server');

        if (! $serverId) {
            $serverId = DB::table('servers')->min('id');
            if (! $serverId) {
                $this->error('No servers found. Add a server first.');

                return 1;
            }
        }

        $serverExists = DB::table('servers')->where('id', $serverId)->exists();
        if (! $serverExists) {
            $this->error("Server ID {$serverId} not found.");

            return 1;
        }

        $this->ensureKeyPairs($serverId);

        $this->info("Registering services for server #{$serverId}...");

        $existing = DB::table('services')
            ->where('server_id', $serverId)
            ->pluck('name')
            ->toArray();

        $detected = $this->detectServices();
        $registered = 0;

        foreach ($detected as $service) {
            if (in_array($service['name'], $existing)) {
                if ($service['name'] === 'php') {
                    $versionExists = DB::table('services')
                        ->where('server_id', $serverId)
                        ->where('name', 'php')
                        ->where('version', $service['version'])
                        ->exists();

                    if ($versionExists) {
                        $this->line("  <comment>SKIP</comment>  php {$service['version']} (already registered)");
                        continue;
                    }
                } else {
                    $this->line("  <comment>SKIP</comment>  {$service['name']} (already registered)");
                    continue;
                }
            }

            DB::table('services')->insert([
                'server_id' => $serverId,
                'type' => $service['type'],
                'name' => $service['name'],
                'version' => $service['version'],
                'installed_version' => $service['installed_version'],
                'unit' => $service['unit'],
                'status' => ServiceStatus::READY->value,
                'is_default' => $service['is_default'] ?? false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $label = $service['name'] === 'php'
                ? "php {$service['version']}"
                : $service['name'];

            $this->info("  <info>ADD</info>   {$label} ({$service['installed_version']})");
            $registered++;
        }

        $this->info("Done. {$registered} service(s) registered.");

        return 0;
    }

    private function ensureKeyPairs(int $serverId): void
    {
        $storageDisk = Storage::disk(config('core.key_pairs_disk'));
        $privatePath = $storageDisk->path((string) $serverId);
        $publicPath = $storageDisk->path($serverId.'.pub');
        $sourcePrivate = storage_path(config('core.ssh_private_key_name'));
        $sourcePublic = storage_path(config('core.ssh_public_key_name'));

        if (! File::exists($privatePath) && File::exists($sourcePrivate)) {
            File::copy($sourcePrivate, $privatePath);
            chmod($privatePath, 0600);
            $this->info('  <info>KEY</info>    Copied SSH private key to key-pairs storage');
        }

        if (! File::exists($publicPath) && File::exists($sourcePublic)) {
            File::copy($sourcePublic, $publicPath);
            $this->info('  <info>KEY</info>    Copied SSH public key to key-pairs storage');
        }
    }

    private function detectServices(): array
    {
        $services = [];

        if ($this->commandExists('nginx')) {
            $version = $this->exec('nginx -v 2>&1 | awk -F/ "{print $2}"');
            if ($version) {
                $services[] = [
                    'name' => 'nginx',
                    'type' => 'webserver',
                    'version' => 'latest',
                    'installed_version' => $version,
                    'unit' => 'nginx',
                    'is_default' => true,
                ];
            }
        }

        if ($this->commandExists('mysql')) {
            $version = $this->exec("mysql -V | grep -oE '[0-9]+\\.[0-9]+\\.[0-9]+'");
            if ($version) {
                $majorMinor = substr($version, 0, strrpos($version, '.'));
                $services[] = [
                    'name' => 'mysql',
                    'type' => 'database',
                    'version' => $majorMinor,
                    'installed_version' => $version,
                    'unit' => 'mysql',
                    'is_default' => true,
                ];
            }
        }

        if ($this->commandExists('redis-server')) {
            $version = $this->exec("redis-server --version | awk '{print $3}' | cut -d= -f2");
            if ($version) {
                $services[] = [
                    'name' => 'redis',
                    'type' => 'memory_database',
                    'version' => 'latest',
                    'installed_version' => $version,
                    'unit' => 'redis',
                    'is_default' => true,
                ];
            }
        }

        if ($this->commandExists('supervisord')) {
            $output = $this->exec('supervisord --version 2>&1');
            $version = explode("\n", trim($output))[0] ?? null;
            if ($version) {
                $services[] = [
                    'name' => 'supervisor',
                    'type' => 'process_manager',
                    'version' => 'latest',
                    'installed_version' => trim($version),
                    'unit' => 'supervisor',
                    'is_default' => true,
                ];
            }
        }

        if ($this->commandExists('ufw')) {
            $version = $this->exec("ufw version | grep -oE '[0-9]+\\.[0-9]+\\.[0-9]+'");
            if ($version) {
                $services[] = [
                    'name' => 'ufw',
                    'type' => 'firewall',
                    'version' => 'latest',
                    'installed_version' => $version,
                    'unit' => 'ufw',
                    'is_default' => true,
                ];
            }
        }

        $services[] = [
            'name' => 'remote-monitor',
            'type' => 'monitoring',
            'version' => 'latest',
            'installed_version' => 'latest',
            'unit' => '',
            'is_default' => true,
        ];

        $phpVersions = ['8.5', '8.4', '8.3', '8.2', '8.1', '8.0'];
        $firstPhp = true;
        foreach ($phpVersions as $v) {
            $binary = "/usr/bin/php{$v}";
            if (file_exists($binary)) {
                $version = $this->exec("{$binary} -r 'echo PHP_VERSION;' 2>/dev/null");
                if ($version) {
                    $services[] = [
                        'name' => 'php',
                        'type' => 'php',
                        'version' => $v,
                        'installed_version' => $version,
                        'unit' => "php{$v}-fpm",
                        'is_default' => $firstPhp,
                    ];
                    $firstPhp = false;
                }
            }
        }

        if ($this->commandExists('node')) {
            $version = $this->exec("node -v | tr -d 'v'");
            if ($version) {
                $major = explode('.', $version)[0] ?? $version;
                $services[] = [
                    'name' => 'nodejs',
                    'type' => 'nodejs',
                    'version' => (string) $major,
                    'installed_version' => $version,
                    'unit' => '',
                    'is_default' => true,
                ];
            }
        }

        return $services;
    }

    private function commandExists(string $cmd): bool
    {
        $result = trim(shell_exec("which {$cmd} 2>/dev/null") ?? '');

        return $result !== '' && file_exists($result);
    }

    private function exec(string $command): ?string
    {
        $output = trim(shell_exec($command.' 2>/dev/null') ?? '');

        return $output !== '' ? $output : null;
    }
}
