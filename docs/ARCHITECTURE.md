# Architecture

Multi-tenant Cloud PBX management platform. Laravel is the control plane (tenants, users,
configuration, billing, reporting, UI). Asterisk is the data plane (SIP, RTP, dialplan, IVR,
queues, recordings). The two talk only through the PBX integration layer.

```
 Browser (Vue 3 + Inertia, SIP.js web phone)
        │ HTTPS / WSS
        ▼
 Laravel app ──────────── PostgreSQL (tenants, config, CDRs)
   │  portals, API        Redis (cache, queues, broadcast)
   │  policies, jobs      Reverb (realtime to browsers)
   ▼
 PBX integration layer (app/Pbx)
   │  ARI (REST/WebSocket), AMI (TCP events), config provisioning
   ▼  private network only
 Asterisk servers (PJSIP, dialplan, queues, recordings)
        │ SIP/TLS, SRTP
        ▼
 SIP trunks / carriers / DIDs
```

In production, Laravel and Asterisk run on **separate hosts**. ARI and AMI are bound to a
private network and never exposed publicly.

## Module boundaries

| Path | Responsibility |
|---|---|
| `app/Tenancy` | Tenant context (`Tenant`), fail-closed `OrganizationScope`, `BelongsToOrganization` trait |
| `app/Authorization` | `Permission` constants and `Role` enum → permission mapping (single source of truth) |
| `app/Actions` | Use-case classes that span several models (e.g. `CreateOrganization`) |
| `app/Pbx` | `PbxDriver` contract, `AsteriskDriver`, dev-only `SimulatorDriver`, `PbxManager` |
| `app/Http/Controllers` | Organization portal |
| `app/Http/Controllers/Platform` | Super-admin portal (cross-tenant) |
| `app/Support/Audit` | Audit trail writer |
| `config/modules.php` | Sidebar modules, their permission, and whether they are built yet |
| `config/pbx.php` | PBX drivers, default package |

## Tenancy

- Every organization-owned table has `organization_id`. Models use `BelongsToOrganization`.
- `SetTenantContext` (route middleware `tenant`) binds the logged-in user's organization after
  authentication, and blocks suspended organizations and disabled users.
- **Fail-closed:** with no tenant context the scope matches *no rows*, and creating a tenant
  model throws. Platform code must opt out explicitly with `Tenant::bypass()`.
- Queued jobs must carry the organization id and wrap their work in `Tenant::runAs()`.
- `User` is deliberately **not** globally scoped (the auth guard must load it before a tenant
  exists). Tenant code reaches users through `Organization::users()`, and controllers return
  404 for users of other organizations.
- Broadcast channels (Phase 10) will be named `organization.{id}.*` and authorized against the
  user's organization.

## Authorization

- Org roles: owner, admin, call_center_manager, supervisor, agent, employee, billing_manager.
  Mapping lives in `App\Authorization\Role::permissions()`; `RolesAndPermissionsSeeder` syncs it
  (idempotent, run on every deploy).
- Platform super admins have `users.is_platform_admin = true` and no organization. `Gate::before`
  grants them every ability; the `platform` middleware guards `/platform`.
- Enforcement is server-side (middleware, policies, `Gate::authorize`). The sidebar is built on
  the server from the same permissions.

## PBX integration

`PbxManager::driver($server)` resolves a driver per `pbx_servers.driver`. The health check runs
over ARI (`GET /ari/asterisk/info`) every minute (`pbx:health`) and on demand. The simulator
driver throws if `APP_ENV=production`.

### Extension provisioning

```
ExtensionController ──dispatch──▶ ProvisionExtension job ──▶ ExtensionProvisioner ──▶ PbxDriver
   (create/update/retry)            (queue, backoff,           (records provisioned /     (ARI push
                                     no overlap per ext)        failed + error on row)     + verify)
```

- Each extension becomes PJSIP `auth`, `aor` (max 5 contacts) and `endpoint` objects named
  `o<org id>_<number>`, pushed with ARI dynamic configuration (`sorcery.conf` → AstDB). The
  endpoint's `set_var CLOUDPBX_ORG` keeps the shared dialplan inside the caller's organization.
- Call handling for calls *to* an extension (ring timeout, DND, voicemail, forwarding) is pushed as
  globals `CPBX_<sip id>_<KEY>`, read by `docs/asterisk/extensions.conf`.
- After pushing, the driver reads the endpoint back; a mismatch is a failure, not a success.
- Failures are stored on the extension (`provisioning_status`, `provisioning_error`), audited
  once, retried by the queue with backoff, and can be retried from the UI.
- Disabled extensions are removed from the PBX; deleted ones are removed by
  `DeprovisionExtension`, which carries only the server id and SIP username.
- `SyncPbxServer` re-pushes every extension on a server. It runs when `pbx:health` sees Asterisk
  come back or restart (globals live in memory), on `pbx:sync`, and `--prune` removes orphan
  endpoints. Reassigning an organization's PBX server moves its extensions.
- Registration state arrives as AMI `ContactStatus` events through `pbx:listen`.

### Routing (trunks, DIDs, IVR, groups, queues, conferences)

```
any routing change ──▶ PushRouting job ──▶ RoutingCompiler (pure) ──▶ RoutingPublisher ──▶ setGlobals
                         (one per org,        organization → map of       clears keys that      (ARI)
                          collapses edits)    CPBX_* dialplan globals     disappeared
```

- The dialplan is static and shared (`docs/asterisk/extensions.conf`); it interprets globals.
  Key layout is documented at the top of `App\Pbx\Routing\RoutingCompiler`.
- **Destinations** are stored as `<type>:<id>` (`App\Pbx\Routing\Destination`) and compiled to
  internal numbers, so renumbering never breaks routing and deleted targets become a hang-up.
- **One number space** per organization across extensions, ring groups, queues, conferences and
  IVR menus (`NumberPlan`).
- **Trunks** are PJSIP endpoint/aor/auth/identify(/registration) objects `o<org>_t<id>` in
  context `cloudpbx-inbound`, which only accepts DIDs of the trunk's own organization.
- **Queues** are read by Asterisk realtime from the `asterisk_queues` / `asterisk_queue_members`
  views, so membership and pausing apply instantly.
- **IVR greetings** are fetched by Asterisk from `/pbx-media/ivr/{id}/{token}.wav`; the token is
  an HMAC of the file, so URLs cannot be guessed and change with every upload. IVR menus are
  drafts until published (`ivr.publish`); editing a live menu also needs `ivr.publish`.
- **Recordings and voicemail** are written by the dialplan to the ARI stored-recordings directory
  and announced with AMI `UserEvent`s; the app stores metadata and streams audio through ARI.

### Calls, webhooks and API

- `CallEventProcessor` creates calls from extension channels (internal/outbound) and trunk
  channels (inbound, linked to the DID), and dispatches `call.started/answered/ended`,
  `recording.ready` and `voicemail.received` webhooks.
- Webhooks are signed (`X-CloudPBX-Signature: t=…,v1=HMAC-SHA256("t.body")`), retried with
  backoff, logged per delivery, and only sent to public addresses (checked again at send time).
- REST API v1 (`routes/api.php`) uses Sanctum tokens and runs inside the token owner's
  organization with their permissions.
- Agents and employees see only calls on their own extension (`Call::visibleTo`).
- Live calls and the dashboard refresh by polling (3 s); Reverb broadcasting can replace it later.
- `pbx:simulate` generates realistic traffic on simulator servers through the real processor.

## Data model (current)

```
packages 1─* package_features
packages 1─* subscriptions *─1 organizations
pbx_servers 1─* organizations 1─* users
organizations 1─* audit_logs *─1 users
users *─* roles *─* permissions   (spatie/laravel-permission)
organizations 1─* departments 1─* extensions *─1 users   (one extension per user)
organizations 1─* calls 1─* call_events;  calls *─1 extensions (from / to), did_numbers
organizations 1─* sip_trunks 1─* did_numbers, outbound_routes
organizations 1─* ivr_menus, ring_groups, conferences, call_queues *─* extensions
organizations 1─* recordings *─1 calls;  voicemail_messages *─1 extensions
organizations 1─* contacts, webhooks 1─* webhook_deliveries
```

Planned tables (spec §22) are added by the phase that owns them, so no empty tables ship.

## Delivery phases

| # | Phase | Status |
|---|---|---|
| 1–7 | Architecture, schema, structure, auth, tenant isolation, roles, dashboard layout | **Done** |
| 8 | PBX connection management: ARI health, AMI listener with reconnect + reconciliation, restart re-sync | **Done** |
| 9 | Extensions, departments, PJSIP provisioning, forwarding/DND, shared dialplan | **Done** (QR device provisioning and voicemail boxes pending) |
| 10 | Live calls (hang up, listen/whisper/barge), call history, web phone, `pbx:simulate` | **Done** (polling instead of Reverb) |
| 11 | SIP trunks, DIDs with open-hours routing, outbound routes, IVR builder | **Done** |
| 12 | Queues, ring groups, recordings, voicemail, conferences | **Done** |
| 13 | Reports, contacts, webhooks, public REST API | **Done** |
| 14 | Billing, invoices, organization settings | **Done** (online payment gateways pending) |
| 15–16 | Feature tests per module, deployment docs | **Done** (browser E2E suite and staging-PBX verification pending) |

## Known deviations from the spec

- Local dev uses PHP 8.2 (Laravel 12 supports it); production should use 8.3+.
- Local dev and tests use SQLite. PostgreSQL is the production target; use `docker-compose.yml`
  and install the `pdo_pgsql` PHP extension to run locally against it.
