# Agend Directory Sync

WordPress plugin that ingests member directory contacts from Upbeat and
pushes them to the Agend directory via the public bulk-upsert API.

## Status

Working end-to-end as a manual sync. Not yet scheduled.

- Fetches `/membershipDirectoryContacts` from Upbeat using credentials
  configured in the `iugo-membership-kiosk` plugin.
- Syncs every Upbeat contact that has a `uniqueid`. Visibility is
  controlled per row via `status` (see Filtering below), so a member
  who loses eligibility or opts out is hidden automatically on the
  next sync without a delete operation.
- Transforms each remaining contact into the Agend bulk-upsert listing
  shape.
- POSTs to `/v1/directory/listings/bulk-upsert` on the configured Agend
  gateway in batches of 100.
- Aggregates created / updated / errored counts and surfaces per-row
  errors in the admin UI.

Not wired:

- Scheduled (cron) sync. Trigger is manual only.
- Encrypted storage for the API key. Stored as plain text in `wp_options`
  for MVP.

## Requirements

- `iugo-membership-kiosk` plugin active and configured with valid Upbeat
  API credentials.

## Configuration

Tools > Agend Directory Sync:

- **Agend gateway base URL**, e.g. `http://localhost:3072` for local dev.
- **Agend API key**. Must hold the `directory.listings.bulk_upsert` scope.
- **external_source** string sent with each bulk-upsert batch. Defaults
  to `aiqs-upbeat`.
- **Auto-publish approved listings** (checkbox, default ON). When set,
  the plugin sends `auto_publish_approved: true` with each batch. Agend
  will then set `published_at` on any listing it accepted as
  `status: 'approved'`, so the row appears on the public directory
  immediately. Already-published rows are not re-stamped on re-sync.
  Turn off only if you want a manual approval workflow in the dashboard.
- **Max records this run** (optional, on the actions form). Caps the
  number of Upbeat contacts processed in a single run, applied AFTER the
  fetch and BEFORE filtering. Useful for verifying with a small slice
  before pushing the whole directory.

## Actions

- **Run Upbeat fetch** - calls Upbeat and dumps the raw first 10 rows.
  Useful for verifying the source shape.
- **Preview transform** - fetches, filters, transforms; renders the first
  5 mapped listings without POSTing anything. Use this to verify the
  mapping before sending live data.
- **Send to Agend** - fetches, filters, transforms, POSTs to the Agend
  gateway. Renders aggregated created/updated/errored counts and surfaces
  per-row errors in a table.

Both **Preview transform** and **Send to Agend** results include a
"Dropped fields" block that lists each drop reason (e.g.
`phone_too_long`, `hero_image_url_invalid`) with a count. Each reason is
expandable into a table of the affected rows, identified by `uniqueid`
and `fullname` (as returned by Upbeat), capped at 50 examples per reason
so the page stays responsive on large syncs. Use these to locate the
source record in Upbeat and clean the data.

## Mapping

Source field (Upbeat) -> Target field (Agend listing):

| Upbeat field             | Agend field                            | Notes |
|--------------------------|----------------------------------------|-------|
| `uniqueid`               | `external_id`                          | Stable id |
| `firstname` + `lastname` | `name`                                 | Falls back to `fullname`, then `Member <membershipNumber>` |
| `publishedBio`           | `description`                          | Omitted when null |
| `email`                  | `email`                                | Omitted when null |
| `businessPhone`          | `phone`                                | Falls back to `homeMobile`. Dropped (field omitted from payload) if > 50 chars - the Agend column is `varchar(50)`. Drop count surfaces under "Dropped fields" in the admin UI. |
| `membershipLevel`        | `category_slugs[0]`                    | Slugified |
| `chapter`                | `tag_slugs[0]`                         | Slugified |
| `designation[]`          | `badge_slugs[]`                        | Each slugified |
| `membershipNumber`       | `custom_fields.membership_number`      | |
| `membershipType`         | `custom_fields.membership_type`        | |
| `membershipLevel`        | `custom_fields.membership_level`       | |
| `jobTitle`               | `custom_fields.job_title`              | |
| `companyName`            | `custom_fields.company_name`           | |
| `chapter`                | `custom_fields.chapter`                | |
| `honorifics`             | `custom_fields.honorifics`             | |
| `title`                  | `custom_fields.title`                  | |
| `linkedIn`               | `custom_fields.linkedin`               | |
| `profileImageUrl`        | `hero_image_url`                       | First-class field. Dropped if not a valid URL or > 1000 chars (`varchar(1000)`). Drop counts surface under "Dropped fields" as `hero_image_url_invalid` / `hero_image_url_too_long`. |
| `designation[]`          | `custom_fields.designations`           | Original array preserved |
| `uniqueid`               | `external_metadata.upbeat_unique_id`   | |
| `dateModified`           | `external_metadata.upbeat_date_modified` | |
| (sync time)              | `external_metadata.synced_at`          | UTC ISO 8601 |

- `status` is always set to `approved` (source system is authoritative).
- Residential address fields (`residentialStreetAddress`,
  `residentialState`, etc.) are intentionally **not** mapped. The
  directory is professional; residential addresses are sensitive and need
  an explicit AIQS decision before being published.

## Filtering and visibility

A contact is **skipped entirely** only when `uniqueid` is missing
(recorded as `missing_uniqueid`). Without a stable id there is nothing
to upsert against.

Every other contact is **synced**, with the Agend `status` derived from
the two Upbeat visibility flags:

| `eligibleToFindAMember` | `memberDirectoryOptIn` | Agend status |
|-------------------------|------------------------|--------------|
| true                    | true                   | `approved`   |
| false OR null           | any                    | `suspended`  |
| any                     | false OR null          | `suspended`  |

Approved listings are publicly visible (subject to the auto-publish
flag setting `published_at`). Suspended listings are preserved in the
directory database but hidden from the public site because the public
queries require `status = 'approved'` AND `published_at IS NOT NULL`.
When the source member flips back to eligible + opted-in, the next sync
re-sets status to `approved`, and if `published_at` was previously set
the row reappears immediately; if it was never set, the auto-publish
flag will populate it.

The admin UI reports per-status counts and any skipped rows after each
preview / send.

## Extension points

- `agend_directory_sync_upbeat_endpoint` filter - override the Upbeat
  endpoint path (defaults to `membershipDirectoryContacts`).
- `agend_directory_sync_listing_payload` filter - applied per row, gets
  `($listing, $contact)`. Use to extend or override the mapping in a
  client plugin without forking.

## Files

- `agend-directory-sync.php` - plugin bootstrap, extends
  `Iugo_Membership_Kiosk_Plugin`, requires the kiosk plugin.
- `includes/class-upbeat-client.php` - thin wrapper around the kiosk
  API's paginated GET helper.
- `includes/class-listing-transformer.php` - pure mapping from Upbeat
  contact rows to Agend listings, plus the skip-reason reporting.
- `includes/class-agend-client.php` - batched POST to the Agend gateway
  bulk-upsert endpoint, aggregates per-row results.
- `includes/class-admin-page.php` - Tools submenu with settings, the
  three action buttons, and result rendering.
