# Agend Entitlement Mirror

Mirrors Upbeat entitlements (typed grants such as
`{ entitlementCategory, entitlementType }`) into the Agend
`upbeat_entitlements` contact custom field, so the directory's entitlement
gating segments react to standing granted or revoked in Upbeat.

Extracted from `agend-apps-core` on 2026-08-04
(SPEC-AMS-20260804-upbeat-entitlement-mirror, operator decision recorded in
the spec's Decisions Log): `agend-apps-core` carries connection details and
generic gateway API bindings only; Upbeat/kiosk-coupled (Pro-client) logic
lives in this standalone plugin so non-Pro installs never carry it. The
option names, class names, and gateway binding this plugin calls
(`agend_apps_crm_sync_entitlement_catalogue()` in agend-apps-core's
`includes/api/crm.php`) are unchanged from the original module — an existing
install upgrades in place with its configured values intact.

Requires both `iugo-membership-kiosk` and `agend-apps-core` (active). It
degrades to an admin notice and loads nothing if either is missing — this
plugin never modifies the kiosk, and it uses `agend-apps-core`'s gateway
client, CRM contact helpers, and external-id meta key resolution rather than
reimplementing them.

## Settings

Tools > Agend Entitlement Mirror:

- **Enable Entitlement Mirror** — off by default.
- **Mirrored Categories** — one Upbeat entitlement category per line.
  Defaults to `Web Personalisation` (PCA's reserved gating category).
  Only entitlements in these categories are mirrored.
- **Contact Field Key** — the `multi_select` contact custom field the
  mirror writes the entitlement slug list to. Defaults to
  `upbeat_entitlements`; must match the gateway catalogue endpoint's
  `field_key`.
- **Contact External Source** — the `external_source` value used to
  resolve and create contacts by `(external_source, external_id)`. Must
  match whatever your Agend account provisioning stamps on `crm_contacts`
  for this install, or externalId resolution always falls through to the
  exact-email fallback. Leave empty to resolve/create by email only.
- **Login Reconciliation Throttle (seconds)** — minimum interval between
  login-triggered reconciliation syncs for the same member. Default 900
  (15 minutes).
- **Suppress Agend Webhooks on Mirror Writes** — off by default. Other
  Agend subscribers may legitimately want the resulting `contact_updated`
  events; enable only if this install's own automation would otherwise
  loop on its own mirror writes.

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
- The manual **Sync Entitlement Catalogue** admin action (also triggered
  automatically the first time the collector observes a slug not yet in
  the cached known-catalogue option).
- `wp agend-apps entitlement-mirror sweep` (see below) — the recovery
  path for missed webhooks, intended for server cron.

## Slug convention

A mirrored entitlement value is `slugify(category) + '/' + slugify(type)`
(kebab-case lowercase ASCII), e.g. `web-personalisation/premium-directory`.
This must match the gateway's slug validation exactly — it is a
cross-plugin, cross-repo contract also implemented by
`agend-directory-sync`'s listing transformer.

## Contact resolution order

externalId-first (`(external_source, external_id)`, matching
`ux_crm_contacts_external`), falling back to an exact-email match, creating
the contact (with the flag values already set) when neither resolves. Never
sends `user_id` — the existing `crm_contacts` auto-link trigger binds the
created contact to the member's user at their first sign-in.

## Full-state writes, never deltas

Every sync writes the member's COMPLETE current entitlement set. A lapsed
entitlement disappears on the next write; this plugin holds no date or
quantity logic of its own — that stays in Upbeat/the kiosk.

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
- `--dry-run` — report the would-change set (member, before, after)
  without writing anything.

The sweep reads each member's current flag value via the gateway before
writing, so a quiet directory (nothing changed since the last sweep) costs
reads, not writes. It exits non-zero if any member errored, so cron
alerting works.

## Values-only rule

The mirror ensures the flag field and its per-type audience segments exist
and keeps contact flag VALUES current — nothing else. Field access
policies, group membership of fields, and segment usage in policies are
authored in Agend, not by this plugin.
