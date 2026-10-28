<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Support\Webhooks;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * POSTs one signed event. Non-2xx responses and timeouts are retried with backoff; each
 * attempt's outcome is stored on the delivery so the portal can show it.
 */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 1800];

    public function __construct(public readonly int $deliveryId) {}

    public function handle(): void
    {
        $delivery = WebhookDelivery::with(['webhook' => fn ($q) => $q->withoutGlobalScopes()])->find($this->deliveryId);

        if (! $delivery || ! $delivery->webhook || ! $delivery->webhook->active || $delivery->delivered_at) {
            return;
        }

        $body = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES);
        $timestamp = now()->timestamp;
        $status = null;
        $error = null;

        try {
            if (! Webhooks::isPublicUrl($delivery->webhook->url)) {
                throw new ConnectionException('The URL no longer resolves to a public address; delivery refused.');
            }

            $response = Http::timeout(10)
                ->withoutRedirecting()
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'CloudPBX-Webhooks/1.0',
                    'X-CloudPBX-Event' => $delivery->event,
                    'X-CloudPBX-Delivery' => (string) $delivery->id,
                    'X-CloudPBX-Signature' => Webhooks::signature($delivery->webhook->secret, $body, $timestamp),
                ])
                ->withBody($body, 'application/json')
                ->post($delivery->webhook->url);

            $status = $response->status();
            $error = $response->successful() ? null : 'HTTP '.$status;
        } catch (ConnectionException $e) {
            $error = $e->getMessage();
        }

        $delivery->forceFill([
            'attempts' => $delivery->attempts + 1,
            'response_status' => $status,
            'error' => $error ? substr($error, 0, 1000) : null,
            'delivered_at' => $error ? null : now(),
        ])->save();

        if ($error && $this->attempts() < $this->tries) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 1800);
        }
    }
}
