# Agend Apps Core

The shared WordPress layer every other Agend plugin builds on: the gateway HTTP
client, member sessions and bearer resolution, response caching, and the
per-endpoint REST wrappers.

Other Agend plugins (Agend Elementor, Agend Content Access, Agend Apps Shop)
depend on this plugin being active. They must depend only on the interfaces
listed below.

## Consumer contract (SPEC-CMS-20260727 US-1.3)

These are the interfaces a dependent plugin may call. Reaching into anything
else in this plugin is a review failure: the rest is internal and changes
without notice.

The stability column means what it says. `Stable` interfaces will not change
shape without a coordinated update to every consumer in this repository.

| Interface | Kind | Stability | When there is no member session |
| :--- | :--- | :--- | :--- |
| `agend_apps_bearer_token` | Filter | Stable | Returns `''`. A consumer must treat an empty string as "anonymous", never as an error. |
| `Agend_Apps_Member_Session::has_session( int $user_id ): bool` | Static method | Stable | Returns `false`. |
| `agend_apps_crm_get_me()` | Function | Stable | Returns a `WP_Error`. Never fabricate an empty member from it. |
| `agend_apps_crm_get_tiers( array $query = array() )` | Function | Stable | Returns the tier catalogue. This endpoint is public and does NOT require a session. |
| `Agend_Apps_Cache::build_key( string $endpoint_key, array $params = array() ): string` | Static method | Stable | Not session-dependent. See the identity warning below. |
| `Agend_Apps_Settings::get_cache_ttl( string $endpoint_key ): int` | Static method | Stable | Not session-dependent. |

### `agend_apps_bearer_token`

Resolves the Supabase bearer for the CURRENT WordPress user. Two providers hook
it: the member session (priority 9) and the token worker (priority 10). The
first non-empty string wins.

Read it through `agend_apps_get_bearer_token()` rather than calling
`apply_filters()` yourself, so provider precedence stays in one place.

An empty return is the normal anonymous case, not a failure. A consumer that
treats it as an error will break every logged-out pageload.

### `Agend_Apps_Member_Session::has_session()`

Answers whether a stored session envelope exists for a user. It does NOT
validate the token, and it says nothing about entitlements.

Use it to decide whether a member-scoped call is worth attempting. Never use it
as an authorisation check.

### `agend_apps_crm_get_me()` and `agend_apps_crm_get_tiers()`

Both return the decoded gateway payload or a `WP_Error`. Always branch on
`is_wp_error()`.

`agend_apps_crm_get_me()` is member-scoped and needs a bearer.
`agend_apps_crm_get_tiers()` is the public tier catalogue and does not.

### Caching and identity

`Agend_Apps_Cache::build_key()` and `Agend_Apps_Settings::get_cache_ttl()` are
the sanctioned way to cache a gateway response.

**A cache key for an identity-enriched response must include the viewer.** The
cache is otherwise shared across every visitor, and a response computed for one
member will be served to the next. This is not hypothetical: it was a live
cross-member leak on `/events`, `/lms/courses` and `/cart`, fixed centrally in
`Agend_Apps_API::get_cached()`. If you are caching anything whose content
varies by who is asking, confirm the identity bypass covers your endpoint
before relying on it.

## What is NOT an authority

The membership snapshot usermeta written by `member-membership-sync.php`
(`_agend_apps_membership_*_display`) is a PRESENTATION signal only. It is a
cache, it fails open, and it keys on mutable tier slugs.

Never read it to decide access to protected content. Content gating goes
through Agend Content Access, whose typed policies resolve tier UUIDs
server-side and fail closed. See the file-level docblock in
`includes/member-membership-sync.php` for the full reasoning.

## Upbeat entitlement mirror (SPEC-AMS-20260804-upbeat-entitlement-mirror)

Mirrors Upbeat entitlements (typed grants such as
`{ entitlementCategory, entitlementType }`) into the Agend
`upbeat_entitlements` contact custom field, so the directory's entitlement
gating segments react to standing granted or revoked in Upbeat. Requires the
`iugo-membership-kiosk` plugin; degrades silently (no notices) when it is
absent. Off by default — a module that fires gateway writes on every Upbeat
webhook and every WordPress login must be opt-in per install.

### Settings

Settings > Agend Apps > Entitlement Mirror:

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

### Triggers

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

### Slug convention

A mirrored entitlement value is `slugify(category) + '/' + slugify(type)`
(kebab-case lowercase ASCII), e.g. `web-personalisation/premium-directory`.
This must match the gateway's slug validation exactly — it is a
cross-plugin, cross-repo contract also implemented by
`agend-directory-sync`'s listing transformer.

### Contact resolution order

externalId-first (`(external_source, external_id)`, matching
`ux_crm_contacts_external`), falling back to an exact-email match, creating
the contact (with the flag values already set) when neither resolves. Never
sends `user_id` — the existing `crm_contacts` auto-link trigger binds the
created contact to the member's user at their first sign-in.

### Full-state writes, never deltas

Every sync writes the member's COMPLETE current entitlement set. A lapsed
entitlement disappears on the next write; this plugin holds no date or
quantity logic of its own — that stays in Upbeat/the kiosk.

### Retry and sweep recovery

A gateway write failure schedules exactly ONE WP-Cron retry (5 minutes
later); a second failure is left to the next login reconciliation and the
nightly sweep. Failures are visible on the Entitlement Mirror settings tab
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

### Values-only rule

The mirror ensures the flag field and its per-type audience segments exist
and keeps contact flag VALUES current — nothing else. Field access
policies, group membership of fields, and segment usage in policies are
authored in Agend, not by this plugin.
