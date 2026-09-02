# agend-wordpress-plugins

Collection of WordPress plugins that integrate into the Agend system. Each plugin lives in its own directory with its own README; this file is the high-level map.

## Agend Apps - Core (`agend-apps-core/`)

The foundational plugin every other Agend plugin builds on. Owns the gateway API client (`agend_apps_api()`) with encrypted API key storage (libsodium, keyed off the site's auth salts), member session and bearer-token resolution, and a shared identity-aware response cache with per-endpoint TTLs. Provides REST proxy endpoints for Events, Courses/Learning Hub, Directory (reviews, badges, custom fields, multi-location) and Memberships, plus cart and attendee proxying, entitlement types and grants gateway bindings, and server-to-server protected asset upload. Also carries the member credential login backbone: Agend-first WordPress login, in-WordPress password recovery and reset, admin-role sync on login, and guest-cart transfer. Authorisation is server-side and fail-closed; the old usermeta entitlement authority is retired, and membership snapshot usermeta is presentation-only.

## Agend Apps - Shop (`agend-apps-shop/`)

Agend product, cart and shop service built on Core. Provides Elementor cart widgets (Add to Cart, Cart Header mini-cart, full Cart View) and syncs the member's cart between the website and the Agend ecosystem. Includes a per-seat attendee editor in the cart view so a purchaser can assign named attendees to each ticket or seat, with live in-place updates as quantities change.

## Agend Elementor (`agend-elementor/`)

Elementor widget pack surfacing Agend data natively in WordPress pages: Events Catalogue (with server-rendered detail pages), Courses/Learning Hub Catalogue, Directory Catalogue (detail pages, reviews, ratings, badges, custom fields), Memberships Catalogue (signup with live tier summary), Account Link, Member Login (with password recovery) and Header Auth (login/My Portal with sign-out dropdown). Widgets are member-aware via the Core session contract, support visitor-facing category/type/city/date filters, inherit theming from the account's site configuration, and guard against stale Elementor element caches after deploys. Sites can nominate a dedicated Events page and Courses page so a catalogue used elsewhere (a homepage CTA) links there instead of taking over its own page, and can design cards and detail pages as Elementor saved templates built from the Agend Field, Image, Link and Content Block widgets. See `agend-elementor/README.md`.

## Agend Embed (`agend-embed/`)

Embeds Agend app surfaces (LMS, Loop) in an iframe with host-assisted single sign-on: when the embed reports it has no session, the plugin navigates the iframe through the site's own IdP-initiated SSO so a logged-in member is signed in without re-entering credentials. Ships an `[agend_embed]` shortcode and is IdP-agnostic via a driver filter (built-in: `agend-saml-idp`, miniOrange SAML IDP). The plugin README documents the postMessage kick-off contract and is the reference for other host implementations. Falls back to the SP-initiated flow when no host listener responds.

## Agend Directory Sync (`agend-directory-sync/`)

Syncs directory contacts from a pluggable data source into the Agend directory via `/v1/directory/listings/bulk-upsert`, in batches of 100. Built-in sources: Upbeat (via the iugo-membership-kiosk plugin) and a Custom HTTP API source that can consume any JSON API without code, with configurable dot-path response extraction, pagination, connection variables, request headers, and authentication (none, static token, or OAuth 2.0 client credentials). Secrets are stored encrypted with optional `wp-config.php` constant overrides. Field mapping supports nested dot-path fields, concatenation across fields and labelled multi-location address slots; visibility and status derive from eligibility and opt-in flags, with per-row drop-reason reporting. Runs manually from the admin UI (fetch, preview, send) or unattended via the `wp agend-directory-sync run` WP-CLI command for server cron.

## Agend Loop Sync (`agend-loop-sync/`)

Dedicated plugin to sync WordPress users, roles and Upbeat committee entitlements into Agend Loop roles and channel access. Users sync on login and role change with a configurable role-to-channel mapping; committees sync on a WP-Cron schedule or on demand from the admin settings screen.

## Agend Content Access (`agend-content-access/`)

Restricts WordPress content, whole pages/posts and (with Elementor) individual page-builder fragments, to Agend members and membership plans. WordPress declares the intended audience through an Agend Access editor panel and per-element display conditions; Agend authorises every protected response server-side, fail-closed, so the browser never receives material it cannot display. Includes an Agend-native display-condition engine with access-checked replacement content, a membership plan catalogue in the editor, protected file support via Core's asset upload, an authenticated source connector for the gateway, a legacy public-content audit, and an Elementor version compatibility contract (3.x, tested up to 4.2.0, with documented V4 atomic-widget limitations).

## Agend Entitlement Mirror (`agend-entitlement-mirror/`)

Mirrors Upbeat entitlement grants into Agend CRM entitlement grants so directory and content-gating segments react to entitlements granted or revoked in Upbeat. Converts each Upbeat category/type pair to a namespaced `gate_key` (guarded against reserved platform keys) and reconciles full state through the entitlement types and grants gateway endpoints, only ever touching grants owned by its own source key. Triggers on Upbeat webhooks, a throttled login safety net, SSO assertion time, and a manual admin action, with a WP-Cron retry and a `wp agend-apps entitlement-mirror sweep` WP-CLI command for nightly server cron. Extracted from Apps Core so Upbeat-coupled logic never ships to non-Pro installs.

## Testing

A repo-level PHPUnit 10.5 harness (`composer test`) runs fast, WordPress-free unit tests against stubs in `tests/`. Test suites exist for agend-apps-core, agend-content-access, agend-entitlement-mirror and agend-directory-sync; the remaining plugins have no suites yet, and there is no integration suite against a real WordPress install.
