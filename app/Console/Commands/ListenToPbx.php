<?php

namespace App\Console\Commands;

use App\Models\PbxServer;
use App\Pbx\Ami\AmiConnection;
use App\Pbx\Calls\CallEventProcessor;
use App\Pbx\PbxManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Long-running AMI event listener for one Asterisk server. Run one per server under
 * Supervisor/systemd. Reconnects with exponential backoff and reconciles calls after each
 * (re)connect so nothing stays "live" that ended while disconnected.
 */
class ListenToPbx extends Command
{
    protected $signature = 'pbx:listen {server : PBX server id} {--max-runtime=0 : Exit after N seconds so the supervisor restarts us (0 = forever)}';

    protected $description = 'Stream call and registration events from an Asterisk server';

    private bool $running = true;

    public function handle(CallEventProcessor $processor, PbxManager $pbx): int
    {
        $server = PbxServer::findOrFail($this->argument('server'));

        if ($server->driver !== 'asterisk') {
            $this->error("{$server->name} uses the {$server->driver} driver; only Asterisk servers have an event stream. Use pbx:simulate for the simulator.");

            return self::FAILURE;
        }

        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->running = false);
            pcntl_signal(SIGINT, fn () => $this->running = false);
        }

        $deadline = (int) $this->option('max-runtime') > 0 ? time() + (int) $this->option('max-runtime') : null;
        $backoff = 1;

        while ($this->running && (! $deadline || time() < $deadline)) {
            $connection = null;

            try {
                $server->refresh();
                $connection = AmiConnection::forServer($server);
                $connection->connect();
                $this->info("[{$server->name}] connected to AMI.");

                $closed = $processor->reconcile($server, $pbx->driver($server)->activeChannelIds());
                if ($closed) {
                    $this->warn("[{$server->name}] closed {$closed} call(s) that ended while disconnected.");
                }

                $backoff = 1;
                $lastActivity = time();

                while ($this->running && (! $deadline || time() < $deadline)) {
                    $message = $connection->read(5);

                    if ($message === null) {
                        // Keepalive: an idle link that stops answering pings is treated as dead.
                        if (time() - $lastActivity >= 30) {
                            $connection->action(['Action' => 'Ping']);
                            $lastActivity = time();
                        }

                        continue;
                    }

                    $lastActivity = time();

                    if (isset($message['Event'])) {
                        DB::transaction(fn () => $processor->handle($server, $message));
                    }
                }
            } catch (Throwable $e) {
                report($e);
                $this->error("[{$server->name}] {$e->getMessage()} – retrying in {$backoff}s");
                sleep($backoff);
                $backoff = min($backoff * 2, 30);
            } finally {
                $connection?->close();
            }
        }

        return self::SUCCESS;
    }
}
