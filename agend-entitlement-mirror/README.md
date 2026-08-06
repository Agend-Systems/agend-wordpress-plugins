# Agend Entitlement Mirror

Mirrors Upbeat entitlements (typed grants such as
`{ entitlementCategory, entitlementType }`) into Agend CRM entitlement
grants (`crm_benefits` + the member's grant rows), so the directory's
entitlement gating segments react to standing granted or revoked in Upbeat.

Extracted from `agend-apps-core` on 2026-08-04
(SPEC-AMS-20260804-upbeat-entitlement-mirror, operator decision recorded in
the spec's Decisions Log): `agend-apps-core` carries connection details and
generic gateway API bindings only; Upbeat/kiosk-coupled (Pro-client) logic
lives in this standalone plugin so non-Pro installs never carry it.

Repointed onto the platform's entitlement-types + entitlement-grants
endpoints by SPEC-CRM-20260805-member-entitlement-grants US-5.1: the mirror
no longer writes a contact custom field. Each Upbeat `(category, type)` pair
converts to a `gate_key` (e.g. `web_personalisation.premium_directory`),
declared via `agend_apps_crm_sync_entitlement_types()` and reconciled per
member via `agend_apps_crm_reconcile_entitlement_grants()` (both in
agend-apps-core's `includes/api/crm.php`).

Requires both `iugo-membership-kiosk` and `agend-apps-core` (active). It
degrades to an admin notice and loads nothing if either is missing — this
plugin never modifies the kiosk, and it uses `agend-apps-core`'s gateway
client and external-id meta key resolution rather than reimplementing them.

## Settings

Tools > Agend Entitlement Mirror:

- **Enable Entitlement Mirror** — off by default.
- **Mirrored Categories** — one Upbeat entitlement category per line.
  Defaults to `Web Personalisation` (PCA's reserved gating category).
  Only entitlements in these categories are mirrored.
- **Contact External Source** — the `external_source` value used to
  resolve and create contacts by `(external_source, external_id)`. Must
  match whatever your Agend account provisioning stamps on `crm_contacts`
  for this install, or externalId resolution always falls through to the
  exact-email fallback. Leave empty to resolve/create by email only.
- **Login Reconciliation Throttle (seconds)** — minimum interval between
  login-triggered reconciliation syncs for the same member. Default 900
  (15 minutes).

The stable `source_key` this install declares types and reconciles grants
under defaults to `upbeat` (never `manual`, which is reserved for
staff-made grants); an admin control for it is a follow-up item.

The enable option is read once, at plugin boot, to decide whether to
register the webhook/login listeners (a known caveat carried over unchanged
from the original module): flipping it on takes effect from the next request
onward, not retroactively within the same request.

## Triggers

- The kiosk's `agend_webhook_entitlement_created` / `_updated` /
  `agend_webhook_contact_updated` actions (the same webhooks the kiosk
  already receives from Upbeat — this plugin subscribes to them, it never
  modifies the kiosk).
- `wp_login` — a throttled safety-net reconciliation so a member's first
  hop into the directory after signing in to WordPress carries fresh
  entitlements even if a webhook was missed. Never blocks or delays
  authentication or an SSO redirect on failure.
- The manual **Sync Entitlement Types** admin action (also triggered
  automatically the first time the collector observes a `gate_key` not yet
  in the cached known-types option).
- `wp agend-apps entitlement-mirror sweep` (see below) — the recovery
  path for missed webhooks, intended for server cron.

## Gate key conversion

A mirrored entitlement's `gate_key` is
`slugify_segment(category) + '.' + slugify_segment(type)` (lowercase ASCII,
underscore-joined segments), e.g. `web_personalisation.premium_directory`.
This is the entitlement's identity on the platform side across every sync
(`Agend_Entitlement_Collector::gate_key()`); it never emits a key reserved
for a code-owned platform capability (`RESERVED_PLATFORM_GATE_KEYS`).

## Contact resolution and creation

The grants endpoint resolves and, on a miss, creates the contact server-side
by `(external_source, external_id)` — this plugin never resolves or creates
a contact itself. Never sends `user_id` — the existing `crm_contacts`
auto-link trigger binds the created contact to the member's user at their
first sign-in.

## Full-state writes, never deltas

Every sync reconciles the member's COMPLETE current grant set for this
install's `source_key`: entries present are granted or refreshed, entries
absent are revoked. Grants owned by staff or another source are never
touched. This plugin holds no date or quantity logic of its own — that
stays in Upbeat/the kiosk.

## Retry and sweep recovery

A gateway write failure schedules exactly ONE WP-Cron retry (5 minutes
later); a second failure is left to the next login reconciliation and the
nightly sweep. Failures are visible on the Entitlement Mirror admin page
(member id + HTTP status only — never the API key or payload PII).

Run the sweep from real server cron (deliberately NOT auto-scheduled via
WP-Cron, to avoid a double-run with a page-load-triggered WP-Cron tick):

```
# Nightly at 02:00, from the WordPress root:
0 2 * * * cd /path/to/wordpress && wp agend-apps entitlement-mirror sweep >> /var/log/agend-entitlement-sweep.log 2>&1
```

Useful flags:

- `--max=<number>` — cap the number of members processed this run.
- `--dry-run` — report the would-sync set (member, gate keys) without
  calling the grants endpoint.

The grants endpoint is itself idempotent, so a quiet member (nothing
changed since the last sweep) costs a network round trip, not a write. The
sweep exits non-zero if any member errored, so cron alerting works.
