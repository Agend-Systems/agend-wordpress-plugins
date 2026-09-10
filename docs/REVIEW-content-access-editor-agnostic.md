# Review: making Agend Content Access editor agnostic

Status: review, for decision
Author: prepared for Agend Systems, September 2026
Subject: `agend-content-access` v0.1.0 (merged to `main`, 228 unit tests green)
Related: SPEC-CMS-20260727-wordpress-member-content-access

## Summary

Three findings decide the shape of this work.

**The coupling is narrower than the plugin's reputation suggests.** Of 20 source
files, four are Elementor-bound and one more leaks Elementor vocabulary into the
connector payload. Document-level protection, the decision engine, the policy
value object, the condition engine, protected downloads, credentials and the
legacy audit are already editor-neutral and work unchanged on a Gutenberg page
today. What a Gutenberg page loses is everything below the page: per-fragment
policies, display conditions, and protected files.

**The repo has already solved this problem once, and the solution is not being
reused.** `agend-apps-core/includes/records/schema.php` is a page-builder-agnostic
settings schema with two working adapters: Elementor
(`class-agend-elementor-schema-controls.php`) and the block editor
(`records/blocks.php` plus `src/blocks/shared/schema-inspector.js`). Content
Access predates that seam and duplicates none of it. The recommendation is to
adopt that pattern rather than invent a second one.

**Segments already exist. Entitlements do not, and are blocked outside this
repo.** CRM segment filtering is built, tested and wired to
`GET /crm/me/segments`. It is only reachable from an Elementor element control,
which is why it reads as missing. Entitlement (`gate_key`) filtering cannot be
built at all right now: the gateway has a write path and no read path, and no
endpoint answers "does this member hold `gate_key` X". That is platform work,
not WordPress work, and it should be raised before the rest is scheduled.

Recommendation: do this in three stages, and treat stage 1 as worth doing on its
own merits even if stages 2 and 3 never happen.

## 1. What a Gutenberg page gets today

This is the honest baseline. Nothing here is speculative; each row was checked
against the code.

| Capability | Elementor page | Gutenberg page today |
| --- | --- | --- |
| Document policy (public / members / selected plans) | yes | **yes**, identical |
| Fail-closed gating on `template_redirect` | yes | **yes**, identical |
| Teaser and CTA on denial | yes | **yes**, identical |
| REST response stripping | yes | **yes**, identical |
| Builder data suppression on denial | yes, `_elementor_data` blanked | n/a, body already replaced |
| Per-fragment policy (a section narrower than the page) | yes | **no** |
| Display conditions, including CRM segments | yes | **no** |
| Protected file downloads | yes, via the Elementor widget | **no** |
| Fragment-level export to Agend | one fragment per widget | **no**, one `type: "html"` fragment holding the entire `post_content`, policy hard-coded `inherit` |
| Authoring-context carve-out | explicit, edit mode and preview | only via the generic `is_admin()` check |

Two of those rows deserve emphasis.

**Display conditions are Elementor-only, at element level only.** The conditions
keys (`agend_conditions_enabled`, `agend_conditions`, `agend_conditions_match`,
`agend_conditions_invert`, `agend_conditions_fallback_id`) are declared as
Elementor controls at `class-agend-content-access-elementor.php:52-56, 165-245`
and read from an element's settings array. `Agend_Content_Access_Meta_Box`,
`Agend_Content_Access_Decision` and `admin/views/policy-panel.php` contain no
reference to conditions at all. So segment filtering cannot be applied to a whole
page in either editor, and cannot be applied at all in Gutenberg.

**The single-fragment fallback is a fidelity floor, not a hole.** A Gutenberg page
exports as one fragment carrying the whole body
(`class-agend-content-access-exporter.php:142-150`) with `policy: inherit`, so the
document policy still governs it. Agend is never told a region is public that is
not. The cost is granularity, not safety.

## 2. Where the coupling actually is

Files with no Elementor reference at all, and therefore no work in this project:
`class-agend-content-access-decision.php`, `-policy.php`, `-catalogue.php`,
`-credentials.php`, `-dependencies.php`, `-conditions.php`,
`-condition-providers.php`, `-condition-runtime.php`, `-condition-sets.php`,
`-originals-audit.php`, `-cli.php`, `admin/class-agend-content-access-admin.php`,
`rest/catalogue-routes.php`, `rest/download-routes.php`, and both front-end
assets.

The coupling that exists falls into three kinds.

### Genuinely editor-specific (every editor needs its own)

These are capabilities, not problems. Each one is a method a driver must supply.

| Capability | Elementor implementation |
| --- | --- |
| Detect the editor is active | `did_action('elementor/loaded')`, `agend-content-access.php:95-99, 302-304` |
| Inject a per-fragment control | `elementor/element/after_section_end`, `-elementor.php:99-247` |
| Decide which nodes may carry a policy | `stack_accepts_controls()`, `-elementor.php:286-296` |
| Suppress a fragment at render time | `elementor/frontend/{type}/should_render`, `-elementor.php:361-396` |
| Render access-checked replacement content | `after_render`, `-elementor.php:554-577` |
| Opt a policy-bearing fragment out of the editor's own cache | `elementor/element/is_dynamic_content`, `-elementor.php:399-478` |
| Flag a restricted fragment while authoring | `add_render_attribute()`, `-elementor.php:612-630` |
| Recognise an authoring or preview request | `-frontend.php:117-144` |
| Probe that the suppression chain is intact | `Agend_Content_Access_Compat`, whole file |
| Parse the document into fragments | `Agend_Content_Access_Elementor_Parser`, whole file |

That list is the driver interface, derived from what already exists rather than
designed from scratch. It is the single most useful output of this review.

### Incidentally named (move, do not rewrite)

`Agend_Content_Access_Elementor::policy_from_settings()`
(`-elementor.php:309-346`) is pure policy validation: it reads two keys, validates
tier UUIDs, and fails closed on an unrecognised mode by returning `active_member`.
Nothing in it is Elementor-specific except that it happens to be handed Elementor's
settings array. It belongs on `Agend_Content_Access_Policy` as
`from_fragment_settings()`, taking a mode value and a tier list.

`Agend_Content_Access_Assets::promote()`, `read_asset()` and `fragment_payload()`
(`-assets.php:59-166`) are already pure, injected-callable functions with no
Elementor reference. Only their caller is coupled.

`Agend_Content_Access_Compat::version_state_for()` and
`broken_atomic_chains_for()` are already injectable pure functions. The pattern
transfers even though the class names probed do not.

### Leaked abstractions (the actual blockers)

These are the ones that make a second editor awkward rather than merely
unimplemented.

**1. Elementor vocabulary is on the wire.** A fragment's `type` is the literal
string `'elementor:' . $widgetType` and its `id` is Elementor's own opaque
seven-character node id (`-elementor-parser.php:153-167`). Both are shipped to
Agend through the connector and become the upsert key on the other side. Whatever
Gutenberg emits has to coexist with that in one namespace.

**2. The exporter sniffs a postmeta key instead of dispatching.**
`fragments_of()` branches on `'' !== trim( get_post_meta( $id, '_elementor_data' ) )`
and then calls `Agend_Content_Access_Elementor_Parser::parse()` by name
(`-exporter.php:127-151`). There is no interface, no registry, no abstract class.
This one `if` is the seam that has to become a dispatch.

**3. Text extraction is a guess at Elementor's naming conventions.**
`TEXT_KEYS = ['title','editor','text','heading','description','html','caption']`
(`-elementor-parser.php:182-200`) probes an Elementor settings map. Gutenberg
stores content in `innerHTML` and attributes, so this has no meaning there and a
block parser needs its own extraction entirely.

**4. Asset promotion walks an Elementor tree.**
`Agend_Content_Access_Assets::promote_document()` hard-codes `_elementor_data`
for both read and write (`-assets.php:222-244`) and `walk_and_promote()`
(`:253-311`) assumes a `{settings, elements}` node shape. The primitive underneath
is neutral; the walk is not.

**5. Elementor-specific copy in a neutral panel.** `admin/views/policy-panel.php:31`
carries the "This page uses Elementor's new editor" notice, fed by
`Agend_Content_Access_Meta_Box::render()` calling the Elementor parser directly
(`-meta-box.php:186-190`).

**6. An Elementor failure mode leaks into the connector's error handling.**
`rest/connector-routes.php:215` catches the `RuntimeException` the Elementor parser
throws on an unparseable document and turns it into a 422.

One thing that is **not** a leak, contrary to first appearances:
`Exporter::revision_of()` reads `_elementor_data` into its hash
(`-exporter.php:91-107`) but also hashes `post_modified_gmt`, `post_status`, the
policy JSON and `post_content`. A Gutenberg edit therefore does change the
revision. No fix needed.

## 3. The pattern to copy

`agend-apps-core/includes/records/schema.php` opens with the exact statement of
intent this project needs:

> A surface declares its editable settings ONCE here, as plain PHP data, and every
> presentation adapter (Elementor today, the block editor later) renders its own
> controls from the same declaration instead of hand-declaring the same settings
> a second time.

It is fully realised, not aspirational:

| Piece | Location |
| --- | --- |
| Neutral schema vocabulary | `agend-apps-core/includes/records/schema.php` |
| Neutral renderers | `agend-apps-core/includes/records/render/*.php` |
| Elementor adapter | `agend-elementor/includes/class-agend-elementor-schema-controls.php` |
| Block adapter, attributes and registration | `agend-apps-core/includes/records/blocks.php` |
| Block adapter, inspector UI | `agend-apps-core/src/blocks/shared/schema-inspector.js` |
| Schema delivery to the editor | `GET /agend-apps/v1/surfaces/{surface}/schema` |
| Build pipeline | root `package.json`, `wp-scripts`, output committed to `build/` |

It also already carries the answer to the awkward part. Block attributes are
block-shaped (a toggle is a boolean) while the neutral renderers take
Elementor-shaped settings (a toggle is `'yes'`), and `blocks.php:99-115` is the
whole of that converter. Content Access has the same mismatch waiting for it,
because `policy_from_settings()` reads Elementor-shaped values.

Two consequences worth stating plainly. The build tooling, the inserter category,
the deploy story for committed `build/` output and the schema REST pattern are all
solved and in production. And the "Agend Access" control set should be declared
once as a schema, not written twice.

## 4. How suppression works in the block editor

The mechanics are settled, and better than the Elementor equivalent in one
respect.

**Suppress at `pre_render_block`, not `render_block`.** Both fire for every
block, static and dynamic alike, so there is no "static blocks cannot be hooked"
problem. The difference is that `pre_render_block` short-circuits: returning any
non-null value skips the block's own rendering entirely, whereas `render_block`
filters output that has already been produced, with any side effects in a
`render_callback` already executed. For fail-closed access control, never
constructing the content is the correct posture. `render_block_data` is
complementary, useful for resolving policy down a nested tree before rendering,
but it cannot stop rendering on its own.

**Add the policy attribute without invalidating existing content.** Three
cooperating filters: `blocks.registerBlockType` and `editor.BlockEdit` in JS,
`register_block_type_args` in PHP. The rule that avoids "this block contains
unexpected or invalid content" is to use a **non-sourced attribute with a
default** and never touch `save()`. A non-sourced attribute serialises into the
block comment delimiter rather than the markup, so existing blocks that predate
the attribute parse cleanly against the declared default. `editor.BlockEdit`
runs for every block, so the panel should be conditional on `isSelected` to
avoid a block-selection performance regression.

**There is no stable block ID in core, and one must be minted.** `clientId` is
editor-session-only, is absent from `parse_blocks()` output, is never serialised,
and can churn within a single session (splitting a paragraph reassigns it). A
core PR to introduce stable IDs exists and is unmerged. So the fragment ID has to
be a minted, persisted, non-sourced attribute. Two collision cases need handling
explicitly, because both copy the delimiter JSON verbatim: **copy/paste** and the
editor's **Duplicate** action. Server-side de-duplication on save, keyed on post
plus block ID, is the safer of the available approaches.

**A parse-time pass is not sufficient.** `core/block` (synced patterns) stores
only a `ref` to a `wp_block` post, so the inner blocks are not in the page's
`post_content` at all and must be resolved. `core/template-part` content lives in
`wp_template_part`. `core/post-content` inverts the relationship, with the
template pulling the post in. Query loops render *other posts'* content inline,
discoverable only at render time. This is why suppression has to live in the
render pipeline, where `pre_render_block` fires per block regardless of which
post or template it came from.

**One constraint the Elementor code cannot be copied into.**
`Agend_Content_Access_Elementor::maybe_suppress()` resolves the document policy
via `get_queried_object_id()` (`-elementor.php:387`). That is correct for
Elementor, where a document renders for the queried post. It is wrong for blocks,
which render inside query loops, template parts and search results where the
queried object is not the post the block belongs to. The block driver must
resolve policy from the block's own post context. Worth stating explicitly,
because the mistake would fail open.

### New leak vectors

Elementor keeps the document body in `_elementor_data` postmeta. Gutenberg keeps
it in `post_content`, which is the field WordPress's content-surfacing code is
built to read. The plugin has no handling for any of this today: a grep for
`pre_get_posts`, `posts_search`, `the_excerpt`, `get_the_excerpt`,
`wp_trim_excerpt`, `is_search` and `is_feed` across `includes/` and `admin/`
returns nothing.

At document level that is mostly acceptable by design; the teaser model
deliberately shows that restricted content exists. At **fragment** level it is
not, because the promise there is narrower: a member-only section on an otherwise
public page.

| Vector | Covered by `pre_render_block`? |
| --- | --- |
| Front-end `the_content` | Yes |
| Auto-generated excerpts | Yes. `wp_trim_excerpt()` applies `the_content`, so `do_blocks()` runs and suppression applies. |
| RSS and Atom feeds | Yes, same path, provided nothing caches pre-suppression output. |
| REST `content.rendered` | Yes, and already stripped by `filter_rest_response()`. |
| REST `content.raw` | No, but gated to users who can edit the post, who can see the content anyway. Not a real disclosure. |
| Core XML sitemaps | Not a vector. Core sitemaps carry URLs and dates only. |
| **Default WordPress search** | **No.** Core searches `post_content` with `LIKE` in SQL, never touching the render pipeline. A public page with a members-only block matches a query on the protected text. Elementor never had this, because core search does not read postmeta. |
| **Third-party search indexers** | **No, and worst of the set.** Yoast, RankMath, ElasticPress and similar commonly index from raw `post_content` at `save_post`, outside the content filters entirely, then serve it through their own search UI. |
| **Full-page caching** | **No.** A page cached while a member viewed it, then served to anonymous visitors, bypasses PHP entirely. Elementor's element cache is a presentation-layer problem the plugin already solves via `is_dynamic_content`; a page cache is a response-layer problem it has never faced. |

The mitigations are known and cheap relative to the risk. Filter protected body
text out of the search query. For caching, `nocache_headers()` alone is not
enough, since `no-cache` still permits storage pending revalidation; define
`DONOTCACHEPAGE` early in the request, which every major WordPress page-cache
plugin respects, and set `Cache-Control: private, no-store` where the CDN layer
is under our control. These belong in scope from the start rather than
retrofitted, because each one fails silently.

## 5. Segments and entitlements

The two halves of this request are in completely different states.

### Segments: built, and reachable from one place

Everything needed already exists and is tested.

| Piece | Location |
| --- | --- |
| Tenant segment list, for the editor picker | `agend_apps_crm_get_segments()`, `GET /crm/segments` |
| Per-viewer segment membership | `agend_apps_crm_get_my_segments()`, `GET /crm/me/segments` |
| Editor vocabulary, cached | `condition-sets.php:140-182` |
| Runtime facts, per-viewer cached, never caches a failure | `condition-runtime.php:133-191` |
| Evaluation | `condition-providers.php:142`, `in_array` against the viewer's slugs |
| Site override | `agend_content_access_segment_facts` filter |

The gap is reach, not capability. Segments can only be applied to an Elementor
element. Making them useful means exposing the condition engine in two more
places: on the document (the policy panel) and on a Gutenberg block. Both consume
machinery that is already written and already fails closed.

### Entitlements: blocked on the gateway

`gate_key` entitlements are modelled in this repo, but only as a producer.
`agend-entitlement-mirror` converts Upbeat category and type pairs into namespaced
`gate_key`s and pushes them out through two write endpoints,
`POST /crm/entitlements/types` and `POST /crm/entitlements/grants`.

There is no read path. Searching the whole repo:

- No `GET /crm/me/entitlement-grants` or equivalent is called anywhere.
- No `agend_apps_crm_*` function reads back `gate_key` grants for the current viewer.
- `agend-content-access` contains no occurrence of `gate_key` at all.

`GET /crm/me/entitlements` sounds like the answer and is not. Its docblock
(`agend-apps-core/includes/api/crm.php:394-414`) is explicit that it returns the
member's resolved membership standing, `{tier_ids, is_member}`. That is what the
Decision engine consumes today, and it says nothing about entitlement grants.

**So per-viewer entitlement filtering cannot be built in WordPress right now.** It
needs a gateway endpoint that answers "which `gate_key`s does the bearer hold",
which is Josh Hinton's or Erick Goncalves' call to schedule. Everything on the
WordPress side after that is small: one API wrapper following the established
`agend_apps_crm_*` shape, one fact source copying `segment_facts()` almost
verbatim, and one entry in the condition vocabulary. Worth raising now, because it
has a longer lead time than any of the WordPress work.

### A defect that blocks the obvious approach

The natural way to add entitlements is as a fourth condition provider. That path
is currently closed, and the code says otherwise.

`condition-sets.php:60-67` documents the contract:

> A site adding its own provider registers the matching checker through
> `agend_content_access_check_condition` as well.

That filter does not exist. A grep for `check_condition` across the entire plugin
returns nothing. `Agend_Content_Access_Condition_Providers::checker()`
(`condition-providers.php:85-144`) takes exactly three fixed callables
(`$wordpress_facts`, `$member_facts`, `$segment_facts`) and dispatches through a
closed `if` chain, with segments as an unguarded final fallthrough rather than an
explicit branch.

Two consequences. A site that registers a provider through the documented
`agend_content_access_condition_sets` filter gets conditions that always deny,
with no diagnostic. And adding entitlements means changing that method's
signature, which is a small change but should be done as a registry rather than a
fourth positional argument.

## 6. Other defects found during the review

Not blockers, but they belong in the same piece of work because they sit in the
code being touched.

**Two independent viewer lookups per request.** The document path
(`Decision::viewer()`, `-decision.php:63-127`) and the condition path
(`Condition_Runtime::member_facts()`, `-condition-runtime.php:70-117`) both call
`GET /crm/me/entitlements`, with separate caches and separate lifetimes: a static
per-request array in one, a per-user transient in the other. A page with a
document policy and conditioned sections makes the same call twice.

**A cache TTL that silently falls back.** `condition-runtime.php:202-212` requests
`Agend_Apps_Settings::get_cache_ttl('crm_me_entitlements')`, but
`crm_me_entitlements` is not registered in `Agend_Apps_Cache::$keys`, so the
configured value never applies and the hardcoded default is always used. Either
register the key or drop the lookup, but the current state reads as configurable
and is not.

**The meta box declares no block-editor compatibility.**
`Agend_Content_Access_Meta_Box::add_meta_box()` (`-meta-box.php:115-124`) passes
no `$callback_args`, so neither `__block_editor_compatible_meta_box` nor
`__back_compat_meta_box` is set. The panel does render in the block editor, but
WordPress treats an undeclared meta box as potentially incompatible. Adding the
flag is a one-line change and is worth doing now, independently of everything
else here, since Gutenberg use is already growing.

**A tension to resolve, not a defect.** The policy meta is deliberately
registered with `show_in_rest => false` (`-meta-box.php:103`) so it is not
readable through a record's public REST representation. A native block-editor
sidebar panel normally needs REST-exposed meta. The way to keep both properties
is to have the panel read and write through the plugin's own namespaced route
with an `edit_post` permission callback, the same pattern
`rest/catalogue-routes.php` already uses, rather than flipping `show_in_rest`.

## 7. Recommended sequence

Staged so each stage ships value alone.

### Stage 1: extract the seam (no Gutenberg yet)

Refactor only, behaviour identical, existing 228 tests must stay green.

1. Move `policy_from_settings()` off the Elementor class onto
   `Agend_Content_Access_Policy`.
2. Define `Agend_Content_Access_Parser` with a `parse( WP_Post ): array` contract
   returning the existing fragment shape, and make the Elementor parser its first
   implementation.
3. Replace the `_elementor_data` sniff in `Exporter::fragments_of()` with a driver
   registry: ask each registered driver whether it recognises the post, first match
   wins, existing single-fragment fallback last.
4. Generalise `Assets::promote_document()` to take the document tree and the
   read/write callbacks from the driver rather than hard-coding `_elementor_data`.
5. Move the Elementor V4 notice out of `policy-panel.php` and behind a driver
   method that returns editor notices.
6. Turn `Condition_Providers::checker()` into a real registry and implement the
   `agend_content_access_check_condition` filter its documentation already promises.
7. Namespace the fragment `type` deliberately and decide the ID scheme before a
   second editor exists, not after.

Stage 1 is worth doing regardless of Gutenberg. It closes a documented-but-absent
extension point, removes a duplicated gateway call, and makes the Elementor
integration testable in isolation.

### Stage 2: the Gutenberg driver

1. Declare the Agend Access control set once as a schema, following
   `records/schema.php`.
2. Elementor renders it through the existing schema-controls adapter instead of its
   current hand-written controls.
3. Block editor renders it through a shared inspector following
   `schema-inspector.js`, injected via `register_block_type_args` in PHP plus
   `blocks.registerBlockType` and `editor.BlockEdit` in JS. Non-sourced attributes
   with defaults, `save()` untouched, panel gated on `isSelected`.
4. Suppression on `pre_render_block`, returning `''` on denial and on any lookup
   failure. Resolve policy from the block's own post context, not
   `get_queried_object_id()`.
5. A block parser implementing the stage 1 interface, resolving `core/block`
   refs and template parts, which have no Elementor analogue.
6. Mint and persist a stable per-block fragment ID as a non-sourced attribute,
   with server-side de-duplication on save to handle copy/paste and Duplicate.
7. Handle the `post_content` leak vectors in section 4: search filtering, and
   `DONOTCACHEPAGE` on any response carrying a policy-bearing block.
8. A compat probe for the block APIs, matching what `Compat` does for Elementor.
9. Decide the document panel: either keep the classic meta box (and add
   `__block_editor_compatible_meta_box`), or build a native
   `PluginDocumentSettingPanel`, imported from `@wordpress/editor` rather than
   the older `@wordpress/edit-post`.

Worth mirroring from Block Visibility, which is the UX most clients will arrive
with: a site-level setting for which roles may set visibility, and which block
types get the panel at all. Without that, every editor can grant or revoke
access on every block.

### Stage 3: reach for segments and entitlements

1. Expose the condition engine on the document policy panel, so segments work on a
   whole page in either editor.
2. Expose it on the Gutenberg block inspector.
3. Add entitlements as a provider, once the gateway read endpoint exists.

## 8. Decisions needed

1. **Raise the entitlements gateway gap now.** Nothing in stage 3 can proceed
   without a per-viewer `gate_key` read endpoint. This is the long pole.
2. **Fragment ID scheme.** Elementor node IDs are already on the wire as upsert
   keys. Gutenberg has no native equivalent, so one must be minted. Confirm with
   the platform team that a second ID scheme in the same namespace is acceptable
   before building it.
3. **Elementor V4 atomic widgets.** Per-section controls are already unavailable
   there. If clients are moving to Gutenberg anyway, that constraint may be worth
   accepting permanently rather than solving.
4. **Feature parity target.** Whether the Gutenberg driver must reach full parity
   including protected files and replacement content, or whether stage 2 can ship
   with per-block policies and conditions only.
