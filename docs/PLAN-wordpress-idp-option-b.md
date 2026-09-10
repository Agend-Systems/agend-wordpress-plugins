# Build plan: WordPress as IdP, Option B (server-to-server identity link)

Status: draft for review
Companion to: `docs/SCOPE-wordpress-idp-member-auth.md`
Audience: Agend engineering, plus the platform team for section 2

## Decisions taken

1. **Linking mechanism: Option B.** A server-to-server link endpoint on the
   gateway, not a SAML round trip. Section 2 is the platform ask.
2. **Password management: the portal links out to WordPress.** When a member's
   identity is linked through a connection flagged as a WordPress connection,
   the Agend portal stops offering its own password form and links to the
   WordPress site's password management instead.
3. **External id: a minted GUID.** The `imk_membership_number` default is
   Upbeat-specific and absent on most installs. See section 5 for why a GUID is
   preferred over the WordPress user id.
4. **Admin surface: a dedicated Identity and SSO settings page**, separate from
   the main Agend Apps screen, with detection-driven control over the linking
   mechanism including the ability to stand down when `agend-saml-idp` is
   present.

## 0. Update: what the gateway actually shipped

Sections 2 and 3 were written as an ask, before the gateway existed. It has
since shipped on `agend-dashboard` and differs from that ask. Where they
disagree, this section is correct.

**Both open questions in 2.3 are answered.** The endpoint creates the platform
user when none exists, passwordless and recoverable only through the portal
reset. And it auto-creates a WordPress connection when `idp_entity_id` matches
none, provided the key also holds `sso.connections.create`, otherwise 403.

**The endpoint.** `POST /v1/sso/identities`, scope `sso.identities.create` (not
`sso.identities.link`), 30 requests a minute. Body is strict: `idp_entity_id`,
`external_id`, `email`, and an optional `contact` with `first_name` and
`last_name`. There is no `create_contact` field; contact provisioning is a
per-connection `jit_contact_provisioning` setting.

**There is a third outcome this plan did not have.** A `202` withholds the link
when the email resolves to an existing platform user who already holds a
password and whose contact is not verified. Nothing is created. The gateway
emails that address a single-use confirm link, and completes the link itself
when the member follows it.

That is not an edge case. It is every member whose Agend password was silently
set from their WordPress password under credential-login mode, which is exactly
the population this whole change exists to rescue. On a migrating site it is the
majority of first attempts.

Two consequences for the client, both load-bearing:

- Re-posting while withheld rotates the pending token and can send another
  email. So a pending member is never re-posted. The read-only
  `GET /v1/sso/identities/status` is polled instead; it mints nothing and emails
  nothing.
- Nothing is pushed back when the member confirms. Polling is the only way the
  site finds out, and it cannot wait for the next `wp_login`, because the member
  confirms mid-session and never logs in again. See section 4.2.

**Section 3 is superseded and no longer needed for new members.** There is no
`password_management_url` or `password_reset_url` on the connection. Linked
members are created passwordless, so there is no second credential to diverge
from the WordPress one. `post_link_return_url` on the connection is a different
thing: where the gateway redirects a member after they confirm ownership. It is
worth setting to a page on the WordPress site, and it must be an absolute
`https` URL set through `sso.connections.update`, never per request.

**Setup trap.** Auto-created connections have `jit_contact_provisioning` off, so
a fresh site auto-creates the connection and then returns `404
CONTACT_NOT_FOUND` for every member without a CRM contact. Turning that on is a
required setup step, not an optimisation.

## 1. Shape of the change

WordPress authenticates the member. On login the site binds that WordPress user
to an Agend identity once, server to server, using the account-scoped API key.
From then on `Agend_Apps_Token_Worker` mints short-lived bearers off the
WordPress session exactly as it does today. No password crosses the boundary in
either direction, ever.

Three identifiers carry the whole model:

| Identifier | Owner | Where it lives |
|---|---|---|
| Connection entity id | The site, registered once at the gateway | `agend_apps_idp_entity_id()`, `includes/identity.php:88` |
| External id | The site, one per member, permanent | New usermeta, see section 5 |
| Agend user and contact ids | The gateway, returned on link | `_agend_apps_supabase_user_id`, `_agend_apps_contact_id`, `includes/identity.php:111,121` |

The pair (connection entity id, external id) is the identity key. If either
changes for an existing member, they unlink and re-link as a different person.
Both must be treated as write-once in practice, and the admin UI must make that
hard to get wrong. This is the single most damaging mistake available in this
design.

## 2. Platform ask: the link endpoint

This section is the thing to take to the Engineering Lead. Nothing on the
WordPress side of section 3.2 can ship without it.

### 2.1 Endpoint

```
POST /v1/sso/identities
Scope: sso.identities.link   (new; sibling to the existing sso.identities.read)
Auth:  x-api-key, account-scoped as today
```

Request:

```json
{
  "idp_entity_id": "https://assoc.example.org/agend-wp",
  "external_id": "0f1d5a1e-3c2b-4f77-9a10-6b2f7c4d8e33",
  "email": "member@example.org",
  "contact": { "first_name": "Ada", "last_name": "Lovelace" },
  "create_contact": false
}
```

Response, 200 or 201:

```json
{
  "data": {
    "linked": true,
    "created": false,
    "user_id": "…",
    "contact_id": "…"
  }
}
```

### 2.2 Semantics to agree

1. **Idempotent.** The same pair returns the existing link rather than an error.
   WordPress retries on transient failures and must not create duplicates.
2. **Account-scoped contact resolution.** The email resolves a contact on the
   account the API key belongs to, and nowhere else. It must never reach a
   contact on another account. This is the security boundary that keeps one
   association's site from touching another's members.
3. **Conflict, not silent re-point.** If the email resolves to a contact already
   linked to a different external id under the same entity id, return 409 with a
   distinguishable code. WordPress records it and surfaces it in the diagnostic
   rather than retrying. Re-pointing a link must be an explicit dashboard
   action, never an API side effect.
4. **No password field.** The endpoint must not accept one and must not set one.
   If the platform-level auth user has to be created for `/v1/sso/tokens` to
   mint against, it is created passwordless, recoverable only through the
   portal's own reset.
5. **`create_contact` is explicit.** Default false. A site that wants
   just-in-time contact creation opts in, so an accidental WordPress user import
   cannot silently populate the CRM.
6. **Rate limited per API key**, and **audited**: every link creation is an
   identity-binding operation and should be attributable to the key that made
   it.

### 2.3 Open questions for the platform team

- Does `/v1/sso/tokens` require a platform auth user to exist, and if so does
  the link endpoint create it, or is a contact-only link sufficient to mint?
- Should the connection be auto-created on first link for a WordPress
  connection, or must the association create it in the dashboard first? The
  dashboard-first answer is safer and matches how webhook subscriptions and the
  `redirect_url_allowlist` are already handled.
- What is the intended lifecycle when a WordPress user is deleted? There is no
  unlink call in this plan and probably should be one.

## 3. Platform ask: the portal password link-out

WordPress currently has no channel to publish a URL to the gateway. Confirmed:
`GET /v1/sites/config` is read-only with scope `sites.config.browse`
(`includes/api/sites.php:25-48`); the SSO connection object documents only
`provider_name` and `idp_certificate` (`includes/api/sso.php:92-138`); and the
one existing case of WordPress sending its own URL upward, `redirect_to` on
forgot-password (`includes/rest/auth-routes.php:873-876`), depends on the
gateway's `redirect_url_allowlist` being maintained by hand.

### 3.1 Proposed model

Add to the SSO connection object:

- `connection_type`: `saml` or `wordpress`.
- `password_management_url`: where the portal sends a member who wants to change
  their password.
- `password_reset_url`: where the portal sends a member who has forgotten it.

Portal behaviour for a member whose identity is linked through a
`wordpress` connection:

- The change-password form is replaced by a link to `password_management_url`.
- The forgot-password flow links to `password_reset_url` instead of sending an
  Agend reset email.
- If the member also holds a legacy Agend password from the credential-login
  era, offer an explicit "remove Agend password" action, so the two credentials
  cannot silently diverge. This is the cleanup path for existing sites.

### 3.2 How WordPress sets those fields

Preferred: `PATCH /v1/sso/connections/{id}` using the existing
`agend_apps_sso_update_connection()` wrapper (`includes/api/sso.php:139-168`),
triggered by an explicit "Publish to Agend" action on the new settings page
rather than silently on every save. This is connection-level configuration and
belongs on the connection.

Constraint: that call needs `sso.connections.update`, an administrative scope a
member-facing site key may well not hold. So the page must check the cached
scopes via `Agend_Apps_Key_Scopes` and, when the scope is absent, show the two
URLs read-only with copy buttons and instructions to paste them into the Agend
dashboard. That degrades to the same manual pattern the team already uses for
`redirect_url_allowlist`, which is acceptable.

Default URL values, both filterable and overridable on the settings page:

- `password_management_url`: the site's member account page, falling back to
  `get_edit_profile_url()`.
- `password_reset_url`: `wp_lostpassword_url()`.

## 4. WordPress build

### 4.1 Mode

`includes/class-agend-apps-settings.php:21-56` currently treats any value other
than exactly `sso` as `credentials`. Add `MEMBER_AUTH_WORDPRESS = 'wordpress'`
and widen both the resolver and `sanitize_member_auth_mode()`
(`admin/class-agend-apps-admin.php:505`) to a three-value vocabulary. Default
stays `credentials` so existing installs do not change behaviour on upgrade.

`credential_login_enabled()` must return false for the new mode, which gives the
correct unloading at `agend-apps-core.php:205-268` and `305-306` for free.

The test double at `tests/doubles.php:21-54` mirrors this logic and must be
updated in the same commit, or `MemberAuthModeTest` will pass against stale
behaviour.

### 4.2 The link step

New file `includes/wp-idp-link.php`, loaded only in `wordpress` mode alongside
the existing conditional requires.

```
agend_apps_wp_idp_link_user( int $user_id ): string   // returns a state constant
```

Flow:

1. Resolve the external id, minting one if absent (section 5).
2. Short-circuit on a recorded link state that is `linked`, so the gateway is not
   called on every login.
3. Call a new `agend_apps_sso_link_identity()` wrapper in `includes/api/sso.php`,
   following the existing convention in that file: no request-args filter and no
   response filter, because the payload is an identity assertion and must not be
   rewritable by sibling plugins (the same reasoning already applied at
   `includes/api/sso.php:272-283`).
4. On success record the ids through `agend_apps_record_linked_identity()`
   (`includes/identity.php:132`), stamp the link state, and call
   `Agend_Apps_Token_Worker::clear_negative_cache()`.
5. On 409 record a conflict state and stop. Do not retry.
6. On transport or 5xx, leave a retryable state and let the next login try.

Triggers, following the established pattern in
`agend-entitlement-mirror/includes/class-entitlement-sync.php:182-207`:

- `wp_login`, with a per-user throttle transient and a `try`/`catch` so a
  gateway failure can never delay or break authentication. Pass an explicit
  short timeout on the request; the client default is 15 seconds
  (`class-agend-apps-api.php:193`), which is far too long to sit on the login
  path. Three to five seconds is the right order.
- `user_register`: mint the external id only. Linking on creation is a separate
  opt-in setting, because this hook fires for every WordPress user including
  administrators and spam registrations.
- A lazy fallback when a bearer is needed and no link state exists, covering
  sessions that predate the feature.

### 4.3 Membership snapshot

`agend_apps_member_sync_membership_meta()` hard-gates on
`Agend_Apps_Member_Session::has_session()` at
`includes/member-membership-sync.php:79`, which is always false in the new mode.
Replace with a provider-agnostic "a bearer is resolvable for this user" check.
The `wp_set_current_user()` impersonation the function already performs works
for the token worker unchanged, since that also reads `get_current_user_id()`.

The same guard blocks the membership webhook at
`includes/rest/webhook-receiver-routes.php:220`. Fix both, and add a `wp_login`
trigger for the snapshot since there is no longer a login response to hang it
off.

### 4.4 Account-link state

`includes/account-link-state.php:133-144` short-circuits to `linked` for any
logged-in user in credentials mode with no gateway round trip. The new mode must
report the real state, driven by the recorded link state from 4.2, so an
unlinked or conflicted member is visible rather than silently broken.

### 4.5 Role sync, logout, widgets, native password flows

As set out in the scope document, sections 5.5 through 5.8. In summary: Agend no
longer assigns WordPress roles; `wp_logout` clears the minted token and the
negative cache; the Member Login and Header Auth widgets stop self-suppressing
and point at WordPress instead
(`agend-elementor/includes/widgets/class-agend-elementor-header-auth.php:364-372`,
`includes/records/render/member-login.php:110`); and the native lost-password
flow is left deliberately unfiltered with a test asserting it.

## 5. External id

Minted GUID, not the WordPress user id. The user id is reassignable after a user
is deleted on some hosts, which would silently hand one member's Agend identity
to another, and it exports the site's user enumeration to the gateway. A
`wp_generate_uuid4()` value costs nothing more and avoids both.

Storage: `_agend_apps_external_id`, underscore-prefixed so it stays out of the
profile UI, consistent with the other identity meta at `includes/identity.php`.

Resolution order in `wordpress` mode, extending
`agend_apps_current_user_external_id()` (`includes/identity.php:57`):

1. The existing `agend_apps_current_user_external_id` filter.
2. The configured `agend_apps_external_id_meta_key` option, when set and the
   meta is non-empty. This keeps Upbeat sites and any site whose SAML NameID
   comes from a meta key working unchanged.
3. The minted GUID.

Write-once. Guard the mint so it cannot overwrite an existing value, and never
regenerate: a lost external id orphans the member's Agend identity rather than
recovering it.

Security: the external id is now an authentication primitive. Anyone who can
write that usermeta or hook the filter can impersonate another member to the
gateway. It must not be user-editable, must not appear in the profile UI, and
must be excluded from the REST users endpoint.

**Interaction with SAML.** If a site later adds `agend-saml-idp`, the NameID the
IdP asserts will not be this GUID, so the same person would link twice as two
identities. The settings page must detect this and warn, which is the main
reason the linking mechanism needs explicit admin control rather than pure
auto-detection.

## 6. Identity and SSO settings page

A dedicated page, as requested, following the `records/settings.php` precedent
of a second `add_options_page` (`includes/records/settings.php:267-273`) rather
than a third tab on the existing screen. Lower-cost alternative if a separate
menu entry is unwanted: a third `?tab=` on `admin/views/settings.php:18-27`. The
fields are identical either way, so this is reversible and does not affect the
rest of the plan.

Follow the conventions the existing admin already sets: `manage_options` checked
in both the render callback and every AJAX handler; explicit `sanitize_callback`
on every `register_setting`; secrets routed to `Agend_Apps_Secret_Store` by a
callback that returns `''` (`admin/class-agend-apps-admin.php:601-614`); markup
in an `admin/views/` partial; and a new hook-suffix branch in `enqueue_assets()`
(`admin/class-agend-apps-admin.php:216-248`), which currently matches only
`settings_page_agend-apps`.

Moved onto the page from the main screen: member sign-in mode
(`admin/class-agend-apps-admin.php:357`), external id meta key (`:375`), account
slug (`:321`), portal URL (`:339`).

New on the page:

- **Connection entity id**, shown read-only with a copy button, for pasting into
  the Agend dashboard when the connection is created. Changing it after linking
  has begun unlinks every member, so it should not be a free-text field without
  a confirmation.
- **Linking mechanism**: Auto, SAML assertion, Server to server, Disabled.
- **Detected IdP plugins**, using the checks `agend-embed` already uses:
  `class_exists( 'WP_SAML_IDP_Service_Provider' )` and
  `class_exists( 'WP_SAML_IDP_Endpoints' )` for `agend-saml-idp`, and
  `defined( 'MSI_VERSION' )` for miniOrange
  (`agend-embed/includes/class-agend-embed-sso-drivers.php:126-201`). When
  `agend-saml-idp` is present, Auto resolves to SAML, the page says so plainly,
  and it warns about the duplicate-identity risk from section 5.
- **Link on user creation**: off by default.
- **Password management URL and password reset URL**, with the publish action
  and the scope-gated fallback from section 3.2.
- **Member reset URL**, which today has no admin UI at all and is settable only
  by filter (`class-agend-apps-settings.php:283-291`). It only applies to
  credential mode, so it belongs here and should be hidden in the other modes.
- **Diagnostic panel** for a chosen user: external id, link state, recorded
  Agend ids, last mint outcome, negative-cache state. This is not optional.
  `agend-content-access` fails closed on an empty bearer
  (`agend-content-access/includes/class-agend-content-access-decision.php:74-81`),
  so a linking gap presents as "member cannot see their content" with no error
  anywhere. Without this panel that is undiagnosable in the field.

## 7. Tests

Against the existing WordPress-free harness (`phpunit.xml.dist`, `composer test`).
`wp_generate_uuid4`, `get_edit_profile_url` and `wp_lostpassword_url` are not
currently stubbed and need adding to `tests/wp-stubs.php`.

- Extend `MemberAuthModeTest` for the third value, and update
  `tests/doubles.php:21-54` in the same commit.
- New `WpIdpExternalIdTest`: resolution order, write-once minting, the
  configured meta key winning when present.
- New `WpIdpLinkTest`: short-circuit when already linked, 409 records a conflict
  and does not retry, transport failure leaves a retryable state and never
  throws, the throttle transient suppresses a second call.
- New guard test: no plaintext password reaches the API client in `wordpress`
  mode.
- New guard test: `allow_password_reset`, `lostpassword_url` and
  `retrieve_password_message` are never filtered, so the member's only recovery
  path cannot be broken by a later change.
- Extend `AccountLinkIdentityRecordTest` for the new mode's real-state
  behaviour.

## 8. Sequencing

Unblocked today, in this order:

1. 4.1 mode plus the test-double update.
2. 4.3 membership snapshot guard and the webhook guard. Worth doing early and
   independently: it is a live bug for any site already running `sso` mode.
3. 5 external id minting and resolution.
4. 6 settings page, without the publish action.
5. 4.5 role sync, logout, widgets, native flow guard tests.

Blocked on section 2:

6. 4.2 the link step itself.
7. 4.4 account-link state, which depends on the link state 4.2 records.

Blocked on section 3:

8. The publish action and the two URL fields on the settings page.

Section 4.3 is worth pulling forward regardless of the rest of this plan.
