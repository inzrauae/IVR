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

`PbxManager::driver($server)` resolves a driver per `pbx_servers.driver`. Phase 1 implements the
health check over ARI (`GET /ari/asterisk/info`), run every minute by `pbx:health` and on demand.
Later phases extend `PbxDriver` with endpoint provisioning, channel control and event streaming.
The simulator driver throws if `APP_ENV=production`.

## Data model (current)

```
packages 1─* package_features
packages 1─* subscriptions *─1 organizations
pbx_servers 1─* organizations 1─* users
organizations 1─* audit_logs *─1 users
users *─* roles *─* permissions   (spatie/laravel-permission)
```

Planned tables (spec §22) are added by the phase that owns them, so no empty tables ship.

## Delivery phases

| # | Phase | Status |
|---|---|---|
| 1–7 | Architecture, schema, structure, auth, tenant isolation, roles, dashboard layout | **Done** |
| 8 | PBX connection management (ARI health ✓; AMI listener, reconnection) | Partial |
| 9 | Extensions, departments, PJSIP provisioning | Planned |
| 10 | Live call events, call history, web phone, realtime dashboard | Planned |
| 11 | SIP trunks, DIDs, routing, IVR builder | Planned |
| 12 | Queues, ring groups, recordings, voicemail, conferences | Planned |
| 13 | Reports, contacts, integrations hub, public API, webhooks | Planned |
| 14 | Billing, invoices, organization settings | **Done** (online payment gateways pending) |
| 15–16 | E2E tests, deployment docs | Planned |

## Known deviations from the spec

- Local dev uses PHP 8.2 (Laravel 12 supports it); production should use 8.3+.
- Local dev and tests use SQLite. PostgreSQL is the production target; use `docker-compose.yml`
  and install the `pdo_pgsql` PHP extension to run locally against it.
