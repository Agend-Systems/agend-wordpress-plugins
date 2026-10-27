# Plan: block editor restrictions, segments and entitlements for Agend Content Access

Status: approved for build, 2026-10-27
Subject: `agend-content-access` 0.1.0, `agend-apps-core` 1.47.0, the Agend gateway
Builds on: `docs/REVIEW-content-access-editor-agnostic.md` (read it first; this plan
takes its stages and records the decisions it left open)
Related: SPEC-CMS-20260727-wordpress-member-content-access,
SPEC-CRM-20260805-member-entitlement-grants

## Summary

Three additions to Agend Content Access, built together because they share one
foundation:

1. **Two new audiences.** "Selected segments" and "Selected entitlements" join
   public, all active members and selected plans, on every surface that sets an
   audience: the page panel, Elementor elements and Gutenberg blocks. Display
   conditions gain an Entitlements group beside the Segments group they already
   have.
2. **Gutenberg blocks.** Every block gets the same Agend Access controls an
   Elementor element has, enforced on the server.
3. **Webhook invalidation.** The dashboard tells WordPress when a member's
   entitlements or segment-relevant details change, so WordPress drops its cached
   copy of that member's facts at once rather than waiting out the cache lifetime.

## Decisions taken

| # | Decision | Chosen |
| --- | --- | --- |
| 1 | Where segments and entitlements apply | Both layers: as access policy modes (Agend's vocabulary) and as display conditions |
| 2 | Source of the entitlement picker | A new gateway read route, `GET /v1/crm/entitlements/types` |
| 3 | Scope that route requires | A new `crm.entitlements.browse` scope. Site API keys need it added before the picker fills |
| 4 | How Gutenberg restrictions attach | Every block, in place, with a sidebar panel. A Group block serves as a container |
| 5 | Webhook model | Trigger and re-read. The event only locates the member; WordPress clears its cache and asks Agend again |
| 6 | Caching of access decisions | One shared, per-member cache for page policies, block policies and display conditions, cleared by webhook, with a five minute backstop |

Decision 5 follows the model Core's webhook receiver already uses for
`crm.membership.*` events. Decision 6 changes one existing behaviour, stated
plainly: today a page policy asks Agend on every request, and after this change a
revocation that fires no webhook takes up to the cache lifetime to apply. Display
conditions already work that way.

## Why webhooks cannot be the only sync

Several changes to what a member can see happen with no write in the CRM, so no
event can fire for them:

- a membership grace period ends (`has_entitlement` is evaluated at read time;
  the `crm.membership.lapsed` sweep runs every six hours);
- a direct entitlement grant reaches its `expires_at`;
- a segment rule with a relative date ("renewal within the next 30 days")
  admits or drops a member as the clock moves;
- an administrator changes which benefits a tier includes, which changes every
  member of that tier at once.

A model where WordPress stored pushed data and trusted it would therefore keep
granting access after a lapse whenever an event did not exist or was missed.
That fails open. So the cache lifetime remains the guarantee and the webhook is
an accelerator: it can only make WordPress forget sooner, never grant longer.

## 1. Policy vocabulary

### Shapes

Two arms join the canonical policy shapes in `Agend_Content_Access_Policy`. They
are copied from `@agend/access-policy` (`packages/@agend/access-policy/src/types.ts`
and `fragments.ts` in the gateway), which is the authority:

```json
{"mode":"segments","segment_ids":["<uuid>", ...]}
{"mode":"selected_entitlements","entitlement_keys":["<gate_key>", ...]}
```

- `segment_ids` are segment UUIDs, non-empty, UUID-shaped, no extra keys.
- `entitlement_keys` are `crm_benefits.gate_key` values, non-empty, each matching
  `^[a-z][a-z0-9_.]{1,62}[a-z0-9]$`, no extra keys.
- Both are lists, not maps, exactly as `tier_ids` already is.

The gateway also defines an `authenticated` arm. It is out of scope here and stays
unparseable on the WordPress side.

### Composition (fragment against document)

`effective_policy()` must agree with `effectivePolicy()` in
`packages/@agend/cms/src/policy/fragments.ts`, because a visitor must not see
different things through WordPress and through the Agend API:

- Same set-mode on both sides intersects: tiers with tiers, segments with
  segments, entitlement keys with entitlement keys. An empty intersection is a
  policy nobody satisfies.
- These pairs cannot be intersected and collapse to `{"mode":"selected_tiers","tier_ids":[]}`,
  which nobody satisfies:
  - two different set-modes (`selected_tiers`, `segments`, `selected_entitlements`)
    in either direction;
  - `active_member` against `segments` or `selected_entitlements`, in either
    direction. A segment selects over all contacts and a direct grant needs no
    membership, so neither set contains the other.
- Otherwise the narrower of the two wins, and a tie keeps the document's.
  Restrictiveness: public, then active_member, then the three set-modes, which
  rank equally.

### Decision states

The gateway's state vocabulary has a fifth state, `access_restricted`, which
WordPress adopts:

| Policy | Unidentified visitor | Identified, outside the set |
| --- | --- | --- |
| `segments` | `authentication_required` | `access_restricted` |
| `selected_entitlements` | `authentication_required` | `access_restricted` |

`access_restricted` gets its own gate copy. It never names a segment: segment
names can describe the visitor ("Overdue renewals") and are not the visitor's
business. Entitlement names may be shown, the same way plan names are, because
an entitlement is something a member can acquire.

## 2. Member facts

### One source

`Agend_Content_Access_Decision::viewer()` and
`Agend_Content_Access_Condition_Runtime::member_facts()` both call
`GET /crm/me/entitlements` today, with separate caches. They are replaced by one
request-memoised, per-member cached source holding:

| Fact | From |
| --- | --- |
| `identified`, `degraded` | bearer presence and lookup outcome, as `Decision::viewer()` defines them today |
| `is_member`, `tier_ids`, `tier_slugs` | `GET /crm/me/entitlements` |
| `entitlement_keys` | the same response, `data.capabilities[].gate_key` |
| `segment_ids`, `segment_slugs` | `GET /crm/me/segments`, fetched only when a policy or condition on the page needs segments |

Rules carried over unchanged:

- A signed-out visitor holds nothing and costs no network call.
- A failed lookup is never cached, returns "unknown", and denies.
- The cache is per member, never shared.

### Cache lifetime and invalidation

- Transient key: the member's WordPress user id, a digest of the bearer the
  answer was fetched with, a **generation number** stored in an option, and the
  member's own generation in user meta. Bumping the global generation
  invalidates every member at once without iterating users; flushing one member
  increments their own, which reaches every bearer's entry without needing to
  know one. The key is built through `Agend_Apps_Cache::build_key()` under
  `crm_me_entitlements`.
- Lifetime: the configured value (`crm_me_entitlements`, registered in
  `Agend_Apps_Cache` so the setting actually applies). The default is 300
  seconds from Core 1.48.0; on an older Core the key is unregistered and Core
  falls back to 60 seconds. Never above 900 seconds, whatever the setting says,
  because it now governs document gating. When the gateway reports
  per-capability `expires_at`, the lifetime is cut so the entry expires no later
  than the earliest one, and an expiry already past is not cached at all.
- `flush_member_facts( $user_id )` clears one member.

## 3. Webhooks

### Gateway events (new)

| Event | Scope | Payload | Fired when |
| --- | --- | --- | --- |
| `crm.entitlement.granted` | one contact | `{contact_id, gate_keys[], source_key, granted_via}` | a grant is created or reinstated, by the reconcile RPC, staff in the CRM, or cart fulfilment |
| `crm.entitlement.revoked` | one contact | `{contact_id, gate_keys[], source_key, granted_via}` | a grant is revoked by any of the same paths |
| `crm.contact.attributes_changed` | one contact | `{contact_id, changed_fields[]}` | an edit to a field segment rules can read: status, tags, topic follows, custom fields |
| `crm.segment.updated` | whole account | `{segment_id, change}`, `change` being `created`, `criteria_changed` or `deleted` | a segment is created, its rules change, or it is deleted |
| `crm.benefit.changed` | whole account | `{tier_id \| null, benefit_id, change}` | a benefit is added to or removed from a tier, or is activated, deactivated or re-keyed |
| `crm.contact.bulk_updated` | whole account | `{contact_count, changed_fields[]}` | a bulk edit touches more than 100 contacts, sent instead of the per-contact event above |

Entitlement events carry one event per contact per call rather than one per key.
Contact-scoped events carry `contact_id`, the Agend contact's UUID. Volatile
fields (engagement score, last activity, last login) never fire the contact
event. There is no tier event: a tier's benefits changing is `crm.benefit.changed`
with a `tier_id`.

Existing events WordPress also listens to: `crm.membership.*` and `crm.seat.*`.

### WordPress handling

Content Access listens on `agend_apps_webhook_received`, which Core fires for
every verified delivery after its own handling:

- a contact-scoped event: find users whose `_agend_apps_contact_id` matches
  `data.contact_id`, and flush each;
- an account-wide event: bump the generation. `crm.segment.updated` also
  deletes the cached segment catalogue and `crm.benefit.changed` the cached
  entitlement catalogue, so editors see a renamed or new item without waiting.
  The plan catalogue is left alone, since no event describes a plan change;
- anything else, and any malformed envelope: ignore, without an error. A failure
  here would turn a verified delivery into a 500 that Agend retries for ever.

The event lists are filterable
(`agend_content_access_webhook_contact_events`, as exact ids plus prefixes, and
`agend_content_access_webhook_account_events`), so a later gateway event can be
handled without a release. `agend_content_access_facts_invalidated` fires after
facts are forgotten, for logging.

The payload is used only to locate a member. Nothing in it is stored or trusted
as a fact. Signature checking, replay protection and deduplication are Core's and
are not repeated.

The association subscribes these event types to the existing receiver URL
(`/wp-json/agend-apps/v1/webhooks/incoming`) from the dashboard.

## 4. Catalogue for editors

- Core gains `agend_apps_crm_get_entitlement_types()` for
  `GET /crm/entitlements/types` (scope `crm.entitlements.browse`), following the
  `agend_apps_crm_get_segments()` shape, and the generated endpoint-scope map is
  regenerated from the gateway contract.
- Content Access serves one editor catalogue at
  `GET /wp-json/agend-content-access/v1/catalogue` (`edit_posts`): plans,
  segments as `{id, slug, name}`, and entitlement types as
  `{gate_key, name, description}`. Each section reports its own `stale` and
  `error`, so a key without the new scope still gets plans and segments, with a
  message naming the missing scope.
- Content Access probes the new Core function with `function_exists` and does not
  add it to the hard dependency list, so an older Core degrades to an empty
  entitlement picker rather than switching protection off.

## 5. Display conditions

- New provider `agend_entitlements`, value is a `gate_key`.
- Segment conditions stay keyed by slug, for compatibility with stored ESAC data.
- `Agend_Content_Access_Condition_Providers::checker()` becomes a registry, and the
  `agend_content_access_check_condition` filter its documentation already
  promises is implemented. An unknown provider still denies.

## 6. Gutenberg

### Attribute

One non-sourced object attribute, `agendAccess`, added to every block type: in
JavaScript through `blocks.registerBlockType`, and in PHP through
`register_block_type_args` so dynamic blocks and the block renderer accept it.
`save()` is never touched, so existing content stays valid. PHP converts the
attribute into the same settings array the Elementor controls produce, so one
function reads a fragment policy and one evaluates conditions, whichever editor
wrote them.

### Enforcement

- `pre_render_block` returns an empty string for a block the visitor may not see,
  before the block renders anything. Children of a suppressed block never render.
- The document policy used for the intersection is the policy of the post the
  block belongs to (`get_the_ID()` during rendering), never
  `get_queried_object_id()`, because blocks render inside query loops, template
  parts and synced patterns.
- A block excluded by display conditions may show replacement content, under the
  same rule as Elementor: only content the visitor could read on its own.
- Authors are never filtered. The block editor's server-side render requests are
  recognised as authoring.

### Leak vectors

| Vector | Handling |
| --- | --- |
| Front-end, excerpts, feeds, REST `content.rendered` | covered by `pre_render_block` |
| Full-page caches | `DONOTCACHEPAGE` and `Cache-Control: private, no-store` on any response whose post carries a restricted or conditioned block, decided at `template_redirect` by scanning the post's blocks, synced patterns included |
| Core search | excluded from front-end search results for visitors who cannot edit, when the post holds a restricted block. Filterable, because it hides the post's public parts too |
| Third-party search indexers | documented limitation; they read raw `post_content` at save |

### Export to Agend

The exporter dispatches by editor. A block page becomes fragments the same way an
Elementor page does: a subtree with no policy below it is one fragment carrying the
folded ancestor policy, and a block with a policy somewhere below it is structural
and is split. Synced patterns are resolved in place, with a cycle guard, a depth
limit and a per-document cap on how many references are expanded. Fragment ids are
positional (`block-0-2-1`): the gateway stores a document's fragments as one value
per revision, so ids need only be unique within a revision.

Nothing is rendered. A static block's HTML is rebuilt from its saved markup, and a
dynamic block (a query loop, latest posts, post content, a template part,
navigation, or any block with a render callback) is exported empty and marked
non-extracted. Rendering would run those blocks and pull other posts' content,
possibly restricted, into this document's fragments, which Agend would then serve
under this document's policy. The cost is fidelity, never safety: a region comes
out empty or without its wrapper markup, not served to the wrong audience.

The revision folds in the synced patterns the export resolved (each one's id,
modification time, status and content), because a pattern's blocks are not in the
page's own content and editing one changes the fragments without touching the
page.

## 7. Build and release

- Root `package.json` gains `build:content-access`, building
  `agend-content-access/src` into the committed `agend-content-access/build`.
- The CI `blocks` job's committed-build check includes the new directory.
- `build-zip.sh` and `check-version-bumps.sh` exclude `src/` for Content Access as
  they already do for Core.
- Core is released before Content Access starts calling its new wrapper.

## 8. Not in this work: gateway follow-ups

These matter only once WordPress content is synced into Agend, which neither
side does today. They are recorded here for the gateway team:

1. The CMS sync request schema (`packages/features/cms-api/src/schemas.ts`)
   accepts only public, active_member and selected_tiers, so a segments or
   entitlements policy is rejected with a 400 before the shared parser sees it.
2. The CMS viewer never resolves segment ids, so a segments policy on CMS content
   denies every visitor. Entitlements do resolve.
3. The legacy `apps/web` public content routes resolve neither.
4. Ingestion checks that tier ids belong to the account, but not segment ids or
   entitlement keys.

## 9. Delivery

Gateway (agend-dashboard, stacked on `staging`):

1. `GET /v1/crm/entitlements/types` and the `crm.entitlements.browse` scope.
2. Entitlement grant events, and `expires_at` on `/crm/me/entitlements`
   capabilities.
3. The contact, segment and benefit invalidation events, and
   `crm.contact.bulk_updated` for edits above 100 contacts.

WordPress (this repository, stacked on `main`), in stack order:

1. This plan.
2. Editor-neutral seam and the single member-facts source. Behaviour identical.
3. Segments and entitlements as policy modes, `access_restricted`, and the
   Entitlements condition provider.
4. The block exporter.
5. Editor catalogue, the page panel and the Elementor controls.
6. The loop gating port.
7. Webhook invalidation.
8. Block enforcement.
9. The block editor panel and its build.

Core's change (the entitlement-types wrapper and the `crm_me_entitlements` cache
key) ships as its own pull request and is released before Content Access starts
calling the wrapper.
