# agend-wordpress-plugins
Collection of Wordpress plugins that integrate into the Agend system

## Agend Apps - Core
The core connection to the Agend ecosystem. Contains API connection PHP global functions and AJAX requests.

## Agend Apps - Shop
Agend product, cart and shop service. Integrates with the Agend products and sync's the user's cart across your website and the Agend ecosystem.

## Agend Embed
Embeds Agend app surfaces (LMS, Loop) in an iframe with host-assisted single sign-on: when the embed reports it has no session, the plugin navigates the iframe through the site's own IdP-initiated SSO so a logged-in member is signed in without re-entering credentials. IdP-agnostic via a driver filter (built-in: `agend-saml-idp`, miniOrange SAML IDP); the plugin README documents the postMessage contract and is the reference for other host implementations. Lives in `agend-embed/`.

## Agend Directory Sync
Reads members from the Upbeat `/membershipDirectoryContacts` endpoint via the iugo-membership-kiosk plugin, transforms them into Agend directory listings using a configurable field mapping, and pushes them to `/v1/directory/listings/bulk-upsert` on the Agend gateway in batches of 100. Runs manually from the admin UI or unattended from the server's cron via a WP-CLI command. Lives in `agend-directory-sync/`.

## Agend Loop Sync
Dedicated plugin to sync user roles and committee entitlements to loop through loop roles and channel access
