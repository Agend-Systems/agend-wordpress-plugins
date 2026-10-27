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
| `Agend_Apps_Cache` / `Agend_Apps_Settings::get_cache_ttl()` | Caching |

Reaching into Core internals rather than these is a review failure.

## Failure behaviour

Fail closed, without exception. An empty or malformed policy restricts. An
unresolvable tier never grants. An entitlement lookup failure redacts and logs.
A missing dependency registers nothing rather than half-registering, because a
half-registered access-control plugin is indistinguishable from one that decided
the visitor may proceed. A stored policy the plugin cannot read, such as a mode
written by a newer release, restricts the page to nobody rather than reading as
unset, and 0.1.1 is the minimum safe version to roll back to from 0.2.0, because
0.1.0 served such a page as public. The same holds for the exemptions: a request
counts as authoring only when it can be shown to be, and a stored document that
cannot be read counts as carrying section rules, so doubt always lands on the
side of withholding. 0.1.3 is the minimum safe version for the authoring and REST
exposures described below, which 0.1.0 to 0.1.2 all had.

### Where a restricted post is gated

A restricted post is gated wherever its body or auto-generated excerpt is
rendered, not only on its own URL: the blog index, archives, search results,
widgets, feeds and the query loops of block themes. A manual excerpt is the only
public teaser, because it is an editor's deliberate summary; an auto-generated
one is the opening of the protected body, so it is never published. Feeds are
always evaluated as an anonymous reader, because a feed is fetched without a
session and shared through caches. That includes the Elementor sections inside an
item: their access policy, display conditions and replacement content are judged
as a signed-out visitor, whoever fetched the feed.

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
Loop block, whose content is gated.

### What counts as authoring

Gating stands down for somebody who is authoring, and only for them. That is a
wp-admin screen showing a post its user may edit, Elementor's editor, Elementor's
own editor AJAX (rendering a widget, fetching a template), and Elementor's
preview iframe.

- **A wp-admin screen is authoring only for the posts its user can edit.**
  `is_admin()` says where a request is, not who is asking. The Posts list in its
  extended view, a dashboard widget or a metabox prints the excerpt or body of
  posts a contributor cannot edit, so the carve-out is the right to edit the post
  being rendered (`edit_post`). Where no post is named, such as a Latest Posts
  block, it is the right to edit other people's posts (`edit_others_posts`).
- **The preview flag is trusted only on the page it names.** The
  `?elementor-preview=<id>` argument is a query-string value anyone can append to
  any URL, and Elementor only checks that the user may edit the id it carries. So
  it counts only when that id is a positive integer equal to the post the request
  serves. Otherwise a contributor could open a restricted page with the id of
  their own draft appended and read it.
- **The preview is authoring for the previewed post only.** Every other post
  rendered during a preview request, such as a query loop listing restricted
  posts, a related posts widget or a template a shortcode pulls in, is what a
  visitor to the same page would be shown, and is gated like it. Otherwise anybody
  who can edit one post could print any restricted post into it and open the
  preview to read it. Elementor's editor itself stays authoring for every post,
  and an element whose Elementor document cannot be identified is treated as a
  visitor's.
- **admin-ajax.php is not authoring.** WordPress reports `is_admin()` for it, but
  other plugins render Elementor templates through it for anonymous visitors
  ("load more", filters, tabs). Sections are suppressed there. Only Elementor's
  own editor requests are exempt, and only for a document the user may edit. One
  editor request can render several documents, such as a template widget or a
  listed post, so the exemption is judged for the document being rendered, not
  for the one the request names. A render Elementor does not place in a document
  is a visitor's.

### Section rules and the raw body

A section rule hides one section of a public page. The rendered page withholds
it, but Elementor writes the text of every section into `post_content` when a
document is saved, without consulting the rules. Anything that reads the raw body
therefore sees the restricted text. For a post whose document carries a fragment
policy or enabled display conditions:

| Reader | Behaviour |
| --- | --- |
| Core search, the REST `search` parameter, `/wp/v2/search` | The post is left out for anyone who cannot edit others' posts. `agend_content_access_search_excludes_fragment_rules` (default true) turns this off |
| Automatic excerpts | Empty, unless the post has a written excerpt. Decided from the document, so it holds before the flag exists. `agend_content_access_safe_generated_excerpt` lets an integration for another editor supply a rebuilt one; it defaults to an empty string |
| REST `context=edit` | `content.raw` is removed for anybody who cannot edit that post |

The posts are found by the `_agend_access_fragment_rules` meta, set when a post is
saved, when Elementor saves a document, and when the document meta is written
directly. The key is protected and never shown in REST. Posts saved before 0.1.3
have no flag, so it converges on its own: an administrator loading wp-admin
flags a hundred posts at a time until the whole table has been covered. To finish
at once, or to run it again after installing an integration for another editor:

```sh
wp agend-content-access backfill-fragment-flags
wp agend-content-access backfill-fragment-flags --batch-size=500
```

The REST edit context is trusted per post, not per request. WordPress allows
`context=edit` on a collection to anybody holding `edit_posts`, which a
contributor has, so it returns the raw body of every published post. It is
honoured for a post only when the requester can `edit_post` that post. For any
other post a restricted body is withheld as it is in the view context, a post a
requester may read but not edit keeps its rendered body and loses the raw one, and
a post that is plainly public and carries no section rules is left alone.

A replacement shown by an Elementor display condition is never a password-
protected post. Its content is rendered without the password prompt, so it is
refused, and the picker does not offer one.

**Not covered:**

- Code that reads `$post->post_content` or calls `get_the_content()` directly
  bypasses every content filter, and so this gate.
- Core search matches its terms against the raw `post_content` of a restricted
  post too: it can be found by a word only its body contains, although the result
  then shows only the gate.
- Third-party search and indexing plugins, and anything else that reads raw
  `post_content` when a post is saved, such as a sync to an external search
  service. The text of a post's restricted sections is in what they index, and
  the exclusions above cannot reach it.
- SEO plugins that generate their own meta descriptions, social previews or
  structured data from the raw body. They do not go through `get_the_excerpt`,
  so a restricted section can be quoted in a page's `<head>`. Set a written
  excerpt, or configure the plugin's description, on such pages.
- Search results for authors and contributors. They cannot tell themselves from
  a visitor in a query, so they cannot find their own section-restricted pages
  by text either.

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
