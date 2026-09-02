# Agend Directory Sync

WordPress plugin that ingests directory records from a pluggable data
source (Upbeat, any JSON API via the built-in Custom HTTP API source, or a
Microsoft Dataverse / Dynamics 365 environment via FetchXML) and pushes
them to the Agend directory via the public bulk-upsert API.
The data source, response model, and field mapping are all configurable,
so the plugin is not tied to any one association's field names,
environment, or upstream API shape.

## Status

Working end-to-end as both a manual sync and an unattended sync.

- Fetches `/membershipDirectoryContacts` from Upbeat using credentials
  configured in the `iugo-membership-kiosk` plugin.
- Syncs every contact that has a value in the configured **External ID**
  source. Visibility is controlled per row via `status` (see Filtering
  below), so a member who loses eligibility or opts out is hidden
  automatically on the next sync without a delete operation.
- Transforms each contact into the Agend bulk-upsert listing shape using
  the configurable field mapping.
- POSTs to `/v1/directory/listings/bulk-upsert` on the configured Agend
  gateway in batches of 100.
- Aggregates created / updated / errored counts and surfaces per-row
  errors in the admin UI (and the WP-CLI output).
- Can run unattended from the server's cron via a WP-CLI command (see
  Scheduling below).

Not wired:

- Encrypted storage for the API key. Stored as plain text in `wp_options`
  for MVP.

## Requirements

- `agend-apps-core` plugin active and configured. It owns the Agend gateway
  connection (base URL + API key, under Settings > Agend Apps); this plugin
  calls it via `agend_apps_directory_bulk_upsert_listings()` and never talks
  to the gateway directly.
- `iugo-membership-kiosk` plugin active. Upbeat API credentials are only
  needed when the Upbeat data source is selected; the Custom HTTP API
  source does not use them (the kiosk plugin itself is still a structural
  dependency of this plugin's bootstrap).
- For scheduled runs: WP-CLI available on the server.

## Configuration

Gateway connection (base URL + API key) is NOT configured here — set it once
in **agend-apps-core** (Settings > Agend Apps). Then, under
Tools > Agend Directory Sync:

- **Data source** — which system the sync fetches records from. Built in:
  `Upbeat (membership kiosk)` (the default), `Custom HTTP API`, and
  `Microsoft Dataverse (FetchXML)`. Other
  plugins can register sources via the `agend_directory_sync_sources`
  filter. Settings below the selector apply only to the selected source.
- **Upbeat directory endpoint** (Upbeat source) — the Upbeat endpoint path
  for the member directory. The path varies per client. Leave blank to use
  the default `membershipDirectoryContacts`.
- **external_source** string sent with each bulk-upsert batch. This is
  part of the upsert key (`external_source` + `external_id`), so keep it
  stable for a given directory. Defaults to `upbeat-directory` if left
  blank.
- **Auto-publish approved listings** (checkbox, default ON). When set,
  the plugin sends `auto_publish_approved: true` with each batch. Agend
  will then set `published_at` on any listing it accepted as
  `status: 'approved'`, so the row appears on the public directory
  immediately. Already-published rows are not re-stamped on re-sync.
  Turn off only if you want a manual approval workflow in the dashboard.
- **Field mapping** (see below). Map each Agend listing field to a
  source field from your environment.
- **Max records this run** (optional, on the actions form). Caps the
  number of Upbeat contacts processed in a single run, applied AFTER the
  fetch and BEFORE filtering. Useful for verifying with a small slice
  before pushing the whole directory.


## Custom HTTP API source

Select **Custom HTTP API** as the data source to sync from any JSON API
without code. Everything about the upstream API is configuration:

- **URL** — the absolute HTTPS endpoint that returns the records. Plain
  HTTP is accepted only when `WP_ENVIRONMENT_TYPE` is `local` or
  `development`.
- **Response data path** — a dot-path through the response model to the
  array of records, e.g. `data.results` for
  `{"data": {"results": [...]}}`. Leave blank when the response root is
  itself the array. A purely numeric segment indexes into a list
  (`data.0.items`). A path that does not resolve to an array fails the
  run loudly, naming the deepest segment that did resolve and the keys
  available there — use **Run source fetch** to preview the raw envelope
  beside what your configured path extracts, and iterate the path until
  it resolves, before any sync runs.
- **Connection variables** — one `name = value` per line. A `{name}`
  placeholder in the URL, the token endpoint URL, or the scope is
  replaced with the value at run time; an unresolved placeholder fails
  the run naming it. Values live in the database, so never put a secret
  here — enter secrets in their own encrypted fields. Example (Azure
  AD / Microsoft Entra): variables `tenant_id` and `org`, token endpoint
  `https://login.microsoftonline.com/{tenant_id}/oauth2/v2.0/token`,
  scope `{org}/.default`.
- **Authentication** — `none`, `static token header`, or
  `OAuth 2.0 client credentials`. Secrets are entered in write-only
  password fields on the settings page and stored ENCRYPTED (libsodium,
  key derived from the site's WordPress auth salts) — a database backup
  alone cannot reveal them, and the value is never shown again after
  saving. A blank field on save keeps the stored value; a checkbox clears
  it. Optionally, the constants `AGEND_DIRECTORY_SYNC_HTTP_TOKEN` /
  `AGEND_DIRECTORY_SYNC_OAUTH_CLIENT_SECRET` in `wp-config.php` override
  the stored values for installs that prefer file-based secrets. Note:
  rotating the WordPress salts invalidates stored secrets — re-enter them.
  - Static token: the header name (default `Authorization`) and value
    template (default `Bearer %s`) are settings.
  - OAuth client credentials: the token endpoint URL, client id, and
    optional scope are settings. Client authentication is selectable:
    `HTTP Basic header` (default) or `Request body (client_secret_post)`,
    the style Azure AD uses. The access token is cached in a transient
    until shortly before expiry; a 401 on a data request re-acquires
    once, then fails.
- **Pagination** — `none`, `page` (incrementing page-number parameter),
  or `offset` (offset/limit parameters). Parameter names, page size, and
  the first page number are settings. Iteration stops on an empty page,
  a short page, or when the optional **has-more path** (another dot-path,
  resolved against each page's response) is `false`. A 500-page safety
  cap aborts the run rather than returning a truncated set.

Rows resolved from the data path that are not JSON objects are skipped
and reported in the run summary as the `row_not_an_object` skip reason.

Field mapping applies to the selected source's rows either way — and
source field names accept the same dot-path syntax (e.g. `contact.email`,
`addresses.0.suburb`), so nested response models map without code. An
exact top-level key match always wins before dot-path traversal, so a
source field whose literal name contains a dot keeps working.

## Microsoft Dataverse source

Select **Microsoft Dataverse (FetchXML)** to sync from a Dynamics 365 /
Dataverse environment. It is a separate source from Custom HTTP API rather
than a mode of it, because Dataverse pages differently from the REST APIs
that source models: there are no page or offset query parameters, and the
page selection lives inside the query document as `page`, `count` and
`paging-cookie` attributes on the FetchXML `<fetch>` element.

Following `@odata.nextLink` is the alternative, and it is opaque — you
cannot ask for page 4, cannot re-request one page after a failure, and
cannot set the page size independently of what the server chose. FetchXML
paging is explicit on all three counts. **This plugin always sets those
three attributes itself**, overwriting whatever the saved query carried, so
the page window is a setting rather than a hand-edit of the query.

### Settings

- **Environment URL** — the environment origin with no API path, e.g.
  `https://yourorg.crm6.dynamics.com`. Must be HTTPS. It also derives the
  OAuth scope, so it has to be the environment the app registration was
  granted access to.
- **Entity set name** and **API version** — the plural set the query runs
  against (`contacts`, `accounts`, a custom table's set name) and the Web
  API version, together building `/api/data/v{version}/{entity set}`.
  Version defaults to `9.2`.
- **FetchXML query** — the query, and therefore the field selection: one
  `<attribute name="..."/>` per field you want returned. Filters, orders
  and `<link-entity>` joins all come along in the same document, which is
  the reason for choosing this interface over `$select`. It must be valid
  XML with a `<fetch>` root or it is not saved. Connection variables
  (`{name}`) are substituted at run time.

  ```xml
  <fetch>
    <entity name="contact">
      <attribute name="contactid" />
      <attribute name="fullname" />
      <attribute name="emailaddress1" />
      <filter><condition attribute="statecode" operator="eq" value="0" /></filter>
      <order attribute="contactid" />
    </entity>
  </fetch>
  ```

  Include an `<order>` on a stable column. Paging a query with no
  deterministic order can return the same row on two pages and miss
  another entirely.
- **Page size** — the `count` attribute, 1-5000 (Dataverse rejects more).
- **Start page** and **Max pages** — the window this run fetches. Start
  page 3 with max pages 1 fetches page 3 alone, which is how you re-run a
  single page after a failure, or pull one slice for verification without
  touching the rest. Max pages `0` means "keep going until Dataverse
  reports no more records", still bounded by a 500-page safety cap that
  aborts rather than returning a partial set. When a run stops because of
  the window with records still available, the summary says so explicitly
  (admin notice, WP-CLI warning) — otherwise the counts would read as a
  complete sync.
- **Use the paging cookie** — leave on. Dataverse returns a cookie with
  each page; the next request carries it back, which is what keeps deep
  pages cheap. Without it the server re-walks every earlier row to reach
  the requested page and caps out at 50,000 rows. A start page above 1
  necessarily begins without a cookie, since there is no earlier response
  to take one from.
- **Request formatted values** — when on, the request asks for all OData
  annotations, so the response carries the `@OData...FormattedValue`
  fields and an option-set or lookup column can be mapped to its label
  instead of its numeric or GUID value. When off, only the two paging
  annotations are requested, giving a smaller response. Paging works
  either way: the paging cookie and the more-records flag ARE annotations,
  so the request never suppresses those two.
- **Entra ID app registration** — directory (tenant) ID, application
  (client) ID, and client secret. Client-credentials authentication; the
  app registration needs an application user in the Dataverse environment
  with read access to the table. The secret is stored encrypted
  (libsodium, key derived from the site's WordPress auth salts) in its own
  slot, separate from the Custom HTTP API source's secrets, and is never
  shown again after saving. `AGEND_DIRECTORY_SYNC_DATAVERSE_CLIENT_SECRET`
  in `wp-config.php` overrides it. Rotating the WordPress salts
  invalidates stored secrets — re-enter them.
- **Advanced** — token endpoint URL and scope are both derived from the
  tenant ID and environment URL when blank, which is right for a standard
  commercial tenant; set them for a sovereign or government cloud where
  the login host and audience differ. Request timeout, connection
  variables, and extra request headers work as they do for the Custom HTTP
  API source. The OData version headers, the annotation preference and the
  Authorization header are always sent and cannot be overridden.

### Verifying a query

**Run source fetch** requests the start page only and shows the FetchXML
that was actually sent (paging attributes included), the raw response
envelope, whether more records remain, whether a paging cookie came back,
and the first five resolved records. That is the loop for getting a query
and a page window right without fetching the whole environment on every
attempt.

Field mapping then applies to the returned rows like any other source.
Dataverse column names are the source field names (`fullname`,
`emailaddress1`); an annotation field is addressed by its literal key,
which contains dots and so relies on the exact-top-level-key match
happening before dot-path traversal.

Rows in `value` that are not JSON objects are skipped and reported in the
run summary as the `row_not_an_object` skip reason. A response with no
`value` array fails the run, naming the keys the response did carry.

## Field mapping

The mapping from source (Upbeat) fields to Agend listing fields is
configurable under Tools > Agend Directory Sync > **Field mapping**. The
set of Agend targets is fixed; for each one you choose which source field
feeds it. Leaving a source blank omits that field from the payload.

**Defaults are intentionally blank** — the mapping must be configured per
client (the previous defaults were AIQS/Upbeat specific). Until `external_id`
is mapped, every row is skipped. The `source` values in the table below are
illustrative examples of a typical Upbeat build, not defaults.

### Core targets (Agend field <- example Upbeat source)

| Agend target           | Example source           | Notes |
|------------------------|--------------------------|-------|
| `external_id`          | `uniqueid`               | Stable id and upsert key. A row with no value here is skipped. |
| `name` (first)         | `firstname`              | Combined with last to build the name. |
| `name` (last)          | `lastname`               | |
| `name` (full fallback) | `fullname`               | Used when first + last are empty. |
| `name` (number fallback) | `membershipNumber`     | Builds `Member <number>` when no name fields are present. |
| `description`          | `publishedBio`           | Omitted when blank. |
| `email`                | `email`                  | Omitted when blank. |
| `phone`                | `businessPhone`          | Dropped if > 50 chars (`varchar(50)`). |
| `phone` (fallback)     | `homeMobile`             | Used when the primary phone source is empty. |
| `category_slugs[0]`    | `membershipLevel`        | Slugified. |
| `tag_slugs[0]`         | `chapter`                | Slugified. |
| `badge_slugs[]`        | `designation`            | Each slugified. Also preserved verbatim under `custom_fields.designations`. |
| `hero_image_url`       | `profileImageUrl`        | Dropped if not a valid URL or > 1000 chars (`varchar(1000)`). |
| eligibility flag       | `eligibleToFindAMember`  | Boolean gate for visibility. Blank = always eligible. |
| opt-in flag            | `memberDirectoryOptIn`   | Boolean gate for visibility. Blank = always opted in. |

A `custom_fields_key = source_field` mapping, one per line, blank by
default. The key is stored under the listing's `custom_fields`.

### Address mapping

Under Tools > Agend Directory Sync > **Address mapping** you can map one or
more addresses to the listing. Each configured location slot has a static
**Label** (stored as the location name) plus a source field per address
component (`address_line_1`, `address_line_2`, `city`, `state`, `postcode`,
`country`, `latitude`, `longitude`). A slot that resolves any address data
becomes one listing location; the first with data is marked primary. Blank
slots are omitted.

This maps to the directory's multi-location support
(SPEC-DIR-20260521-directory-multi-location) via the `locations[]` array on
the bulk-upsert API. A typical Upbeat build maps a business address to
location 1 and a residential address to location 2 — residential addresses
are sensitive, so map them only when the directory should publish them.

### Always set

- `external_metadata.upbeat_unique_id`, `external_metadata.upbeat_date_modified`
  (from `dateModified`), and `external_metadata.synced_at` (UTC ISO 8601).
- `status` is derived from the two visibility flags (see Filtering).

## Actions

- **Run Upbeat fetch** - calls Upbeat and dumps the raw first 10 rows.
  Useful for verifying the source shape and confirming the source field
  names to map.
- **Preview transform** - fetches, filters, transforms; renders the first
  5 mapped listings without POSTing anything. Use this to verify the
  mapping before sending live data.
- **Send to Agend** - fetches, filters, transforms, POSTs to the Agend
  gateway. Renders aggregated created/updated/errored counts and surfaces
  per-row errors in a table.

Both **Preview transform** and **Send to Agend** results include a
"Dropped fields" block that lists each drop reason (e.g.
`phone_too_long`, `hero_image_url_invalid`) with a count. Each reason is
expandable into a table of the affected rows, identified by `external_id`
and `fullname`, capped at 50 examples per reason so the page stays
responsive on large syncs. Use these to locate the source record in
Upbeat and clean the data.

## How the upload runs

**Send to Agend** runs as a stepped job, not as one long request.

The pipeline used to run end to end inside the single admin-post request. That
is fine under WP-CLI, which has no request timeout, and the CLI still works that
way. In a browser it failed at scale in a way that looked worse than it was: the
web server returned a **504** while PHP carried on to completion behind it, so
the sync finished but the operator saw an error and no report, and the natural
response was to press the button again and start a second concurrent run.

Now the button creates a job and returns immediately. The page then advances it
one step per request: one step fetches and transforms, then one step per upload
batch of 100. Each request is short, so nothing approaches the timeout however
large the directory, and a progress bar reports batches, listings, and running
created / updated / error counts.

- **Leave the tab open.** Stepping is driven by the page. Closing it pauses the
  job rather than losing it; reopening Tools > Agend Directory Sync shows the
  unfinished run and offers **Resume**. It never resumes on its own, so opening
  a tab cannot restart an upload.
- **Pause / Resume / Cancel** are all available mid-run. Cancelling keeps the
  batches already uploaded: the bulk-upsert is idempotent on
  (`external_source`, `external_id`), so a cancelled run is a partial sync that
  a later run completes, not something to undo.
- **One at a time.** Starting a second sync while one is in flight is refused,
  and two open tabs cannot advance the same job at once (a step takes a
  database-enforced lock).
- The finished summary is the same panel a one-request run produced, written to
  the same 30-minute result transient. It is stored when the job ends, so it
  survives a reload.

Unattended runs are unaffected and remain the better choice for very large
directories: `wp agend-directory-sync run` has no request timeout and does not
need a browser open (see Scheduling below).

Note the fetch-and-transform step is still a single request. It has never been
the step that timed out, and for a paged source it is a handful of API calls,
but a directory large enough to make fetching itself slow would need that step
chunked too.

## Scheduling (server cron)

The same fetch -> transform -> send pipeline is exposed as a WP-CLI
command so it can run unattended from the server's crontab:

```bash
wp agend-directory-sync run
wp agend-directory-sync run --max=50 --dry-run
```

- `--max=<n>` caps the rows processed this run (after fetch, before
  transform). Omit or `0` for all rows.
- `--dry-run` fetches and transforms only; nothing is POSTed.

Add a crontab entry that changes into the WordPress root and runs the
command. Example, every 30 minutes, logging to a file:

```cron
*/30 * * * * cd /var/www/site && wp agend-directory-sync run --quiet >> /var/log/agend-directory-sync.log 2>&1
```

Running from real cron (rather than WP-Cron) means the sync does not
depend on site traffic to fire. The gateway URL, API key,
`external_source`, auto-publish flag, and field mapping all come from the
same settings the manual UI uses, so configure once in the admin and the
scheduled run inherits it.

## Upgrading from a tenant-specific build

Earlier releases defaulted `external_source` to a fixed, tenant-specific
value. If you are upgrading a site that synced with one of those, set the
**external_source** field in settings to that previous value **before the
first sync on the new version**. `external_source` is part of the upsert
key, so a changed value makes the gateway treat every listing as new and
re-inserts the whole directory instead of updating in place.

If you are unsure what the previous value was, read the `external_source`
of any listing already in the Agend directory for this account, or check
the value the site had saved under Tools > Agend Directory Sync before
upgrading.

## Filtering and visibility

A contact is **skipped entirely** only when the configured External ID
source is missing (recorded as `missing_external_id`). Without a stable
id there is nothing to upsert against.

Every other contact is **synced**, with the Agend `status` derived from
the two configured visibility flag sources (eligibility + opt-in):

| eligibility flag | opt-in flag | Agend status |
|------------------|-------------|--------------|
| true             | true        | `approved`   |
| false OR null    | any         | `suspended`  |
| any              | false OR null | `suspended` |

A blank flag source counts as satisfied, so an environment with no
eligibility / opt-in concept publishes everyone (subject to auto-publish).

Approved listings are publicly visible (subject to the auto-publish flag
setting `published_at`). Suspended listings are preserved in the
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
  client plugin without forking, beyond what the field-mapping UI covers.

## Files

- `agend-directory-sync.php` - plugin bootstrap, extends
  `Iugo_Membership_Kiosk_Plugin`, requires the kiosk plugin, registers
  the WP-CLI command.
- `includes/class-field-map.php` - configurable field-mapping defaults,
  resolution, validation, and persistence.
- `includes/class-upbeat-client.php` - thin wrapper around the kiosk
  API's paginated GET helper.
- `includes/class-http-api-source.php` - the generic JSON API source
  (configurable URL, response data path, auth mode, pagination mode).
- `includes/class-dataverse-source.php` - the Dataverse source: FetchXML
  page-window paging, paging-cookie handling, OData headers, Entra ID
  client-credentials auth.
- `includes/class-config.php` - connection-configuration primitives shared
  by the configurable sources (URL/header/variable sanitising, `{name}`
  substitution). Pure functions, no I/O.
- `includes/class-oauth-token-manager.php` - client-credentials token
  acquisition and caching, shared by the sources that authenticate that
  way; each names its own secret slot.
- `includes/class-listing-transformer.php` - pure mapping from source
  contact rows to Agend listings (driven by the resolved field map),
  plus the skip-reason reporting.
- `includes/class-agend-client.php` - batched POST to the Agend gateway
  bulk-upsert endpoint, aggregates per-row results.
- `includes/class-sync-runner.php` - the shared fetch -> transform ->
  send pipeline, used by the WP-CLI command and (in dry-run mode) as the
  fetch-and-transform stage of a stepped job.
- `includes/class-sync-job.php` - the resumable send job: job state, one
  step per upload batch, per-batch payload storage, progress.
- `includes/class-job-controller.php` - the admin-ajax endpoints the
  progress panel calls to start, step, pause, cancel, and clear a job.
- `includes/class-cli-command.php` - the `wp agend-directory-sync run`
  command (loaded only under WP-CLI).
- `includes/class-admin-page.php` - Tools submenu with settings, the
  field-mapping editor, the three action buttons, and result rendering.
