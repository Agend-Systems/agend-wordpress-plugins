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
  modifies the kiosk). The listeners run at priority 20, after the kiosk's
  own handlers have erased the member's cached entitlements; at the
  default priority this plugin would run first and mirror the stale cache.
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
- `--force` — bypass the unchanged-since-last-sync fingerprint skip (see
  below) and reconcile every member regardless.
- `--delay=<ms>` — fixed pause, in milliseconds, between members. Default
  0 (no pause).

The grants endpoint is itself idempotent, so a quiet member (nothing
changed since the last sweep) costs a network round trip, not a write. The
sweep exits non-zero if any member errored, so cron alerting works.

### Unchanged-since-last-sync fingerprint skip

Every `reconcile_member()` call (webhook/login sync and the CLI sweep alike)
first computes a fingerprint of everything that would actually be sent to
the gateway: `[external_source, source_key, the grant entries]`, hashed with
`md5(wp_json_encode(...))`. If that fingerprint matches the one stored after
the member's last successful reconcile, the call is skipped entirely —
before even the contact lookup — and logged as "Skipping reconcile:
unchanged since last successful sync." A fingerprint is stored after any
successful outcome (a real gateway call or the deliberate "holds nothing,
no contact" skip), but never after a `WP_Error`, so a failed attempt is
always retried on the next pass. The fingerprint transient
(`agend_ent_mirror_fp_<md5(member_id)>`) defaults to a 7-day TTL, filterable
via `agend_entitlement_mirror_fingerprint_ttl`. Pass `$force = true` to
`reconcile_member()` (or `--force` on the CLI sweep) to bypass the check.

### Rate-limit pacing

The Agend gateway limits each API key to 60 requests/minute. The CLI sweep
paces itself against that limit using the two rate-limit transients
agend-apps-core's API client already stores from every response's
`X-RateLimit-*` headers (`agend_apps_rate_limit_remaining`,
`agend_apps_rate_limit_reset`):
`Agend_Entitlement_Sync::wait_for_rate_limit_window()` sleeps until just
past the cached reset time (capped at 120 seconds) whenever the cached
remaining-requests count is at or below a floor (default 1, filterable via
`agend_entitlement_mirror_rate_limit_floor`), and is called before every
non-dry-run member. If a reconcile call still comes back rate limited (a
429 from the gateway, or agend-apps-core's own local `agend_apps_rate_limited`
short-circuit), the sweep waits out the rate-limit window again (or a fixed
60-second fallback if no window information is available) and retries that
member exactly once before counting it as an error. Every sleep this module
performs (pacing, the `--delay` flag) goes through the injectable
`agend_entitlement_mirror_sleeper` filter, so it can be replaced in tests or
by an install with its own throttling strategy.

### Types-sync failure cooldown

A failed entitlement-types push (`sync_types()` returning a `WP_Error`) sets
a 300-second cooldown transient (`agend_ent_mirror_types_cooldown`,
filterable via `agend_entitlement_mirror_types_cooldown`). While that
cooldown holds, `maybe_sync_types_for_new_keys()` skips discovery and the
types push entirely for every member's `reconcile_member()` call — the
grants reconcile itself still proceeds — so one failed types push does not
repeat (and re-fail) for every remaining member in a sweep.
