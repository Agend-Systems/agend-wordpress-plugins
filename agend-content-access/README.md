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

## What this plugin owns

Document policies, Elementor fragment policies, the source connector, direct
WordPress request gating, protected assets, and the legacy migration.

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
unset, and 0.1.1 is the minimum safe version to roll back to from 0.2.0, because
0.1.0 served such a page as public.

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

**Not covered:** code that reads `$post->post_content` or calls
`get_the_content()` directly bypasses every content filter, and so this gate. So
does core search, which matches the terms against the raw `post_content`: a
restricted post can be found by a word only its body contains, although the
result then shows only the gate.

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
