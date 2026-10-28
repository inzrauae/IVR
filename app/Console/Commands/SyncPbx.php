<?php

namespace App\Console\Commands;

use App\Jobs\SyncPbxServer;
use App\Models\PbxServer;
use Illuminate\Console\Command;
use Throwable;

class SyncPbx extends Command
{
    protected $signature = 'pbx:sync {server? : PBX server id (default: every active server)} {--prune : Also remove tenant endpoints the database no longer knows about}';

    protected $description = 'Re-provision every extension on a PBX server';

    public function handle(): int
    {
        $servers = $this->argument('server')
            ? PbxServer::whereKey($this->argument('server'))->get()
            : PbxServer::where('is_active', true)->get();

        if ($servers->isEmpty()) {
            $this->error('No matching PBX server.');

            return self::FAILURE;
        }

        $status = self::SUCCESS;

        foreach ($servers as $server) {
            try {
                $result = app()->call([new SyncPbxServer($server->id, (bool) $this->option('prune')), 'handle']);
                $this->line(sprintf('%-30s synced %d, failed %d, pruned %d', $server->name, $result['synced'], $result['failed'], $result['pruned']));

                if ($result['failed'] > 0) {
                    $status = self::FAILURE;
                }
            } catch (Throwable $e) {
                report($e);
                $this->error("{$server->name}: {$e->getMessage()}");
                $status = self::FAILURE;
            }
        }

        return $status;
    }
}
