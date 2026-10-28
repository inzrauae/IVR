<?php

namespace App\Support;

use App\Jobs\DeliverWebhook;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Support\Str;

/**
 * Queues an event for every active webhook of an organization that subscribed to it.
 */
final class Webhooks
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function dispatch(int $organizationId, string $event, array $data): void
    {
        Webhook::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('active', true)
            ->get()
            ->filter(fn (Webhook $webhook) => in_array($event, $webhook->events ?? [], true))
            ->each(fn (Webhook $webhook) => self::send($webhook, $event, $data));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function send(Webhook $webhook, string $event, array $data): WebhookDelivery
    {
        $delivery = $webhook->deliveries()->create([
            'event' => $event,
            'payload' => [
                'id' => (string) Str::uuid(),
                'event' => $event,
                'created_at' => now()->toIso8601String(),
                'organization_id' => $webhook->organization_id,
                'data' => $data,
            ],
        ]);

        DeliverWebhook::dispatch($delivery->id);

        return $delivery;
    }

    /**
     * False when the URL's host is, or resolves to, a loopback, private or reserved address.
     * Checked when a webhook is saved and again before every delivery (DNS can change).
     */
    public static function isPublicUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '' || strtolower($host) === 'localhost') {
            return false;
        }

        $host = trim($host, '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Value of the X-CloudPBX-Signature header: "t=<unix time>,v1=<hex HMAC-SHA256 of "<t>.<body>">".
     */
    public static function signature(string $secret, string $body, int $timestamp): string
    {
        return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
    }
}
