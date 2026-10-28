<?php

namespace App\Authorization;

/**
 * Every permission in the system, named "<module>.<action>".
 */
final class Permission
{
    public const DASHBOARD_VIEW = 'dashboard.view';

    public const CALLS_VIEW_LIVE = 'calls.view_live';

    public const CALLS_MANAGE_LIVE = 'calls.manage_live';

    public const WEBPHONE_USE = 'webphone.use';

    public const EXTENSIONS_VIEW = 'extensions.view';

    public const EXTENSIONS_MANAGE = 'extensions.manage';

    public const DEPARTMENTS_VIEW = 'departments.view';

    public const DEPARTMENTS_MANAGE = 'departments.manage';

    public const IVR_VIEW = 'ivr.view';

    public const IVR_MANAGE = 'ivr.manage';

    public const IVR_PUBLISH = 'ivr.publish';

    public const ROUTING_VIEW = 'routing.view';

    public const ROUTING_MANAGE = 'routing.manage';

    public const QUEUES_VIEW = 'queues.view';

    public const QUEUES_MANAGE = 'queues.manage';

    public const RING_GROUPS_VIEW = 'ring_groups.view';

    public const RING_GROUPS_MANAGE = 'ring_groups.manage';

    public const TRUNKS_VIEW = 'trunks.view';

    public const TRUNKS_MANAGE = 'trunks.manage';

    public const DIDS_VIEW = 'dids.view';

    public const DIDS_MANAGE = 'dids.manage';

    public const CALL_HISTORY_VIEW = 'call_history.view';

    public const RECORDINGS_VIEW = 'recordings.view';

    public const RECORDINGS_DOWNLOAD = 'recordings.download';

    public const RECORDINGS_DELETE = 'recordings.delete';

    public const VOICEMAIL_VIEW = 'voicemail.view';

    public const VOICEMAIL_MANAGE = 'voicemail.manage';

    public const CONFERENCES_VIEW = 'conferences.view';

    public const CONFERENCES_MANAGE = 'conferences.manage';

    public const CONTACTS_VIEW = 'contacts.view';

    public const CONTACTS_MANAGE = 'contacts.manage';

    public const REPORTS_VIEW = 'reports.view';

    public const REPORTS_EXPORT = 'reports.export';

    public const INTEGRATIONS_VIEW = 'integrations.view';

    public const INTEGRATIONS_MANAGE = 'integrations.manage';

    public const BILLING_VIEW = 'billing.view';

    public const BILLING_MANAGE = 'billing.manage';

    public const USERS_VIEW = 'users.view';

    public const USERS_MANAGE = 'users.manage';

    public const SETTINGS_VIEW = 'settings.view';

    public const SETTINGS_MANAGE = 'settings.manage';

    public const AUDIT_LOGS_VIEW = 'audit_logs.view';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_values((new \ReflectionClass(self::class))->getConstants());
    }
}
