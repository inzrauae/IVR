<?php

namespace App\Authorization;

use App\Authorization\Permission as P;

/**
 * Organization-level roles. Platform super admins are flagged on the user record
 * (users.is_platform_admin) rather than holding a tenant role.
 */
enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case CallCenterManager = 'call_center_manager';
    case Supervisor = 'supervisor';
    case Agent = 'agent';
    case Employee = 'employee';
    case BillingManager = 'billing_manager';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Organization Owner',
            self::Admin => 'Organization Administrator',
            self::CallCenterManager => 'Call Center Manager',
            self::Supervisor => 'Supervisor',
            self::Agent => 'Agent',
            self::Employee => 'Standard Employee',
            self::BillingManager => 'Billing Manager',
        };
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => P::all(),
            self::Admin => array_values(array_diff(P::all(), [P::BILLING_MANAGE])),
            self::CallCenterManager => [
                P::DASHBOARD_VIEW, P::CALLS_VIEW_LIVE, P::CALLS_MANAGE_LIVE, P::WEBPHONE_USE,
                P::EXTENSIONS_VIEW, P::DEPARTMENTS_VIEW, P::QUEUES_VIEW, P::QUEUES_MANAGE,
                P::RING_GROUPS_VIEW, P::RING_GROUPS_MANAGE, P::CALL_HISTORY_VIEW,
                P::RECORDINGS_VIEW, P::RECORDINGS_DOWNLOAD, P::VOICEMAIL_VIEW,
                P::CONFERENCES_VIEW, P::CONFERENCES_MANAGE, P::CONTACTS_VIEW, P::CONTACTS_MANAGE,
                P::REPORTS_VIEW, P::REPORTS_EXPORT, P::USERS_VIEW,
            ],
            self::Supervisor => [
                P::DASHBOARD_VIEW, P::CALLS_VIEW_LIVE, P::CALLS_MANAGE_LIVE, P::WEBPHONE_USE,
                P::QUEUES_VIEW, P::RING_GROUPS_VIEW, P::CALL_HISTORY_VIEW, P::RECORDINGS_VIEW,
                P::VOICEMAIL_VIEW, P::CONTACTS_VIEW, P::REPORTS_VIEW,
            ],
            self::Agent, self::Employee => [
                P::DASHBOARD_VIEW, P::WEBPHONE_USE, P::CALL_HISTORY_VIEW, P::VOICEMAIL_VIEW, P::CONTACTS_VIEW,
            ],
            self::BillingManager => [
                P::DASHBOARD_VIEW, P::BILLING_VIEW, P::BILLING_MANAGE, P::REPORTS_VIEW,
            ],
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $r) => [$r->value => $r->label()])->all();
    }
}
