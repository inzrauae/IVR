<?php

namespace App\Models;

/**
 * Defaults for the JSON settings stored on organizations.settings, one array per section.
 */
final class OrganizationSettings
{
    public const DATE_FORMATS = [
        'Y-m-d' => '2026-10-28',
        'd/m/Y' => '28/10/2026',
        'm/d/Y' => '10/28/2026',
        'd M Y' => '28 Oct 2026',
    ];

    public const LANGUAGES = ['en' => 'English', 'si' => 'Sinhala', 'ta' => 'Tamil'];

    public const RECORDING_MODES = [
        'none' => 'Do not record',
        'inbound' => 'Inbound calls',
        'outbound' => 'Outbound calls',
        'all' => 'All calls',
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function defaults(): array
    {
        return [
            'general' => [
                'date_format' => 'Y-m-d',
            ],
            'call' => [
                'default_caller_id' => null,
                'ring_timeout' => 25,
                'max_call_minutes' => 120,
                'recording_mode' => 'none',
                'recording_announcement' => true,
                'allow_international' => false,
                'block_premium' => true,
                'hold_music' => 'default',
            ],
            'notifications' => [
                'missed_call_emails' => [],
                'voicemail_emails' => [],
                'trunk_failure_emails' => [],
                'billing_emails' => [],
            ],
            'security' => [
                'ip_allowlist' => [],
            ],
        ];
    }
}
