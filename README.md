# agend-wordpress-plugins
Collection of Wordpress plugins that integrate into the Agend system

## Agend Apps - Core
The core connection to the Agend ecosystem. Contains API connection PHP global functions and AJAX requests.

## Agend Apps - Shop
Agend product, cart and shop service. Integrates with the Agend products and sync's the user's cart across your website and the Agend ecosystem.

## Agend Directory Sync (AIQS)
Tenant-specific plugin for AIQS. Reads members from the Upbeat `/membershipDirectoryContacts` endpoint via the iugo-membership-kiosk plugin, transforms them into Agend directory listings, and pushes them to `/v1/directory/listings/bulk-upsert` on the Agend gateway in batches of 100. Lives in `agend-directory-sync-aiqs/`.

## Agend Loop Sync
Dedicated plugin to sync user roles and committee entitlements to loop through loop roles and channel access
