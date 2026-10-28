<?php

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * In-memory stand-in for the parts of Asterisk's ARI that the AsteriskDriver uses:
 * dynamic res_pjsip objects, global variables, endpoint listing and asterisk/info.
 */
class FakeAsterisk
{
    /** @var array<string, array<string, array<string, string>>> type => id => attribute => value */
    public array $objects = [];

    /** @var array<string, string> */
    public array $globals = [];

    public ?int $failWith = null;

    public string $startupTime = '2026-10-01T00:00:00.000+0000';

    public static function install(): self
    {
        $fake = new self;
        Http::fake(fn (Request $request) => $fake->handle($request));

        return $fake;
    }

    public function endpoint(string $id): ?array
    {
        return $this->objects['endpoint'][$id] ?? null;
    }

    private function handle(Request $request): PromiseInterface
    {
        if ($this->failWith) {
            return Http::response(['message' => 'Simulated PBX failure'], $this->failWith);
        }

        $path = preg_replace('#^.*/ari/#', '', parse_url($request->url(), PHP_URL_PATH));

        if ($path === 'asterisk/info') {
            return Http::response([
                'system' => ['version' => '22.1.0', 'entity_id' => 'fake'],
                'status' => ['startup_time' => $this->startupTime, 'last_reload_time' => $this->startupTime],
                'build' => ['os' => 'Linux'],
            ]);
        }

        if ($path === 'asterisk/variable' && $request->method() === 'POST') {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $this->globals[$query['variable']] = $query['value'] ?? '';

            return Http::response(null, 204);
        }

        if ($path === 'endpoints/PJSIP') {
            return Http::response(array_map(
                fn ($id) => ['technology' => 'PJSIP', 'resource' => $id, 'state' => 'offline', 'channel_ids' => []],
                array_keys($this->objects['endpoint'] ?? []),
            ));
        }

        if (preg_match('#^asterisk/config/dynamic/res_pjsip\w*/(\w+)/(.+)$#', $path, $m)) {
            [, $type, $id] = $m;

            return match ($request->method()) {
                'PUT' => $this->put($type, $id, $request->data()['fields'] ?? []),
                'GET' => isset($this->objects[$type][$id])
                    ? Http::response($this->fields($this->objects[$type][$id]))
                    : Http::response(['message' => 'Object not found'], 404),
                'DELETE' => $this->delete($type, $id),
                default => Http::response(null, 405),
            };
        }

        return Http::response(['message' => "Unhandled fake ARI path {$path}"], 404);
    }

    /**
     * @param  list<array{attribute: string, value: string}>  $fields
     */
    private function put(string $type, string $id, array $fields): PromiseInterface
    {
        $stored = [];
        foreach ($fields as $field) {
            // Repeated attributes (set_var) are kept as a newline-joined list.
            $stored[$field['attribute']] = isset($stored[$field['attribute']])
                ? $stored[$field['attribute']]."\n".$field['value']
                : $field['value'];
        }

        $this->objects[$type][$id] = $stored;

        return Http::response($this->fields($stored));
    }

    private function delete(string $type, string $id): PromiseInterface
    {
        if (! isset($this->objects[$type][$id])) {
            return Http::response(['message' => 'Object not found'], 404);
        }

        unset($this->objects[$type][$id]);

        return Http::response(null, 204);
    }

    /**
     * @param  array<string, string>  $stored
     * @return list<array{attribute: string, value: string}>
     */
    private function fields(array $stored): array
    {
        return collect($stored)->map(fn ($value, $attribute) => ['attribute' => $attribute, 'value' => $value])->values()->all();
    }
}
