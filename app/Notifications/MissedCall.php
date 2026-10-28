<?php

namespace App\Notifications;

use App\Models\Call;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Holds plain values, not the Call: queued models are re-fetched through the tenant scope,
 * which matches nothing in a queue worker.
 */
class MissedCall extends Notification implements ShouldQueue
{
    use Queueable;

    public readonly string $from;

    public readonly string $to;

    public readonly string $at;

    public function __construct(Call $call)
    {
        $this->from = $call->from_name ? "{$call->from_name} ({$call->from_number})" : ($call->from_number ?? 'Unknown caller');
        $this->to = (string) $call->to_number;
        $this->at = $call->started_at->format('Y-m-d H:i');
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Missed call from {$this->from}")
            ->line("{$this->from} called {$this->to} at {$this->at} and nobody answered.")
            ->action('Open call history', route('call-history.index'));
    }
}
