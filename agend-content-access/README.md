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
one served to the wrong audience. A block's rules are exported as the policy
that says them, or as hidden from everyone when no policy can (see "Access
rules"), as for Elementor.

### The Agend Access panel in the block editor

Every block gets an **Agend Access** panel in its sidebar, shown for the
selected block only. It is one control, a searchable rule picker, and it writes
one attribute, `agendAccess`, and nothing else. A block nobody touched has no
attribute and saves exactly as it did before: the attribute is a non-sourced
object whose default is `{}`, and a value equal to its default is not written.
`save()` is never changed, so no existing block becomes "invalid".

**Which blocks.** All of them, except two that are not stored with a comment
delimiter and so have nowhere to keep an attribute: `core/missing` (the
unsupported block placeholder) and `core/freeform` (the Classic block). A rule
set on either would be forgotten on save, so they are left out in JavaScript and
in PHP, and a test keeps the two lists equal. The panel is also offered only
where the attribute is declared: a block type registered by a script that runs
before this plugin's editor script never receives it, and for that block the
panel stays hidden instead of offering a setting the post would not keep.

**What the author sees**, top to bottom:

1. A one-line summary in plain English: "Everyone who can see the page", "Only
   people who match any of these 2 rules", "Everyone except people who match all
   of these 3 rules".
2. **Who can see this block.** The block's rules as removable chips labelled
   with their group (Plan: Gold, Segment: Board, Entitlement: Premium directory,
   Platform: Directory access, Role: Editor, Signed in, Any current member),
   then a search box. Results are grouped (Membership, Segments, Entitlements,
   WordPress) and are buttons: Enter adds, Down and Up move between them, and
   Enter in the search box adds the first match.
3. **Match**: Any of these, or All of these. Only with two or more rules.
4. **Show to everyone except these people**, with a one-line example. Only with
   one or more rules.
5. **Show instead**: Nothing, or a public page. A searchable select when the
   list is long. Only with one or more rules.
6. "Visitors who are not allowed never receive this block."

A rule a block holds that the list no longer offers (a deleted segment, a
retired plan) stays as a chip marked "Unavailable: ..." and is never dropped
silently: dropping it would quietly change who can see the block. A plan that
was switched off reads "(no longer active)". If the list cannot be loaded the
panel says so, with Try again, and still shows and lets an author remove
whatever the block already holds. An error from one list (for example the
entitlements scope message) appears under the search box and does not hide the
others.

**The picker at scale.** The vocabulary is fetched once per editor session and
every keystroke filters it in memory, so about 800 entitlements cost no request
per key. With nothing typed it shows Membership and WordPress in full and the
first five of each other group, with how many more there are. A search matches
every word typed, in any order, against the rule's label, its group word
("plan", "role"), the description and the key of an entitlement, and puts names
that start with what was typed first. It renders at most 50 results and then
says "Showing 50 of N. Keep typing to narrow it down."

**The stored value.** What each control writes. The panel writes the smallest
object that says what was chosen, drops a key the moment it stops applying, and
leaves a default out:

| Control | Writes |
| --- | --- |
| A rule chosen | `rules`, a list of `"<provider>:<value>"` |
| Match: All of these | `match: "all"` (Any is the default and is not written; needs two rules) |
| Show to everyone except these people | `invert: true` |
| Show instead | `fallbackId`, a post id. Only public content is offered |

Rule providers: `agend_wordpress` (`logged_in`, `logged_out`, `role_<role id>`),
`agend_membership` (`is_member`, or a plan's id), `agend_segments` (a segment's
id) and `agend_entitlements` (an entitlement's gate key). New rules name plans
and segments by ID, never slug; a slug a block already holds still works and is
recognised in the panel as the same rule.

Removing the last rule removes `match`, `invert` and `fallbackId` with it, and
with nothing chosen the attribute is removed (never saved as `{}`).

**The contract is closed, and only `meta` is ignorable.** A block's `agendAccess` may carry
`rules`, `match`, `invert`, `fallbackId`, the first panel's keys (below) and one reserved key,
`meta`, which may hold data that has NO effect on who sees the block: it is ignored, and the
panel keeps it as found. Any other top-level key makes the whole value unreadable, on the
server (`Agend_Content_Access_Rules::BLOCK_KEYS`) and in the panel alike, and the block is
hidden from everyone: `{rules: [A], groups: [...]}` is not read as `{rules: [A]}`, because that
would drop a narrowing the version does not know about, and `{groups: [...]}` is not read as no
rules. So a future release that adds a key which narrows access must add it at the top level:
an older version then fails closed instead of showing the block more widely. Only `meta`, never
a new key, may be used for something an older version can safely ignore. A script or tool that
writes its own key into the attribute must use `meta`. A rule that is only whitespace is
unreadable too, on both sides, rather than none on the server and a rule in the panel.

**Blocks saved by the first panel.** The first panel stored two settings, an
audience (`mode`, `tierIds`, `segmentIds`, `entitlementKeys`) and display
conditions (`conditionsEnabled`, `conditions`, `conditionsMatch`,
`conditionsInvert`). Those blocks are read as rules and shown in the new panel.
Opening one never rewrites it: it is saved in the new shape only when an author
changes something, and nothing of the old shape survives that write.

| First shape | Shown as |
| --- | --- |
| `mode` inherit, or none | no rules |
| `active_member` | `agend_membership:is_member` |
| `selected_tiers` | one `agend_membership:<id>` per plan |
| `segments` | one `agend_segments:<id>` per segment |
| `selected_entitlements` | one `agend_entitlements:<key>` per key |
| conditions switched on | the conditions as stored, with their Match, Reverse and replacement |
| an audience AND conditions | both sets joined, Match set to All, with the notice "This block uses an older two-part setting. Saving changes here will replace it with the rules shown." |

An audience and conditions are two sets that must both pass, which one list
cannot say exactly. Joining them with All is never looser than the pair, and is
exact when the audience is one rule. A first-shape value that one list would
show more loosely than the server enforces it is shown as "Unreadable setting
(hidden from everyone)", with a reset and nothing else: a set-mode with nothing
selected, conditions switched on, empty and reversed, an audience with reversed
conditions, a mode this version does not know, or a list or condition that is
not text. So is an audience list holding an entry the server's pattern refuses (a plan
or segment id that is not a UUID, an entitlement key that is not a gate key, the
server's own `UUID_PATTERN` and `ENTITLEMENT_KEY_PATTERN`): the server drops such an
entry and reads the rest, but the panel would show it as a rule, and the first edit
would write it in the new shape, where a slug is evaluated. So are a value that is not
an object, a rule list holding anything that is not a rule, a Match that is not `any`
or `all`, a Reverse that is not a boolean (the server hides the block from everyone, so
the panel must not show it as open), a rule that is only whitespace, a key outside the
contract, and a value that mixes both shapes. Reset clears
the setting and keeps `meta`.

A blue stripe on the canvas marks a block that carries rules, or one the panel
cannot read.

**Where the vocabulary comes from.** One route, once per editor session and not
on every block selection, for users who can `edit_posts`:

| Route | Returns |
| --- | --- |
| `GET /wp-json/agend-content-access/v1/conditions` | `{groups, fallbacks, errors}` |

`groups` is `[{key, label, stale, items: [{value, label, kind, description?,
aliases?, inactive?}]}]`: Membership (`agend_membership`), Segments
(`agend_segments`), Entitlements (`agend_entitlements`, platform gates first) and
WordPress (`agend_wordpress`), then any group a site adds through the
`agend_content_access_condition_sets` filter. Each `value` is already
`"<provider>:<value>"` and ID based; `kind` is member, plan, segment,
entitlement, platform, login, role or other, which is what the chip is labelled
from; `aliases` are slug forms of the same rule. `fallbacks` is
`[{id, title, type}]` of public content: pages, synced patterns and, when Elementor
is active, its templates, each titled with its kind ("Page: Join us", "Pattern: ...",
"Elementor template: ...") and typed `page`, `pattern` or `elementor_template`, so a
panel can offer a redirect only pages. `errors` is `[{group, message}]`, one per list
that failed.
`?refresh=1` reads the lists from the gateway again, which is what Try again
asks for. The catalogue route (`/catalogue`) is unchanged. The page panel reads this
route too, so one list of rules serves a page, a block and an Elementor element.

**Building it.** The editor code is in `src/` and ships built, because
deployment copies files without a build step. From the repository root:

```sh
npm run build:content-access      # writes build/index.* (block editor) and build/classic.* (classic editor)
npm run start:content-access      # the same, watching for changes
node --test agend-content-access/tests/js/access-model.test.mjs
node --test agend-content-access/tests/js/page-model.test.mjs
node --test agend-content-access/tests/js/legacy-access.test.mjs
node --test agend-content-access/tests/js/rule-vocabulary.test.mjs
node --test agend-content-access/tests/js/access-summary.test.mjs
node --test agend-content-access/tests/js/copy.test.mjs
node --test agend-content-access/tests/js/lists-loader.test.mjs
```

Commit `build/` with the source: CI rebuilds it and fails if it differs. The
release zip leaves `src/` out. The logic that decides what the panel writes is in
`src/access-model.mjs`, how a first-shape block reads in `src/legacy-access.mjs`,
what is listed, labelled and searched in `src/rule-vocabulary.mjs`, the summary
line in `src/access-summary.mjs`, what the page panel writes in `src/page-model.mjs`,
and the once-per-session loader in `src/lists-loader.mjs`. None of them imports
WordPress, which is what lets `node --test` run them. The English copy they compose is in `src/copy.mjs` and
its translated twin in `src/strings.js`, which a test keeps in step.

### The Agend Access panel on a page

A page's rules are set in the same picker, in whichever editor the page is edited in.
**One panel, two hosts.** The components are the block panel's: `src/rule-editor.js`
holds the chips, search, Match and Reverse, and the block panel (`src/access-panel.js`)
and the page panel (`src/page-editor.js`) are both built from it, so a rule is chosen,
shown and removed the same way everywhere.

| Editor | Where the panel is | How it saves |
| --- | --- | --- |
| Block editor | A native document settings panel, **Agend Access** (`PluginDocumentSettingPanel`, from `src/page-panel-plugin.js`, part of the block editor script) | A REST field, `agend_access_rules`, sent with the post when the author clicks Update |
| Classic editor, and any edit screen that is not the block editor | The **Agend Access** meta box, which renders a mount point; the same components are mounted on it by `build/classic.js` (a second, small entry that needs none of the block editor) | One hidden input, `agend_access_page_rules`, holding JSON, read by the nonce checked `save_post` handler, and only when the form's `post_ID` is the post being saved: `save_post` fires for every post a request writes, and another post saved in the same request is not the form's |

The meta box is declared `__back_compat_meta_box`, which hides it in the block editor so
an author sees one panel. It no longer claims to be block editor compatible.

**What the author sees**, top to bottom:

1. A one-line summary: "Everyone", "Only people who match any of these 2 rules",
   "Everyone except people who match this rule".
2. **Who can see this page.** The rule picker, Match, and **Show to everyone except these
   people** with the example "Useful for a join page that members should be redirected
   away from."
3. **If someone isn't allowed**, once there is a rule: **Show a placeholder** (the
   default; a select of "Built-in placeholder" and the public pages, synced patterns and
   Elementor templates), **Redirect to a page** (a select of pages only: a pattern has no
   address), or **Send them back to the page they came from** (a fallback page select:
   "If they did not come from this site, they go here instead").

A redirect with no page chosen is not a setting, so the panel keeps it as a draft, says
"Choose the page to send people to. The page cannot be saved until you do." and writes
nothing until it is finished. While the draft is held the page really cannot be saved: the
block editor's save is locked (`lockPostSaving`, under a lock name of the panel's own), and
the classic form is not submitted, so the editor never answers "Page updated" for a change
it did not save. The lock goes when the choice is finished, undone, or the panel is closed.
Switching action starts the new action with nothing chosen: the pattern chosen as a
placeholder is not a page to redirect to.

**How a page's rules are stored and kept private.** `Agend_Content_Access_Page_Rules`
owns both editors' saves, so neither can accept what the other refuses.

- The value is the post meta `_agend_access_rules`, `{rules, match, invert, denied:
  {action, target_id, fallback_id}}`, which `Rules::from_post()` reads. The meta is
  registered without `show_in_rest`, so it is in no `meta` object and cannot be written
  through one, with an `auth_callback` that needs `edit_post` for that post.
- The block editor reads and writes a REST field, not registered meta, for two reasons.
  A registered meta is in every response of the post, `view` context included, and
  WordPress does not restrict who may READ one (its `auth_callback` guards writes only),
  so the association's plan, segment and entitlement ids would be public. And the block
  editor sends every registered meta back on any save that edits one, while an unset
  object meta comes out of REST as `[]`: each save of an untouched 0.1.x page would have
  stored an empty rule set, which wins over the old policy and publishes the page. A
  field is sent back only when an author edited it.
- The field's schema has `context` `edit` only, so it is never in a `view` response.
  Its getter returns nothing, and its setter refuses, for anybody who cannot
  `edit_post` THAT post (WordPress lets anybody holding `edit_posts` ask for the edit
  context of a collection). `filter_rest_response()` removes it from every response that
  is not an edit of the post by somebody who may edit it, whatever state the page is in.
- Reading never writes. A page that only has a 0.1.x `_agend_access_policy` is shown as
  its converted rules with the notice "This page uses an older setting, shown here as
  rules. Saving will update it." Nothing is rewritten until an author changes the panel,
  and a save that sends nothing for it, in either editor, keeps what is stored.
  A setting this version cannot show (an unreadable policy, rules that are not a list of
  strings, rules reversed with nothing to match, a set of several parts) is shown as
  "Unreadable setting (hidden from everyone)" with a reset and nothing else.

**What is checked on save.** Anything invalid is refused whole, with a message an author
can act on and the previous value kept; nothing is ever coerced into something wider.

| Part | Accepted |
| --- | --- |
| `rules` | A list of at most 200 strings `"<provider>:<value>"`, the value starting with a non-space character, no control characters, at most 255 characters, for a provider the site evaluates (the four built in, the ESAC names the engine reads, or one a site adds through `agend_content_access_condition_sets`). Trimmed and de-duplicated. Whether the value is something the lists currently offer is not checked: a plan that is gone shows as unavailable and denies |
| `match` | `any` or `all`; `any` below two rules |
| `invert` | A boolean. Reversed with no rules is refused: it would hide the page from everyone |
| `denied.action` | `placeholder`, `redirect` or `back` |
| `denied.target_id` | Placeholder: 0 (built-in) or a published, passwordless, public page, synced pattern or Elementor template (when Elementor is active). Redirect: a published, passwordless page of a type the panel is offered on, not a pattern or template, and not this page |
| `denied.fallback_id` | Back: 0 or a page as for a redirect |
| Anything else | An unknown key, a flag that the setting was unreadable, a list where an object belongs: refused |

A number the chosen action does not use is dropped. In the block editor a refusal is a 400
before the post is written (`rest_pre_insert_{type}`), so a refused value never half saves a
page, and the panel shows the message under its summary until the value is changed. In the
classic editor it is carried across the redirect as the admin notice the panel has always
used.

**Saving replaces the 0.1.x policy.** The new meta wins over `_agend_access_policy`, so
saving rules deletes the old key, and removing every rule deletes the rules AND the old
policy: the page becomes open to everyone, which the author chose. The old key then cannot
come back when the new one is removed. The old key is deleted only after the new value reads
back as it was written: a write that fails leaves the old policy enforcing and the author is
told, because deleting it first would leave the page with neither, which is public.

### The Agend Access section in Elementor

Every section, column, container and widget has an **Agend Access** section in its
Advanced tab: one list of rules, in the same words as the block panel. It writes
`agend_access_rules`, `agend_access_match` and `agend_access_invert` into the
element's settings, which `Agend_Content_Access_Rules::from_elementor_settings()` reads.

**What the author sees**, top to bottom:

| Control | Setting | Shown |
| --- | --- | --- |
| **Who can see this**: a searchable multi-select, with "Leave empty to show this to everyone who can see the page. Visitors who are not allowed never receive this element." | `agend_access_rules`, a list of `"<provider>:<value>"` | Always |
| **Match**: Any of these, or All of these | `agend_access_match` | When a rule is chosen |
| **Show to everyone except these people**, with "Useful for a join banner that members should not see." | `agend_access_invert`, `yes` | When a rule is chosen |
| **Show instead**: Nothing, or a public page, synced pattern or Elementor template (the block panel's list) | `agend_conditions_fallback_id` | When a rule is chosen, or the element still has older display conditions switched on |
| A notice | none | Only on an element that still holds an older setting |
| **Older audience setting** | `agend_access_mode` | Only when it is not "same as the page" |
| **Older display conditions** | `agend_conditions_enabled` | Only when it is on |

The list offers the block panel's vocabulary from the same PHP source
(`Agend_Content_Access_Condition_Sets::rule_groups()`), flattened to one name a rule
with its kind in front: "Any current member", "Plan: Gold", "Segment: Board",
"Entitlement: Premium directory", "Platform: Directory access", "Signed in",
"Signed out", "Role: Editor". Plans and segments are stored by id and entitlements by
gate key. A plan that was switched off is offered as "Plan (inactive): Gold". The options
are built in Elementor's editor only: a visitor's render registers the same controls
with none, so it pays for no catalogue read and no query, and what a stored rule
means never depends on them. In the editor they are built once per request and shared
by every control stack Elementor registers, so about 800 entitlements cost one read and
not one per widget type.

Match, the reverse switch and "Show instead" only mean something with a rule, so the
server hides them until one is chosen, and an emptied list leaves no rule behind
even if a reverse is still stored. Elementor's editor cannot tell an empty list
from a full one in a condition, so there they stay visible; what the server reads is
what counts.

**Older settings on existing elements.** An element saved before the rule list can
hold an audience (`agend_access_mode` with its plan, segment and entitlement lists) and
display conditions (`agend_conditions_*`). They are not migrated and they are not
dropped: they are still read, and still enforced, beside any rules, as an
intersection that can only narrow. The new list cannot show them, and a restriction
an author cannot see would be a trap, so an element that holds one shows a notice,
"This element still uses an older access setting, which keeps applying alongside the
rules above.", and the one control that removes it:

- **Older audience setting** is a select. Choosing **Remove** writes `inherit`,
  which is the default, so nothing is stored for it and the control disappears. The
  plan, segment and entitlement lists it leaves behind do nothing without a mode.
- **Older display conditions** is a switch. Switching it off ends them, and the
  control disappears. Their list, Match and reverse are left behind, inert.

Both are hidden on every element that does not hold an older setting, so they cost
an author nothing and never offer a way to set one up. The editor marks an element
that holds one (`data-agend-access-older`).

An older setting keeps its meaning exactly: the plan, segment and entitlement lists
and the old conditions list, Match and reverse are no longer controls, and Elementor
passes a stored setting that has no control through untouched. Elementor does read a
control that a condition hides as unset, and enforcement reads what Elementor
presents, so each older control is hidden only when it is off. The tests port
Elementor's own condition algorithm to prove that hiding any control never changes who
sees an element.

**Not changed:** suppression removes the element and its wrapper before render, an
element is shown only when its own rules and its page's both allow, "Show instead" is
checked again at render, an element carrying any rule, old or new, or any such
descendant, is kept out of Elementor's element cache, and the Editor V4 atomic
element limitation above stands.

**A rule the list does not offer is kept.** A multi-select writes back only the values it
shows, so a rule an element holds that the list does not offer (a deleted segment, a
rule from a provider a site removed, every Agend rule while the catalogue cannot be
loaded) was lost on the next edit of the list, and under "all of these" or "show to
everyone except these people" that widens who sees the element. Two things stop it:

- The editor offers every rule the open document holds, read from its `_elementor_data`
  when the editor loads: a plan that was switched off as "Plan (inactive): Gold", a slug
  alias under the name of the plan or segment it stands for, and anything the list cannot
  name as "Unavailable: <rule>".
- A save puts back what it lost. `elementor/document/save/data` (Elementor 3.3 and
  later, draft and autosave saves included) gives the saved elements to
  `Agend_Content_Access_Elementor::preserve_unseen_rules()`, which adds to each element
  any rule it held before that the saved data lacks and the list does not offer (the
  catalogue's own rules are offered, so removing one of those sticks; a slug alias is put
  back unless the rule it stands for was saved, because an element edited in place in a
  second document, such as a header or footer part or a global widget, was never offered
  its alias, and the list is built from the document the editor opened on), but only where
  losing it widens who sees the element: the saved element matches "all of these", is
  reversed ("everyone except these people"), or would be left with no rules at all. Under
  plain "any of these" with other rules left, a removed rule stays removed, because it
  can only narrow the audience. A match or reverse setting it cannot read counts as
  widening. It only ever adds, and it asks the catalogue itself, because a save is not an
  editor request.

**The cost:** under "all of these", reversed, or when it is the last rule, an "Unavailable"
rule cannot be removed from an element in the editor until the catalogue offers it again,
or the element is replaced. It is not the safe side in every mode: reversed, a rule that
matches no one lets everyone in, which is why it is kept there.

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

## Access rules

One control per block, Elementor section and page, and one place that judges it.
What an author sets is a list of `"<provider>:<value>"` rules with a match and a
reverse:

```json
{ "rules": ["agend_membership:<tier uuid>", "agend_segments:<segment uuid>"], "match": "any", "invert": false }
```

A block carries it in `agendAccess` (with `fallbackId`), a page in the post meta
`_agend_access_rules` (with a `denied` block that says what to do with somebody it
does not admit: see "If someone is not allowed"), and an Elementor section in
`agend_access_rules`, `agend_access_match` and `agend_access_invert`. The rules are
`agend_membership:is_member`, `agend_membership:<tier id or slug>`,
`agend_segments:<segment id or slug>`, `agend_entitlements:<gate key>`,
`agend_wordpress:logged_in`, `logged_out` and `role_<id>`, and any provider a site
registers through `agend_content_access_check_condition`.

Three states, kept apart on purpose: NO RULES restricts nothing, RULES are judged,
and UNREADABLE (a value this version cannot read) denies everyone. A reverse over no
rules is not "no rules": it hides the thing from everyone, as the condition engine
has always read it.

`Agend_Content_Access_Rules` reads all of it. Alongside the new shape it reads what
0.1.x and 0.2.0 stored, with no data migration:

| Stored | Read as |
| --- | --- |
| `active_member` | `agend_membership:is_member` |
| `selected_tiers` | one `agend_membership:<tier uuid>` per plan, match any |
| `segments` | one `agend_segments:<uuid>` per segment |
| `selected_entitlements` | one `agend_entitlements:<gate key>` per key |
| `public`, `inherit` | no rules |
| A set-mode that names nothing | unreadable (nobody), never "no rules" |
| A stored rule set with a key this version does not write (anything but `rules`, `match`, `invert`, `fallbackId`, `all_of` and `denied`), or with neither `rules` nor `all_of` | unreadable (nobody). It used to be read for the keys it did know, and with none of them that was "no rules": a stray value in a page's `_agend_access_rules` published the page over its 0.1.x policy |
| A page's `_agend_access_policy` | the same, when `_agend_access_rules` is absent. The new meta wins when both exist |
| An element's `agend_access_mode` and id lists, and `agend_conditions_*` | the same, plus the old display condition keys as they were |

An element or block that carries BOTH an old policy and enabled old conditions has to
satisfy both: the rules hold a list of parts that are ANDed, used for this and for
judging a block with its post. It can only narrow. Display conditions switched on with
none chosen are no rules, and no longer keep a page out of caches or search.

### One evaluator

`Agend_Content_Access_Access::verdict( $rules, $options )` returns
`{allowed, state, degraded, unknown}`. The page gate and its placeholder, the loop,
excerpt and feed gates, the REST filter, blocks and the `render_block` backstop,
Elementor sections and the fallback a hidden block shows all go through it. A block or
section is shown only when its own rules AND its post's rules allow. The option
`anonymous` judges for nobody, as a feed must, and looks nothing up.

An Agend identity is a bearer, never a WordPress login: a visitor with no bearer is
nobody to Agend and costs no gateway call. A member is somebody the gateway calls one
who also holds a plan, so where the two answers disagree the visitor is not a member.
Plans and segments match by id or slug, and a value shaped like an id is matched
against ids only. A request makes at most one entitlements call and one segments call.

Everything uncertain denies, reversed or not: an unreadable rule set, an unknown
provider, a malformed rule, a lookup that failed, a provider that threw. `degraded`
says the denial is down to something that could not be evaluated.

The state of a denial changes the words shown, never who is admitted. The first row
that applies wins, judged on the rules that denied:

| Visitor | Rules involved | State |
| --- | --- | --- |
| Anonymous, signed out, or Agend cannot say who they are | any | `authentication_required` |
| Signed in, holds nothing | a membership or plan rule that is not reversed | `membership_required` |
| A member | a plan rule that is not reversed | `plan_required` |
| Anyone else | segments, entitlements, roles, reversed rules | `access_restricted` |

"Signed out" means Agend does not know the visitor when an Agend rule is involved, and
not logged in to WordPress when none is.

### If someone is not allowed

A page keeps its URL for somebody its rules do not admit, and does one of three things
with them. The page's `denied` setting, `{action, target_id, fallback_id}`, says which,
and **the default is the placeholder**, including for a page that only has a 0.1.x
`_agend_access_policy` and no new meta. It applies to the page's own URL only: lists,
search, feeds and REST keep showing the summary and nothing else.

| `action` | What the visitor gets |
| --- | --- |
| `placeholder` (default) | The body is replaced on the same URL. `target_id` 0 is the built-in placeholder; otherwise a chosen public page, synced pattern or Elementor template. HTTP 200, `noindex` (the `wp_robots` filter and an `X-Robots-Tag` header), `Cache-Control: private, no-store` and `DONOTCACHEPAGE` |
| `redirect` | A 302 to `target_id`'s permalink, on `template_redirect` before any output, also marked uncacheable |
| `back` | A 302 to the page they came from, when the `Referer` header is on this site and not this page (the header only: `wp_get_referer()` prefers the `_wp_http_referer` request parameter, which a link can set); otherwise to `fallback_id`'s permalink; otherwise the placeholder |

**This replaces the 403 and the teaser page.** A restricted page used to answer 403 when
it had no manual excerpt and a teaser page when it had one. Both are now the placeholder:
a 200, with the manual excerpt above the message when there is one, so 0.1.x pages look
much as they did.

**Editors see everything in the editor. On the live site and in previews everyone, administrators
included, sees exactly what their own membership allows.** Being able to edit a page is not a
membership, so it no longer lets an editor or administrator past the page's rules on the live
front end, in a WordPress preview (`?preview=true`), in a list, an excerpt or the REST view
context. What shows an author everything is the authoring carve-outs, which are unchanged and
apply only to a post the user may edit: the block editor and its REST edit and render requests,
the Elementor editor and its preview of the document being edited, and wp-admin screens
(including the comment screens). An administrator who is not a member and who opens the live page,
or its preview, gets the page's action (placeholder, redirect or back) like anybody else.
The action applies to whoever the rules deny, so a page with Reverse on, such as a join
page, redirects the people who DO match, its members, to their dashboard, and shows
everyone else the page.

**One exception: an outage.** When the denial is down to something that could not be
evaluated (the verdict's `degraded`: Agend unreachable, a lookup that failed, a provider
that threw), the page always shows the placeholder, whatever its action says. The visitor
may well be a member whom Agend could not confirm, and a redirect or a way back would
bounce real members off the site for as long as the outage lasts. A page that chose a
placeholder shows that one; a page that redirects or sends back shows the built-in one.
The body is still never shown.

**Every redirect fails to the placeholder, never to the body.** `Agend_Content_Access_Destination`
answers "no" for anything it cannot be sure of, and the page then shows the placeholder:

- A chosen page must be published, have no password, be of a type the rule panel is offered
  on (a pattern and a template have no address), and its own rules must admit THIS visitor.
  A page the visitor could not open is a second denial, and two such pages that redirect to
  each other would be a loop.
- Its permalink must validate as local (`wp_validate_redirect()` with an empty fallback, because
  `wp_safe_redirect()` answers what it dislikes by redirecting to wp-admin).
- It must not be the page being left. The comparison ignores scheme, case, a trailing slash,
  the query and the fragment, and is made against both the page's permalink and the address
  requested. A path-less address on a plain-permalink site is told apart by its `p`, `page_id`
  and similar variables.
- A referrer is never echoed. Its host is compared with this site's (exactly: not a suffix,
  not user information, no backslash, whitespace or control character, an http or https
  scheme, the same port), and the address is then REBUILT from this site's own scheme and
  host plus the referrer's path and query. `allowed_redirect_hosts` is not consulted: it is
  for redirects the site chooses, and a referrer is not one. No referrer, an off-site one and
  this page itself all go to `fallback_id`, and failing that to the placeholder.

#### The whole page is replaced, and its comments are closed

A denied page does not run its own template. Replacing only the body let everything else a
template prints go to the visitor: a page builder that renders its layout from meta on
`the_content` (Beaver Builder does) overwrote the placeholder, a template that never calls
`the_content` printed protected fields (flexible content, a theme builder's dynamic tags), and
the comments, the comment form and the comment feed were served. So:

- **The template is swapped.** `template_include` at `PHP_INT_MAX` hands WordPress the plugin's
  own `templates/denied-page.php`, so no theme, builder or plugin template can run after it.
  It prints the theme's header, the placeholder and the theme's footer, and the template
  itself reads nothing of the page: not its title, body, fields or comments. The theme's own
  header and footer, and `wp_head()`, still run, and they are the theme's and the plugins' to
  print what they like about the page being served (a classic `header.php` can print a custom
  field, an SEO plugin prints a description: see the "Not covered" list below). A classic theme gets
  `get_header()`, a `main` element holding the placeholder, and `get_footer()`. A block theme
  has no such files, so the document is built from the pieces core's template canvas uses: the
  charset, `wp_head()`, the body classes, `wp_body_open()`, a `wp-site-blocks` wrapper holding
  the theme's `header` template part, the placeholder in a constrained `main` and the `footer`
  part, and `wp_footer()`. The parts are rendered with core's `core/template-part` block, so a
  child theme's override and a part edited in the site editor are the ones shown. If the
  template file is missing the request ends in a 403, never in the page's own template.
- **The body is still replaced, and re-asserted.** For anything that still calls `the_content`
  for the denied page (a widget, a template part, a builder that overwrites it at a later
  priority): the swap at priority 1 stays, and a second pass at `PHP_INT_MAX` puts the
  placeholder back.
- **Its feed is refused.** A feed of a denied page, which is its comment feed, answers 403,
  uncacheable, whatever the page's action says.
- **Its comments are closed.** For the denied page, `comments_open` and `pings_open` are false,
  `comments_array` is empty and `get_comments_number` is 0.

**Comments on a restricted page, elsewhere.** Nearly every list of comments is a `WP_Comment_Query`
(`get_comments()`, a recent-comments widget, the comment blocks, the REST collection
`/wp/v2/comments` with or without `post=`). On `pre_get_comments` the posts the viewer is denied are
added to the query's `post__not_in`, so a viewer who cannot read a page cannot read the comments on
it. Only an authoring request for a post the user may edit keeps them (the wp-admin comment screens, the
block editor, Elementor's editor); an editor on the live site is judged by their own membership. A
single comment (`GET /wp/v2/comments/<id>`) on such a page is refused with `rest_cannot_read`.

It is done in the query's arguments rather than its SQL because `WP_Comment_Query` caches its result
under a key made from its arguments alone: an exclusion added to the SQL would let a persistent object
cache hand an editor's comments to the next visitor. If the list of restricted posts cannot be read the
query is made to match nothing. **The cost:** only a request that asks for comments pays. One indexed
query finds the posts that carry page rules and have a comment, so a site whose restricted pages have
no comments pays that and nothing else; each such post then costs one decision, against the viewer's
facts, which are memoised for the request, so the gateway is asked at most once. The result is kept for
the request.

**The site's comment feed is a different query.** `/comments/feed/`, a search's comment feed
(`?s=x&feed=comments-rss2`) and an archive's comment feed are not built by `WP_Comment_Query`: core's
`WP_Query` writes their SQL itself, so the exclusion above never ran for them and they listed the
comments on restricted pages. They are filtered on `comment_feed_where` (at `PHP_INT_MAX`), which
appends `AND comment_post_ID NOT IN ( ... )` with the posts an anonymous reader is denied. A feed is
shared and cached, so it is judged as an anonymous reader whoever fetched it: an editor reading the
feed does not see them either. It is cache-safe because core caches a feed's comment ids under the md5
of the whole SQL, so a clause that differs is a key that differs. If the list cannot be made the clause
is `AND 0`, which lists no comment. The comment feed of one page that the visitor is denied is refused
with a 403, as above.

**SEO plugin descriptions.** The `<head>` is printed by `wp_head()`, not by the denied page's template,
and an SEO plugin builds its meta description, `og:description` and `twitter:description` from the post
itself: Rank Math's `%excerpt%` and Yoast's `%%excerpt%%` fall back to the opening of the body when no
excerpt was written, and Jetpack's Open Graph tags and All in One SEO do the same. For a post the reader
is denied, these hooks return the teaser (the manual excerpt, or nothing) as plain text:

- Yoast SEO: `wpseo_metadesc`, `wpseo_opengraph_desc`, `wpseo_twitter_description`.
- Rank Math: `rank_math/frontend/description`, `rank_math/opengraph/facebook/og_description`,
  `rank_math/opengraph/twitter/twitter_description`.
- All in One SEO: `aioseo_description`, `aioseo_facebook_tags`, `aioseo_twitter_tags`.
- Jetpack: `jetpack_open_graph_tags`.

A page with no rules, a reader the rules admit and an editor of the page keep the plugin's own text. A
plugin's own custom description for a restricted page is replaced too, because a hook cannot tell it from
one built out of the body, so write the manual excerpt to say what a visitor should see.

**A page everyone may read, with a restricted block or section on it,** has the same leak by another
road: with no manual excerpt these plugins build the description from the raw body, so a members-only
block near the top is quoted in the `<head>`. For a singular page that carries block or section rules and
has no manual excerpt, the same hooks are given the safe generated excerpt (the block tree rebuilt
without its restricted blocks, and nothing for an Elementor page), as plain text of about 160
characters. A description the author wrote is kept where it can be detected cheaply: Yoast's
`_yoast_wpseo_metadesc` and Rank Math's `rank_math_description`, when not empty. All in One SEO and
Jetpack always get the safe excerpt on such a page. A page with no block or section rules is not
touched. Another SEO
plugin or theme that prints a description of its own, and the structured data (JSON-LD) descriptions these
plugins print, are not covered and need the same treatment. The page's own `post_content` and the cached
post are only read, never changed.

#### The built-in placeholder

Rendered on the server by `Agend_Content_Access_Placeholder`, so it is the same in a block
theme, a classic theme and on an Elementor page (whose builder data is blanked for the denied
page, so it cannot render over the placeholder). It shows the page's manual excerpt when
there is one (never an automatic excerpt: that is the opening of the body), then a heading,
a message and buttons that follow why the visitor was refused. Only the words change; who is
admitted was decided before.

| State | Heading | Message | Buttons |
| --- | --- | --- | --- |
| `authentication_required` | Sign in to read this | "This content is for members. Please sign in to read it." | Sign in, and Join when a membership or plan rule that is not reversed is on the page |
| `membership_required` | Join to read this | "This content is for members. An active membership is required to read it." | Join |
| `plan_required` | Available on another plan | "This content is available to members on: Gold, Silver." (the plans are named; generic when they cannot be) | View membership options |
| `access_restricted` | This content is restricted | "This content is limited to selected members." or "... people who hold: Journal." (entitlements are named, a segment never is) | none |

The messages are the loop gate's own (`Agend_Content_Access_Frontend::gate_copy()`), so the
two cannot drift. Sign in and Join go to Agend Apps Core's portal setting
(`Agend_Apps_Settings::get_portal_url()`) with a `return_to`; Core has no separate join or
membership address, so they share a destination, as the loop gate's two calls to action always
did. A button whose address is not configured is left out. Any state this version does not
know reads as `access_restricted`. All copy is translatable, and a manual excerpt cannot run a
shortcode: its square brackets are written as entities.

Filters, each given the state and the denied page (null in the neutral preview):
`agend_content_access_placeholder_heading`, `_message`, `_buttons` (a list of
`{label, url, kind}`, anything else dropped) and `_html`, which gets the final markup.
Whatever they return is shown to somebody the page refused, so it must hold nothing from the
body. The markup is plain and carries stable classes (`agend-access-placeholder`,
`agend-access-placeholder--<state>`, `__summary`, `__prompt`, `__heading`, `__message`,
`__actions`, `__button`, `__button--signin`, `__button--join`), with a small inline stylesheet
enqueued only when one is rendered. It is a single line of markup, so `wpautop` has nothing to
break.

#### Choosing the placeholder, `[agend_access_message]` and the pattern

`[agend_access_message]` renders the same heading, message and buttons for the CURRENT denied
request, which it reads from the denial the page gate stored for the request
(`Agend_Content_Access_Frontend::current_gate()`: the state and the rules, never the page's
content). Outside a denied request, such as an author editing the pattern, a preview or a page
that is not denied, it renders a neutral preview that says "Members-only content placeholder"
and offers nothing.

The block pattern **Agend: Members-only content** (`agend-content-access/members-only`, in the
Agend category, `agend`) is a core Group holding a core Shortcode block with that shortcode. A
site inserts it, restyles it, saves it as a synced pattern and chooses that pattern as a page's
placeholder. A block with a rule inside it is judged as part of the denied page, which refuses
this visitor, so keep rules out of a placeholder.

A chosen placeholder is rendered by `Fragment_Rules::fallback_html()`, the same access-checked
render as a hidden block's "show instead": published, no password, a kind that may be shown,
and its own rules admit this visitor. A target that fails any of those, renders nothing, or is
the denied page itself is replaced by the built-in placeholder. The picker (`fallbacks` in the
conditions route, and the Elementor control) offers pages, synced patterns and, when Elementor
is active, Elementor templates, each with no password and no rules.

### Export

`Rules::to_policy()` gives the `@agend/access-policy` arm when the rules say exactly
one: a single kind, match any or one rule, not reversed, Agend providers only
(`is_member` alone is `active_member`, tier ids are `selected_tiers`, segment ids
`segments`, gate keys `selected_entitlements`). No rules is null. Everything else,
unreadable rules included, is `{"mode":"selected_tiers","tier_ids":[]}`, hidden from
everyone, so Agend never serves more than the rules allow. A block or section that
carries any rule is its own fragment, so that policy cannot spread to its siblings.

### Changed from 0.2.0, deliberately

- A block or section is shown to somebody who satisfies both its rules and its post's.
  Two disjoint plan or segment lists used to admit nobody, as the gateway folds them;
  they now admit whoever satisfies both. The export still folds them to nobody, which
  is narrower, never wider.
- The page gate and display conditions use one rule for who is signed in to Agend: the
  bearer. A bearer with no WordPress login is a member to display conditions too, and a
  WordPress login with no bearer is not.
- `is_member` and a held plan must agree. The page gate used the plans held, display
  conditions the gateway flag.
- A segment rule no longer asks for the member standing it never reads.
- Display conditions are exported, as hidden from everyone, instead of not at all.
- A denied page answers with the placeholder, not a 403 or a teaser page: a 200 on the same
  URL that is `noindex` and never cached. Pages that only have a 0.1.x policy get it too.
- A fallback must be a page of a type that can carry rules, a synced pattern or an Elementor
  template. Any other post type renders nothing.
- A denied page is served with a minimal template of the plugin's own (the theme's header, the
  placeholder and the theme's footer), not the page's. Its comments are closed and its feed is
  refused, and the comments on a restricted page are kept out of comment queries and the REST
  comments route for a viewer who may not read it.
- A denial down to an outage shows the placeholder instead of redirecting or sending the
  visitor back, so real members are not bounced away while Agend is unreachable. It used to
  be acted on like any other denial.
- A page's rules are set in the rule picker, in the block editor's document settings and in
  the classic editor's meta box. The page's form of radio buttons and check boxes for the
  five 0.1.x audiences is gone.

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
held without a membership, so neither is a subset of "all active members". The
export folds a fragment of one set-mode into a document of another to nobody, as
the gateway does; WordPress admits the people who satisfy both (see "Access rules").

These modes are what the 0.1.x page panel wrote, and what an Elementor
element saved before the rule list still holds. They are read into the rule list
above, and nothing evaluates them as policies. The page panel shows a page that only
has one as its converted rules, and replaces it when an author saves new ones.

Entitlement keys arrive with the member's standing at no extra cost. Segments
are fetched, once per request, only when a policy being evaluated is a segments
policy.

## Choosing an audience in the editor

A page, a block and an Elementor element all choose rules; the plans, segments and
entitlements are rules in each.

| Surface | What it offers |
| --- | --- |
| Page, block editor | The **Agend Access** document settings panel: "Who can see this page" (the rule picker, Match, Reverse) and "If someone isn't allowed" (see "The Agend Access panel on a page") |
| Page, classic editor | The same panel, mounted in the **Agend Access** meta box |
| Block, block editor | The **Agend Access** sidebar panel: rules, Match, Reverse and Show instead (see "The Agend Access panel in the block editor") |
| Elementor element, Advanced tab | "Who can see this": one searchable list of rules, with Match, a reverse switch and Show instead (see "The Agend Access section in Elementor") |

Rules every surface keeps:

- **A rule that is no longer offered is never dropped.** A plan, segment or entitlement
  that was deleted, or a plan that was deactivated, stays as a chip marked unavailable, so
  the setting can be repaired. Dropping it would quietly change who can read the content.
- **A list that cannot be loaded says why, under the search box.** What the page, block or
  element already holds is still shown and can still be removed, and a save that changes
  nothing keeps it.
- **A page's panel never writes until an author changes it.** Its classic input is empty
  until then, and the block editor sends the field only when it was edited, so opening a
  page, or saving one, never turns a setting the panel cannot show into a public page.
- **Platform capabilities are labelled.** An entitlement the platform itself
  defines, Directory access for example, reads "Platform: Directory access" in
  every panel.

### The editor catalogue

The lists an editor chooses from are fetched on the WordPress server and
reduced there, so the API key never reaches the browser and no pricing, member
count or benefit detail does either. One cached copy of each list serves the
rule vocabulary (the block panel, the page panel and the Elementor list) and the
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
standing or segments. That holds for the parts of a document as well as the
document: an Elementor section's policy, its display conditions and its fallback
are judged for a signed-out reader in a feed, as a block's are.

On its own URL a denied page shows its placeholder, or redirects, as its denial
setting says (see "If someone is not allowed"); everywhere above it keeps showing
the summary only.

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
anonymous visitors. A denied visitor's placeholder or redirect on the page's own
URL is marked the same way, so it is not served to the next visitor, who may be
allowed. The `agend_content_access_uncacheable_render` action fires
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
fails it. The switch is made twice: before the block renders, on
`render_block_data`, and inside the block's own render callback, which is wrapped
when the block type is registered. Some paths render a block without the first (a
navigation's children read from a `ref` post, a widget group's after a third
party short-circuits the render), and none can skip the second.

### What counts as authoring

Gating stands down for somebody who is authoring, and only for them. That is a
wp-admin screen, the block editor's own server-side renders (see Blocks),
Elementor's editor, Elementor's own editor AJAX (rendering a widget, fetching a
template), and Elementor's preview iframe.

- **A carve-out is never granted for a post the user cannot edit.** A wp-admin
  screen, a block editor render and Elementor's edit mode describe the shape of a
  request, and a request is not a capability. Each is authoring for a post only
  when the user can `edit_post` THAT post, so a contributor who opens the block
  renderer, or the REST edit context, with their own draft as the post reads
  nothing of any other post: not its restricted blocks, its restricted body, its
  generated excerpt or what a Latest Posts block lists. A render with no post to
  name, such as a template part, a widget or the list a Latest Posts block builds
  of its own choosing, needs `edit_others_posts`, and the posts such a list prints
  are then judged one by one when each is rendered. The same applies on a wp-admin
  screen: a contributor's Posts list, in extended view, shows the excerpts of
  their own posts only.
- **The preview flag is trusted only on the page it names.** The
  `?elementor-preview=<id>` argument is a query-string value anyone can append to
  any URL, and Elementor only checks that the user may edit the id it carries. So
  it counts only when that id is a positive integer equal to the post the request
  serves. Otherwise a contributor could open a restricted page with the id of
  their own draft appended and read it, restricted blocks and sections included.
- **The preview is authoring for the previewed post only.** Every other post
  rendered during a preview request, such as a query loop listing restricted
  posts, a related posts widget or a template a shortcode pulls in, is what a
  visitor to the same page would be shown, and is gated like it. A block is judged
  against the post it belongs to, so the blocks of any other post are a visitor's
  too, and so are the blocks on a request that is not for a single post.
  Otherwise anybody who can edit one post could print any restricted post into it
  and open the preview to read it. Elementor's editor itself and wp-admin screens
  are authoring for every post the user may edit, and an element whose Elementor
  document cannot be identified is treated as a visitor's.
- **admin-ajax.php is not authoring.** WordPress reports `is_admin()` for it, but
  other plugins render Elementor templates through it for anonymous visitors
  ("load more", filters, tabs). Sections and blocks are judged there as a
  visitor's. Only Elementor's own editor requests are exempt, and only for a
  document the user can edit. Elementor's edit mode holds for the whole request
  once the user may edit the one document the editor is open on, and that request
  can render others (a Loop Grid, a Template widget), so each section is judged
  against the document it belongs to. A section of a document the user cannot
  edit is a visitor's, and so is any section whose document cannot be identified.

### Section rules and the raw body

A rule on a block or an Elementor section hides it from the rendered page. The
raw body keeps everything. A block's text stays in the post's markup beside its
rule, and Elementor writes the text of every section into `post_content` when a
document is saved, without consulting the rules. Anything that reads the raw body
therefore sees the restricted text. For a post whose document carries a block or
section rule:

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
table has been covered, loading each batch's posts and meta in a query apiece
rather than a post at a time. To finish at once, or to look at every post again, for
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
- SEO plugins and themes that generate their own meta descriptions, social previews or
  structured data from the raw body, other than the descriptions listed under "SEO plugin
  descriptions" above. They do not go through `get_the_excerpt`. For a page the reader is
  denied, those listed plugins are given the teaser, but a restricted block or section on a
  page the reader CAN read can still be quoted in its `<head>` by any of them. Set a written
  excerpt, or configure the plugin's description, on such pages.
- **Paths that never reach the denied-page template.** The body is still the placeholder in
  each, but these show or print something about the page around it:
  - **Embeds** (`/embed/`, and a page pasted into another WordPress site). WordPress's embed
    view returns before the page gate decides anything, so the denied-page template does not
    apply. The embed shows the page's comment count (the number only, never a comment), and a
    theme with its own embed template could print the page's custom fields. The count is not
    zeroed here: doing it needs a decision for the page in every embed, and it is a number.
  - **Legacy AMP reader mode and print plugins.** They render a page of their own on
    `template_redirect`, so they build their own template from the page and the plugin's
    template is not the one that runs. What they print about the page is theirs to guard.
  - **A classic theme's `header.php`.** The denied page runs the theme's header, and a header
    that prints one of the page's custom fields (a banner image, a subtitle) prints it, because
    the field is read from the post and not from the body.
- Search results for authors and contributors. They cannot tell themselves from
  a visitor in a query, so they cannot find their own pages with rules by text
  either.

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

**What a block is judged against.** Its own rules AND the rules of the post it
belongs to, by the same rules as an Elementor section: it can narrow what its
post allows and can never widen it, and a visitor must satisfy both. Judged in one
evaluation (`Agend_Content_Access_Access`), so the visitor is asked about once. "The post it belongs to" is the post
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
object, a set-mode with nothing valid selected, a malformed rule, an unknown
provider (reversed or not), a lookup that failed and a check that throws all deny.
An unrecognised v1 mode, or an attribute that is not an object, reads as members
only, as it does for Elementor. A new-shape `agendAccess` that cannot be read (a
`rules` that is not a list of strings, a `match` that is not `any` or `all`, an
`invert` that is not a boolean) denies everyone.

**Fallbacks.** A block removed by its own rules may show replacement content, and
only content the visitor could read on its own: a fallback whose page has rules
that refuse this visitor renders nothing. A fallback is a page, a synced pattern or
an Elementor template, and is rendered its own way (a page through the content
filter, a pattern through `do_blocks()` and the shortcode pass, a template through
Elementor's frontend, which is guarded). A post with a password, one that is not
published, and one of a post type that cannot carry rules at all, which is every
type the rule panel is not offered on, renders nothing: "no rules" means nothing
for a type that has never been able to carry one, so an id typed by hand cannot
print a private post type's body. A block removed by the rules of its post,
or by a v1 audience, gets no fallback. A fallback that names itself ends rather than
recursing.

**Authors are never filtered, for the posts they may edit.** The block editor
renders server-side over REST, so those requests are recognised as authoring, by
`Agend_Content_Access_Frontend::is_authoring_for_post()`. ALL of these hold:

1. WordPress is serving a REST request. A page URL with `?context=edit` is not.
2. The user can `edit_posts`. A query arg, a header or an unauthenticated REST
   call cannot supply this.
3. The route is `/wp/v2/block-renderer/...`, or the request asks for
   `context=edit`.
4. The user can edit the post being rendered, asked of EACH post as it renders and
   not once for the request. The request names one post (the `post_id`, or the
   post the editor loads) and renders many: a Latest Posts block, a query loop, a
   pattern or a template part list or print posts that are not that one, and what
   the request names says nothing of them. Listing other people's posts with
   `context=edit`, or naming your own draft to render a block that lists them, is
   not authoring them. With no post to name (a template part, a widget, a block
   renderer call with no `post_id`) the user must be able to `edit_others_posts`,
   because such a render can reach a post that is not theirs.

wp-admin screens follow the same fourth rule: a post is authored there only when
the user can edit it, so a contributor's list screen shows the excerpts of their
own posts and no one else's. An admin-ajax render is always a visitor's, because
"load more" and live filters print blocks to anonymous visitors through it. An
editor viewing the front end is judged like anyone else, as for Elementor, apart
from the preview iframe, and there only for the blocks of the post being previewed.

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
Elementor section with rules is flagged on save
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
