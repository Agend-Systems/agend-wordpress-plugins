# PCA Shopping Centres Directory: WordPress implementation runbook

Steps to recreate the Shopping Centres Online directory prototype on a PCA WordPress
environment (staging, live) once it has been proven locally. Everything here is on the
WordPress side or is configuration the WordPress side depends on. Dashboard code changes
are listed separately at the end.

Proven locally on pca.test on 2026-09-08. Local-only steps are marked as such.

## 0. Prerequisites

| Item | Requirement |
|---|---|
| Plugins | agend-apps-core with the changes on branch `feat/pca-directory-listing-fields` (listing address fields, windowed pager, My Account directory item); agend-elementor; agend-saml-idp; agend-loop-sync (populates `imk_membership_number`); WooCommerce; Elementor Pro (custom CSS on templates). |
| Agend account | Slug `pca`, environment matching the site (`agend_apps_account_slug`, `agend_apps_environment`). |
| API key | Must hold, in addition to the directory scopes: `sso.tokens.create` and `sso.identities.read`. Without these the Account Link status check and the member token mint return 403 and members only ever see the anonymous directory. Optional features are scope-gated by the plugin: Badges & Credentials needs `directory.achievements.browse`, export reports `directory.export_reports.browse`, the review form `directory.reviews.manage`, member self-edit `directory.listings.self_update`. The plugin reads the key's scopes from the gateway and disables features whose scope is missing; use Test connection after changing the key. |
| Elementor | Containers are disabled on PCA; templates use sections and columns. Keep it that way or the provisioning script's markup will not match. |

## 1. Member sign-in mode

Set the site to SSO mode so a WordPress login does not try to authenticate against Agend
with a password:

```
wp option update agend_apps_member_auth_mode sso
```

Effects: the Agend credential login bridge, the `/agend-apps/v1/auth/*` routes, the Member
Login widget and the Header Auth login link are disabled. WordPress authenticates users
itself. Agend identity comes from the SAML link below.

## 2. SAML: WordPress as identity provider, Agend as service provider

The plugin derives the member's external id from the `imk_membership_number` user meta
(filterable via `agend_apps_external_id_meta_key`). The SAML NameID must carry the same
value, otherwise the identity the gateway stores will never match what the plugin asks for.

1. In the dashboard, ensure the PCA SSO connection (slug `pca-wordpress`, protocol SAML) exists
   with `idp_entity_id = https://<site>/saml/metadata`, `idp_sso_url = https://<site>/saml/sso`,
   JIT provisioning on, `allowed_domains` as required, and `sp_acs_url` / `sp_entity_id`
   pointing at the gateway for that environment (`https://api.agend.dev/api/auth/sso/pca/acs`
   and `/metadata` for dev cloud; the production API host for live).
2. In WordPress, agend-saml-idp > Service Providers: one SP entry per gateway environment,
   `entityId` and ACS URL equal to the connection's `sp_entity_id` and `sp_acs_url`,
   NameID format `urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified`, assertions signed.
3. agend-saml-idp attribute mappings for that SP entity id:
   `nameid_attribute = imk_membership_number`, user attributes `user_email -> email`,
   `first_name -> first_name`, `last_name -> last_name`.

   Local note: this was done with `wp eval` against the `wp_saml_idp_service_providers` and
   `wp_saml_idp_attribute_mappings` options. On staging and live use the plugin's admin UI.
4. Any member who linked before the NameID mapping was corrected holds an `sso_identities`
   row keyed by their WordPress username. They must connect again (or the row is re-keyed by
   an administrator). Locally the row for samuel@iugo.com.au was re-keyed to `1546531`.

Connect flow for a member: My Account > Directory > "Connect my account" sends the browser to
`<gateway>/api/auth/sso/pca/initiate?relayState=<return url>`, the gateway redirects to the
WordPress IdP, WordPress asserts the membership number as NameID, the gateway stores the
identity and returns the member to the relay URL. After that the plugin's token worker mints
short-lived member tokens automatically on every request that needs one; no further action.

Contact creation on both sides (verified locally 2026-09-08 with a fresh WordPress user):

1. On WordPress login, agend-entitlement-mirror reconciles the member with the CRM (external
   source `agend_entitlement_mirror_external_source`, `pca-wordpress-dev` locally), which
   creates the CRM contact and settles the membership number on the user.
2. On "Connect my account", the SAML assertion carries that membership number. The gateway
   (branch `feat/sso-jit-contact-link`) creates the auth user if needed, stores the SSO identity,
   links the account membership, and binds the CRM contact by external id, then by email. If no
   contact exists and the connection's "Create CRM contact on first SSO sign-in" switch is on
   (default), it creates one tagged `source: sso_jit`. A contact already bound to another user
   is never re-bound.
3. The gateway's identity status and token responses now return `user_id` and `contact_id`;
   the plugin records them on the WordPress user as `_agend_apps_supabase_user_id` and
   `_agend_apps_contact_id`, the same keys credential mode uses. The Account Link widget and the
   My Account endpoint show "Your directory profile is still being set up" while `contact_id`
   is empty.

Requires the `feat/sso-jit-contact-link` dashboard branch (migration
`20260908061001_sso-jit-contact-provisioning-flag.sql`) deployed to the environment's API.

## 3. QLD entitlement (bespoke test fixture)

This is a custom fixture for testing and is not part of the product configuration.

In the dashboard, for the PCA account:

1. Benefit: `QLD directory access`, gate key `entitlement.state_qld` (already present on
   PCA alongside the other states and `entitlement.national`).
2. Directory entitlement scope: `entitlement.state_qld` on field `location.state`,
   values `qld` (Directory > Settings > Entitlement scopes). Present on PCA.
3. Custom field access policies: the gated fields (`total_centre_glar_sqm`, `owners`,
   `asset_owners`, `mat`, `year_opened`, `office_gla_sqm`, and so on) use
   `selected_entitlements` including `entitlement.state_qld`. Present on PCA.
4. Grant to the test contact: CRM > Members > the contact > Entitlements > grant
   "QLD directory access" (manual source).

   Local note: the grant for contact `e3a8c520-83d2-4a6d-aa74-9ee862384e2d`
   (samuel@iugo.com.au) was inserted directly into `crm_contact_entitlement_grants` with
   `crm_ensure_manual_entitlement_source`. On staging and live use the CRM UI.

Verification: with the member token attached, `GET /v1/directory/facets` returns
`custom.total_centre_glar_sqm`; anonymously it does not. Listings outside QLD do not expose
the gated fields to this member.

## 4. Directory page, card template and filter template

Run the provisioning script from this folder against the site. It is idempotent and keyed by
the `_sco_directory_role` post meta, so re-running updates the same three posts:

```
wp eval-file docs/pca-sco-directory/provision-sco-directory.php
```

It creates or updates:

| Post | Type | Purpose |
|---|---|---|
| SCO Directory Card | Elementor section template | One listing card: name, centre type pill, one-line address, Suburb, Owner, Asset owner, GLAR (m²) rows, last updated, "View profile". |
| SCO Directory Filters | Elementor section template | Search, Centre type, Owner, Asset owner, GLAR range, Reset. |
| Shopping Centres Directory | Page (`/shopping-centres-directory`) | Directory Catalogue widget, filters on the left, 3 columns, 12 per page, numbered pagination, PCA colours. |

Before running on another site, set `$sco_author` at the top of the script to an administrator
user id on that site. The script clears the Elementor CSS cache and the template picker cache.

Manual alternative: build the same three items in Elementor using the Agend Field, Agend
Pills, Agend Link and Agend Filter widgets, then select them as Card template and Filter
template on the Directory Catalogue widget. The script is the source of truth for settings.

Optional: set the page as the dedicated Listing catalogue page in Settings > Agend Widgets
so detail URLs resolve to it.

## 5. WooCommerce My Account "Directory" item

Provided by agend-apps-core (see the plugin change list below).

1. Settings > Agend Apps: set "Directory page (My Account link)" to the Shopping Centres
   Directory page. If left empty the plugin falls back to the dedicated Listing catalogue page;
   if neither is set the endpoint is not registered.
2. The plugin registers the `/my-account/agend-directory/` endpoint and flushes rewrite rules
   once per ruleset version. If the endpoint 404s, visit Settings > Permalinks once.
3. PCA's My Account navigation is a WordPress menu ("My Account", location `member-dashboard`),
   not WooCommerce's generated list, so add the item there: Appearance > Menus > My Account >
   Custom link, URL `/my-account/agend-directory`, label "Directory", placed after
   "Academy training courses". Locally this was done with
   `wp menu item add-custom 18 "Directory" "/my-account/agend-directory" --position=15`.
   The theme's icon map has no entry for this endpoint, so it renders without an icon unless
   the theme's `agendtheme_wc_dashboard_icon_for_account_endpoint` filter is extended.
4. Behaviour: linked members see "Go to directory"; unlinked members see "Connect my account",
   which sends them through the SSO round trip and back to this endpoint; members without a
   membership number see a "not ready" message; a gateway error shows a neutral unavailable
   message. In credentials mode the item always shows the linked state because the WordPress
   session is already an Agend session.

Verified locally on 2026-09-08 as samuel@iugo.com.au: unlinked state rendered, "Connect my
account" completed the SAML round trip and the gateway created the identity keyed by membership
number 1546531, after which the plugin resolved `linked` and minted a member token without any
further action.

Return redirect (dashboard side, fixed on branch `fix/gateway-origin-gate-sso-relay`): the
gateway now honours a RelayState whose origin is the SAML connection's own identity provider
origin (derived from `idp_sso_url` and `idp_entity_id`), in addition to the platform allow-list.
No `GATEWAY_ALLOWED_ORIGINS` entry is needed for a WordPress site that is the IdP of its own
connection. Verified locally 2026-09-08: connect, SAML round trip, identity created, member
returned to My Account showing the linked state. Requires that dashboard branch to be deployed
to the environment's API before the round trip returns to WordPress there.

API key allowed origins (dashboard side, same branch): a unified key's `allowed_origins` no
longer denies requests that carry no `Origin` header, so server-side WordPress calls work with a
restricted key. Patterns: list the apex explicitly (`https://pca.test`), `*.suffix` matches https
subdomains, and http is accepted for `localhost` and `.test`/`.local`/`.localhost` dev hosts.

## 6. Data the cards depend on

Cards read the search payload. On PCA the sync currently provides name, street address and a
PCA asset number for most listings; owners, GLAR and centre type are sparse. For the cards
to look like the mock, the directory sync (agend-directory-sync field map, or the source
export) needs to populate `centre_type` (category), `owners`, `asset_owners`,
`total_centre_glar_sqm` and the suburb, state and postcode of the primary location.

Also required from the dashboard side: the search card mapper only returns city and state in
`primary_location`, so the one-line address and postcode on cards stay empty until
`address_line_1` and `postcode` are added to the card projection.

## Plugin changes shipped for this work (agend-apps-core)

- New listing fields in the field registry: `listing:street`, `listing:address`,
  `listing:postcode`, `listing:updated_at` (`includes/records/fields.php`, tests in
  `tests/ListingFieldsTest.php`).
- Windowed numbered pagination in `assets/js/directory-catalogue.js` with CSS for gaps and
  disabled steps in `assets/css/directory-catalogue.css`.
- WooCommerce My Account "Directory" endpoint and the `agend_apps_directory_page_id` setting
  (files listed in the pull request).

## Local-only steps that must not be repeated elsewhere

- Supabase migrations were applied to the local database, after normalising 23 rehearsal
  `crm_order_items.product_type` rows in demo accounts.
- The local API key's scopes, the local SSO connection's SP URLs (`localhost:3072`), the
  local SAML SP entry and the re-keyed `sso_identities` row were changed with SQL and
  `wp eval`. On staging and live these are dashboard and plugin admin UI operations.
