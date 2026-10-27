# Agend Content Access

Restricts WordPress content to Agend members and membership plans.

Implements SPEC-CMS-20260727-wordpress-member-content-access.

## The one rule

**WordPress declares the intended audience. Agend decides whether the current
visitor belongs to it.**

The browser never makes that decision, and never receives protected material it
cannot display. No access decision in this plugin reads WordPress user metadata,
WordPress roles, or WooCommerce.

## Requirements

| Dependency | Required? | Why |
| --- | --- | --- |
| WordPress 6.0+ | yes | |
| PHP 7.4+ | yes | |
| Agend Apps Core | **yes** | Transport, API key storage, member bearer contract, plan catalogue |
| Elementor Pro | optional | Fragment-level policies. Without it, whole post and page policies still work |

Explicitly NOT required, and never to be added: ACF, WooCommerce, WooCommerce
Memberships, the Iugo plugin, or any particular theme. The reference environment
is a plain WordPress install with Elementor Pro (Decision 2.16).

### Elementor versions

**Supported range: 3.0.0 up to and including 4.2.0.**

- **Build target: 3.x.** Fragment work is written against the 3.x control and
  rendering APIs.
- **Tested up to: 4.2.0.** The first target site runs Elementor 4.2.0, so 4.x is
  a release gate rather than an afterthought (US-4.4).

The range is declared in code as `Agend_Content_Access_Compat::MIN_ELEMENTOR`
and `::MAX_TESTED_ELEMENTOR`, and enforced at runtime:

| Installed | Behaviour |
| --- | --- |
| Below 3.0.0 | Admin warning. Per-section restrictions may not apply; page-level restrictions are unaffected |
| 3.0.0 to 4.2.0 | Supported |
| Above 4.2.0 | Admin warning that the combination is untested, with a prompt to verify a restricted section against a logged-out visitor |

"Tested up to" means exactly that. It is not forward compatibility, and support
is not implied by the code happening to run. Do not raise
`MAX_TESTED_ELEMENTOR` without re-running the US-4.4 validation; a unit test
pins the constant to the version actually validated so that bump cannot pass
unnoticed.

### Editor V4 atomic widgets: known v1 limitation

Elementor's Editor V4 introduces atomic elements (`e-div-block`, `e-heading` and
the rest). **Per-section Agend Access controls are not available on them in v1.**
Their control stack does not accept the injected section.

What still works on a V4 page, and is not a limitation:

- **Document policies apply in full.** A page restricted to members is
  restricted, whichever editor built it.
- **Suppression still applies.** Atomic elements inherit `Element_Base`, which
  is where the `should_render` filter fires, so an element whose effective
  policy denies the viewer is still removed.

That inheritance is the load-bearing assumption, so it is not left to trust: a
runtime probe checks on every admin pageload that the atomic classes still
extend `Element_Base`, and raises a non-dismissible error naming the classes if
they do not. That state is the one in which the plugin would believe it is
protecting content and would not be.

An editor opening a V4 page sees a note in the Agend Access panel explaining
that individual sections cannot be restricted separately and that the page-level
setting covers the whole page. The note appears only when atomic elements are
actually present, not on every Elementor page.

### What the document parser supports

The connector flattens an Elementor page into one fragment per widget, folding
each ancestor's policy down into it.

| Node type | Handling |
| --- | --- |
| `section`, `column`, `container` | Structural. Not a fragment; its policy folds into descendants |
| `widget` | A fragment, carrying the intersection of every policy above it |
| anything else | **Fails the sync revision**, naming the node type |

Failing is deliberate. Skipping an unknown node is how a restricted region
silently stops being represented, after which Agend serves content it never
learned to gate.

**Editor V4 atomic widgets are a known limitation.** They extend `Widget_Base`
rather than `Widget_Common_Base`, and use a props schema instead of
`Controls_Manager` sections, so no per-widget Agend Access control can be
offered on them. Document policies and suppression still apply, because
`Atomic_Element_Base` extends `Element_Base` and the `should_render` filter
still fires. So a V4 page is protected at the page level; only per-section
restriction is unavailable.

Widget content extraction is best-effort: a widget type whose text this does not
recognise still produces a fragment carrying its policy, with
`payload.extracted` false. That is a fidelity gap, never a security one. The
region renders empty rather than to the wrong audience.

### Block editor pages

A page built in the block editor is exported as fragments too, by a second
driver that runs after the Elementor one. A block carries its audience in one
attribute, `agendAccess`, and the connector folds each block's policy down into
whatever sits below it, as it does for Elementor.

| Block | Handling |
| --- | --- |
| No block below it carries a policy | One fragment for the whole subtree, carrying the policy folded down to it |
| A block below it carries a policy | Structural. Not a fragment; its children are walked with the policy folded so far |
| Plain content between blocks | A `block:freeform` fragment. Blank runs are skipped |
| Synced pattern (`core/block`) | Resolved in place, so a restriction inside it is seen, up to 50 references per document. A missing, unpublished, protected, cyclic or over-limit reference is an empty fragment |

Fragment ids are positional, `block-` plus the index path (`block-2-1-0`), and
`type` is `block:` plus the block name.

**Nothing is rendered.** A static block's HTML is rebuilt from its saved markup.
A dynamic block (a query loop, latest posts, post content, a template part,
navigation, or any block with a render callback) is exported empty, with
`payload.extracted` false, because rendering it would pull other posts' content,
possibly restricted, into this document's fragment, to be served under this
document's policy.

A structural block's own wrapper markup (a Group's `<div>`) is not exported.
That, an unreadable pattern and a dynamic block are fidelity gaps, never
security ones: the failure mode is a region that comes out empty or bare, not
one served to the wrong audience. Display conditions are not exported, as for
Elementor.

### The Agend Access panel in the block editor

Every block gets an **Agend Access** panel in its sidebar, shown for the
selected block only. It writes one attribute, `agendAccess`, and nothing else.
A block nobody touched has no attribute and saves exactly as it did before: the
attribute is a non-sourced object whose default is `{}`, and a value equal to its
default is not written. `save()` is never changed, so no existing block becomes
"invalid".

**Which blocks.** All of them, except two that are not stored with a comment
delimiter and so have nowhere to keep an attribute: `core/missing` (the
unsupported block placeholder) and `core/freeform` (the Classic block). A policy
set on either would be forgotten on save, so they are left out in JavaScript and
in PHP, and a test keeps the two lists equal. The panel is also offered only
where the attribute is declared: a block type registered by a script that runs
before this plugin's editor script never receives it, and for that block the
panel stays hidden instead of offering a setting the post would not keep.

**The controls**, in the Elementor panel's order and wording, and what each one
writes. The panel writes the smallest object that says what was chosen, and
drops a key the moment it stops applying, so no stale id can linger:

| Control | Writes |
| --- | --- |
| Who can see this: Same as the page | nothing (`{}` if nothing else is set) |
| All active members | `mode: "active_member"` |
| Selected membership plans | `mode: "selected_tiers"`, `tierIds` |
| Selected segments | `mode: "segments"`, `segmentIds` |
| Selected entitlements | `mode: "selected_entitlements"`, `entitlementKeys` |
| Add display conditions | `conditionsEnabled: true`. Off removes every condition key |
| Conditions | `conditions`, a list of `"<provider>:<value>"` |
| Match: All of these | `conditionsMatch: "all"` (Any is the default and is not written) |
| Reverse the result | `conditionsInvert: true` |
| Show instead | `fallbackId`, a post id. Only public content is offered |

Changing the audience clears the lists of the modes the block has left. Choosing
"Same as the page" with conditions off removes the attribute. A key the panel
does not own, written by a script or a newer release, is kept untouched. A block
can only narrow the page's audience, never widen it, exactly as for an Elementor
section.

The audience lists show what the catalogue offers. A choice the block already
holds that the catalogue no longer offers (a deleted segment, a retired plan) is
shown first, ticked and marked unavailable, never dropped: dropping it would
quietly change who can see the block. A platform gate reads "Platform: ...".
If a list cannot be loaded the panel says so and still shows, and lets an
author clear, whatever the block already holds.

A blue stripe on the canvas marks a block that carries an audience policy, and
an amber one a block with display conditions only. A block whose stored value
the panel cannot read is shown as restricted to members, which is what the site
enforces, and its panel offers only the audience control until an author
chooses an option.

**Where the lists come from.** The panel calls two routes, once per editor
session and not on every block selection, both for users who can `edit_posts`:

| Route | Returns |
| --- | --- |
| `GET /wp-json/agend-content-access/v1/catalogue` | `{plans, segments, entitlements}`, each `{items, stale, error}` |
| `GET /wp-json/agend-content-access/v1/conditions` | `{groups, fallbacks}`: `groups` is `[{provider, label, conditions: [{value, label}]}]`, with each `value` already `"<provider>:<value>"`, and `fallbacks` is `[{id, title}]` of public pages |

**Building it.** The editor code is in `src/` and ships built, because
deployment copies files without a build step. From the repository root:

```sh
npm run build:content-access      # writes build/index.js, index.css, index.asset.php
npm run start:content-access      # the same, watching for changes
node --test agend-content-access/tests/js/access-model.test.mjs
node --test agend-content-access/tests/js/lists-loader.test.mjs
```

Commit `build/` with the source: CI rebuilds it and fails if it differs. The
release zip leaves `src/` out. The logic that decides what the panel writes is in
`src/access-model.mjs` and the once-per-session loader in `src/lists-loader.mjs`,
neither of which imports WordPress, which is what lets `node --test` run them.

## What this plugin owns

Document policies, Elementor fragment policies, block policies and display
conditions, the source connector, direct WordPress request gating, protected
assets, and the legacy migration.

## What it does NOT own

Transport, API keys, environment URLs, portal resolution, member login, or token
minting. All of that is Agend Apps Core's, consumed through its published
interfaces:

| Interface | Used for |
| --- | --- |
| `agend_apps_api()` | Outbound gateway calls |
| `agend_apps_get_bearer_token()` / the `agend_apps_bearer_token` filter | Member identity |
| `agend_apps_crm_get_tiers()` | Membership plan catalogue for the editor |
| `agend_apps_crm_get_segments()` | Segment list for the editor |
| `agend_apps_crm_get_entitlement_types()` | Entitlement type list for the editor. Core 1.48.0 or later; probed with `function_exists()`, not a hard dependency |
| `Agend_Apps_Cache` / `Agend_Apps_Settings::get_cache_ttl()` | Caching |

Reaching into Core internals rather than these is a review failure.

## Policy modes

A policy names who may read a document or an Elementor element. The shapes are
copied from the gateway's `@agend/access-policy`, which is the authority.

| Mode | Admits |
| --- | --- |
| `public` | Everyone |
| `active_member` | Anyone holding a current membership |
| `selected_tiers` | Members on at least one named plan (`tier_ids`) |
| `segments` | Visitors in at least one named segment (`segment_ids`) |
| `selected_entitlements` | Visitors holding at least one named entitlement (`entitlement_keys`, gate keys) |

A visitor outside a `segments` or `selected_entitlements` policy is
`access_restricted`, a fifth decision state beside `authentication_required`,
`membership_required` and `plan_required`. Its gate copy never names a segment,
names the entitlements of an entitlements policy when the catalogue can (the
generic "limited to selected members" otherwise), and offers no join or upgrade
link, because nothing to join unlocks it. A segment is a set over all contacts and an entitlement can be
held without a membership, so neither is a subset of "all active members", and
a fragment cannot narrow a document of a different set-mode: that pairing
admits nobody.

Entitlement keys arrive with the member's standing at no extra cost. Segments
are fetched, once per request, only when a policy being evaluated is a segments
policy.

## Choosing an audience in the editor

All five audiences are chosen from the same places.

| Surface | What it offers |
| --- | --- |
| Page panel (the Agend Access meta box) | All five audiences: Public, All active members, Selected membership plans, Selected segments, Selected entitlements. Each of the last three has a checkbox list with a filter box |
| Elementor element, Advanced tab | "Who can see this": Same as the page, All active members, and the same three selections, each a multi-select shown only for its own audience |

The page panel is registered as block editor compatible, so it works in the
block editor's meta box area as it does in the classic editor.

Rules both surfaces keep:

- **A selection that is no longer offered is never dropped.** A plan, segment
  or entitlement that was deleted, or a plan that was deactivated, is listed
  first in the panel, still ticked and marked unavailable, so the policy can be
  repaired. Dropping it would quietly change who can read the content.
- **A list that cannot be loaded says why, under its own radio.** The radio is
  disabled, because a new choice cannot be made, while Public, All active
  members and the other lists stay available. The stored audience's radio is
  still drawn checked, and a disabled control is not submitted, so saving the
  page keeps the stored policy instead of reading "no audience" as a clear.
  Choosing Public is how to remove a restriction.
- **Selecting nothing is refused, not saved.** Saving a set audience with
  nothing ticked keeps the previous setting and says which list needs a choice.
  On an Elementor element, selecting none hides the element from everybody.
- **Platform capabilities are labelled.** An entitlement the platform itself
  defines, Directory access for example, reads "Platform: Directory access" in
  the panel, the Elementor control and the display conditions.

### The editor catalogue

The lists an editor chooses from are fetched on the WordPress server and
reduced there, so the API key never reaches the browser and no pricing, member
count or benefit detail does either. One cached copy of each list serves the
page panel, the Elementor controls, the display condition vocabularies and the
REST route, so a list costs one gateway call per cache lifetime however many
surfaces show it.

`GET /wp-json/agend-content-access/v1/catalogue` (requires `edit_posts`; add
`?refresh=1` to bypass the cache) returns the three lists, each as
`{items, stale, error}`:

```json
{
  "plans":        {"items": [{"id": "...", "name": "...", "slug": "...", "type": "...", "active": true}], "stale": false, "error": ""},
  "segments":     {"items": [{"id": "...", "slug": "...", "name": "..."}], "stale": false, "error": ""},
  "entitlements": {"items": [{"gate_key": "...", "name": "...", "description": "...", "kind": "platform-gate"}], "stale": false, "error": ""}
}
```

`stale` means a refresh failed and the items are the last known copy. `error`
says why, in words an editor can act on. The lists fail independently, so an
API key without one scope still gets the others. The earlier `GET .../plans`
route is unchanged.

| List | Core function | API key scope | Cached under |
| --- | --- | --- | --- |
| Plans | `agend_apps_crm_get_tiers()` | `crm.tiers.browse` | `agend_content_access_plan_catalogue` |
| Segments | `agend_apps_crm_get_segments()` | `crm.segments.browse` | `agend_content_access_segment_catalogue` |
| Entitlements | `agend_apps_crm_get_entitlement_types()` | `crm.entitlements.browse` | `agend_content_access_entitlement_catalogue` |

Segments and entitlements are cached for the member facts lifetime, at most 15
minutes. A failure is never cached: the list is served from the last known copy
if there is one, and fills the moment the cause is fixed.

What an editor sees when a list is empty:

| Cause | Message |
| --- | --- |
| Agend Apps Core is older than 1.48.0 and has no wrapper | "Agend Apps Core 1.48.0 or later is needed to list entitlements (or segments). Update Agend Apps Core on this site." |
| The gateway, or Core's own check of the key, refused with a 403 | "This site's Agend API key needs the crm.entitlements.browse (or crm.segments.browse) scope before it can be listed. Add the scope to the key in Agend." |
| Anything else | "The list of entitlements (or segments) could not be loaded from Agend. Try again in a few minutes." |

The wrapper for entitlement types is not a hard dependency: an older Core
leaves the entitlement list empty with that message rather than switching
protection off. The transport message Core reports is deliberately not shown.

The gate shown to a signed-in visitor outside an entitlements policy names the
entitlements, as a plan gate names plans, because an entitlement is something a
member can acquire. A row with no name of its own is skipped rather than shown
by its key. A segments policy still names nothing, and never reads the segment
list for a gate.

## Failure behaviour

Fail closed, without exception. An empty or malformed policy restricts. An
unresolvable tier never grants. An entitlement lookup failure redacts and logs.
A missing dependency registers nothing rather than half-registering, because a
half-registered access-control plugin is indistinguishable from one that decided
the visitor may proceed. A stored policy the plugin cannot read, such as a mode
written by a newer release, restricts the page to nobody rather than reading as
unset. The same holds for the exemptions: a request counts as authoring only when
it can be shown to be, and a stored document that cannot be read counts as
carrying rules, so doubt always lands on the side of withholding. 0.1.3 is the
minimum safe version to roll back to from 0.2.0: 0.1.0 served an unreadable
policy as public, and 0.1.0 to 0.1.2 all had the authoring and REST exposures
described below.

## Where a restricted post is gated

A restricted post is gated wherever its body or auto-generated excerpt is
rendered, not only on its own URL: the blog index, archives, search results,
widgets, feeds and the query loops of block themes. A manual excerpt is the only
public teaser, because it is an editor's deliberate summary; an auto-generated
one is the opening of the protected body, so it is never published. Every policy
mode is gated this way, including segments and selected entitlements, and the
gate names entitlements exactly as the post's own page does. Feeds are always
evaluated as an anonymous reader, because a feed is fetched without a session
and shared through caches. A feed therefore never asks Agend for a member's
standing or segments.

A listing costs no more gateway calls than a single post. However many
restricted posts a loop renders, the visitor's standing is asked of Agend at
most once per request, and their segments at most once, through the same
per-request source as every other decision. The plan, segment and entitlement
lists a gate names are likewise asked of Agend at most once each per request, a
failed ask included, so a gateway outage costs a listing one timeout rather than
one per post. That memory lasts only for the request: a failure is still never
written to the cache, and the editor's `refresh` still asks again.

When a restricted post's body is shown to a member anywhere on a page, that page
is marked uncacheable (`DONOTCACHEPAGE`, and `Cache-Control: private, no-store`
while headers can still be sent), so a page cache cannot serve a member's copy to
anonymous visitors. The `agend_content_access_uncacheable_render` action fires
for cache integrations that do not read the constant.

The Latest Posts block is the exception that cannot be gated after the fact. Its
"full post" display prints `post_content` itself, from the database row, so no
content filter is ever offered the body. For anyone who is not authoring it is
rendered in excerpt mode instead, and the excerpt is gated. **The cost is
deliberate:** a Latest Posts block set to full posts shows excerpts to every
visitor, restricted or not. Listing only the posts that need it would mean
re-running the query core builds from the block's attributes, and a copy that
drifted from core would fail open. To print full posts in a list, use a Query
Loop block, whose content is gated. Only the display mode is changed, so a Latest
Posts block that carries a rule of its own is still removed for a visitor who
fails it.

### What counts as authoring

Gating stands down for somebody who is authoring, and only for them. That is a
wp-admin screen, the block editor's own server-side renders (see Blocks),
Elementor's editor, Elementor's own editor AJAX (rendering a widget, fetching a
template), and Elementor's preview iframe.

- **The preview flag is trusted only on the page it names.** The
  `?elementor-preview=<id>` argument is a query-string value anyone can append to
  any URL, and Elementor only checks that the user may edit the id it carries. So
  it counts only when that id is a positive integer equal to the post the request
  serves. Otherwise a contributor could open a restricted page with the id of
  their own draft appended and read it, restricted blocks and sections included.
- **admin-ajax.php is not authoring.** WordPress reports `is_admin()` for it, but
  other plugins render Elementor templates through it for anonymous visitors
  ("load more", filters, tabs). Sections and blocks are judged there as a
  visitor's. Only Elementor's own editor requests are exempt.

### Section rules and the raw body

A rule on a block or an Elementor section hides it from the rendered page. The
raw body keeps everything. A block's text stays in the post's markup beside its
rule, and Elementor writes the text of every section into `post_content` when a
document is saved, without consulting the rules. Anything that reads the raw body
therefore sees the restricted text. For a post whose document carries a block or
section policy, or enabled display conditions:

| Reader | Behaviour |
| --- | --- |
| Core search, the REST `search` parameter, `/wp/v2/search` | The post is left out for anyone who cannot edit others' posts. `agend_content_access_search_excludes_fragment_rules` (default true) turns this off |
| Automatic excerpts | The same for every viewer, unless the post has a written excerpt: a block document's is rebuilt without its ruled blocks, an Elementor document has none. `agend_content_access_safe_generated_excerpt` is the block half |
| REST `context=edit` | `content.raw` and `content.block_version` are removed for anybody who cannot edit that post |

The posts are found by the `_agend_access_fragment_rules` meta, set when a post is
saved, when Elementor saves a document, and when an Elementor document is written
without a save, by an importer or WP-CLI. The key is protected and never shown in
REST. Posts saved before the flag existed have none, so it converges on its own:
an administrator loading wp-admin flags a hundred posts at a time until the whole
table has been covered. To finish at once, or to look at every post again, for
instance after editing a synced pattern that many pages use:

```sh
wp agend-content-access backfill-fragment-flags
wp agend-content-access backfill-fragment-flags --batch-size=500
```

A stored Elementor document that cannot be read counts as carrying rules, since
"I could not tell" is not "there are none".

The REST edit context is trusted per post, not per request. WordPress allows
`context=edit` on a collection to anybody holding `edit_posts`, which a
contributor has, so it returns the raw body of every published post. It is
honoured for a post only when the requester can `edit_post` that post. For any
other post a restricted body is withheld as it is in the view context, a post a
requester may read but not edit keeps its rendered body and loses the raw one,
and a post that is plainly public and carries no rules is left alone. This is the
same per-post rule the block editor's renders use, so a post's body, its
footnotes and its raw source agree on who is authoring it.

A replacement shown by a display condition, in a block or in an Elementor
section, is never a password-protected post. Its content is rendered without the
password prompt, so it is refused, and the picker, in Elementor and in the block
editor panel, does not offer one.

**Not covered:**

- Code that reads `$post->post_content` or calls `get_the_content()` directly
  bypasses every content filter, and so this gate.
- Third-party search and indexing plugins, and anything else that reads raw
  `post_content` when a post is saved, such as a sync to an external search
  service. The text of a post's restricted blocks and sections is in what they
  index, and the exclusions above cannot reach it.
- SEO plugins that generate their own meta descriptions, social previews or
  structured data from the raw body. They do not go through `get_the_excerpt`,
  so a restricted block or section can be quoted in a page's `<head>`. Set a
  written excerpt, or configure the plugin's description, on such pages.
- Search results for authors and contributors. They cannot tell themselves from
  a visitor in a query, so they cannot find their own pages with rules by text
  either.
- An Elementor preview of a post is an authoring request for the whole request.
  Other posts rendered inside the previewed post, such as a loop, are not gated
  while it is open, so anybody who can edit a post can print what is inside it.

## Blocks

A block carries its audience and its display conditions in one attribute,
`agendAccess`, and a block the visitor may not see is removed before it renders.
The enforcement reads the attribute wherever it came from (the editor panel,
hand-written markup, an import, the REST API), so a restriction does not depend
on how it was typed.

**Where.** On `pre_render_block`, registered first, on every request: the front
end, REST and the loop renders of a block theme alike. A non-empty return skips
the block AND its inner blocks, so a denied Group never constructs its children,
and no `render_callback` runs for content the visitor may not read. That is the
enforcement point, because `render_block` filters output that was already
produced.
A block with no policy and no enabled conditions returns before any identity
lookup, so a page that uses none of this pays nothing and asks Agend nothing.

**The backstop.** Navigation, Navigation Submenu, Navigation Link and Widget Group
render their children by calling `$inner_block->render()` from their own render
callback, which never asks `pre_render_block`. A `render_block` filter, registered
first, applies the same decision to the blocks that skipped it, and returns `''`
(or the display condition fallback) for a denied one. It is a backstop: the block
has already rendered, so it guarantees that no output of a denied block is
returned, not that nothing ran. A block the main path already allowed is not
decided again.

**What a block is judged against.** The INTERSECTION of the policy of the post it
belongs to and its own, by the same rules as an Elementor section: it can narrow
what its post allows and can never widen it. "The post it belongs to" is the post
WordPress is rendering (`get_post()`), never the queried object, because blocks
render inside query loops, template parts and synced patterns. A restricted
post's block in a loop on a public page is judged against that post, and a
public post's block is not narrowed by the restricted page it appears on.

Outside a loop the global post is only what the main query left behind: the
first post of the archive, or the last one once a loop has ended. A header or
footer template part is therefore judged against the queried post on a singular
request and against no post otherwise, on its own policy alone. A custom query's
loop, which is what a Query Loop block with its own query runs, is still a loop,
even though `in_the_loop()` is false there: the global post counts as left behind
only when it is one of the main query's own post objects.

**Feeds are judged for nobody.** A feed's output is shared and cached whoever
fetched it, so in a feed a block's policy, its display conditions and its fallback
are all judged as for a signed-out visitor, as the page gate judges a feed's
document. A feed never asks Agend for anybody's standing.

**Fails closed.** An unrecognised mode, an attribute that is present and is not an
object, a set-mode with nothing valid selected, a malformed condition, an unknown
condition provider (inverted or not) and a check that throws all deny. An
unrecognised mode reads as members only, as it does for Elementor.

**Display conditions and fallbacks.** Conditions are evaluated first and can only
remove. A block they exclude may show replacement content, and only content the
visitor could read on its own: a fallback that is itself restricted renders
nothing. A block hidden by its policy gets no fallback. A fallback that names
itself ends rather than recursing.

**Authors are never filtered.** The block editor renders server-side over REST, so
those requests are recognised as authoring, by
`Agend_Content_Access_Frontend::is_block_editor_request()`. ALL of these hold:

1. WordPress is serving a REST request. A page URL with `?context=edit` is not.
2. The user can `edit_posts`. A query arg, a header or an unauthenticated REST
   call cannot supply this.
3. The route is `/wp/v2/block-renderer/...`, or the request asks for
   `context=edit`.
4. If a post is being rendered, the user can edit that post. Listing other
   people's posts with `context=edit` is not authoring them. With no post in the
   request (a block renderer call with no `post_id`) the user must be able to
   `edit_others_posts`, because such a call can render a template part or a
   pattern that is not theirs.

wp-admin screens are authoring for users who can `edit_posts`, and an
admin-ajax render is always a visitor's, because "load more" and live filters
print blocks to anonymous visitors through it. An editor viewing the front end
is judged like anyone else, as for Elementor, apart from the preview iframe.

**Page caches.** A response is never stored if the post it renders holds a
restricted or conditioned block anywhere, synced patterns included. That is
decided on `template_redirect` for a singular request, by scanning the queried
post's blocks, and again at render time whenever a restricted or conditioned
block is evaluated, which covers loops, template parts and widgets. Both set
`DONOTCACHEPAGE` and `Cache-Control: private, no-store` (while headers can still
be sent) and fire `agend_content_access_uncacheable_render`. Anonymous renders
are marked too: the page is per-viewer for everybody, a member is often not
signed in to WordPress, and a cache keyed on the URL cannot tell them apart.

**Search.** Core search matches the raw `post_content`, so a hit on protected
words would reveal that they are there. A document carrying a block or an
Elementor section with a policy or enabled display conditions is flagged on save
(`_agend_access_fragment_rules`, set for both editors, and again when an Elementor
document is written without a save) and left out of any search
query for users who cannot `edit_others_posts`. A contributor can search every
published post, so `edit_posts` is not enough. That covers the main search and
the queries behind the REST `?search=` parameter and `/wp/v2/search`. The
exclusion runs last on `pre_get_posts`, so no other callback can overwrite it. It
also hides the post's public parts from search, so it can be turned off with the
`agend_content_access_search_excludes_fragment_rules` filter (default true).

**Generated excerpts.** When no excerpt was written, core builds one by
rendering the body, and it renders a Group, Columns or Column block's children
without the wrapper, so a restriction on the wrapper is never asked about. For a
document that carries rules and has no manual excerpt, the generated excerpt is
replaced by one that is the same for every viewer: a block document's is rebuilt
from its own tree with every restricted or conditioned block removed (synced
patterns included) and cut by WordPress's own excerpt code, and an Elementor
document has none, because the text Elementor stores in `post_content` is a
flattened copy of everything it rendered. A manual excerpt is the editor's
deliberate public text and is left alone. The filter is
`agend_content_access_safe_generated_excerpt`.

**Footnotes.** A footnote's text lives in the post's `footnotes` meta, not in the
paragraph that cites it, and core prints it in the Footnotes block and exposes it
as `meta.footnotes` in REST. For a post that holds a restricted block, the
footnotes anchored inside a block the viewer is denied are removed from both. A
member keeps the notes of the blocks they may read. It fails closed: a post with a
restricted block and an unreadable footnote list returns none, and so does an
entry with no id when anything was denied. The list is not renumbered. REST also
omits `meta.footnotes` for a document that is denied outright.

**Not covered.**

- Third-party search indexers (Yoast, RankMath, ElasticPress and similar) that
  read raw `post_content` at save serve it through their own search.
- The search flag is set when the post is saved (and, for Elementor, when it has
  stored the document). Editing a synced pattern later does not update the pages
  that use it until each is saved again, and a reference past the 50 per document
  the tree check resolves is not followed. A post saved before it held a
  restricted block is not flagged, until the backfill (above) looks at it again.
  Rendering, cache marking and the generated excerpt do not depend on the flag
  alone: the excerpt scans the live tree.
- Code that reads `$post->post_content` or calls `get_the_content()` directly
  bypasses the render pipeline, as it does for every gate here.
- A block with a rule in a template part, or in other posts a loop lists, is not
  in the page's own content. Rendering enforces it and marks the page at render
  time, but the early cache decision and the search flag do not see it.
- An author whose role lacks `edit_posts` sees their own restricted blocks
  filtered in the editor, and so does a user who can only write their own posts
  in a server-side preview that carries no post id.
- The Footnotes block rendered from a template for a post whose whole document is
  denied, and a read of every meta key at once (`get_post_meta( $id )`), return the
  stored footnotes. Third-party code that reads the `footnotes` meta through a
  raw query is outside the filter.

## Member standing cache

A visitor's membership standing comes from one source,
`Agend_Content_Access_Viewer_Facts`, shared by document policies and display
conditions. It asks `GET /v1/crm/me/entitlements` at most once per request,
and holds the answer per member for the cache lifetime: Core's
`crm_me_entitlements` setting. Its default is 300 seconds (five minutes) from
Core 1.48.0, which registers the key; on an older Core the key is unregistered,
so Core falls back to 60 seconds. Whatever the setting says, the lifetime is
never longer than 900 seconds (fifteen minutes), because it now decides document
gating and the setting has no upper bound of its own. When the
gateway reports an entitlement expiry, the lifetime is cut so the entry does
not outlive it, to what is left with no floor. An expiry that has already
passed, or is less than a second away, is not cached at all: a grant that has
ended must not be held, and a transient lifetime of 0 would mean "never expires"
in WordPress. An expiry that is not a readable date-time is treated as none.

This changes one behaviour. A document policy used to ask Agend on every
request, so a revocation applied on the next page load. Now a revocation that
fires no webhook takes up to the cache lifetime to apply. Display conditions
already worked that way. Webhooks (next section) make the cache forget sooner
and never remember longer, and the lifetime stays as the backstop.

Rules the cache keeps:

- It is per member and per bearer. The key carries the WordPress user id, a
  digest of the bearer the answer was fetched with, and two generation numbers:
  a global one from the `agend_content_access_facts_generation` option, so one
  bump invalidates every member at once, and the member's own, in the
  `_agend_content_access_facts_gen` user meta, which a flush increments so a
  webhook (which has no bearer) still reaches every entry for that member. The
  bearer is in the key because the user id alone is not the identity the gateway
  answered for: after a re-link, or with a bearer provider not tied to the
  WordPress user, an entry fetched under the old bearer would otherwise be served
  for the new one. The key is built through Core's `Agend_Apps_Cache::build_key()`
  under `crm_me_entitlements`, so Core's Cache tab "Clear" button clears these
  entries too.
- A visitor with no WordPress user id is never cached, even when a bearer token
  reaches the plugin through the `agend_apps_bearer_token` filter. One shared
  key would serve every such visitor the same standing.
- A visitor with no bearer is never read from or written to the cache either.
  There is no identity to key an entry on, and an entry written under an earlier
  bearer would be stale.
- A failed lookup is never cached. It reads as unknown and denies. A 2xx whose
  body is not the documented answer counts as a failure: `success` false, no
  `data` array, no `tier_ids` array (or, for segments, no list under `data`).
  An empty array, which Core returns for a 204, would otherwise read as "holds
  nothing", be cached, and let an inverted condition pass.
- A cached entry is checked field by field before it is used. One with a field of
  the wrong type is a miss and is replaced, never repaired, so a malformed entry
  in the transient store cannot reach a condition.

## Webhooks

Agend can tell WordPress that something changed, so a cached answer is dropped
sooner than its lifetime. Subscribe the event types below to the receiver Agend
Apps Core already provides, `/wp-json/agend-apps/v1/webhooks/incoming`, from the
Agend dashboard. Core verifies the signature, drops repeat deliveries and handles
`crm.membership.*` and `crm.seat.*` for its own membership snapshot, then hands
every verified delivery on. This plugin adds no route and no secret of its own.

| Event type | What it clears |
| --- | --- |
| `crm.membership.created`, `updated`, `cancelled`, `renewed`, `reinstated`, `lapsed` | That member's cached facts |
| `crm.seat.added`, `updated`, `removed` | That member's cached facts |
| `crm.entitlement.granted`, `crm.entitlement.revoked` | That member's cached facts |
| `crm.contact.attributes_changed` | That member's cached facts |
| `crm.segment.updated` | Every member's cached facts, and the segment list in the editor pickers |
| `crm.benefit.changed` | Every member's cached facts, and the entitlement list in the editor pickers |
| `crm.contact.bulk_updated` | Every member's cached facts |

How it works:

- A member event carries `data.contact_id`. It is used only to find the WordPress
  users whose `_agend_apps_contact_id` meta (which Core writes at login) holds that
  id, and each one's facts are forgotten. At most 50 users are flushed per event.
  A contact who has never signed in to this site matches nobody, which is normal.
- An event for the whole account (a segment's rules, a benefit, or a bulk edit
  too large to send per contact) forgets every member at once.
- The next request that needs the facts asks Agend again with the member's own
  token. Nothing in a payload is stored or trusted: a `granted` event for a key
  Agend does not then report leaves the member without it. A forged, replayed or
  out of order delivery can only make WordPress forget, never grant.
- A malformed delivery, an unknown event type or a contact id that is not a UUID
  is ignored without an error, so Agend never retries a delivery because of this
  plugin. The plan list in the editor is not cleared, because no event describes
  a plan change.

The cache lifetime is still the backstop, and it is the only thing that covers
changes that fire no event: a grace period ending, a grant reaching its expiry,
a segment rule with a relative date ("renewal within 30 days") admitting or
dropping a member as time passes, and imports and merges. That lifetime is
Core's `crm_me_entitlements` setting, never more than 15 minutes.

Hooks for developers:

- `agend_content_access_webhook_contact_events` filters the events that forget one
  member, as `array( 'exact' => string[], 'prefixes' => string[] )`. The event
  must carry `data.contact_id`.
- `agend_content_access_webhook_account_events` filters the events that forget
  everyone, as a list of exact event ids.
- `agend_content_access_facts_invalidated` fires after facts were forgotten, with
  the scope (`user` or `account`), the event type and the WordPress user ids
  flushed. It does not fire when a member event matched nobody.

## Running the tests

From the repository root:

```sh
composer install
composer test                        # the whole collection
vendor/bin/phpunit --testsuite agend-content-access
node --test agend-content-access/tests/js/access-model.test.mjs agend-content-access/tests/js/lists-loader.test.mjs
```

These are UNIT tests: no database and no WordPress bootstrap. WordPress
functions are stubbed in `tests/wp-stubs.php`, which keeps the suite fast and
removes the wp-tests install step that usually stops WordPress plugin suites
from being run at all. Anything needing real WordPress behaviour (hook ordering
across plugins, actual REST dispatch, database state) belongs in an integration
suite against a real install, which does not exist yet.

## Status

Under construction.

| Story | State |
| --- | --- |
| US-3.1 scaffold and dependency gate | done |
| US-3.3 membership plan catalogue | done |
| US-3.2 native post and page policy panel | done |
| US-3.4 direct WordPress request gating | done |
| US-3.5 source connector | done |
| US-4.1 / US-4.2 Elementor fragment policies | done |

Policies are authored and enforced on the WordPress side, and the source
connector exposes them to Agend behind a rotatable service credential
(Settings, Agend Content Access). Still missing: US-3.6, the gateway write
surface that receives them, so nothing is ingested yet.
