<?php

use App\Authorization\Permission as P;

/*
|--------------------------------------------------------------------------
| Organization portal modules
|--------------------------------------------------------------------------
|
| Drives the sidebar and the routes for modules that are not built yet. A module
| with 'available' => false renders a clearly-marked "not yet available" page that
| names the delivery phase, so no screen pretends to work before it does.
|
*/

return [
    ['key' => 'dashboard', 'title' => 'Dashboard', 'icon' => 'LayoutDashboard', 'path' => 'dashboard', 'route' => 'dashboard', 'permission' => P::DASHBOARD_VIEW, 'group' => 'Overview', 'available' => true],
    ['key' => 'live-calls', 'title' => 'Live Calls', 'icon' => 'PhoneCall', 'path' => 'live-calls', 'route' => 'live-calls.index', 'permission' => P::CALLS_VIEW_LIVE, 'group' => 'Overview', 'available' => true],
    ['key' => 'web-phone', 'title' => 'Web Phone', 'icon' => 'Headset', 'path' => 'web-phone', 'route' => 'web-phone.index', 'permission' => P::WEBPHONE_USE, 'group' => 'Overview', 'available' => true],

    ['key' => 'extensions', 'title' => 'Extensions', 'icon' => 'Phone', 'path' => 'extensions', 'route' => 'extensions.index', 'permission' => P::EXTENSIONS_VIEW, 'group' => 'Telephony', 'available' => true],
    ['key' => 'departments', 'title' => 'Departments', 'icon' => 'Building2', 'path' => 'departments', 'route' => 'departments.index', 'permission' => P::DEPARTMENTS_VIEW, 'group' => 'Telephony', 'available' => true],
    ['key' => 'ivr', 'title' => 'IVR Builder', 'icon' => 'Workflow', 'path' => 'ivr', 'route' => 'ivr.index', 'permission' => P::IVR_VIEW, 'group' => 'Telephony', 'available' => true],
    ['key' => 'routing', 'title' => 'Call Routing', 'icon' => 'Split', 'path' => 'routing', 'route' => 'routing.index', 'permission' => P::ROUTING_VIEW, 'group' => 'Telephony', 'available' => true],
    ['key' => 'queues', 'title' => 'Call Queues', 'icon' => 'ListOrdered', 'path' => 'queues', 'route' => 'queues.index', 'permission' => P::QUEUES_VIEW, 'group' => 'Telephony', 'available' => true],
    ['key' => 'ring-groups', 'title' => 'Ring Groups', 'icon' => 'BellRing', 'path' => 'ring-groups', 'route' => 'ring-groups.index', 'permission' => P::RING_GROUPS_VIEW, 'group' => 'Telephony', 'available' => true],
    ['key' => 'trunks', 'title' => 'SIP Trunks', 'icon' => 'Cable', 'path' => 'trunks', 'route' => 'trunks.index', 'permission' => P::TRUNKS_VIEW, 'group' => 'Telephony', 'available' => true],
    ['key' => 'dids', 'title' => 'DID Numbers', 'icon' => 'Hash', 'path' => 'dids', 'route' => 'dids.index', 'permission' => P::DIDS_VIEW, 'group' => 'Telephony', 'available' => true],

    ['key' => 'call-history', 'title' => 'Call History', 'icon' => 'History', 'path' => 'call-history', 'route' => 'call-history.index', 'permission' => P::CALL_HISTORY_VIEW, 'group' => 'Activity', 'available' => true],
    ['key' => 'recordings', 'title' => 'Call Recordings', 'icon' => 'Disc3', 'path' => 'recordings', 'route' => 'recordings.index', 'permission' => P::RECORDINGS_VIEW, 'group' => 'Activity', 'available' => true],
    ['key' => 'voicemail', 'title' => 'Voicemail', 'icon' => 'Voicemail', 'path' => 'voicemail', 'route' => 'voicemail.index', 'permission' => P::VOICEMAIL_VIEW, 'group' => 'Activity', 'available' => true],
    ['key' => 'conferences', 'title' => 'Conferences', 'icon' => 'Users', 'path' => 'conferences', 'route' => 'conferences.index', 'permission' => P::CONFERENCES_VIEW, 'group' => 'Activity', 'available' => true],
    ['key' => 'contacts', 'title' => 'Contacts', 'icon' => 'Contact', 'path' => 'contacts', 'route' => 'contacts.index', 'permission' => P::CONTACTS_VIEW, 'group' => 'Activity', 'available' => true],
    ['key' => 'reports', 'title' => 'Analytics & Reports', 'icon' => 'ChartColumn', 'path' => 'reports', 'route' => 'reports.index', 'permission' => P::REPORTS_VIEW, 'group' => 'Activity', 'available' => true],

    ['key' => 'integrations', 'title' => 'Integrations', 'icon' => 'Plug', 'path' => 'integrations', 'route' => 'integrations.index', 'permission' => P::INTEGRATIONS_VIEW, 'group' => 'Administration', 'available' => true],
    ['key' => 'billing', 'title' => 'Billing', 'icon' => 'CreditCard', 'path' => 'billing', 'route' => 'billing.index', 'permission' => P::BILLING_VIEW, 'group' => 'Administration', 'available' => true],
    ['key' => 'users', 'title' => 'Users & Permissions', 'icon' => 'ShieldCheck', 'path' => 'users', 'route' => 'users.index', 'permission' => P::USERS_VIEW, 'group' => 'Administration', 'available' => true],
    ['key' => 'settings', 'title' => 'Settings', 'icon' => 'Settings', 'path' => 'organization-settings', 'route' => 'organization-settings.index', 'permission' => P::SETTINGS_VIEW, 'group' => 'Administration', 'available' => true],
];
