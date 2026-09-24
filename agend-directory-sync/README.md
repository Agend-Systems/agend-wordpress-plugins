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
- **Directory app link**: a URL pasted from the Agend dashboard, under
  Settings > SSO > Share links > Member directory, shaped like
  `{api host}/sso/{account}/directory-home?idp={slug}`. Only the scheme,
  host and port are checked, against this site's configured Agend API
  environment (agend-apps-core's Settings > Agend Apps); the path, the
  account slug and the query string are stored as pasted and never
  validated. A link that does not match is rejected on save. Leave blank
  to remove it. An "Open directory app" button appears at the top of this
  page when the link is set and matches the current environment, and the
  signed-in user's Agend session grants directory app access. That last
  check has nothing to grant it yet: the Agend dashboard does not
  currently issue the claim it looks for, so the button stays hidden for
  everyone until that ships (see `Agend_Directory_Sync_Admin_Page::
  DIRECTORY_APP_ACCESS_CLAIM`'s docblock). A dashboard-side integration
  can flip this on later through the
  `agend_directory_sync_can_open_directory_app` filter, below.


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
- **Secondary filter (grouped sync)**: an extra filter applied on top of
  the query at fetch time, so one sync run can target one group of records
  without editing the main query. The saved query is never edited by this
  feature. It combines with the main query's own filters using AND, and the
  FetchXML shown by Run source fetch includes it.

  It is **not** in this settings list. It has its own card at the top of the
  page, directly under the Manual sync actions, because it changes what
  those actions do rather than how the plugin connects to Dataverse. The
  card has its own **Save filter** button, separate from **Save settings**
  below, and saving one never touches the other.

  **Run source fetch, Preview transform and Send to Agend all use the SAVED
  filter, not whatever is on screen.** The card states which filter is
  saved, and while an edit is unsaved it says so and disables those three
  actions, so a sync can never run against a filter you only think is
  applied. Save the filter, or reload the page to discard the edit. There
  are two modes:

  - **Guided (pick values by label)**: name the field's logical name (for
    example `pca_membergroup`), press **Load values**, and choose which
    values count as "in the group" by their label rather than their raw
    stored value. Discovery works like this: the plugin first reads the
    values actually in use on the entity (a distinct-values query against
    the field), and falls back to the field's option set metadata when that
    read is not possible or comes back empty, so a value nobody has used
    yet is still selectable. Results are cached for five minutes. **Load
    values** is the only button: when it answers from that cache it says how
    old the cached list is and offers a single **Reload from Dataverse**
    link, which is the one way to go behind the cache. Up to 200 values are
    loaded at once; when there are more, a search box appears so you can
    find a value outside that first page rather than raising the cap. Selecting values by label builds the filter for you, with the
    operator chosen from the field's type: `in` for a choice (option set),
    lookup, status or state field, `contain-values` for a multi-select
    choice field, and `eq` for a yes/no field. The generated fragment is
    shown read-only below the values for reference and is not itself saved,
    only the field, the chosen values, and their labels are.

    Worked example: field `pca_membergroup` (a choice field), values
    "Region North" and "Region South" selected, produces a filter
    equivalent to:

    ```xml
    <filter type="and">
      <condition attribute="pca_membergroup" operator="in">
        <value>798380003</value>
        <value>798380004</value>
      </condition>
    </filter>
    ```
  - **Advanced (write the FetchXML filter myself)**: the original raw
    mode, unchanged: a `<filter>` element (or a single `<condition>`, which
    is wrapped in `<filter type="and">` for you), added as another
    `<filter>` under the query's `<entity>`. Must be valid XML or it is not
    saved. Connection variables (`{name}`) are substituted at run time.

    ```xml
    <filter type="and">
      <condition attribute="pca_membergroup" operator="eq" value="Region North" />
    </filter>
    ```

  Leave the guided field blank (or the raw textarea blank in advanced mode)
  to sync everything the main query returns. See Grouped uploads below for
  scripting one group at a time from the command line.
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

### Visibility flags: truthy mode vs. value map mode

Each of the two visibility flags (eligibility, opt-in) can be read one of
two ways, chosen per flag under "How to read this field":

- **True or false (with invert)** - the default. The source value is
  coerced to a boolean; **Invert this flag** flips a source that is
  true-means-hide instead of true-means-visible. This is today's
  behaviour, unchanged.
- **Map each value** - the source value is matched case-insensitively
  against a configured list of value rows (each mapped to **Published**
  or **Draft**), matched against the raw value and, for a Dataverse choice
  field, its label too. A value that matches no row falls back to the
  **Any other value** outcome. Invert does not apply in this mode.

When a flag is in map mode, its outcome can be `draft` as well as
`published`; a plain truthy flag can only gate visibility on/off. Where
both flags resolve an outcome, the most restrictive one wins
(`published` < `draft`). While neither flag is in map mode, the combined
`status` output is byte-for-byte identical to before this feature (see
Filtering and visibility below) - only once a flag is switched into map
mode can a row come back `pending` instead of `suspended`.

A source value that matched no map row is counted per flag and surfaced
as an "Unmapped values" warning on the **Preview transform** and
**Send to Agend** results, so it can be added to the map or left to the
default outcome deliberately.

### Always set

- `external_metadata.upbeat_unique_id`, `external_metadata.upbeat_date_modified`
  (from `dateModified`), and `external_metadata.synced_at` (UTC ISO 8601).
- `status` is derived from the two visibility flags (see Filtering).

## Actions

The actions and the most recent result sit at the top of the Tools > Agend
Directory Sync page, above the settings form. Each result's raw-data and
transformed-listing windows are collapsible: they open on the page load that
follows the action that produced them and stay collapsed on any later visit,
so a page carrying an old result stays scannable.

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

### Per-row errors and new-option notices

The gateway validates each listing in a batch independently and answers
with one result per row, which **Send to Agend** (and the WP-CLI command)
report as follows:

- **Per-row error examples** - a row the gateway rejected (`status:
  'error'`) appears in a table with its `external_id`, error `code` and
  `message`, and, when the gateway names the specific fields at fault
  (`error.fields`), a "field issues" column listing each one as
  "Listing #N (external id X), field F: reason", capped at 10 field
  issues per row and 10 row examples overall.
- **New options were created** - a row that succeeded may carry a
  notice that the gateway auto-created a new select/radio/multi-select
  option for one of its `custom_fields` values (`OPTION_CREATED`). These
  are aggregated per field across the whole run into a note such as
  "New options were created for custom_fields.state: 'VIC', 'WA' (3
  rows)"; any other notice code the gateway sends is only counted, per
  code, alongside it.
- **Warnings** - a row's `warnings` (plain-text notes that did not stop
  it from succeeding) are counted and shown with up to 10 examples.

All three degrade gracefully against an older gateway that does not yet
send `error.fields` or `notices`: the result looks exactly as it did
before this feature.

Every message the gateway returns is passed through the same privacy
scrub as a batch-level failure: a "received ..." tail is stripped, every
quoted literal is blanked, and an email address is redacted, so a
member's own data submitted to the gateway is never echoed back into the
admin screen, the CLI output, or a log.

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
batch. Each request is short, so nothing approaches the timeout however large
the directory, and a progress bar reports batches, listings, and running
created / updated / error counts.

- **Batch size and upload timeout are configurable** under Settings, alongside
  `external_source`. **Batch size (listings per request)** defaults to 25 and
  is clamped to 1-100 (100 stays the gateway's hard cap); a smaller batch
  finishes each step faster, which helps on a host with a short execution-time
  limit. **Upload timeout (seconds)** defaults to 45 and is clamped to
  15-300, and is the ceiling given to each batch's own gateway request. WP-CLI
  and cron runs use the full setting. A browser step is always capped at 45
  seconds regardless of the setting, because `max_execution_time` alone is not
  a reliable ceiling there: on Linux, PHP's own execution-time limit does not
  count time spent blocked on a network read, so it can be far more generous
  than what the host or an intermediate proxy actually allows the request to
  run for before cutting it off outright (Kinsta and many others cut at 60
  seconds). A sync already in progress keeps the batch size it started with
  even if the setting changes mid-run, so its batch numbering stays consistent
  from start to finish; the same applies to a run paused before this batch
  size setting existed and resumed afterwards, which is treated as having
  used 100 (what every earlier release actually chunked with).
- **A batch that fails with a transport-level or upstream-availability
  failure is retried automatically** in the browser stepper, up to twice (5
  seconds, then 15, before each retry), before it is recorded as a failure
  noting how many attempts were made. This covers a transport failure that
  never reached the gateway at all (a dropped connection, a DNS failure, a
  timeout), and a real 502, 503 or 504 status the gateway (or something in
  front of it) did answer with; every other status, including all 4xx and a
  500, is a real answer retrying cannot change, so those are never retried,
  and neither is a non-JSON response (an edge or proxy's own HTML error page)
  whose real status could not be determined. Retrying is safe because the
  bulk-upsert is idempotent on (`external_source`, `external_id`): re-sending
  a batch that may or may not have reached the gateway produces the same end
  state either way. If a batch's own attempt count reaches the limit without
  ever coming back with an answer at all (the PHP process was killed, or a
  host recycled the worker mid-request), it is recorded as failed noting that
  no response was received, rather than being retried forever. While a batch
  is waiting to retry, the progress panel shows a countdown instead of
  stepping again immediately, and a batch that succeeds after a retry is
  noted in the finished result ("Batch N succeeded after K attempts…") since
  some of what it counts as created or updated may already have been
  committed by the earlier, timed-out attempt. WP-CLI does not retry a
  timeout; rerunning the command is safe for the same reason.
- **Cancel takes effect immediately**, even while a batch upload is in
  flight: it does not wait for the current step to finish before it is
  honoured, and that step's own result (whatever it turns out to be) is
  discarded rather than overwriting the cancellation.
- **If the step request itself never gets an answer** -- the browser tab lost
  its connection, or an edge or proxy returned an HTML error page instead of
  the expected JSON, most commonly a 504 -- the page retries the step after 10
  seconds rather than treating it as failed, up to 3 consecutive failures
  before it pauses with a message asking the operator to check their
  connection and press Resume. This is a different situation from the
  batch-level retry above: nothing here is known about the batch that step
  was trying to send, only that the browser could not get a straight answer
  about it.
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
need a browser open (see Scheduling below). It uses the same batch size and
timeout settings, but does not retry a timeout itself -- it has no per-request
time limit to protect, so the plain bulk-upsert failure is what a rerun of the
command (safe, per the idempotency above) would fix.

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
- `--secondary-filter=<fetchxml>` (Microsoft Dataverse source only)
  replaces the saved secondary filter for this run with the given
  `<filter>` or `<condition>` fragment. Pass an empty value to run
  unfiltered on a site whose saved settings carry a filter. The saved
  query and saved filter are not changed.
- `--secondary-filter-field=<name>` and `--secondary-filter-values=<v1,v2>`
  (Microsoft Dataverse source only) are the guided alternative to
  `--secondary-filter`: name the field's logical name and a comma-separated
  list of the raw values to match, and the plugin builds the filter itself
  using the same field-type-to-operator rule the admin UI's guided mode
  uses. Both flags are required together. They are mutually exclusive with
  `--secondary-filter`: passing both fails the run rather than guessing
  which one wins. As with `--secondary-filter`, this replaces the saved
  secondary filter for this run only; the saved settings are not changed.

### Grouped uploads

To upload a directory one group at a time (for example, one Dataverse
custom-field value per run), keep the main FetchXML query as the full
directory and script the group as the secondary filter. The guided flags
are the form an operator will usually reach for, since they take the same
raw values shown in the admin UI's Values list rather than a hand-written
FetchXML condition:

```bash
for group_field in 798380003 798380004; do
  wp agend-directory-sync run --secondary-filter-field=pca_membergroup --secondary-filter-values="$group_field"
done
```

The raw `--secondary-filter` form still works for anything the guided flags
cannot express:

```bash
for group in "Region North" "Region South"; do
  wp agend-directory-sync run --secondary-filter="<condition attribute=\"pca_membergroup\" operator=\"eq\" value=\"$group\" />"
done
```

Each run upserts only the listings the composed query returns; listings
outside the group are left as they are.

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
- `agend_directory_sync_can_open_directory_app` filter - gets
  `($can, $user_id)`. Decides whether the "Open directory app" button
  renders for the current user. Currently always false, since it depends
  on an `app_access` JWT claim the Agend dashboard does not issue yet;
  reserved for a future dashboard-side integration to hook.

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
