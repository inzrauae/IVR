<?php

namespace App\Notifications;

use App\Models\Extension;
use App\Models\VoicemailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Holds plain values rather than tenant models (see MissedCall).
 */
class VoicemailReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public readonly string $from;

    public readonly string $mailbox;

    public readonly ?int $duration;

    public function __construct(VoicemailMessage $message, Extension $mailbox)
    {
        $this->from = $message->caller_name ?: ($message->caller_number ?? 'an unknown caller');
        $this->mailbox = "{$mailbox->number} · {$mailbox->name}";
        $this->duration = $message->duration;
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
            ->subject("New voicemail for {$this->mailbox} from {$this->from}")
            ->line("{$this->from} left a ".($this->duration ? "{$this->duration}-second " : '')."message for {$this->mailbox}.")
            ->action('Listen', route('voicemail.index'));
    }
}
