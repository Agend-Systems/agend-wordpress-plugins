# Scope: WordPress as the identity provider for member auth

Status: draft for review
Author: prepared for Agend Systems, September 2026
Related: the credential-login security review of `agend-apps-core` (same date)

## Summary

Make the WordPress session the identity provider. The member's WordPress
password becomes the only credential they hold on the website, it is managed
in WordPress, and it never reaches the Agend gateway. The member's Agend
identity stays separate: the website obtains short-lived, server-to-server
bearer tokens for it from the WordPress session, and never learns, sets or
propagates an Agend password.

Most of the machinery already exists. The SSO token worker, the identity
resolvers and the `sso` sign-in mode were built in July 2026, before credential
login was layered on in September 2026. This is a return to that architecture
rather than a new one, plus the work needed to make it stand on its own.

There is one genuine gap: `POST /v1/sso/tokens` is resolve-only and will not
create an identity link. Something has to establish the link first. That is the
single decision this scope turns on, and one of its two options needs gateway
work by the platform team.

## 1. Trust model

Current (`credentials` mode):

- Agend owns the credential. A WordPress password is forwarded to
  `POST /v1/auth/register` and becomes the member's platform-wide Agend
  password.
- The bearer comes from a full Agend session (access token plus refresh token)
  stored in WordPress usermeta.

Target (`wordpress` mode):

- WordPress owns the credential. The password never leaves the site, in either
  direction, under any flow.
- The Agend member identity is separate. Whatever password it has is set by the
  member in the Agend portal, and the website neither knows nor can change it.
- The WordPress session is the assertion. Bearers are minted server to server,
  are short-lived, carry no refresh token, and never reach the browser.
- Boundary rule to enforce in code review: no plaintext password is ever passed
  to `agend_apps_api()`.

## 2. What already exists and is reused unchanged

| Component | File | Role in the target model |
|---|---|---|
| `Agend_Apps_Token_Worker` | `agend-apps-core/includes/class-agend-apps-token-worker.php` | Mints `POST /v1/sso/tokens` from the WordPress session. Caches in `_agend_apps_sso_token`, 60 second expiry buffer, negative cache 300s unlinked and 60s on error, no refresh token by design. |
| `agend_apps_current_user_external_id()` | `includes/identity.php:57` | Resolves the WordPress user to the subject the gateway keys on. Filterable. |
| `agend_apps_idp_entity_id()` | `includes/identity.php:88` | Names the account's SSO connection. Filterable. |
| `agend_apps_record_linked_identity()` | `includes/identity.php:132` | Records the Agend user and contact ids. Already shared by three surfaces. |
| Sign-in mode setting | `includes/class-agend-apps-settings.php:21-56` | The switch that unloads the credential path. |
| Conditional bootstrap | `agend-apps-core.php:205-268, 305-306` | Already skips `wp-login-bridge.php`, `member-provisioning.php`, `member-identity.php` and `rest/auth-routes.php` when credential login is off, and force-disables the bridge filter. |
| Account-link state and REST route | `includes/account-link-state.php`, `includes/rest/account-link-routes.php` | The "is this member linked" surface. |

Sibling plugins are already source-neutral and need no change. `agend-loop-sync`
(`class-agend-loop-sync-user-sync.php:34`) and `agend-entitlement-mirror`
(`class-entitlement-sync.php:113`) hook WordPress's own `wp_login`, not the
Agend login. `agend-apps-shop` and `agend-embed` have no credential-login
dependency at all.

Switching the mode alone already closes every password propagation path
identified in the security review, because the three files that carry them are
not loaded.

## 3. The gap: how a member gets linked

`POST /v1/sso/tokens` is documented resolve-only at
`includes/api/sso.php:252-258`: "the gateway never provisions, so an unlinked
member returns a 404 `EXTERNAL_IDENTITY_NOT_FOUND`". A row must exist in
`sso_identities` before the worker can mint anything. Today only a real SAML
assertion creates one.

### Option A: SAML round trip (works today, no gateway change)

The site runs a SAML IdP plugin (`agend-saml-idp` or miniOrange). On first need
the member is bounced through IdP-initiated SSO, the assertion links the
identity, and from then on the token worker mints silently off the WordPress
session. `agend-embed` already implements the host-side kick-off contract and a
driver filter (`agend_embed_sso_kickoff_url`).

Cost: every site needs a SAML IdP plugin installed and an SSO connection
configured in Agend with `allow_idp_initiated` enabled. That is heavy for a
small association site, and it makes the linking path depend on a plugin that is
not in this repo.

### Option B: server-to-server link endpoint (needs gateway work)

A gateway endpoint that, given the site's account-scoped API key, an IdP entity
id, an external id and the member's email, resolves or creates the contact and
creates the identity link. Either a new `POST /v1/sso/identities` or a
provisioning flag on the existing mint call. WordPress calls it once, on first
login, and never again for that member.

This removes the SAML dependency and is the honest fit for "the WordPress
session is the IdP". It is not a new trust grant: the site already holds a key
that can mint impersonation-grade tokens for any linked member, so the ability
to create the link is a smaller step than the ability to mint.

Requires a platform decision from the Engineering Lead.

**Recommendation:** Option B as the target, Option A as the interim for any site
that already has SAML configured. Build the linking step behind a small internal
interface so the two are interchangeable and sites can move without a data
migration.

## 4. External id scheme

The default external id is `imk_membership_number` (`includes/identity.php:36`),
which is written by the external iugo-membership-kiosk plugin. Nothing in this
repository writes it. A site without Upbeat has no such meta, so
`agend_apps_current_user_external_id()` returns an empty string and every member
is permanently unlinked.

The new mode needs a default that every WordPress user has, that is stable
across email and name changes, and that is never reassigned to a different
person.

Recommendation: mint an opaque per-user UUID on first link, store it in
underscore-prefixed usermeta, and fall back to it whenever the configured meta
key resolves empty. Keep the existing option and filter so Upbeat sites continue
to resolve on the membership number. Do not use the WordPress user id, which can
be reused after deletion on some hosts.

Security constraint: the external id is now an authentication primitive. Anyone
who can write that usermeta, or hook `agend_apps_current_user_external_id`, can
impersonate another member to the gateway. It must not be user-editable, must
not appear in the profile UI, and must not be exposed through any REST route.

## 5. Work breakdown

### 5.1 Mode

Add a third value alongside `credentials` and `sso` in
`class-agend-apps-settings.php:21-56`. `credential_login_enabled()` must return
false for it, which gives the correct unloading behaviour for free. Add the
third radio and honest help text to
`admin/class-agend-apps-admin.php:711-733`, naming the credential boundary
explicitly so the site admin understands what changes.

### 5.2 Linking on login

New file `includes/wp-idp-link.php`, loaded only in the new mode. On `wp_login`:
resolve or mint the external id, check link status, create the link by whichever
mechanism is configured, record the ids through
`agend_apps_record_linked_identity()`, and clear the token worker's negative
cache. Add a lazy fallback on first bearer need so sessions that predate the
hook still link.

### 5.3 Membership snapshot

`agend_apps_member_sync_membership_meta()` hard-gates on
`Agend_Apps_Member_Session::has_session()` at
`includes/member-membership-sync.php:79`. In the new mode that is always false,
so the snapshot never populates and membership-based content gating silently
degrades. The guard must become "a bearer is resolvable for this user", covering
both providers. The `wp_set_current_user()` impersonation the function already
uses works for the token worker too, since that also reads
`get_current_user_id()`.

The same guard blocks the membership webhook at
`includes/rest/webhook-receiver-routes.php:220`. Fix both.

Add a `wp_login` trigger for the snapshot, since there is no longer a login
response to hang it off.

### 5.4 Account-link state

`includes/account-link-state.php:133-144` short-circuits to `linked` for any
logged-in WordPress user in credentials mode, with no gateway round trip. The
new mode must not inherit that shortcut. It needs the real status check, so an
unlinked member is visible rather than silently broken.

### 5.5 Role sync

Recommend dropping Agend-driven WordPress role assignment in the new mode.
`includes/member-identity.php:104` maps the gateway's `is_account_admin` to the
WordPress `administrator` role. There is no login response in the new mode to
carry that flag, and the security review flagged it as full WordPress admin,
including plugin install and PHP execution, reachable from an Agend credential.
WordPress owns WordPress roles.

### 5.6 Session lifecycle

Hook `wp_logout` to `Agend_Apps_Token_Worker::clear_token()` and
`clear_negative_cache()`. Nothing does this today, so a minted token survives
logout in usermeta until it expires.

`Agend_Apps_Member_Session` is instantiated unconditionally at
`agend-apps-core.php:278`. Leave it registered during migration so legacy
sessions drain, then stop constructing it in the new mode.

### 5.7 Widgets

`includes/records/render/member-login.php:110` returns an empty string whenever
credential login is off. In the new mode the Member Login widget should render a
WordPress login form, or be retired in favour of the theme's.

The Elementor Header Auth widget self-suppresses in any non-credentials mode
(`agend-elementor/includes/widgets/class-agend-elementor-header-auth.php:364-372`).
That is wrong here: it should send signed-out visitors to `wp-login.php` and
signed-in members to the portal handoff. Update the Gutenberg editor notice at
`includes/records/blocks.php:233-248` to match.

### 5.8 Password surfaces stay WordPress-native

Nothing in the repository filters `allow_password_reset`, `lostpassword_url` or
`retrieve_password_message`, so WordPress's own flows already work. In the new
mode they become the canonical ones. Leave them intact deliberately and add a
test asserting they are not filtered, so a future change cannot break the
member's only way to recover their account.

Remove or hard-guard `agend_apps_auth_change_password()`
(`includes/api/auth.php:327`). It has no caller today and would push a password
to the gateway if one were added.

### 5.9 Content access diagnostic

`agend-content-access` fails closed on an empty bearer
(`includes/class-agend-content-access-decision.php:74-81`). It is the
highest-risk consumer in the new model: any linking gap denies members their
content with no visible cause and no error anywhere.

Add an admin diagnostic panel showing, for a chosen user: the external id
resolved, the link status, the last mint result and the negative-cache state.
Without it, "member cannot see gated content" is undiagnosable in the field.

### 5.10 Migration for existing credentials-mode sites

Switching the mode stops new password propagation but does not undo what has
already happened. Members whose WordPress password was promoted still have it as
their Agend password.

Sequence:

1. Switch the mode. Existing stored sessions drain naturally, which the current
   `sso` mode help text already promises.
2. Members link on next WordPress login.
3. Advise members to change their Agend password in the portal if they want the
   two genuinely separate. This is a customer communication item, not a code
   one, and it is the point where the disclosure gap from the security review
   gets closed.

Members created by the provisioning hook hold a random Agend password they never
knew. That is fine and is already the desired end state for them.

## 6. What this closes from the security review

| Finding | Status in the new mode |
|---|---|
| 1. WordPress password silently promoted to a platform credential | Closed. The `user_register` hook and the login bridge are not loaded. |
| 2. Reset propagates everywhere, divergence causes a silent lockout | Closed. No gateway login at `wp-login.php`, so a WordPress password is never refused. |
| 3. `/auth/register` is a platform-wide account oracle | Closed. The `/auth/*` routes are not registered. |
| 4. Gateway login adopts a pre-existing WordPress account by email | Closed. There is no gateway login to adopt from. |
| 5. Agend account admin maps to WordPress administrator | Closed if 5.5 is adopted. |
| 6. Refresh tokens stored unencrypted in usermeta | Closed. Minted tokens are short-lived and carry no refresh token. |
| 7. REST login attaches the session to whoever is already logged in | Closed. The route is gone. |
| 8. Live reset token left in the URL | Not applicable. The in-WordPress reset flow goes away. |
| 9. Verbose gateway errors on public endpoints | Reduced. The public auth routes go away. |
| 10. Forced 14 day persistent cookie | Closed. WordPress's own remember-me choice applies. |

## 7. New risks this introduces

- **The API key becomes an impersonation primitive.** With `sso.tokens.create`
  it mints member-grade tokens for any linked external id. Key handling matters
  more than before, and each site's key should hold only the scopes it needs.
  The key is already encrypted at rest via the libsodium secret store, which is
  the right baseline.
- **External id integrity is now the authentication boundary.** See section 4.
- **Silent degradation.** Every bearer consumer treats an empty bearer as
  anonymous except content access, which denies. A linking failure is invisible
  without the diagnostic in 5.9.
- **Blast radius is bounded but not eliminated.** Compromising one WordPress
  site still allows impersonating that account's members to the gateway. The
  improvement is that it no longer yields a platform-wide credential or a
  long-lived refresh token, so it cannot reach other associations.

## 8. Decisions taken

Resolved. The build plan is `docs/PLAN-wordpress-idp-option-b.md`.

1. **Password management surface.** WordPress owns it. The Agend portal links
   out to the WordPress site's password management for members whose identity is
   linked through a connection flagged as a WordPress connection, rather than
   offering its own form.
2. **Linking mechanism.** Option B, the server-to-server link endpoint. This
   needs the platform team; the endpoint contract is section 2 of the plan.
3. **External id scheme.** A minted GUID, with the existing option and filter
   retained so Upbeat and SAML sites keep resolving on their own scheme.
4. **Mode shape.** A third mode, `wordpress`, beside `credentials` and `sso`.
   Default stays `credentials` so existing installs do not change on upgrade.
5. **Role mapping.** Agend no longer assigns WordPress roles in the new mode.
6. **Admin surface.** A dedicated Identity and SSO settings page, with the
   linking mechanism under explicit admin control so it can stand down when
   `agend-saml-idp` is present.

## 9. Effort shape

Sections 5.1, 5.3, 5.4, 5.6, 5.7 and 5.8 are self-contained changes inside
`agend-apps-core` and one Elementor widget, each small and independently
testable against the existing PHPUnit harness.

Section 5.2 is the substantive new code, and its size depends on decision 2.
Against Option A it is mostly orchestration of an existing flow. Against Option B
it is a single new API wrapper plus the `wp_login` handler, and it is blocked on
the gateway endpoint existing.

Section 5.9 is a new admin screen, small but worth doing before any site goes
live on the new mode.

Section 5.10 is process and customer communication rather than code.
