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

## Upbeat entitlement mirror

The Upbeat entitlement mirror (SPEC-AMS-20260804-upbeat-entitlement-mirror)
moved out to its own plugin, `agend-entitlement-mirror`, on 2026-08-04
(operator decision: this plugin carries connection details and generic
gateway API bindings only; Upbeat/kiosk-coupled logic lives in its own
plugin so non-Pro installs never carry it). See that plugin's README for
settings, triggers, and the WP-CLI sweep command.

This plugin still owns the thin gateway binding the mirror calls,
`agend_apps_crm_sync_entitlement_catalogue()` in `includes/api/crm.php` —
consistent with every other endpoint wrapper in that file, it carries no
Upbeat-specific knowledge.

## Shared front-end assets

The CSS/JS behind the Agend Elementor catalogue widgets lives in
`assets/` here, not in the Elementor plugin: none of it is Elementor-specific,
it only talks to the rendered DOM and the REST fragments API. This plugin
registers every `agend-elementor-*` handle on `wp_enqueue_scripts` at priority
5 (`includes/records/assets.php`), ahead of any consumer's own
`wp_enqueue_scripts` hook. Sibling plugins enqueue these by handle only; they
do not register them again.
