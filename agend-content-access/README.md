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
`membership_required` and `plan_required`. Its gate copy is generic: it never
names a segment, and it offers no join or upgrade link, because nothing to
join unlocks it. A segment is a set over all contacts and an entitlement can be
held without a membership, so neither is a subset of "all active members", and
a fragment cannot narrow a document of a different set-mode: that pairing
admits nobody.

Entitlement keys arrive with the member's standing at no extra cost. Segments
are fetched, once per request, only when a policy being evaluated is a segments
policy. The editor controls for the two new modes arrive in a later release.

## Failure behaviour

Fail closed, without exception. An empty or malformed policy restricts. An
unresolvable tier never grants. An entitlement lookup failure redacts and logs.
A missing dependency registers nothing rather than half-registering, because a
half-registered access-control plugin is indistinguishable from one that decided
the visitor may proceed.

## Member standing cache

A visitor's membership standing comes from one source,
`Agend_Content_Access_Viewer_Facts`, shared by document policies and display
conditions. It asks `GET /v1/crm/me/entitlements` at most once per request,
and holds the answer per member for the cache lifetime: Core's
`crm_me_entitlements` setting when it is set, otherwise five minutes. When the
gateway reports an entitlement expiry, the lifetime is capped so the entry does
not outlive it (never below 30 seconds).

This changes one behaviour. A document policy used to ask Agend on every
request, so a revocation applied on the next page load. Now a revocation that
fires no webhook takes up to the cache lifetime to apply. Display conditions
already worked that way. Webhook invalidation, which makes the cache forget
sooner and never remember longer, arrives in a later release, and the
lifetime stays as the backstop.

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
- A failed lookup is never cached. It reads as unknown and denies.

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
