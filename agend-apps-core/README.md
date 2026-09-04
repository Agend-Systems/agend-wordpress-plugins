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
registers every `agend-apps-records-*` handle on `wp_enqueue_scripts` at priority
5 (`includes/records/assets.php`), ahead of any consumer's own
`wp_enqueue_scripts` hook. Sibling plugins enqueue these by handle only; they
do not register them again.

## Deprecated names (removed in the release after 1.8.0)

The page-builder-agnostic record layer under `includes/records/` shipped
under the Elementor plugin's `agend_elementor_*` / `Agend_Elementor_*` prefix
before it moved here. `includes/records/deprecated.php` keeps every name
below resolving, with a deprecation notice, for one release.

| Old name | New name |
| :--- | :--- |
| `agend_elementor_courses_list_args()` | `agend_apps_records_courses_list_args()` |
| `agend_elementor_detail_template_labels()` | `agend_apps_records_detail_template_labels()` |
| `agend_elementor_events_list_args()` | `agend_apps_records_events_list_args()` |
| `agend_elementor_fetch_list()` | `agend_apps_records_fetch_list()` |
| `agend_elementor_field_applies()` | `agend_apps_records_field_applies()` |
| `agend_elementor_field_kind()` | `agend_apps_records_field_kind()` |
| `agend_elementor_field_options()` | `agend_apps_records_field_options()` |
| `agend_elementor_field_terms()` | `agend_apps_records_field_terms()` |
| `agend_elementor_field_value()` | `agend_apps_records_field_value()` |
| `agend_elementor_filter_config()` | `agend_apps_records_filter_config()` |
| `agend_elementor_filter_options()` | `agend_apps_records_filter_options()` |
| `agend_elementor_filter_registry()` | `agend_apps_records_filter_registry()` |
| `agend_elementor_format_field()` | `agend_apps_records_format_field()` |
| `agend_elementor_listings_list_args()` | `agend_apps_records_listings_list_args()` |
| `agend_elementor_pill_field_options()` | `agend_apps_records_pill_field_options()` |
| `agend_elementor_preview_record()` | `agend_apps_records_preview_record()` |
| `agend_elementor_render_cards()` | `agend_apps_records_render_cards()` |
| `agend_elementor_render_field()` | `agend_apps_records_render_field()` |
| `agend_elementor_shop_cart_enabled()` | `agend_apps_records_shop_cart_enabled()` |
| `agend_elementor_shop_cart_page_url()` | `agend_apps_records_shop_cart_page_url()` |
| `agend_elementor_show_achievements_enabled()` | `agend_apps_records_show_achievements_enabled()` |
| `agend_elementor_ssr_colour_style()` | `agend_apps_records_ssr_colour_style()` |
| `agend_elementor_ssr_detail_enabled()` | `agend_apps_records_ssr_detail_enabled()` |
| `agend_elementor_unwrap_list()` | `agend_apps_records_unwrap_list()` |
| `agend_elementor_asset_url()` | `agend_apps_records_asset_url()` |
| `agend_elementor_asset_version()` | `agend_apps_records_asset_version()` |
| `agend_elementor_register_dompurify()` | `agend_apps_records_register_dompurify()` |
| `Agend_Elementor_Pages` | `Agend_Apps_Records_Pages` |
| `Agend_Elementor_Fragments_Controller` | `Agend_Apps_Records_Fragments_Controller` |
| `Agend_Elementor_Record_Context` | `Agend_Apps_Records_Record_Context` |
| `Agend_Elementor_Filter_Context` | `Agend_Apps_Records_Filter_Context` |
| `agend_elementor_cart_mode` (filter) | `agend_apps_records_cart_mode` |
| `agend_elementor_dedicated_page_id` (filter) | `agend_apps_records_dedicated_page_id` |
| `agend_elementor_detail_url` (filter) | `agend_apps_records_detail_url` |
| `agend_elementor_filter_registry` (filter) | `agend_apps_records_filter_registry` |
| `agend_elementor_show_achievements_enabled` (filter) | `agend_apps_records_show_achievements_enabled` |
| `agend_elementor_ssr_detail_enabled` (filter) | `agend_apps_records_ssr_detail_enabled` |
| `agend-elementor-*` enqueue handles (see above) | `agend-apps-records-*` |
| `agend-elementor/v1` REST namespace | `agend-apps/v1` |

The ten `AGEND_ELEMENTOR_*` option/kind/limit constants keep resolving too
(defined to their `AGEND_APPS_RECORDS_*` value), and the class aliases above
resolve via `class_alias()`. The stored option names themselves
(`agend_elementor_events_page_id` and friends) are unchanged and are not
deprecated: they are live site data, not a code-facing name.

## Content-settings schema (page-builder-agnostic)

A record surface (events catalogue, courses catalogue, and so on) declares its
Content-tab settings ONCE, as plain PHP data, in
`includes/records/schema/<surface>.php`. Every presentation adapter renders its
own controls from that same declaration instead of hand-declaring the same
settings a second time. Elementor's adapter is
`Agend_Elementor_Schema_Controls` in the Agend Elementor plugin; the future
block editor surface gets its own.

All 14 Elementor widgets now read their Content-tab settings from a core
schema: `events-catalogue`, `courses-catalogue`, `directory-catalogue`,
`memberships-catalogue`, `export-reports`, `filter`, `header-auth`,
`member-login`, `account-link`, `record-block`, `record-field`,
`record-image`, `record-link`, `record-pills`. Each schema is guarded by a
fidelity test (`agend-elementor/tests/*ControlsTest.php`) against a fixture
recorded from the pre-conversion widget, so a schema or adapter change that
drifts from the original controls fails the test rather than silently
changing what a saved page renders.

Fetch a surface's schema with `agend_apps_records_surface_schema( string
$surface ): array` (e.g. `'events-catalogue'`). It runs the result through the
`agend_apps_records_surface_schema` filter, so a site can add or amend a field
once and have it appear in every adapter's editor.

### Vocabulary

Schema = `array( 'sections' => Section[] )`. Section = `array( 'id', 'label',
'condition'?, 'fields' => Field[] )`. Every field carries `name` (the setting
key, PERSISTED in saved pages — never change one), `label`, `type`, `default`,
plus optionally `description`, `condition`, `label_block`.

| Type | Extra keys | Default shape |
| :--- | :--- | :--- |
| `toggle` | — | boolean |
| `text` / `textarea` | — | string |
| `number` | `min`, `max`, `step` | numeric |
| `select` | `options` (array, or a callable string resolved at render time) or `groups` | string |
| `multiselect` | `options` (same shape as `select`) | array, optional — omit for no default |
| `template` | `placeholder` (the "no template" entry's label) | string, `''` |
| `note` | `content` | none (no persisted value) |
| `heading` | `separator` (`'before'`/`'after'`/`'none'`), optional | none (no persisted value) |
| `adapter` | none read by the vocabulary; `label`/`description` are documentation only | n/a — the widget declares its own control |

`condition` is Elementor's own shape (`array( 'other_field' => $value )`, or
`array( 'other_field!' => $value )` for not-equal) kept as-is, since it is
simple and every adapter can evaluate it directly.

**`name` is a contract, not a label.** It is the key a saved page stores the
setting under. Renaming one orphans every page that already set it; add a new
field and migrate instead.

**`adapter`** is the escape hatch for a control the shared vocabulary cannot
describe: a repeater, a media picker, a URL field, a colour, or a control that
needs a builder-specific key such as `selectors`. The schema records only the
field's `name` (so the section keeps its order); the widget itself supplies a
`public function register_adapter_control( string $name ): void` with a
`switch` on the name that runs its original `add_control()` /
`add_responsive_control()` call verbatim, including any surrounding
conditional logic (the Export Report widget's "no reports configured" notice
registers only when the account has none, which is a runtime check, not a
field-value `condition`). `Agend_Elementor_Schema_Controls::register()` calls
it when a field's type is `adapter`, guarded with `method_exists` so a widget
without one is skipped rather than fatal.

The shared `record_type` select every field widget (Agend Field, Agend Image,
Agend Link, Agend Content Block, Agend Pills) exposes is a single fragment,
`agend_apps_records_schema_record_type_field(): array`, included as the first
field of each widget's first section rather than five separate declarations.

When a Content-tab control genuinely has no schema equivalent, it becomes an
`adapter` field rather than forcing a bad fit into the vocabulary; the
vocabulary itself is extended (as `heading` was) only when the control is in
fact generic across page builders and not an Elementor-specific concept.

## Catalogue renderers (page-builder-agnostic)

The events, courses, directory and memberships catalogues are rendered by core,
not by the editor that placed them: `agend_apps_records_render_<surface>_catalogue( array $settings ): string`
in `includes/records/render/` takes the surface's settings (the keys declared
by its schema, with Elementor-shaped values: toggles are `'yes'` or `''`) and
returns the markup plus the `data-agend-*-config` JSON the front-end script
reads. An adapter echoes the result; the Elementor widgets do exactly that.

Fidelity is pinned by `tests/fixtures/<surface>-catalogue-render.json`, HTML
recorded from the widgets before the render moved. `CatalogueRenderTest`
requires both the core function and the delegating widget to reproduce those
fixtures byte for byte. Change a renderer deliberately, re-record the fixture in
the same commit, and say so.

