# Gateway brief: WordPress as identity provider

Audience: whoever picks this up in the Agend apps and gateway repo. This brief
is self-contained. You do not need the WordPress plugin repo to work from it.

Companion documents, in the WordPress plugin repo:
`docs/SCOPE-wordpress-idp-member-auth.md` and
`docs/PLAN-wordpress-idp-option-b.md`.

## 1. Why this exists

Today, an association's WordPress site running Agend Apps Core can be set to
`credentials` mode. In that mode the site forwards the member's plaintext
WordPress password to `POST /v1/auth/register` and `POST /v1/auth/login`, so the
member's WordPress password becomes their platform-wide Agend password. A
security review of the WordPress side found that this happens silently, in both
directions, with no disclosure to the member:

- Any WordPress user creation, including an admin typing a password into
  wp-admin, registers that password as the member's Agend credential.
- A member who changes their WordPress password locally is then locked out of
  the site with a generic "incorrect password" error, and the only way back in
  is an Agend password reset, which changes their credential on every other
  association's site they belong to.
- The register route is public on every Agend-connected WordPress site and
  returns `EMAIL_ALREADY_REGISTERED` verbatim, which makes any such site an
  oracle for whether an email has an Agend account anywhere on the platform.

The fix on the WordPress side is a new sign-in mode, `wordpress`, in which
WordPress owns the credential outright and no password ever crosses to the
gateway. The site authenticates the member itself and then obtains a
short-lived Agend bearer for them server to server.

That last step is where the gateway work is needed.

## 2. What already works, and the exact gap

The WordPress plugin already has a token worker that does the right thing. On
each request for a signed-in WordPress user it resolves an external subject id
for that user and calls:

```
POST /v1/sso/tokens
Scope: sso.tokens.create
Body: { "idp_entity_id": "...", "external_id": "..." }
```

It caches the returned `access_token` against `expires_at`, re-mints on expiry,
and treats any failure as "no bearer", which degrades to an unattended
API-key-only call. There is no refresh token, by design.

That endpoint is resolve-only. An unlinked member returns
`404 EXTERNAL_IDENTITY_NOT_FOUND`, because the gateway never provisions from it.
So a row must already exist in `sso_identities` binding
`(idp_entity_id, external_id)` to an Agend user and contact.

**Today the only thing that creates that row is a real SAML assertion.** That
means WordPress-as-IdP currently requires every association site to install and
configure a SAML IdP plugin and register a SAML connection, which is too heavy
for most association sites and makes the linking path depend on a plugin outside
the Agend codebase.

**The gap is a way to create that link server to server, from the site's
account-scoped API key, with no password and no SAML round trip.**

## 3. Ask 1: the link endpoint

```
POST /v1/sso/identities
Scope: sso.identities.link      (new, sibling to the existing sso.identities.read)
Auth:  x-api-key, account-scoped exactly as the existing SSO endpoints
```

Request body:

```json
{
  "idp_entity_id": "https://assoc.example.org/agend-wp",
  "external_id": "0f1d5a1e-3c2b-4f77-9a10-6b2f7c4d8e33",
  "email": "member@example.org",
  "contact": { "first_name": "Ada", "last_name": "Lovelace" },
  "create_contact": false
}
```

Success response, `201` on creation and `200` when the link already existed:

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

`user_id` is the platform auth user id, `contact_id` the CRM contact id and may
be null for a contactless member. WordPress stores both against the WordPress
user and uses `contact_id` to route inbound membership webhooks, so returning
them here matters.

### 3.1 Required semantics

**Idempotent.** The same `(idp_entity_id, external_id)` pair returns the
existing link rather than erroring. WordPress retries on transport failures and
5xx, so a non-idempotent endpoint will create duplicates.

**Account-scoped contact resolution, without exception.** The `email` resolves a
contact on the account the API key belongs to and nowhere else. It must never
reach, read or bind a contact on a different account. This is the security
boundary the whole design rests on: without it, association A's website could
bind itself to association B's member and then mint bearers for them through the
existing `/v1/sso/tokens`.

**Conflict, never a silent re-point.** If the email resolves to a contact that is
already linked to a *different* `external_id` under the same `idp_entity_id`,
return `409` with a distinguishable error code. Do not re-point the existing
link. WordPress records the conflict, stops retrying, and surfaces it in an
admin diagnostic. Re-pointing a link must be an explicit dashboard action by a
human.

**No password, ever.** The endpoint must not accept a password field and must not
set, clear or rotate one. If a platform auth user has to exist for
`/v1/sso/tokens` to mint against, create it passwordless, recoverable only
through the portal's own reset flow. A member who has never set an Agend
password should be able to use the site indefinitely without one.

**`create_contact` defaults to false.** Just-in-time contact creation is opt-in
per site. WordPress will call this endpoint on `wp_login` for every user
including administrators, so an accidental default of true would populate the
CRM with staff accounts and imported users.

**Rate limited per API key**, in line with the other write endpoints.

**Audited.** Creating a link binds a real person's identity. Each creation should
be attributable to the API key that made it, with the entity id, external id and
resolved contact recorded.

### 3.2 Error codes WordPress will branch on

WordPress needs to distinguish four outcomes, because it treats each differently.
Please keep them distinguishable by HTTP status plus `error.code`:

| Outcome | Status | WordPress behaviour |
|---|---|---|
| Linked, or already linked | 200 / 201 | Record ids, stamp linked, clear the token worker's negative cache |
| Email matches no contact and `create_contact` is false | 404 | Stamp "no contact", retry on next login, surface in diagnostic |
| Email maps to a contact linked to a different external id | 409 | Stamp conflict, stop retrying, surface for human resolution |
| Key lacks the scope | 403 | Stand down entirely, surface in the admin as a configuration error |

Anything else, including 5xx and transport failure, is treated as retryable on
the next login and never blocks authentication.

### 3.3 Open questions

1. Does `/v1/sso/tokens` require a platform auth user to exist before it can
   mint, or can it mint against a contact-only link? If it needs the auth user,
   this endpoint has to create one, which is the largest unknown in the ask.
2. Should the SSO connection be auto-created on first link for a WordPress
   connection, or must the association create it in the dashboard first?
   Dashboard-first is safer and matches how webhook subscriptions and the
   `redirect_url_allowlist` are already handled.
3. Is an unlink call needed? WordPress has no plan to call one today, but a
   deleted WordPress user currently leaves an orphaned link with no way to clear
   it. Worth deciding now rather than later.
4. Should `sso.identities.link` be a distinct scope, or folded into the existing
   `sso.identities.read`? A distinct scope is preferred so a site can hold read
   without hold write.

## 4. Ask 2: the connection object and the portal password link-out

The second half of the change is that the Agend member portal should stop
offering a password form to members whose credential lives in WordPress, and
link out to the WordPress site instead. Otherwise the member has two passwords
that silently diverge, which is the same class of problem the whole change is
trying to remove.

### 4.1 Connection fields

The SSO connection object today, as far as the WordPress client knows it,
carries only `provider_name` and `idp_certificate`. Three fields need adding:

- `connection_type`: `saml` or `wordpress`.
- `password_management_url`: where the portal sends a member who wants to change
  their password.
- `password_reset_url`: where the portal sends a member who has forgotten it.

Two URLs rather than one, because the portal needs a destination for both cases
and WordPress serves them from different places.

### 4.2 Portal behaviour

For a member whose identity is linked through a connection with
`connection_type: wordpress`:

- Replace the change-password form with a link to `password_management_url`.
- Send the forgot-password flow to `password_reset_url` instead of sending an
  Agend reset email.
- If the member also holds a legacy Agend password from the credential-login
  era, offer an explicit "remove Agend password" action. This is the cleanup
  path for sites migrating off `credentials` mode, where members already have a
  platform password that was silently set from their WordPress one.

A member may belong to several accounts, only some of which are WordPress
connections. The portal rule should therefore key off the identity the member
signed in through, not off the account alone.

### 4.3 How the URLs get set

Preferred: WordPress sets them via the existing
`PATCH /v1/sso/connections/{id}`, from an explicit "Publish to Agend" action on
its settings screen. This is connection-level configuration and belongs on the
connection.

Constraint worth knowing: that call needs `sso.connections.update`, an
administrative scope a member-facing site key may not hold. When the scope is
absent, the WordPress admin screen will show both URLs read-only with copy
buttons so an admin can paste them into the Agend dashboard by hand. So the
dashboard also needs to expose these three fields for manual entry. That mirrors
how `redirect_url_allowlist` is already handled.

## 5. What WordPress will do, so you can sanity-check the contract

On `wp_login`, for a site in `wordpress` mode:

1. Resolve the member's external id. This is a GUID minted once per WordPress
   user and stored in user meta, write-once, never regenerated.
2. Skip if a linked state is already recorded locally, so the gateway is not
   called on every login.
3. Otherwise call `POST /v1/sso/identities` with a short timeout, wrapped so a
   gateway failure can never delay or break the WordPress login.
4. Record `user_id` and `contact_id`, stamp the link state, clear the token
   worker's negative cache.

Thereafter every gateway call for that member resolves a bearer through the
existing `POST /v1/sso/tokens` path, unchanged.

The `idp_entity_id` is a stable per-site string the association registers once.
It is half the identity key, so it never changes after linking begins.

## 6. Non-goals

- No change to `POST /v1/sso/tokens`. It stays resolve-only.
- No password handling of any kind in the new endpoint.
- No cross-account resolution, lookup, or linking.
- No change to the existing SAML flow. Sites with `agend-saml-idp` keep linking
  through assertions, and the WordPress admin screen detects that plugin and
  stands the new mechanism down.

## 7. Suggested gateway-side test scenarios

- Same pair posted twice returns the same link, `created` true then false.
- An email belonging to a contact on a different account does not resolve, in
  either direction, under any combination of entity id and external id.
- An email already linked to a different external id under the same entity id
  returns 409 and leaves the existing link untouched.
- `create_contact: false` against an unknown email returns 404 and creates
  nothing.
- A key without `sso.identities.link` returns 403 and creates nothing.
- A link created here is immediately usable by `POST /v1/sso/tokens`.
- No code path in the new endpoint can write to a password column.
