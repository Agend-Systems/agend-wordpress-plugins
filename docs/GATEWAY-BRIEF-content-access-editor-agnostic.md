# Gateway brief: content access entitlements and ingestion

Audience: whoever picks this up in the Agend apps and gateway repo. This brief is
self-contained. You do not need the WordPress plugin repo to work from it, and
you should not need to change anything in it.

Companion document, in the WordPress plugin repo:
`docs/REVIEW-content-access-editor-agnostic.md`. Read it only if you want the
WordPress-side reasoning; every contract you need is restated here.

## 0. How to pick this up

There are two independent asks. Ask 1 unblocks a WordPress feature that is
otherwise impossible to build. Ask 2 unblocks a WordPress feature that is
already built and shipping nothing. They do not depend on each other and can be
done in either order or by different people.

The deliverable is gateway-side design and implementation, plus answers to the
open questions in each section. Where an open question changes the WordPress
contract, say so explicitly in your reply rather than picking silently, because
one of them (section 3.4, question 2) has a zero-cost window that closes as soon
as anything is ingested.

Out of scope for whoever takes this: any change to the WordPress plugins. If you
conclude the WordPress side has the contract wrong, write that down and hand it
back rather than working around it.

## 1. Why this exists

`agend-content-access` restricts WordPress pages, and individual page-builder
regions within them, to Agend members and membership plans. The rule it is built
on is that WordPress declares the intended audience and Agend decides whether
the visitor belongs to it. Every decision is server-side and fails closed.

Two pieces of that design terminate at the gateway and stop.

**Members cannot be gated on entitlements.** The plugin can gate on membership
tier and on CRM segment. It cannot gate on an entitlement, because the platform
has a write path for entitlement grants and no read path. Nothing in the
WordPress estate can answer "does the member making this request hold
`gate_key` X".

**Nothing WordPress publishes is ingested.** The plugin exposes an authenticated
source connector that serves its documents and their per-region access policies.
It has been complete since the US-3.5 work. There is no consumer, so no
WordPress content is in Agend and no policy WordPress declares is enforced
anywhere except on the WordPress site itself.

A third change is landing on the WordPress side that makes both more urgent:
clients are moving from Elementor to the Gutenberg block editor, and the plugin
is being made editor agnostic. That work introduces a second kind of content
fragment, which is why section 3 asks you to settle the fragment identity
contract now rather than after the first ingestion.

## 2. Ask 1: a per-viewer entitlement grants read

### 2.1 What exists today

The grant model is already built and in production use. `agend-entitlement-mirror`
mirrors Upbeat entitlements into Agend through two write endpoints:

```
POST /v1/crm/entitlements/types
Scope: crm.entitlements.sync
Body: { "source_key": "upbeat", "entries": [ { "gate_key": "...", "name": "...", "description": "..." } ] }

POST /v1/crm/entitlements/grants
Scope: crm.entitlements.sync
Body: { "contact_id": "...", "source_key": "upbeat",
        "entries": [ { "gate_key": "...", "starts_at": "...", "expires_at": "...",
                       "quantity_allowed": 0, "quantity_remaining": 0, "external_ref": "..." } ] }
Returns: { "data": { "contact_id": "...", "granted": 0, "refreshed": 0, "unchanged": 0, "revoked": 0 } }
```

A `gate_key` is `{category}.{type}`, lowercase, matching
`^[a-z][a-z0-9_.]{1,62}[a-z0-9]$`, maximum 64 characters. Ten keys are reserved
to the platform and refused server-side on `types`: `api.access`,
`community.forum`, `content.sponsored`, `directory.access`, `events.discount`,
`jobs.board`, `lms.certifications`, `lms.courses`, `mentorship.program`,
`resources.library`.

The grants endpoint is already full-state per source: entries present are
granted or refreshed, entries absent are revoked, and grants owned by another
source are untouched.

### 2.2 The gap

There is no read. Specifically, across the whole WordPress estate:

- No route reads back `gate_key` grants for the bearer.
- No `agend_apps_crm_*` wrapper exists for one.
- `gate_key` does not appear anywhere in `agend-content-access`.

`GET /v1/crm/me/entitlements` sounds like the answer and is not. It returns the
member's resolved membership standing, `{ tier_ids, is_member }`, which is a
different question and is already consumed for tier gating.

The model to copy is `GET /v1/crm/me/segments`, which answers the equivalent
question for segments, is bearer-scoped, and is already consumed by this plugin.

### 2.3 The ask

```
GET /v1/crm/me/entitlement-grants
Auth: member bearer, exactly as /v1/crm/me/segments
```

Response:

```json
{
  "data": {
    "gate_keys": [ "committee.chair", "resources.library" ],
    "grants": [
      {
        "gate_key": "committee.chair",
        "name": "Committee Chair",
        "source_key": "upbeat",
        "expires_at": "2027-01-01T00:00:00Z",
        "quantity_remaining": null
      }
    ]
  }
}
```

Both shapes, deliberately. `gate_keys` is the flat list the condition engine
evaluates against, mirroring how the segments response is reduced to a list of
slugs. `grants` carries the detail needed to cap a cache lifetime and to render
an admin diagnostic.

### 2.4 Required semantics

**Resolve effectiveness server-side.** Return only grants that are in force at
request time. Do not return everything with dates and expect WordPress to
compare them. Client-side date comparison is a fail-open risk through clock skew
and timezone handling, and the platform already resolves standing server-side
for `/crm/me/entitlements`.

**Include every source, platform-owned grants included.** Content gating asks
what the member holds, not who granted it. If the response omits reserved
platform keys such as `resources.library`, the most useful gates on an
association site are unreachable and the feature is largely pointless.

**Bearer only. Never accept a contact id.** An administrator asking "who holds
X" is a different endpoint with a different scope. Accepting a subject parameter
here turns a member-facing route into an enumeration oracle, which is the same
class of mistake the account-scoped resolution rule exists to prevent elsewhere.

**Return `expires_at` where one exists.** WordPress caches this per viewer for a
few minutes. Without an expiry it cannot cap that cache, so a transient can
outlive the grant it was built from.

**An empty result is a 200, not a 404.** A member with a valid bearer and no
grants, including a member with no CRM contact, must return `200` with an empty
list. WordPress denies on both empty and error, but it reports them differently:
empty is a settled answer, error is an outage worth alerting on. A 404 collapses
that distinction.

**Never cache at the edge.** `Cache-Control: private, no-store`, matching the
other `/crm/me/*` routes.

### 2.5 Error codes WordPress will branch on

WordPress treats each of these differently, so keep them distinguishable by
status plus `error.code`.

| Outcome | Status | WordPress behaviour |
|---|---|---|
| Grants returned, possibly empty | 200 | Cache per viewer, evaluate, allow or deny on the result |
| No bearer, or expired | 401 | Treat as anonymous, deny anything non-public, no gateway call on the next request |
| Rate limited | 429 | Unknown, deny, do not cache, back off |
| Anything else, including 5xx and transport failure | 5xx | Unknown, deny, do not cache the failure, emit a degraded-mode signal |

The rule throughout is that unknown denies. A failure is never cached, because
caching one would extend a momentary blip into a full cache lifetime of wrongly
denied members.

### 2.6 Open questions

1. **Exhausted quantity-limited grants.** Content gating is a read, not a
   consumption. Should a grant with `quantity_remaining: 0` appear in
   `gate_keys`? Our preference is to include it and let the caller decide, but
   it needs a decision rather than an accident.
2. **Scope model.** `/crm/me/segments` is bearer-authenticated with no
   additional key scope. Should this route match that, or does reading
   entitlements warrant its own scope? Matching is simpler; say if it is wrong.
3. **A combined access endpoint, worth considering instead.** A gating decision
   currently needs up to three round trips per viewer:
   `/crm/me/entitlements` for tiers, `/crm/me/segments` for segments, and this
   new route for gate keys. On a page with a document policy and conditioned
   regions the plugin already calls the first of those twice through two
   independent code paths. A single `GET /v1/crm/me/access` returning
   `{ tier_ids, is_member, segments, gate_keys }` would collapse all of it into
   one call and one cache entry. This is your call, not ours, but it is the
   cheaper shape and now is when it costs nothing to choose it.

## 3. Ask 2: the ingestion surface and the fragment identity contract

### 3.1 What WordPress exposes today

The connector is finished and authenticated behind a rotatable service
credential managed in the WordPress admin. Two routes:

```
GET /wp-json/agend-content-access/v1/connector/documents
Returns: { "documents": [ { "id": "123", "revision": "<sha256>", "slug": "...",
                            "collection": "post", "modified_at": "<ISO 8601>" } ],
           "total": 42 }

GET /wp-json/agend-content-access/v1/connector/documents/{id}
Returns: the full document record below.
Headers: Cache-Control: private, no-store
```

`revision` is a SHA-256 over the post's modified time, status, access policy,
builder data and content. It changes when the content changes and also when only
the policy changes, so a page flipped from public to members-only produces a new
revision with no content edit. It is the change token to poll on.

The full record:

```json
{
  "source": { "system": "wordpress", "id": "123", "revision": "<sha256>", "url": "https://site.example/page/" },
  "title": "string",
  "slug": "string",
  "collection": "post",
  "description": "manual excerpt only, empty if none",
  "status": "publish",
  "language": "en",
  "published_at": "<ISO 8601 or null>",
  "updated_at": "<ISO 8601 or null>",
  "author": { "name": "string" },
  "image": "<url or null>",
  "taxonomy": { "category": [ "slug" ] },
  "access_policy": null,
  "fragments": [],
  "provenance": null
}
```

`access_policy` is one of exactly three shapes, or null:

```json
{ "mode": "public" }
{ "mode": "active_member" }
{ "mode": "selected_tiers", "tier_ids": [ "<uuid>" ] }
```

A fragment:

```json
{
  "id": "4f9a2c1",
  "position": 0,
  "type": "elementor:heading",
  "payload": { "html": "string", "extracted": true },
  "policy": { "mode": "inherit" }
}
```

A fragment's `policy` takes the same three shapes plus `{ "mode": "inherit" }`.
The policy on a fragment is already the intersection of every policy above it in
the page tree, folded down by WordPress before export, so you do not need to
re-derive inheritance. A fragment can only ever narrow its document, never widen
it. Where a page has no page-builder data, the whole body arrives as a single
fragment with `id: "body"`, `type: "html"` and `policy: { "mode": "inherit" }`.

`description` deliberately carries only a manually written excerpt. It is never
auto-generated, because an auto-generated excerpt would be built from restricted
body text.

### 3.2 The fragment identity problem, and why it needs deciding now

Today `type` is the literal string `elementor:` concatenated with the Elementor
widget type, and `id` is Elementor's own opaque seven-character node id.

Two things are about to change that.

**A second editor is arriving.** The Gutenberg work will emit a second family of
fragments. WordPress core assigns no stable per-block identifier at all, so the
plugin has to mint and persist one itself.

**Neither editor's ids are durable across ordinary authoring.** Elementor
regenerates node ids on duplication, copy and paste, and template import. A
minted Gutenberg id survives those operations but is cloned by them, so two
blocks can briefly claim the same id until WordPress de-duplicates on save.

The practical consequence is that a fragment id is stable enough to address a
region within one revision, and not stable enough to be a durable primary key
across revisions.

### 3.3 Required semantics

**Replace a document's fragments wholesale per revision. Do not upsert them
individually.** This follows directly from 3.2. Incremental fragment upsert
keyed on fragment id will accumulate orphans every time an editor duplicates a
section. The entitlement grants endpoint already uses full-state reconciliation
for the same reason, so the posture is consistent with the platform.

**Treat the type field as open.** Accept `elementor:*` and whatever the block
namespace turns out to be. Do not validate against a closed list of widget or
block types: both are extensible by third-party plugins, and rejecting an
unknown one would fail the sync of an otherwise-valid page.

**Never infer public.** `{ "mode": "inherit" }` means the document policy
applies. A missing, empty or unrecognised policy is a restriction, not a grant.
The WordPress side is uniformly fail-closed and the ingestion side has to match,
otherwise the guarantee breaks at the boundary.

**Reject a document whole, never partially.** WordPress already refuses to ship
a partial parse: a page containing a region it cannot model returns 422 rather
than a record with that region silently missing. Mirror that. A document that
fails validation is not ingested at all and the previously ingested revision
stands, because a half-ingested page is one whose restricted regions Agend never
learned about and will therefore serve.

**`extracted: false` is a fidelity gap, not a grant.** A fragment can arrive
carrying a policy and no text, when WordPress recognised the region but not its
content format. Store it and gate it. The correct outcome is a region that
renders empty, never one that renders to the wrong audience.

### 3.4 Open questions

1. **Pull or push.** WordPress exposes a read connector, so the assumption is
   the gateway polls it. Confirm the direction and settle a cadence. If you would
   rather WordPress pushed on save, say so now: that is a real change on our side
   but a cheap one while nothing is ingested.
2. **The fragment type namespace, and the window on it.** Today the editor name
   is fused into the type string as `elementor:heading`. The alternative is to
   split it: `{ "editor": "elementor", "type": "heading" }`. Splitting is
   cleaner, makes "all fragments from editor X" a real query, and avoids parsing
   a compound string. **Nothing has ever been ingested, so changing this costs
   nothing today and needs a migration on both sides the moment it has.** This is
   the one decision in this brief with a closing window. Please answer it first.
3. **Deletion.** What should happen when a document disappears from the list
   response, or its WordPress status changes to draft or private? Silent
   retention is the dangerous default, since it leaves Agend serving a record
   whose source no longer publishes it.
4. **Ordering.** Is `position` authoritative for rendering order, or advisory
   with ordering owned by the projection?

## 4. What WordPress will do, so you can sanity-check the contracts

For Ask 1, per viewer, on a request for gated content:

1. Resolve the member bearer through the existing token path. No bearer means
   anonymous, and no gateway call is made at all.
2. Call the new route once per request, memoised, and cache the result in a
   per-viewer transient for a few minutes, capped by the soonest `expires_at`.
3. Evaluate the page's conditions against the returned `gate_keys` locally, so a
   page with thirty gated regions still makes one call.
4. Never cache a failure. Deny, and emit a degraded-mode signal.
5. Flush the cached facts for one member when an entitlement webhook arrives for
   them.

For Ask 2:

1. Nothing changes in WordPress. The connector is already serving.
2. The service credential is rotatable from the WordPress admin, so plan for the
   credential to change without notice and handle a 401 by re-reading
   configuration rather than by disabling the sync.

## 5. Non-goals

- No change to `POST /v1/crm/entitlements/types` or
  `POST /v1/crm/entitlements/grants`. The write path is correct and in use.
- No change to `GET /v1/crm/me/entitlements`. It answers membership standing and
  should keep answering exactly that.
- No administrative "who holds this gate key" route. That is a separate ask with
  a separate scope, and folding it into the member-facing route is the specific
  thing section 2.4 rules out.
- No entitlement writes from `agend-content-access`. It is a consumer only.
- No change to segments. That path works end to end and needs nothing.

## 6. Suggested gateway-side test scenarios

For Ask 1:

- A member with grants from two sources sees both, with platform-owned keys
  included alongside mirrored ones.
- A grant whose `expires_at` has passed is absent from the response, without the
  caller having to compare dates.
- A member with a valid bearer and no CRM contact returns 200 and an empty list,
  not a 404.
- A request carrying no bearer returns 401 and never a partial or anonymous
  result set.
- The route cannot be induced to return another member's grants by any
  parameter, header or path variation.
- A response carries `Cache-Control: private, no-store` and is not stored by any
  shared cache in front of the gateway.

For Ask 2:

- A document whose only change is its access policy produces a new `revision`
  and is re-fetched.
- A document containing a region the parser cannot model returns 422 from
  WordPress and leaves the previously ingested revision untouched.
- A page edited to duplicate a restricted section does not leave an orphaned
  fragment behind after the next sync.
- A fragment with `policy: { "mode": "inherit" }` resolves to the document
  policy, and a document with `access_policy: null` does not resolve to public.
- A fragment with `extracted: false` is stored and gated rather than dropped.
- A rotated service credential produces a 401 that pauses and resumes the sync
  rather than disabling it.
