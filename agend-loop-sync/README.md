# Agend Loop Sync

Keeps Agend Loop in step with the membership records behind a WordPress
site. Members' Loop accounts are refreshed automatically, and every Upbeat
committee becomes a private Loop channel whose members and moderators
follow the committee roster.

All traffic goes through the Agend gateway (`/v1/loop/integration/*`) using
the connection configured in Agend Apps Core. The plugin never talks to Loop
directly and never modifies the membership plugin.

## Capabilities at a glance

- **Member sync.** When a member signs in, changes their profile, has their
  WordPress role changed, or has their record updated in Upbeat, their Loop
  account is refreshed: their display name is updated and Loop adds them to
  any channels their member role grants.
- **Committee channels.** Each Upbeat committee is created and kept up to
  date as a private Loop channel, named after the committee.
- **Committee roles become channel roles.** Committee positions such as
  Chair, Secretary or Member map to Loop channel roles (owner, moderator,
  member) through an editable mapping.
- **Retiring committees.** Channels for committees deleted in Upbeat, or
  left with no members on this site, are archived (hidden). Removing members who leave a committee is built in
  and takes effect once the Agend gateway supports it (see
  [Removing access](#removing-access)).
- **Choose which committees sync.** All web-visible committees, committees in
  selected Upbeat groups, or an explicit allow-list.
- **Scheduled and on demand.** Committees sync hourly, twice daily or daily,
  and an administrator can run a sync at any time.
- **One-click backfill.** Refreshes every member's Loop account in batches
  of 100, for the initial rollout.
- **Safe by default.** Ships disabled and in dry-run mode, never removes
  anyone when Upbeat data is incomplete, and never slows down sign-in.
- **Built-in diagnostics.** Read-only reports of committees, committee role
  names and committee members, plus a last-run summary and activity log on
  the settings screen.

## Requirements

- WordPress 6.0+ and PHP 7.4+.
- **Agend Apps Core**, active and connected to the Agend gateway. Provides
  the gateway client and Loop API bindings. The gateway API key needs the
  `loop.users.sync`, `loop.committees.browse`, `loop.committees.create` and
  `loop.committees.delete` scopes.
- **Agend Membership** (`iugo-membership-kiosk`), active. Provides access to
  Upbeat and the `imk_membership_number` user meta used to identify members.
- Single sign-on from this site into Loop (the Agend SAML IdP). The plugin
  reads the IdP entity ID from the `wp_saml_idp_settings` option, falling
  back to `<site URL>/saml/metadata`, and sends it on every call so the
  gateway matches members against this site's SSO connection only.

If either required plugin is missing, the plugin shows an admin notice and
does nothing else.

## How it works

### Member identity

A member is identified in Loop by their **Upbeat membership number**, the
same value the site's SAML IdP asserts at sign-in. A WordPress user is only
synced when they have:

- a membership number (`imk_membership_number` user meta),
- that is made up of digits only (up to 18 digits), and
- a valid email address.

Users who do not meet these rules are skipped and the reason is logged.

Loop matches the membership number against the identities created through
this site's SSO connection, then falls back to the email address among the
organisation's Loop members.

Syncs only ever update an **existing** Loop account. Loop accounts are
created when a member first signs in to Loop through SSO; a member without
one is reported as unresolved and picked up on a later sync once their
account exists.

### Member sync

| Trigger | When it runs |
|---|---|
| Sign-in (`wp_login`) | Deferred to the end of the request, after the response is sent where the server supports it, so a slow gateway never delays the sign-in redirect. |
| Profile update (`profile_update`) | Immediately. |
| Role change (`set_user_role`) | Immediately. |
| Upbeat record changed (`iugo_membership_kiosk_invalidate_member_caches_complete`) | When the membership plugin reports an upstream create, update or delete for the member, so changes made in Upbeat reach Loop without waiting for a scheduled run. |

Each sync:

- updates the member's display name in Loop, and
- adds the member to the channels that Loop's role-to-channel settings grant
  their member role. The member role is assigned when the member's Loop
  account is created at SSO sign-in, from the groups the IdP asserts.
  Channels are only ever added: a member is not removed from a channel, and
  an existing channel role is not changed, when their role changes.

The plugin also sends the member's WordPress role slugs, but Loop does not
currently act on them.

A user is synced at most once per request, even when several triggers fire
together. A failed sync is logged and never blocks the WordPress action that
triggered it.

### Committee sync

Upbeat lists committee members by name rather than membership number, so the
sync works member by member:

1. Read the committee list from Upbeat once, to know which committees exist,
   whether each is web-visible, and which groups it belongs to.
2. For every WordPress user with a membership number, ask Upbeat for that
   member's committee activities.
3. Combine the results into a roster for each committee.
4. Apply the committee filter, then send each roster to Loop.
5. Clear channels whose committee has no local members left, and archive
   channels for committees that no longer exist in Upbeat (see
   [Removing access](#removing-access)).

Because rosters are built from local users, a channel only ever contains
members who exist on this WordPress site.

Each committee is keyed in Loop by its Upbeat unique ID, so renaming a
committee in Upbeat renames its channel rather than creating a new one.
Member activities name their committee but carry no ID, so the ID is looked
up in the committee list by name. As a result:

- A committee with no unique ID in Upbeat, or whose name is shared by another
  committee, is skipped and a warning is logged.
- A new or renamed committee is skipped until the committee list catches up
  (see [Things to know](#things-to-know)).

#### Role mapping

Every committee role is mapped to a Loop channel role using the
**Committee role mapping** setting, one `Committee Role = channel role`
line per mapping. The default mapping is:

| Committee role | Loop channel role |
|---|---|
| Chair, Convenor, President | owner |
| Vice-Chair, Deputy, Secretary | moderator |
| Member | member |

- Matching is exact first, then case-insensitive.
- A committee role with no mapping becomes `member`, and a warning is logged.
- A member holding several roles on one committee gets the most senior
  channel role (owner, then moderator, then member).
- A channel can have several owners and moderators.

Use the **Scan members for committees** or **Inspect committee catalogue**
diagnostic to see the exact role names used in Upbeat.

#### Choosing which committees sync

| Filter | Committees synced |
|---|---|
| All web-visible committees (default) | Every committee marked as allowed on the web in Upbeat. |
| Committees in selected groups | Web-visible committees that belong to at least one of the listed Upbeat groups (exact match). |
| Only committees on the allow-list | Exactly the listed committees (names match case-insensitively), whether or not they are web-visible. |

#### Removing access

When every Upbeat lookup in a run succeeds, the sync treats the rosters as
complete:

- **Deleted committees are archived.** A channel this plugin created whose
  committee no longer exists in Upbeat is archived and hidden from members.
  Archiving is reversible: if the committee comes back, the next sync
  restores its channel.
- **Departed members are removed (pending gateway support).** The plugin
  asks Loop to replace each channel's roster with the current one, which
  would remove members who left. The Agend gateway does not support this
  yet: it adds and updates roster members but never removes them. Until it
  does, the settings screen shows "Gateway did not confirm replace mode;
  departed members are not being removed", and departed members must be
  removed from the channel in Loop by hand.
- **Committees with no local members left are archived.** A committee that
  still exists in Upbeat but no longer has any member on this site has its
  channel archived, like a deleted committee. If local members return, the
  next sync restores the channel.

If any lookup fails, or member activities name a committee the committee
list does not have yet, the run switches to **merge mode**: members are added
and updated, but nothing is removed or archived, because a missing member
cannot be told apart from one who left. The settings screen and log record
when this happens. If the committee list itself cannot be read from Upbeat,
the run stops before making any change.

### Initial backfill

**Backfill all users** runs the member sync for every member with a
membership number in one pass, in batches of 100, using the gateway's bulk
endpoint. Run it once at rollout so existing Loop accounts get current
display names and role-based channels without waiting for each member to
sign in. Members without a Loop account are counted as failed; they are
picked up after their first SSO sign-in. It honours dry-run mode.

## Safety and reliability

- **Off and in dry run on install.** The plugin is disabled until enabled,
  and dry run starts on, so a fresh install never writes to Loop unattended.
  In dry run every payload is logged instead of sent.
- **No removals or archiving on incomplete data.** See
  [Removing access](#removing-access).
- **Sign-in is never held up.** Sign-in syncs run after the response is sent.
- **Bad records cannot break a committee.** Non-numeric membership numbers
  are skipped and malformed emails are left out, rather than sent and
  rejected along with the whole roster.
- **Self-healing schedule.** If the scheduled event goes missing (for
  example after a cron reset or a file-based deploy), it is recreated on the
  next page load. Changing the schedule takes effect straight away.
- **Stale-sync warning.** The settings screen warns when the last committee
  sync is more than twice the scheduled interval old, which usually means
  WP-Cron is not running.
- **Privacy-aware logging.** The activity log never stores emails, names or
  avatar URLs, and caps each entry's size.
- **Settings survive deactivation.** Deactivating clears the scheduled event
  but keeps all settings.

## Data sent to Loop

| Sync | Fields |
|---|---|
| Member | IdP entity ID, membership number, email, display name, WordPress role slugs. Loop stores only the display name; the email is used to find the account when the membership number does not match. |
| Committee | IdP entity ID, committee slug and name, active flag, sync mode, and for each member: membership number, channel role, the original committee role name, and email (when valid). Loop uses the email only if the membership number does not match an account. |

No other profile data is sent.

## Settings

**Settings > Loop Sync** (requires the `manage_options` capability).

| Setting | Default | Purpose |
|---|---|---|
| Enable plugin | Off | Master switch for all syncing. |
| Dry run | On | Log what would be sent without sending anything. |
| User sync | On | Member sync triggers and backfill. |
| Committee sync | On | Scheduled and manual committee syncs. |
| Committee sync schedule | Daily | Hourly, twice daily or daily. |
| Committee role mapping | See [Role mapping](#role-mapping) | Committee role to channel role, one per line. Lines with a channel role other than owner, moderator or member are dropped on save. |
| Committee filter | All web-visible committees | See [Choosing which committees sync](#choosing-which-committees-sync). |
| Committee groups | Empty | One Upbeat group per line, for the groups filter. |
| Committee allow-list | Empty | One committee name per line, for the allow-list filter. |

## Admin tools

All on the settings screen. Each action is restricted to administrators and
nonce-protected.

| Tool | What it does | Calls Loop? |
|---|---|---|
| Sync committees now | Runs a full committee sync immediately. | Yes (unless dry run) |
| Backfill all users | Pushes every member to Loop in batches of 100. | Yes (unless dry run) |
| Scan members for committees | Lists local members who sit on a committee, with their committees, roles, email and membership number, plus a count of each role name. Up to 200 members are listed. | No |
| Inspect committee catalogue | Lists every Upbeat committee with its unique ID, web flag, groups and activity count, plus every role name in use. | No |

The screen also shows:

- **Last run:** time of the last committee sync and a summary of committees
  synced and committees archived. Member counts (applied, skipped because
  they have no Loop account, removed) and the membership numbers that did
  not match a Loop account appear only when the gateway reports them. The
  current gateway does not, and the screen says so rather than showing
  zeros.
- **Recent activity:** the 30 most recent log entries (the last 200 are
  kept). With `WP_DEBUG` on, entries also go to the PHP error log.

Scan and inspect results are kept for 5 minutes so the screen can display
them.

## Go-live checklist

1. Confirm Agend Apps Core is connected to the gateway, Agend Membership is
   active, and members can sign in to Loop through SSO.
2. On **Settings > Loop Sync**, tick **Enable plugin** and leave **Dry run**
   on.
3. Run **Inspect committee catalogue** and **Scan members for committees**.
   Add every committee role name found to the role mapping, and choose the
   committee filter.
4. Run **Backfill all users** and **Sync committees now**, then check
   **Recent activity** to confirm the payloads look right.
5. Turn **Dry run** off. Run **Backfill all users**, then **Sync committees
   now**.
6. Review the backfill result. Members counted as failed do not have a Loop
   account yet; they are picked up after their first SSO sign-in.
7. Confirm the schedule, and make sure WP-Cron fires (see below).

## Things to know

- **New and renamed committees can take up to a day to sync.** Until the
  cached committee list includes the new name, that committee is skipped and
  each run makes no removals or archiving. Once the list refreshes, the
  committee syncs, and a renamed committee keeps its existing channel.
- **Removing a committee from the filter freezes its channel.** It stops
  being updated but is not archived while the committee still
  exists in Upbeat.
- **Members need a Loop account first.** Syncs update existing accounts;
  they do not create them.
- **Upbeat changes can take time to arrive.** The membership plugin caches
  the committee list for up to 24 hours and each member's committee
  activities for up to 1 hour, so a sync reflects Upbeat as of those caches.
- **One site per Agend account.** Archiving considers every committee
  channel synced into the Agend account, so two WordPress sites syncing
  committees into the same account would archive each other's channels.
- **Run time grows with membership size.** A committee sync makes one Upbeat
  call per member (cached by the membership plugin). On large memberships,
  leave it to the schedule; manual runs lift the PHP time limit.
- **WP-Cron depends on site traffic.** On low-traffic sites, or for
  predictable timing, trigger WP-Cron from a server cron job.
- **Role-based channels come from Loop, not WordPress.** Which channels a
  member role grants is configured in Loop's integration settings, and the
  role itself comes from SSO. WordPress roles do not affect Loop access.
- **Role-based channels are add-only.** Losing a role does not remove the
  member from the channels it granted.

## Developer notes

- Entry point `agend-loop-sync.php`; classes in `includes/`, the settings
  screen in `admin/` and `templates/`.
- Gateway calls go through Agend Apps Core's Loop bindings in
  `agend-apps-core/includes/api/loop-integration.php`
  (`agend_apps_loop_sync_user()`, `agend_apps_loop_bulk_sync_users()`,
  `agend_apps_loop_sync_committee()`, `agend_apps_loop_list_committees()`,
  `agend_apps_loop_archive_committee()`). Their request and response filters
  are the extension points.
- Settings are stored as individual `agend_loop_sync_*` options. The cron
  hook is `agend_loop_sync_committee_event`.
- Tests live in `tests/` and run as part of the repository's PHPUnit suite
  (`composer test` from the repository root).
