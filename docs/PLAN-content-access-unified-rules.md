# Plan: one access rule list per block, section and page

Status: agreed direction 2026-10-27, open questions answered 2026-10-28, build starting
Amends: `docs/PLAN-content-access-blocks-segments-entitlements.md`
Builds on: the 0.2.0 stack, PRs #132 to #141 (stack #142)

## Why

The 0.2.0 stack shows authors two settings in one panel: "Who can see this" (an
access policy shaped for Agend, from SPEC-CMS-20260727) and "Display conditions"
(a rule list ported from the Iugo ESAC plugin). On a WordPress-rendered page they
do the same job, both are enforced on the server before render, and authors
cannot tell them apart. Reviewed in a browser test on 2026-10-27 and rejected as
over-engineered.

**The rule: one control per block, Elementor section and page. Its interface is
the rule list (the old "display conditions"); its behaviour is full protection:
checked on the server before render, content never sent to a visitor who fails
it, fail closed.**

## What the author sees

### Blocks and Elementor sections

- **Who can see this**: a searchable rule picker. Rules: signed in, signed out,
  a WordPress role, any current member, a plan, a segment, an entitlement.
- **Match**: any of the rules, or all of them.
- **Reverse**: show only to people who do not match (e.g. a join banner hidden
  from members).
- **Show instead**: optional public content shown in the hidden block's place
  (a public page, synced pattern or Elementor template; access-checked again at
  render, as `Fragment_Rules::fallback_html()` already does).

### Pages (block editor and Elementor pages alike)

- **Who can see this page**: the same picker, Match, and **Reverse** (show only
  to people who do not match; the action below then applies to people who DO
  match, e.g. a join page that redirects existing members to their dashboard).
- **If someone is not allowed**, the author picks one:
  1. **Show a placeholder (default).** Same URL; a placeholder is rendered in
     place of the page body. By default the plugin's built-in "Members-only
     content" placeholder; the author may instead choose a public page, synced
     pattern or Elementor template.
  2. Redirect to a specific page, server side before render.
  3. Send them back to the page they came from: same-site referrer only; no
     referrer, an off-site referrer, or a referrer equal to the current URL goes
     to a fallback page the author chooses.
- In lists (archives, search, feeds, query loops) a restricted page keeps
  showing only its summary; redirects and placeholders apply to the page's own
  URL only.

### The built-in default placeholder

- Rendered on the server by the plugin, so it works the same in block themes,
  classic themes and Elementor pages.
- Shows the page's summary (manual excerpt) when there is one, so pages
  restricted under 0.1.x look much as they did with the old teaser gate.
- Copy and buttons adapt to the reason the visitor failed: signed out gets
  "Sign in" (and "Join" when a membership rule is involved); signed in but not a
  member gets "Join"; anything else gets a generic restricted message that never
  names a segment. Sign-in and join links come from Agend Apps Core's existing
  settings (portal and membership URLs).
- Also registered as a block pattern ("Agend: Members-only content") so a site
  can insert it into a synced pattern, restyle it, and choose that as its
  placeholder instead. The pattern's buttons are a small dynamic block or
  shortcode so the links and copy stay correct.
- Filterable markup and copy for themes that need it.

### Picker at scale

PCA's staging account has about 800 entitlements. The picker must stay usable:
search as you type, selected rules as removable chips at the top, results
grouped by kind (membership, segments, entitlements with platform gates apart,
WordPress), render at most 50 matches with a "refine your search" hint, capped
height with scroll.

## Decisions (2026-10-28)

1. Placeholder is the default page action, using the built-in default
   placeholder above. The old "standard message" is folded into it.
2. Pages have Reverse, like blocks and sections.

## How it works underneath (unchanged engine)

Keep: the provider registry and Agend checks (`Viewer_Facts`, membership,
segments, entitlements), block enforcement at `pre_render_block` plus the
`render_block` backstop, the page gate, loop/excerpt/feed/REST/search/cache
protection, the exporter, webhook invalidation, the catalogue and its route.

Change:

- **One stored shape.**
  - Block and Elementor attribute `agendAccess` becomes `{rules: string[], match: "any"|"all", invert: bool, fallbackId: int}` (0.2.0 is unreleased, so the block shape can change freely).
  - The page gets new meta `_agend_access_rules` `{rules, match, invert, denied: {action: "placeholder"|"redirect"|"back", target_id, fallback_id}}` (placeholder with `target_id` 0 means the built-in default).
  - Rule strings stay `"<provider>:<value>"`.
  - For new rules, plans and segments are named by id (`agend_membership:<tier uuid>`, `agend_segments:<segment uuid>`), and entitlements by gate key (`agend_entitlements:<gate_key>`). Slug values still evaluate, for ESAC compatibility.
- **One evaluator.** Rules go to allow or deny via the existing condition engine. It fails closed: an unknown provider, a lookup failure or a malformed rule all deny, and an inverted rule over an unknown denies too.
- **Compatibility readers, no data migration.**
  - The 0.1.x page policy `_agend_access_policy` and the Elementor `agend_access_mode` / `agend_access_tier_ids` settings are read as equivalent rule lists. Examples: `active_member` becomes `agend_membership:is_member`, and `selected_tiers` becomes one plan rule per tier.
  - Existing ESAC display-condition keys are read as they are.
  - If both an old policy and old conditions exist on one element, both must pass (an intersection), so nothing widens.
- **Gate copy for the standard message** is derived from the failing rule set:
  - a signed-out visitor gets the sign-in prompt;
  - a non-member, when a membership or plan rule is involved, gets the join message;
  - anyone else gets the generic restricted message, which never names a segment.
- **Export to Agend.** Derive an `@agend/access-policy` arm when the rule list is expressible: a single kind, match any, not inverted, Agend providers only. Otherwise export `{"mode":"selected_tiers","tier_ids":[]}` (hidden from everyone). Fail closed, invisible to authors.

## Build plan (stacked on `feat/content-access-unified-rules`, top of #141)

1. **Rules model and evaluator.**
   - Stored shapes and compatibility readers.
   - One `allows( rules, viewer )` path, used by page gating, loop gating, blocks, Elementor and fallbacks.
   - Policy derivation for export, and gate-state derivation.
   - Port every existing test of policy enforcement to the rules form, and keep the 0.1.x compatibility fixtures.
2. **Page denial actions and the default placeholder.**
   - Placeholder (the default): the built-in placeholder, or chosen public content, in place of the body.
   - Redirect: `wp_safe_redirect` at `template_redirect`.
   - Back: `wp_get_referer()`, same host only, loop guard and fallback.
   - Reverse on pages.
   - Ship the built-in placeholder renderer and register it as a block pattern.
   - Loops, feeds and REST keep the summary.
3. **Shared searchable picker plus the block panel.**
   - Rewrite the block sidebar on one panel.
   - Keep the stray-`{}` fix: an empty value removes the attribute.
4. **Page panel.**
   - The same picker in the page settings: a native document panel in the block editor, and the meta box elsewhere. Decide which, or build both.
   - The denial-action controls with page pickers.
5. **Elementor controls.**
   - One section: rules (grouped searchable SELECT2), match, reverse, show instead.
   - Remove the mode and tier controls, and read the old keys.
6. **Independent Opus review**, then a browser re-test on clone-blocks (blocks) and an Elementor site.

## Test environment (as left on 2026-10-27)

- **clone-blocks** (`~/Sites/clone-blocks`, https://clone-blocks.test, Elementor installed but inactive).
  - Its `agend-content-access` and `agend-apps-core` plugin symlinks point at the worktrees `content-access-block-review` and `apps-core-entitlement-types`. The original targets are in the main checkout.
  - The API key is the wdaa dev key. The previous key is backed up in option `agend_apps_secrets_pretest`.
  - Admin user 21 is linked to a wdaa member (2 plans, in segment `eda0ee09-...-000000000001`).
  - Test page 14, `/agend-access-block-test-claude/`, uses the old block shape.
- **Local gateway**: start with `pnpm --filter api dev` in `../agend-dashboard-feat-crm-access-invalidation-events`. Its `apps/api/.env.local` is a symlink to the main checkout's file (gitignored).
  - Port 3072 is what `environment=local` uses.
  - The three new gateway migrations are not applied locally, because the local DB carries 7 unrelated migrations. So the entitlement picker shows its scope message.
- **Herd caches compiled PHP by symlinked path.** After re-pointing a plugin symlink, run `herd restart` or the site keeps serving the old code.
- **The browser test of the current two-setting UI passed on every leak surface:**
  - DOM and HTML;
  - REST content and excerpt;
  - REST edit context (401);
  - front-end and REST search;
  - `private, no-store` caching;
  - member and anonymous outcomes;
  - webhook invalidation.

  Re-run the same checks against the new model.
