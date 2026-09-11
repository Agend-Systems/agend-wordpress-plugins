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

### Member sign-in mode

The site setting `Agend_Apps_Settings::get_member_auth_mode()` (`credentials`,
the default, or `sso`) decides whether the credential login surface exists at
all: the login bridge, the `user_register` provisioning hook, and the
`/agend-apps/v1/auth/*` REST routes are not loaded in `sso` mode, and the
Elementor member-login and header-auth widgets render nothing on the front end.
`agend_apps_bearer_token`, `Agend_Apps_Member_Session::has_session()`, and the
`agend_apps_auth_*()` gateway wrappers remain available in both modes, so a
consumer plugin never needs to branch on the mode itself.

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

The CSS/JS behind every Agend surface lives in `assets/` here, not in the
Elementor plugin: none of it is Elementor-specific, it only talks to the
rendered DOM and the REST fragments API. This plugin registers every
`agend-apps-records-*` handle on `init` at priority 5
(`includes/records/assets.php`), not on `wp_enqueue_scripts`, because a block's
`viewScript` and `style` name these handles and the block editor resolves them
outside the front-end enqueue hook. Sibling plugins enqueue these by handle
only; they do not register them again.

**Handle rename in 1.15.0.** The templated surfaces' asset (Agend Field,
Pills, Image, Link, Panel) moved out of the Elementor plugin and is now
`agend-apps-records-record-fields`, hosted here. It was
`agend-elementor-record-fields`, registered in `agend-elementor.php`. It had to
move: those surfaces render from core now, and a site running the block editor
alone has no Elementor plugin to register the handle, so a record block would
have rendered unstyled. The old handle no longer exists, so a site that
dequeued or depended on it by name needs updating.

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
'condition'?, 'tab'?, 'fields' => Field[] )`. `tab` accepts only `'style'`; a
section without it, or with any other value, is a content section, rendered on
Elementor's Content tab and in the block inspector's default slot. A `'style'`
section renders on Elementor's Style tab and under the block inspector's
`InspectorControls group="styles"` slot. Every field carries `name` (the
setting key, PERSISTED in saved pages — never change one), `label`, `type`,
`default`, plus optionally `description`, `condition`, `label_block`.

| Type | Extra keys | Default shape |
| :--- | :--- | :--- |
| `toggle` | — | boolean |
| `colour` | — | string, a CSS colour (Elementor's COLOR control, the block inspector's `ColorPalette`) |
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
describe: a repeater, a media picker, a URL field, or a control that needs a
builder-specific key such as `selectors`. (A colour is its own `colour` type,
not an `adapter` field, since US-1.1.) The schema records only the
field's `name` (so the section keeps its order); the widget itself supplies a
`public function register_adapter_control( string $name ): void` with a
`switch` on the name that runs its original `add_control()` /
`add_responsive_control()` call verbatim, including any surrounding
conditional logic (the Export Report widget's "no reports configured" notice
registers only when the account has none, which is a runtime check, not a
field-value `condition`). `Agend_Elementor_Schema_Controls::register()` calls
it when a field's type is `adapter`, guarded with `method_exists` so a widget
without one is skipped rather than fatal.

The templated surfaces (Agend Field, Agend Image, Agend Link, Agend Panel,
Agend Pills) have no record-type setting to declare. Each one's own field or
panel key names the type by itself, and a key that does not apply to the
surrounding template is caught where it is read, by
`agend_apps_records_field_applies()` and by the panel's own type check, both of
which can say WHICH field is wrong rather than only that something is.

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

The member login surface follows the same pattern:
`agend_apps_records_render_member_login( array $settings ): string` in
`includes/records/render/member-login.php`, pinned by
`tests/fixtures/member-login-render.json` and proven byte-identical by
`MemberLoginRenderTest`. It returns `''` when the Elementor SSO gate
(`Agend_Apps_Settings::credential_login_enabled()`) is off; the widget keeps
its own edit-mode notice for that case and otherwise echoes the core
renderer.

## Block editor surface

The block editor is the primary target for new surface work. Elementor is
supported at parity, not led.

`includes/records/blocks.php` registers one block per surface, listed in
`AGEND_APPS_RECORDS_BLOCK_SURFACES` (inserter order): `events-catalogue`,
`courses-catalogue`, `directory-catalogue`, `memberships-catalogue`,
`member-login`, `header-auth`, `account-link`, `export-reports` and `filter`,
all namespaced `agend-apps/`. Every block is a thin adapter over the two seams
above: its attributes are derived from the surface schema
(`agend_apps_records_block_attributes()`), and its render callback is the
surface's core renderer, fed the attributes converted to Elementor-shaped
settings (`agend_apps_records_settings_from_attributes()`). Every block
registers under the "Agend" inserter category (`block_categories_all`).

`member-login` is the one surface that does not allow several instances per
page: two sign-in forms is not a real layout, and its script binds one session
status per page. `header-auth` and `account-link` look similar but do allow
several, because their scripts bind per element rather than per page, so a
header and a mobile menu can each carry one.

Agend Apps Shop registers its own cart blocks the same way, from its own build
directory, by calling `agend_apps_records_register_surface_blocks()`. Core does
not know those surfaces exist: the schema and renderer lookups either side of
that call are global function-name lookups, so a sibling plugin owns its
surfaces end to end.

**A surface setting is declared in the schema or it does not exist.** A widget
or block may not register its own control for a setting the renderer reads;
the schema is the single declaration both builders read, and a setting living
in only one builder is the class of defect this seam exists to prevent.

The block inspector fetches the schema from
`GET /agend-apps/v1/surfaces/<surface>/schema` (editors only, option lists
resolved) and renders it with `src/blocks/shared/schema-inspector.js`, so a
setting added to a schema appears in the block editor and in Elementor from
the same line. `SchemaInspector` takes a `tab` prop (`'content'` or `'style'`)
so an edit component can render each half of the schema in its own
`InspectorControls` slot; a `colour` field renders as `ColorPalette` from
`@wordpress/components`, sourced from the active theme's
`useSettings( 'color.palette' )`. The schema response also carries a top-level
`notices` array (empty for most surfaces; `member-login` reports the SSO-mode
warning here when credential sign-in is off), which the edit component renders
above its placeholder.

Most blocks' `index.js` registers through the one shared edit component,
`createSurfaceEdit( { surface, icon, label, instructions } )` in
`src/blocks/shared/surface-edit.js`: content sections in the default
`InspectorControls` slot, style sections in `group="styles"`, any schema
notices above a `Placeholder` standing in for the front-end render.
`instructions` is an optional `( attributes ) => string`, defaulting to the
column/pagination summary the catalogue blocks share; `member-login` supplies
its own.

### Surfaces that render nothing without context

A templated surface (`filter`, and the record surfaces used inside a card or
detail template) renders nothing on a page where it has no catalogue or record
in scope. A bare placeholder would tell a template author nothing about what
they are styling, so those blocks show the core renderer's own stand-in markup
instead, fetched through `useSurfacePreview()` in
`src/blocks/shared/surface-preview.js` from
`POST /agend-apps/v1/surfaces/<surface>/preview` (editors only).

That is a dedicated route rather than `ServerSideRender` for a specific
reason: a block's registered render callback is also the LIVE front-end path,
and `agend_apps_records_render_block()` forwards only the attributes, with no
way to say "this call is a preview". A callback that renders nothing without
context therefore cannot tell a preview request from a real page. Widening the
shared render contract so every block carried a preview flag it never uses was
rejected; one editor-only route that passes the extra opt is the smaller
change.

The route also returns a `reason` code, resolved through
`agend_apps_records_surface_render_reason()`, which finds the surface's
`agend_apps_records_<surface>_render_reason()` companion beside its renderer.
Those companions return a stable code, never translated copy, so the
conditions behind an editor notice live with the render they belong to while
each editor owns its own wording. Per-instance reasons cannot go through
`agend_apps_records_block_surface_notices()`, which is keyed by surface id
alone and never sees a block's attributes; that function is for
account-wide states such as SSO mode.

Source lives in `src/blocks/<surface>/`; the compiled block directory in
`build/blocks/<surface>/` is COMMITTED because deployment copies files without a
build step. After changing anything under `src/`, run `npm run build` at the
repo root and commit the output; the `Block build matches source` CI job fails
when the committed output is stale. `npm start` watches during development.

`BlockSurfaceTest` proves each block left at its defaults renders exactly what
its Elementor widget renders at its control defaults.

## Block card and detail templates

A card, detail or filters template can be authored in the block editor as well
as in Elementor.

### Authoring one

1. Go to Appearance > Patterns (Site Editor > Patterns on a block theme) and
   create a pattern. It must be a SYNCED pattern: an unsynced one is copied
   into the page and leaves no post to address.
2. Build it from the Agend blocks in the "Agend" inserter category: Agend
   Field, Agend Image, Agend Pills, Agend Link / Button and Agend Panel for a
   card or detail template, or Agend Filter for a filters template. These are
   the same surfaces as the Elementor widgets of the same names, so
   `agend-elementor/README.md`'s table of what each one does applies
   unchanged.
3. Publish it. Saving is what tags it: the content is parsed for Agend record
   blocks, and the record type they imply is recorded in postmeta. A pattern
   with no Agend record block in it is not tagged and will not appear in a
   template picker.
4. Point a catalogue at it: the Card Template, Detail Template and Filters
   Template pickers on any Agend catalogue block or widget list every tagged
   pattern alongside any Elementor saved templates. When both builders are
   registered, each title is suffixed with its builder so two identically
   named templates can be told apart.

There is no record-type control to set, exactly as in Elementor: the field or
panel each block is set to names its own type.

### How it works

`includes/templates/` holds the block editor's own implementation of the two
template contracts, registered on every request because the block editor is
part of WordPress:

- `Agend_Apps_Block_Template_Renderer` renders a template once per record.
- `Agend_Apps_Block_Template_Source` lists the templates a picker can offer.

A block template is a `wp_block` post, the post type behind synced patterns. It
is addressable by a bare integer post id, which the registry requires, and it
gets a real editing UI and edit-once semantics for free. Registered block
patterns have no post id at all, and `wp_template_part` needs a block theme, so
neither can serve.

That post pool holds every reusable block on the site, most of them nothing to
do with Agend, so a qualifying template is TAGGED on `save_post_wp_block`: the
content is parsed for blocks named `agend-apps/record-*`, and the record type
they imply is stored in postmeta. The picker filters on that flag rather than
inspecting content on every read, exactly as the Elementor source filters on
`_elementor_template_type`.

Two things differ from the Elementor renderer and are worth knowing:

**There is no output cache to defeat.** Elementor's renderer has to disable
Elementor's own per-document element cache for the duration of every render,
or every card after the first returns the first card's markup. WordPress has
no equivalent: `WP_Block::render()` re-invokes a dynamic block's render
callback on every call, and `core/block`'s recursion guard stores reference
ids, not output. This was checked against core source, not inferred, because
the whole per-record design rests on it. The renderer still keeps its OWN
recursion guard and depth limit, because core's guard only covers `core/block`
references and does nothing about a nested catalogue calling back into the
registry.

**CSS reaches a card by two different routes.** `ensure_styles()` runs during
the host page request and enqueues the block types' registered style handles,
so the host page carries them as ordinary `<link>` tags. `$with_css` covers
only what that structurally cannot: block-supports CSS that does not travel
inline with the markup, chiefly layout rules, which WordPress normally drains
into the page footer. A REST fragment request never fires `wp_footer`, so it
never happens there, and the renderer captures
`wp_style_engine_get_stylesheet_from_context( 'block-supports' )` after the
render instead. Capturing does not drain the store, `with_css` is only ever set
from the fragments REST controller (so the store holds this template's rules
alone), and the generated class names are content-hash-derived from a structure
identical across records, which is why `cards.php` asks for it on the first
card only. That function needs WordPress 6.1, so it is guarded and degrades by
omitting the layout CSS.

Known limitation: `page_contains_surface()` uses `has_block()`, which
substring-searches serialised content, so a surface placed inside a synced
pattern that the host page only references by id is invisible to it. That
method is advisory, so a false negative suppresses a hint and nothing more.

This subsystem is covered by unit tests against stubs. There is no integration
suite in this repo, so per-record rendering, the layout-CSS capture and the
tagging have not been exercised against a running WordPress.

## Updates

None of the Agend plugins are on WordPress.org, so updates are served from
GitHub Releases via a static manifest published on GitHub Pages at
`https://agend-systems.github.io/agend-wordpress-plugins/manifest.json`. Every
Agend plugin's main file declares
`Update URI: https://agend-systems.github.io/agend-wordpress-plugins/{slug}`,
and Core registers the WordPress 5.8+ per-hostname
`update_plugins_agend-systems.github.io` filter (`Agend_Apps_Updater`,
`includes/class-agend-apps-updater.php`) that answers it for every installed
Agend plugin, not only itself. Because Core is a dependency of every sibling
plugin, this one class covers the whole family from a single manifest fetch.
It also hooks `plugins_api` so the Dashboard's "View details" modal shows the
manifest's description and changelog.

The manifest is cached in the `agend_apps_update_manifest` site transient for
12 hours; a failed fetch is cached as a sentinel for 1 hour so a dead endpoint
does not get hit on every admin page load. Two filters tune this without
touching code:

- `agend_apps_update_manifest_url` — override the manifest URL (e.g. to point
  a staging site at a different manifest).
- `agend_apps_update_cache_ttl` — override the fresh-manifest cache TTL, in
  seconds.

**Forcing a re-check.** Clicking "Check again" on Dashboard > Updates deletes
WordPress's own `update_plugins` site transient; `Agend_Apps_Updater` listens
for that (`delete_site_transient_update_plugins`) and flushes its own cache at
the same time, so a forced check always re-fetches the manifest. Code can do
the same via `Agend_Apps_Updater::flush_cache()`.

Every Agend plugin also gets a "Check for updates" link on the Plugins screen
(next to "Visit plugin site"), for users with the `update_plugins`
capability, modelled on the equivalent link plugin-update-checker adds.
Clicking it flushes both caches, forces `wp_update_plugins()`, and redirects
back to the Plugins screen with a dismissible "Checked for Agend plugin
updates." notice.

