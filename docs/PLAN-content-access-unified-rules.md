# Plan: one access rule list per block, section and page

Status: agreed direction 2026-10-27, open questions answered 2026-10-28, build items 1 to 5 done, review and browser re-test next
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
3. **A denial caused by an outage always shows the placeholder.** When the verdict's
   `degraded` is true (Agend unreachable, a lookup failed, a provider threw), the page
   shows its placeholder, built-in or chosen, and never redirects or sends the visitor
   back, so real members are not bounced away while Agend is down. Decided 2026-10-28,
   built with item 4.

## Page actions: as built (2026-10-28)

Build-plan item 2. Code: `Agend_Content_Access_Frontend::gate_request()` chooses,
`Agend_Content_Access_Destination` approves a redirect, `Agend_Content_Access_Placeholder`
renders. The README ("If someone is not allowed") is the reference; this records what was
decided where the plan was silent.

- **Placeholder is the default** for any denied page, including one with only a 0.1.x
  `_agend_access_policy`. It replaces the 403 and the teaser page. 200, `noindex` (the
  `wp_robots` filter and `X-Robots-Tag`), `Cache-Control: private, no-store` and `DONOTCACHEPAGE`.
- **Redirect and back never fail to the body.** Anything uncertain shows the placeholder: a
  target that is missing, unpublished, password protected, not a page, restricted for this
  visitor or the current page; a referrer that is missing, off-site or the current page.
  A referrer is rebuilt on this site's own scheme and host after an exact host comparison, so
  it can never be an open redirect.
- **The action is for whoever the rules deny**, so a reversed page can send its members to their
  dashboard. It looks at why in one case only: a denial down to an outage shows the placeholder
  instead of redirecting or sending back (Decision 3).
- **Placeholder choices** are pages, synced patterns and, when Elementor is active, Elementor
  templates, rendered by `Fragment_Rules::fallback_html()` (page through `the_content`, pattern
  through `do_blocks()` and the shortcode pass, template through Elementor's frontend). A fallback
  must now be of a type that can carry rules, a synced pattern or an Elementor template. The
  conditions route's `fallbacks` entries gained a `type`, so the page panel can offer a redirect
  only pages.
- **Sign in and Join share one address.** Core exposes only the portal URL
  (`Agend_Apps_Settings::get_portal_url()`), which the loop gate already used for both, so the
  placeholder does too. Overridable through the `agend_content_access_placeholder_buttons` filter.
  Revisit if Core grows a separate membership address.
- **`[agend_access_message]`** reads the denial the page gate stored for the request, and renders a
  neutral preview outside one. The pattern "Agend: Members-only content" is a Group holding it.
  A block with a rule inside a placeholder is judged as part of the denied page, so it is hidden:
  keep rules out of a placeholder.
- **The page panel's controls** for the action and its targets write `denied` and offer
  `type: "page"` fallbacks only for a redirect: built as item 4 below.

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
2. **Page denial actions and the default placeholder.** Built 2026-10-28, see "Page actions: as built".
   - Placeholder (the default): the built-in placeholder, or chosen public content, in place of the body.
   - Redirect: `wp_safe_redirect` at `template_redirect`.
   - Back: `wp_get_referer()`, same host only, loop guard and fallback.
   - Reverse on pages.
   - Ship the built-in placeholder renderer and register it as a block pattern.
   - Loops, feeds and REST keep the summary.
3. **Shared searchable picker plus the block panel.**
   - Rewrite the block sidebar on one panel.
   - Keep the stray-`{}` fix: an empty value removes the attribute.
4. **Page panel.** Built 2026-10-28 on `feat/content-access-rules-page-panel`, not yet browser-tested.
   - The same picker in the page settings: both. A native document settings panel in the block
     editor (`PluginDocumentSettingPanel`), and the meta box elsewhere, which mounts the same
     components on a mount point and writes one hidden JSON input. The meta box is
     `__back_compat_meta_box`, hidden in the block editor so an author sees one panel. The
     components are extracted (`src/rule-editor.js`, `src/choice-select.js`) and shared with
     the block panel, not copied; the classic editor has its own small entry, `build/classic.js`.
   - The denial-action controls with page pickers: Show a placeholder (default), Redirect to a
     page (pages only), Send them back (with a fallback page).
   - **Decision: a REST field, not registered meta, carries the rules in the block editor.**
     A registered meta is in every `view` response and WordPress does not guard reads, and the
     editor resends every registered meta on any save while an unset object meta reads as `[]`,
     which would store an empty set over a 0.1.x policy and publish the page. The field is
     `edit` context only, checked for `edit_post` on that post in its getter and setter, and
     removed by the response filter from anything that is not an edit by somebody who may edit.
   - Saving validates: rules are `"<provider>:<value>"` for known providers, `match` any or all,
     `invert` a boolean, `action` one of three, ids published content of an allowed type. Invalid
     is refused whole and the previous value kept, in REST before the post is written.
   - Saving new rules deletes `_agend_access_policy`; clearing every rule deletes the rules and
     the policy. A save that submits nothing for the panel, in either editor, never clears a
     stored setting. A page with only a 0.1.x policy is shown as its converted rules, with a
     notice, and is not rewritten until an author changes it.
   - The outage rule (Decision 3) is in the page gate.
   - Known limits: the placeholder and fallback selects offer at most 100 pages per kind (the
     existing limit of `Condition_Sets::fallback_choices()`); the browser re-test must check
     both hosts, which could not be opened from the build session.
5. **Elementor controls.**
   - One section: rules (grouped searchable SELECT2), match, reverse, show instead.
   - Remove the mode and tier controls, and read the old keys.
   - Status 2026-10-28: built on `feat/content-access-rules-elementor`, not yet browser-tested
     on an Elementor site.
     - The list is the block panel's vocabulary from one shared PHP source
       (`Condition_Sets::rule_groups()`), flattened to "Plan: Gold" style labels, built
       once per request.
     - Match, the reverse switch and Show instead appear once a rule is chosen. Show
       instead also stays for an element with older display conditions.
     - An element that still holds an older audience or older display conditions keeps
       enforcing them (an intersection) and gets a notice and one control to remove each.
       Both are hidden elsewhere.
     - Known limits: Elementor's editor cannot test "list not empty", so the three controls
       are always visible there (the server hides them exactly); and a stored rule the list no
       longer offers is enforced but not drawn, and is dropped if the list is changed.
     - Open: the options ship in the editor config with the shared widget stack and again with
       each of section, column and container, so about 800 entitlements are sent a few times.
       Measure the editor's load on PCA before release.
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
