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

Which SAML IdP plugin, if any, takes part in the Agend connection is decided by
`Agend_Apps_Settings::detected_idp_plugin()`. It auto-detects by default, but
the `agend_apps_idp_plugin` setting (Identity and SSO settings page) lets an
admin pin it to `saml`, `miniorange`, or `none` -- for a site running a SAML
IdP plugin for an unrelated purpose that must stay out of this connection.

### Linking members in `wordpress` sign-in mode

In `wordpress` mode a signed-in member is linked to their Agend identity by an
IdP-initiated SAML round trip through the site's own identity provider plugin
(`includes/wp-idp-saml-link.php`). Three triggers run that same round trip,
all gated by one eligibility check (`agend_apps_saml_link_eligibility()`), the
member's recorded link state and its throttle, and all inert unless the
**Background link** toggle on Settings > Identity and SSO is on (it is off by
default):

- **Background.** A hidden iframe, started from `wp_footer` after the page has
  loaded. Invisible, but late: a page that reads a member's restricted data on
  the server (the directory's listing detail and first page of cards) has
  already been built without their bearer, and shows the logged-out
  placeholders until the next load.
- **Link members as they sign in** (default on). `login_redirect`,
  `woocommerce_login_redirect` and `woocommerce_registration_redirect` are
  rewritten to a same-origin start step
  (`/?agend_apps_saml_link_start=1&redirect_to=...`). The start step runs on the
  next, authenticated request and only then issues the IdP URL, because the
  IdP nonce is bound to the session and the new auth cookie is not in
  `$_COOKIE` while the sign-in request is still running. The member comes back
  linked, to the page they were heading for. wp-admin, the cart, the checkout
  and WooCommerce account form pages are never detoured. Other sign-in code
  that ends in a redirect can call `agend_apps_saml_link_signin_redirect()` with
  its destination and the member's id.
- **Link members before directory pages load** (default on). A signed-in,
  unlinked member's request for the dedicated directory page or a listing
  detail is redirected through the round trip on `wp` priority 3, before the
  priority 4 sign-in gate and the priority 5 server-rendered detail swap fetch
  anything, and returned to the same URL. Filter
  `agend_apps_saml_link_prerender_required` (`$required`, `$context`) to add
  other member content pages.

A member who has just come back from a top-level round trip, linked or not, is
shown the page rather than sent round again: the loop guard is the link state's
`toplevel_at` marker, its `completed` flag and the existing throttle. The
diagnostics panel on the same settings page shows when a sign-in or pre-render
round trip was issued, and why one was skipped.

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

### Directory: require sign-in to view details

The directory catalogue's `require_login_for_detail` setting (Detail View, off by
default) puts listing details behind a sign-in for a visitor who is not signed in
(`is_user_logged_in()`). It works on two levels.

**Links.** For that visitor every link to a listing points at
`wp_login_url( $detail_url )` instead: the card anchor and every field built from
`common:detail_url` (Agend Link, Agend Field and Agend Image `link to detail`) in
`agend_apps_records_render_cards()`, the cards the script builds itself, and a
click on a card in a catalogue that opens details in place. A direct load of a
detail path on a client-rendered catalogue sends the visitor to the same sign-in
page before anything is drawn. A signed-in visitor, or the setting off, gets
exactly the previous markup and config (`requireLogin` is only written when it
applies).

**Direct detail URLs.** A request for `/{page}/listing/{slug}/` (query var
`agend_dir_listing`, or the legacy `agend_listing`) is answered with a 302 to
`wp_login_url( $detail_url )` before anything renders and before any gateway
call: `agend_apps_records_maybe_redirect_directory_detail_to_login()`, on `wp` at
priority 4, ahead of the server-rendered detail swap at priority 5. It only acts
on a front-end GET for the main query. The setting lives on a widget, and a
server-rendered detail page never renders the widget, so the request cannot read
it there. Instead the setting is **mirrored on save** to a post meta flag on the
page, `_agend_apps_directory_require_login`, and the request reads that flag with
`agend_apps_records_directory_detail_requires_login( $page_id )`. The page is the
dedicated directory page when one is set, the queried page otherwise.

- The flag holds the list of builder sources that have the setting on, so one
  builder saving a page never clears what another recorded. Core records the
  `blocks` source on `save_post_page` (`parse_blocks`); Agend Elementor records
  `elementor` on `elementor/document/after_save`, through
  `agend_apps_records_directory_set_login_source()`. Core never reads
  `_elementor_data`.
- A page with several catalogues is flagged when **any one** of them has the
  setting on. Turning the last one off, or removing it, deletes the flag.
- A page saved before this existed has no flag. On the first request after an
  update the dedicated directory page is re-derived once
  (`agend_apps_records_directory_maybe_backfill_login_flag()`, per
  `AGEND_APPS_RECORDS_LOGIN_BACKFILL_VERSION`), and again whenever the dedicated
  directory page is changed. A builder takes part by listening to the
  `agend_apps_records_directory_backfill_login_flag` action.
- **Known blind spot:** only a catalogue placed in the page's own content is
  detected. One that lives only inside a saved template, an Elementor
  theme-builder template, a synced pattern or a block-theme template is not, and
  the setting then protects the links but not the direct detail URL. For a
  catalogue on a page that is not the dedicated directory page, save that page
  once after upgrading.

This setting gates navigation, not data: the directory proxy routes stay public,
like the catalogue itself. The decision lives in
`Agend_Apps_Records_Pages::login_target()`, so rendered links and the redirect
cannot disagree.

### Directory: item list custom fields

A listing's `item_list` custom field, for example a shopping centre's tenants, is
a list of objects. `GET /v1/directory/listings/{slugOrId}` returns it as
`{ key, label, type: 'item_list', value, item_fields }`: `value` is the items in
stored order, each `{ <item_key>: string | number }`, and `item_fields` is the
list's declaration, `{ key, label, type: 'text' | 'number' }` in declared order.
The gateway omits an empty list, and sends a restricted one as the usual gated
entry with no `value`. A gateway that predates `item_fields` sends none, and the
renderers then use the item keys in first-seen order as both the column order
and the headings.

Every surface that shows a custom field draws an item list through one PHP
renderer, `agend_apps_records_render_item_list()` in `includes/records/format.php`
(the catalogue script, `assets/js/directory-catalogue.js`, draws the same markup
client-side), so a list used to render as "Array", "[object Object]" or nothing
and now renders as one of two layouts:

- **Table** (the default). A header row of the item field labels and one row per
  item in stored order, inside `.agend-dir-cf__item-list-wrap`, which scrolls
  sideways on a narrow screen so the page itself does not. The wrapper takes
  keyboard focus (`tabindex="0"`) and is a named region (`role="region"`,
  `aria-label` is the field's label) so it can be scrolled without a mouse.
- **List**. One entry per item: the shown fields' values in declared order,
  joined by ", ", empty values skipped.

`number` cells take thousands separators (`number_format_i18n()` on the server,
the page's own `lang` in the script) and keep the value's own decimals. Every
cell is escaped. An item with nothing to show in the chosen fields is left out,
and a list with no item left renders nothing, not even its label. A number too
large to be one (`1e999`) stays as written.

`tests/fixtures/item-list-render.json` pins the markup. `ItemListRenderTest` holds
the PHP renderer to every case, and `tests/js/item-list-render.test.mjs` (run with
`node --test`, no dependencies, wired into the `blocks` CI job) loads the script's
renderer into a fake `document` and holds it to the same cases. Change the markup in
both renderers and re-record the fixture together.

Two controls choose how, both with new names that nothing else uses:

- `item_list_layout` (Table or List) and `item_list_fields` (item field keys,
  comma separated; blank shows all). **Item fields** limits the columns, or the
  list's values, to the named keys *in declared order*, ignores a key the list
  does not have, and shows every field when none of them match.
- On the **Directory catalogue** (Detail View) they apply to every item list on
  the detail. On the **Agend Field** surface (shown for a custom field) they
  apply to that one field.

Where each reaches the page:

- **Client-rendered detail.** The catalogue config carries
  `detail.itemList = { layout, fields }`, written only when it is not the default,
  so an existing catalogue's config is unchanged.
- **Server-rendered detail.** The detail never renders the widget, so
  `agend_apps_records_ssr_item_list_options()` reads the choice back from the
  catalogue on the host page, and only for a listing that has an item list. Core
  reads the first block-editor catalogue in the page's content; any other page
  builder answers the `agend_apps_records_directory_detail_settings` filter
  (Agend Elementor reads its own element data there; core never parses another
  builder's data). Nothing is mirrored or backfilled, unlike the sign-in flag.
  Only a catalogue in the page's own content is seen, so one inside a saved
  template, a synced pattern or a theme-builder template gives the defaults.
  A page with several catalogues uses the first.
- **Agend Field.** `common:custom_field` is `text` in the field registry, but a
  record whose entry for the key is an `item_list` makes it one:
  `agend_apps_records_field_kind_for_record()` settles the kind from the record
  (the `item_list` kind), and the field getter returns the whole entry rather than
  flattened text. It always renders in a `div`, whatever HTML tag is chosen, and
  without the before and after text. An empty or missing list shows the
  widget's fallback text; a restricted one shows its restricted text and no values.
- **Retired panel and templated details.** The retired `listing_custom_fields`
  panel, and any templated detail that still renders the whole Details section,
  always use the default table of every item field, since the Detail View controls
  belong to the catalogue widget. For a layout or fields chosen per widget, use an
  Agend Field pointed at the custom field.

The table and list styles are `assets/css/item-list.css`, registered as
`agend-apps-records-item-list` and a dependency of both the directory catalogue
and the Agend Field stylesheets, so each loads it with no further wiring. The
editor's preview listing carries a `tenants` item list, which also puts it in the
custom field picker.

### Filter: category levels (directory)

The directory **Category** filter has a **Category level** setting
(`category_level`, shown only when the filter is Directory > Category) so two
filters on one page offer a category and then its subcategories:

- **All categories** (the default) is the filter as it was: every category the
  API returns, in the order it returns them, with checkboxes, a dropdown or
  buttons.
- **Top level** offers only categories with no parent.
- **Subcategories of selected** offers the children of the category chosen in
  the Top level filter, in API order, and is hidden until a top-level category
  with children is chosen. Its "Any" option uses the filter's own **"Any" option
  label** setting.

Top level and Subcategories of selected show as a dropdown or buttons only; a
saved Checkboxes presentation falls back to the dropdown, because a level shows
one category at a time. With **Choices I define** the level is ignored, since
author defined choices carry no category tree.

- **One selection.** Both levels write the catalogue's existing
  `state.categories`, so the query builders, the Agend Map and **Clear filters**
  need no change. A selected top-level category is shown by the Top level filter
  and its children are offered by the other; a selected subcategory is shown by
  the Subcategories filter, with its parent shown by the Top level filter.
  Choosing the Subcategories filter's "Any" sets the selection back to the
  parent, and choosing a different top-level category replaces the selection, so
  a stale subcategory never lingers. When another Category filter on the page
  leaves several categories selected, both levels show "Any" (the Subcategories
  filter only while every selected category sits under one top-level category;
  otherwise it is hidden), and a choice in either replaces the selection with
  that one category. A top-level category is sent alone; the API widens it to
  listings linked to any of its subcategories.
- **The derivation** is a pure module, `assets/js/category-levels.js`
  (`window.agendFilters.categoryLevels`), registered as
  `agend-apps-records-category-levels` and a dependency of
  `agend-apps-records-filters`, so it always loads first. `assets/js/filters.js`
  draws the two levels from it and redraws them on a state-change notification
  that every filter's write raises, because controls are otherwise built once
  and rebuilt only by Clear. `tests/js/category-levels.test.mjs` exercises the
  module under Node, and CI runs it.
- **The runtime config.** `agend_apps_records_filter_config()` writes
  `categoryLevel` (`all`, `top` or `sub`) for the directory Category filter, and
  its source carries `parentKey: 'parent_id'` (the registry entry's `parent`), so
  the script keeps each category's parent from the categories response.
- **The categories request.** `agend_apps_directory_get_categories()`, now in
  `includes/api/directory-categories.php`, asks `GET /v1/directory/categories`
  for `limit=500&active_only=true&counts=false` (still filterable through
  `agend_apps_directory_get_categories_args`), so a tree larger than the API's
  default page of 50 arrives whole and an inactive category is never offered.
  The response is cached under a key that includes those arguments, so a list
  cached by an earlier release is not served after upgrading. The legacy filter
  bar and the catalogue's category exclusion options read the same function and
  so also receive up to 500 active categories. An API version that rejects a
  `limit` above 100 is retried once at 100, cached under its own key, so the
  filters still get a list, capped at 100, instead of none.

### Filter: range brackets (custom field)

The Agend Filter's **Range brackets** presentation (`range_brackets`, offered by
the custom field filter beside **Number range**) lets a visitor pick one preset
bracket, "Under 10,000", "10,000 to 30,000", "Over 30,000", instead of typing a
minimum and maximum. Both bounds are inclusive and either may be open. It is
only a way of filling in the existing `custom_fields[<key>][min]` and `[max]`:
choosing a bracket writes exactly the `{min, max}` strings the Number range
control writes, so the request, Reset and pagination are unchanged. No gateway
or proxy change is involved.

- **Where the brackets come from.** *Use Agend's brackets* (the default) reads the
  `presets` of the field's entry in `GET /v1/directory/facets`, an array of
  `{ label, min?, max? }` where an omitted or `null` bound is open. *Custom
  brackets* uses the widget's own list instead. If Agend is chosen but the facet
  has no presets, the widget's list is the fallback. Custom never falls back to
  Agend. With nothing to offer, a visitor sees no control (the script hides it,
  or the server draws nothing for an empty custom list) and an editor preview
  says why in place of the control.
- **The custom list** is one bracket per line, `Label | min | max`, a blank bound
  being open: `Under 10,000 | | 9999`. A bound is a plain number (no thousands
  separators), a minimum may not be above its maximum, and at least one bound is
  required. Lines that do not fit are skipped, not guessed at, and the editor
  preview lists them; at most 12 brackets are used. The same rules validate
  Agend's presets, quietly. Parsing is
  `agend_apps_records_filter_range_brackets_parse()`; the validated rows reach the
  script as `rangeBrackets.custom` in the filter's `data-agend-filter` config, so
  the script never parses the text.
- **Display** is a dropdown, buttons or radio buttons, each with an "Any" choice
  (label configurable, default "Any") that clears the range.
- **Restoring a selection.** When the control is built, the range already in the
  catalogue state decides what is selected. Bounds that exactly match a bracket
  select it. Bounds that match none (a hand-edited or shared range, or a
  bracket Agend has since changed) show **Any**, but the state is left alone, so
  the results stay filtered by that range and choosing **Any** clears it. The
  control never rewrites the state to suit itself. Catalogue state is not yet
  read from or written to the URL by these scripts; anything that puts a range
  into `state.custom_fields` before the controls are built is reflected.
  `window.agendFilters.rangeBrackets.find()` is the exact-match rule, for a layer
  that wants to name the active bracket (a chip).
- **The first paint** is the same disabled, `aria-busy` stand-in the other
  controls use, drawn in the final shape of the chosen display with only "Any".

## Directory map

The location search, the Nearest sort, the List / Map switch and the Agend Map
surface (`directory-map`, an Elementor widget and a block) are described for
authors in the Agend Elementor README. The pieces here:

- **Proxy routes**: `GET /agend-apps/v1/directory/markers` (the search filters
  plus `limit` up to 2000 and `bbox`), `/directory/geocode?q=` and
  `/directory/map-settings`, wrapping the gateway endpoints of the same names
  in `includes/api/directory-map.php`. `/directory/search` also accepts `bbox`.
  Gateway statuses pass through (403 without the geocoding feature, 404 for
  no match, 503 while the geocoder is down), so the browser can tell them
  apart.
- **Coordinate rounding**: `agend_apps_directory_round_coordinates()` rounds
  `lat`/`lng` to 3 decimals (about 110 m), `radius` to 1 and `bbox` edges to 4
  before a search or markers request, so near-identical searches share one
  cached response instead of writing a transient each.
- **Catalogue contract**: the directory catalogue publishes
  `window.agendCatalogues.listing` (`state`, `params()`, `lastParams`,
  `reload()`, `hrefFor( slug )`, `open( slug )`, `view`) and dispatches
  `agend:directory-results` (`detail.params`, the query it just answered) and
  `agend:directory-view` (`detail.view`, `'list'` or `'map'`) on `document`.
  `assets/js/directory-map.js` is the only consumer today; anything else that
  needs to follow a catalogue can use the same events. The state keys are
  `near` (`{lat, lng, radius, label}`), `bbox` (`"north,south,east,west"`) and
  `view`; `query.php` builds the same query from them for the server-rendered
  first page.
- **Map assets**: Leaflet 1.9.4 and leaflet.markercluster 1.5.3 are vendored
  under `assets/js/vendor/` (licences alongside) and registered as
  `agend-apps-records-leaflet` and `agend-apps-records-leaflet-markercluster`.
  The root `.gitignore` ignores `vendor/`, so files there are added with
  `git add -f`.
- **Opening a profile**: with a listing detail template set, catalogue cards
  and map pins navigate to the server-rendered profile instead of drawing the
  built-in detail in place (`agend_apps_records_directory_catalogue_ssr_detail()`).
  `includes/records/speculation.php` adds a speculation rule that prefetches
  Agend detail links (`a.agend-card-link`, built-in cards, map pin links) as
  soon as the pointer rests on one ("moderate" eagerness); the
  `agend_apps_records_detail_link_selector` filter changes or empties the
  selector. A detail template fetches reviews only if it has a reviews
  panel, and a signed-in member's profile views are cached per member.
- **Tiles**: `agend_apps_records_directory_map_tiles()` uses CARTO's light
  basemap with the key in the `agend_apps_map_carto_key` option (Settings >
  Agend Widgets > Maps), otherwise OpenStreetMap's own tiles. The
  `agend_apps_records_directory_map_tiles` filter swaps in another provider.

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


## Endpoint scope guard

Core refuses to send a gateway call that the stored API key is known not to be
allowed to make. The refused call still appears in the API log, with outcome
`blocked` ("Blocked locally: key lacks access"), no status, and an error code of
`agend_apps_scope_missing` or `agend_apps_app_not_allowed` whose message names
the missing scope or app. Callers get a `WP_Error` with the same code and a
`status_code` of 403 (plus `path`, `missing` and `app`), so they treat it as the
gateway's own 403.

The guard only acts when it is sure. It never blocks when the key's scopes are
not known for the configured key, when the endpoint is not in the map, for
`/health`, or while a scope refresh is in flight. An empty `app_ids` list from
`/v1/health` means "unrestricted" (the gateway reports `[]` for both that and
"restricted to nothing"), so apps are never blocked on it. A `*` scope, or an
`app.*` scope, satisfies every scope it covers. Because the cached scopes can be
up to an hour old, a call about to be blocked on data older than five minutes
triggers one throttled refresh (at most once per five minutes) and is
re-checked against the fresh scopes first.

Switch it off for a site with:

    add_filter( 'agend_apps_api_block_unauthorised', '__return_false' );

The filter receives `( bool $block, string $method, string $path, string[] $missing )`.

### Regenerating the endpoint scope map

`includes/api/endpoint-scopes.php` is generated from the gateway's OpenAPI
contract and committed. Rerun it whenever the gateway adds or changes an
endpoint or its scopes:

    php agend-apps-core/bin/generate-endpoint-scopes.php /path/to/agend-dashboard/apps/api/openapi.json

The output is deterministic, so an unchanged contract produces no diff. Keys
are `METHOD /path` relative to the `/v1` base URL, with `app` (the first path
segment) and `scopes` (a list of alternatives: any one fully held is enough).
Only `apiKey` security requirements are mapped. The matcher is
`Agend_Apps_Endpoint_Scopes` and prefers literal segments over parameters.

## API log

Core records every call it makes to the Agend gateway, and every webhook the
gateway delivers to `agend-apps/v1/webhooks/incoming`, in the
`{prefix}agend_apps_api_log` table. View it at **Tools > Agend API Log**
(`manage_options`). It replaces nothing: the Agend Pro membership-kiosk log is
a separate system.

**Personal data is redacted before anything is stored.** The redactor
(`includes/logging/class-agend-apps-log-redactor.php`) applies three layers to
the request and response bodies, query string, URL path, error message and
page path:

1. **Key rules**, at every depth of the JSON. Emails, phone numbers, dates of
   birth and demographics, addresses and coordinates, person and company names,
   ABN/ACN/TFN and licence numbers, member and external ids, IP addresses,
   free text (notes, bios, messages, content, descriptions, answers, reasons,
   search terms), profile and social URLs, secrets, tokens and session ids,
   and payment details. Every leaf beneath a matched key is redacted, so
   `addresses[]` and `custom_fields` are covered in full. Inside custom fields
   only the answer goes; `key`, `label` and `type` stay so a failure can be
   traced to its field.
2. **Value scanners** on every remaining string: emails, AU, North American
   and international phone numbers, Luhn-valid card numbers, JWTs, `Bearer`
   tokens, provider keys (`ag_live_…`, `cs_live_…`), and the query string of
   any URL inside a value (signed links, checkout URLs).
3. **Known values.** Every personal value the exchange carries (bodies,
   query parameters, listing slugs, path tokens), plus the signed-in user's
   own name and email, is scrubbed from free text anywhere in the same row, so
   a gateway error echoing a name ("Jane is too short") or a slug ("Listing
   jane-smith-physio not found") does not leak it.

Paths are redacted too: the segment after `invitations`, `verify`, `tokens`,
`confirm` or `reset` is a token and is always removed, and a slug after a
person collection (`listings`, `contacts`, `members`, `users`...) is replaced
with a correlation marker. Only the first segment of the front-end page path is
kept readable. The `WP_DEBUG` lines the client writes to `debug.log` use the
same path and message redaction.

Redacted values read `[redacted:email#a1b2c3]`: the six characters are a keyed
hash, so the same value correlates across rows but cannot be recovered. Secrets
carry no hash.

**Never stored:** request headers (the API key, member bearer and cart session
never reach the logger), the referrer, the full IP address (`/24` or `/48`
only), the requester's email (a salted `user_hash` instead, which the viewer
and CLI match when you search by email; the viewer's filter form is posted and
hashed server-side so the email never appears in a URL), and the webhook
signature. An unverified webhook delivery (bad signature, or no secret
configured) stores no body, event id or type: anyone can POST to that route,
so it is recorded as metadata only. Request and
response bodies are never stored at all for `/auth/login`, `/auth/register`,
`/auth/refresh`, `/auth/mfa/verify`, `/auth/reset-password`,
`/auth/change-password`, `/auth/session-handoff` and `/sso/tokens`.

**What is kept:** method, host, path and path template, status, outcome
(`success`, `http_error`, `transport_error`, `invalid_response`,
`rate_limited`, `blocked`, `retried`, and for webhooks `signature_invalid`, `duplicate`,
`invalid_payload`, `not_configured`), duration, attempt number, the gateway's
`X-Request-Id` (or `X-Vercel-Id`), the webhook event type and id, a UUID shared
by every call in one PHP request, and allowlisted response headers. Successful
calls keep up to 5,000 characters of each redacted body, and a successful body
over 256 KB is summarised rather than redacted; failed calls keep the whole
redacted body up to 1 MB.

**What cannot be guaranteed:** a third party's name or street address written
as prose inside a non-personal field (an event description that names a
speaker) has no pattern to match. Emails, phone numbers, cards and tokens are
still caught there.

**Writes** are buffered and flushed in one multi-row INSERT on `shutdown`
(early, every 50 entries, in WP-CLI and cron), so logging adds no queries
inside a request. A write failure never breaks the request; a batch that fails
is retried row by row so one bad row cannot drop the rest.

**Retention:** a daily WP-Cron event (`agend_apps_api_log_prune`) deletes rows
older than the retention setting (default 30 days, 1 to 365) in batches of
5,000 using the `created_at` index, up to a million rows a run; a run that
reaches that cap schedules a follow-up five minutes later.

**Switches and filters:**

- `define( 'AGEND_APPS_API_LOG', false );` in `wp-config.php` turns logging off
  and locks the setting.
- `agend_apps_log_sensitive_keys` / `agend_apps_log_safe_keys` widen the key
  rules. The safe list cannot un-redact a built-in personal key.
- `agend_apps_log_body_excluded_paths` adds endpoints whose bodies are never
  stored.
- `agend_apps_log_entry` sees each redacted row (never the raw entry) before
  it is buffered; return `false` to drop it.

**WP-CLI:** `wp agend-apps logs list [--status=5xx] [--path=/v1/crm]
[--since="1 day ago"] [--email=<email>]`, `wp agend-apps logs prune
[--days=<n>]`, `wp agend-apps logs purge [--yes]`.

**Sibling plugins** that call third-party systems directly can log through the
same redaction with `agend_apps_log_http()`:

```php
if ( function_exists( 'agend_apps_log_http' ) ) {
	agend_apps_log_http(
		array(
			'source'        => 'agend-directory-sync',
			'method'        => 'GET',
			'url'           => $url,
			'status'        => is_wp_error( $response ) ? null : wp_remote_retrieve_response_code( $response ),
			'duration_ms'   => $elapsed_ms,
			'response_body' => is_wp_error( $response ) ? '' : wp_remote_retrieve_body( $response ),
			'error'         => is_wp_error( $response ) ? $response->get_error_message() : null,
		)
	);
}
```

There is deliberately no field for request headers.
