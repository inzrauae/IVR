<?php

namespace App\Pbx\Ami;

use App\Models\PbxServer;
use App\Pbx\Exceptions\PbxException;

/**
 * Minimal blocking AMI client: connect, log in, stream messages, send actions.
 */
class AmiConnection
{
    /** @var resource|null */
    private $socket = null;

    private AmiParser $parser;

    /** @var list<array<string, string>> */
    private array $pending = [];

    private int $actionCounter = 0;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $secret,
        private readonly int $timeout = 5,
    ) {
        $this->parser = new AmiParser;
    }

    public static function forServer(PbxServer $server): self
    {
        if (! $server->ami_host || ! $server->ami_username) {
            throw new PbxException("AMI host and username are not configured for {$server->name}.");
        }

        return new self($server->ami_host, (int) $server->ami_port, $server->ami_username, (string) $server->ami_secret);
    }

    public function connect(): void
    {
        $socket = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $error, $this->timeout);

        if (! $socket) {
            throw new PbxException("Cannot connect to AMI at {$this->host}:{$this->port}: {$error} ({$errno})");
        }

        stream_set_timeout($socket, $this->timeout);
        $this->socket = $socket;
        $this->parser = new AmiParser;
        $this->pending = [];

        $banner = fgets($socket);
        if ($banner === false || ! str_starts_with($banner, 'Asterisk Call Manager')) {
            $this->close();
            throw new PbxException('Unexpected AMI banner: '.trim((string) $banner));
        }

        $response = $this->action(['Action' => 'Login', 'Username' => $this->username, 'Secret' => $this->secret, 'Events' => 'on']);

        if (($response['Response'] ?? null) !== 'Success') {
            $this->close();
            throw new PbxException('AMI login failed: '.($response['Message'] ?? 'no response'));
        }
    }

    /**
     * Send an action and wait for its response. Events received meanwhile are queued for read().
     *
     * @param  array<string, string>  $fields
     * @return array<string, string>
     */
    public function action(array $fields): array
    {
        $id = 'cloudpbx-'.(++$this->actionCounter);
        $this->write(AmiParser::encode($fields + ['ActionID' => $id]));

        $deadline = microtime(true) + $this->timeout;

        while (microtime(true) < $deadline) {
            foreach ($this->receive(max(0.1, $deadline - microtime(true))) as $message) {
                if (($message['ActionID'] ?? null) === $id && isset($message['Response'])) {
                    return $message;
                }

                $this->pending[] = $message;
            }
        }

        throw new PbxException("AMI action {$fields['Action']} timed out.");
    }

    /**
     * Next message from the stream, or null if none arrived within $timeout seconds.
     *
     * @return array<string, string>|null
     */
    public function read(float $timeout): ?array
    {
        if ($this->pending === []) {
            $this->pending = $this->receive($timeout);
        }

        return array_shift($this->pending);
    }

    public function close(): void
    {
        if ($this->socket) {
            @fwrite($this->socket, AmiParser::encode(['Action' => 'Logoff']));
            @fclose($this->socket);
        }

        $this->socket = null;
    }

    /**
     * @return list<array<string, string>>
     */
    private function receive(float $timeout): array
    {
        $this->ensureConnected();

        $read = [$this->socket];
        $write = $except = null;
        $seconds = (int) $timeout;
        $micro = (int) (($timeout - $seconds) * 1_000_000);

        $ready = @stream_select($read, $write, $except, $seconds, $micro);

        if ($ready === false) {
            throw new PbxException('AMI socket error.');
        }

        if ($ready === 0) {
            return [];
        }

        $chunk = fread($this->socket, 65536);

        if ($chunk === '' || $chunk === false) {
            if (feof($this->socket)) {
                $this->socket = null;
                throw new PbxException('AMI connection closed by the server.');
            }

            return [];
        }

        return $this->parser->push($chunk);
    }

    private function write(string $data): void
    {
        $this->ensureConnected();

        if (@fwrite($this->socket, $data) === false) {
            $this->socket = null;
            throw new PbxException('Writing to AMI failed.');
        }
    }

    private function ensureConnected(): void
    {
        if (! $this->socket) {
            throw new PbxException('AMI is not connected.');
        }
    }
}
