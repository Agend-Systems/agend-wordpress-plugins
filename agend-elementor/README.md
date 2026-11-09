# Agend Elementor Widgets

Elementor widgets that surface Agend Events, Learning, Directory and Careers (jobs) data inside WordPress pages, powered by the Agend gateway via Agend Apps Core.

**The block editor is the primary target for new surface work.** Every surface in this pack also ships as a Gutenberg block from Agend Apps Core, and both editors build from the same declarations: one page-builder-agnostic schema per surface for the settings, and one core renderer per surface for the output. This plugin is an adapter over those, not the place a surface is defined. A setting or a rendering decision added here and not in the schema or the renderer exists in Elementor only, which is the class of defect that seam exists to prevent. See the Content-settings schema and Block editor surface sections of `agend-apps-core/README.md`.

Both editors can also supply card and detail templates, and a site can run both at once: a template is addressed by a bare post id, and each adapter claims the ids it owns. An Elementor template is an `elementor_library` post; a block template is a `wp_block` synced pattern.

## Dedicated catalogue pages

Settings > Agend Widgets > Catalogue Pages lets a site nominate one **Events page**, one **Courses page**, one **Directory page** and one **Jobs page**. With a page set:

- every Events, Courses or Directory Catalogue widget, wherever it sits, links items to `{page}/event/{slug}/`, `{page}/course/{slug}/` or `{page}/listing/{slug}/` (or the `?agend_event=` / `?agend_course=` / `?agend_listing=` form without pretty permalinks);
- a widget on any other page (a homepage CTA, for example) navigates to the dedicated page instead of opening the detail in place;
- a detail URL requested on any other page is redirected (301) to the dedicated page;
- the server-rendered detail only ever replaces the dedicated page.

Jobs follow the same rules at `{page}/job/{slug}/` (`?agend_job=` without pretty permalinks). The slug arrives on the namespaced `agend_job` query var, since `job` is a name job board plugins commonly claim. Job detail is client-rendered only for now: there is no server-rendered job detail or job detail template yet.

With no page set, behaviour is unchanged: the page hosting the widget acts as its own detail page.

## Agend Jobs (Agend Careers)

The **Agend Jobs** widget (block: **Agend Jobs Board**) is the `jobs-catalogue` core surface: a list or grid of published jobs from the gateway's public `/v1/jobs` endpoints, with a filter bar and a job detail view whose apply button opens the listing's application link, or its application email when "Offer apply by email" is on. Applications are always made off-site.

- **Featured jobs strip**: turn on "Featured jobs only", hide the filters and set a small page size. With a Jobs page set, the strip links there.
- **Featured jobs first** (on by default) puts sponsored and featured listings ahead of the chosen order, across every page.
- **One category**: "Only this category" pins the board to one category and hides the visitor category filter.
- Needs the `jobs.listings.browse` scope; without it the widget draws nothing for visitors.
- **Who can see it** is a site setting, Settings > Agend Widgets > Jobs visibility:
  - **Public:** everyone sees everything.
  - **Titles public:** anyone sees the list; a job's description, salary and how to apply need a signed-in visitor.
  - **Members only:** the board itself needs a signed-in visitor.

  The setting is enforced by the `/agend-apps/v1/jobs` REST proxy (and its `/jobs/categories` and `/jobs/tags` lists) as well as the page, so hidden data is never sent to the browser. "Signed in" is a logged-in WordPress user. A site that must tell members from other accounts narrows it with the `agend_apps_records_jobs_visitor_can_view` filter. The sign-in wording is on the widget, under Job Detail.

### Visitor filter bar

Each filter is switched on under Visitor Filter Bar:

| Filter | Notes |
| --- | --- |
| Keyword search | Title, summary and employer. |
| Location search | Typed text matched against city, state, country or postcode. |
| Distance search | Off by default. A suburb or postcode box, a **Use my location** button and a radius dropdown. Only jobs within the radius show, each card saying "12.3 km away". See [Distance search and sort](#distance-search-and-sort). |
| Sort | Off by default. A dropdown of Newest first, Closing soonest and Title, A to Z, plus **Nearest** once a location is set. The Order setting under Listings stays the order the board opens in, and is the first option when it is not one of these (Highest salary). |
| State | A dropdown of the states in "States offered" (Australian states by default). Each must match the state as written on the listings. |
| Category | The board's active categories, in their admin order. |
| Employment type, work arrangement, experience level | Fixed lists. |
| Date posted | Last 24 hours, 3, 7, 14 or 30 days. |
| Tag filters | A repeater: each row is its own filter built from chosen tags. **Dropdown** lets the visitor pick one of the row's tags (for example a "Salary band"); **Checkboxes** offers each tag on its own (for example "Accreditation required"). |

"Let visitors pick several" turns category, employment type, work arrangement and experience level into checkbox lists, where a job matching any ticked option shows. Different filters combine: a job must match all of them, including every tag set across the tag filters.

### Distance search and sort

Switch on **Show distance search** under Visitor Filter Bar. It reuses what the Directory's Location filter does, so a visitor meets the same controls and messages on either:

- A typed suburb, postcode or address is turned into a point by the `/agend-apps/v1/directory/geocode` route, and a site whose account has no place search hides the box (the same `/directory/map-settings` check the Directory uses). **Use my location** asks the browser for the device's position and needs no place search. A device position never enters the page address.
- **Radius choices (km)** (default `10, 25, 50, 100, 250`, from 1 to 1000) and **Default radius (km)** set the dropdown. A single choice fixes the radius and hides the dropdown.
- **Region** (blank by default) is added to every typed place before it is looked up, like the Directory location filter's Region setting, so a bare postcode such as `5000` resolves in that country (for example "Australia") rather than wherever the geocoder's first match is. Unlike the Directory, text that already names the region as a whole word ("Adelaide, Australia") is sent as typed; a region inside another word does not count, so "US" is still added to "Houston". The visitor's text is shortened if needed so the region always fits the 200 character lookup, and the chip keeps the visitor's own words. Up to 60 characters.
- Setting a location sends `lat`, `lng` and `radius` together (never one or two of them) and, with the sort dropdown on, opens the board on **Nearest**. Clearing it returns to the board's own order.
- Each job found shows its distance on the card. A job's page shows its **reference number**, the **postcode** in the location line, and the **contact name and phone** (the phone as a `tel:` link) under the apply button. A field a listing does not have is not drawn.

Distance search, the Nearest sort and the postcode in text search need the gateway release that adds `lat`, `lng`, `radius`, `sortBy=distance` and the `location_postcode`, `distance_km`, `reference_number`, `contact_name` and `contact_phone` fields (the matching agend-dashboard change). Before it ships, a board with the controls on stays usable: a gateway that refuses the distance parameters is asked again without them and every job is shown with a note, and one that ignores them shows every job with the same note. Both settings are off by default, so a board that has not opted in changes only in showing the new job fields once the gateway sends them.

### Filter sidebar and job page header layouts

Two opt-in presentations of the same board. Both are off by default, and a board that has not chosen them renders exactly as before.

- **Filter layout** (Visitor Filter Bar): `Filter bar` (default) or `Filter sidebar`. The sidebar puts the keyword, place and radius in a tinted search panel with one Search button (place and radius only while Show distance search is on), and each filter you have switched on becomes a group on the left: tick boxes for category, employment type, work arrangement, experience level and tag groups in checkbox style, and radio lists with an Any option for state, date posted and tag groups in select style. A long list shows its first five options with a **Show N more** toggle. The results bar has the count, the sort dropdown (when Show sort is on) and, in a narrow container, a **Filters (n)** button that opens the groups as a panel. What is applied shows as removable chips above the results with **Clear all**. Filters apply as soon as they change, the same as the bar. Cards gain a right-hand column with the closing date and how long ago the job was posted, and the salary is emphasised.
- **Job page layout** (Job Detail): `Standard` (default) or `Header panel`. The header layout draws a rounded panel in the content area (not a full width band) with a back link, the title, the employer and "Reference X, posted date", and a facts strip overlapping its bottom edge with location, salary, job type and closing date. Below, the description and its pills sit on the left; an apply box (with "or email" when Offer apply by email is on), a "Questions about the role" box (contact name and phone as a `tel:` link) and the employer panel sit on the right. Any box or cell with no data is not drawn. Both columns stack in a narrow container.
- **Job page header colour** (Style, Colours) sets the panel's colour. Blank uses the heading colour. It applies to the header layout only.

The layouts use the board's existing colours (heading, body, accent, button), so a category pill is a pale tint of the accent colour with darker text and titles take the heading colour, inside these two layouts only.

Several values per filter, tags, state, date posted, featured first and the categories and tags lists need the gateway release that adds them to `GET /v1/jobs` (agend-dashboard #6826 and #7037). Against an older gateway those filters are ignored and show every job, and the category options fall back to the categories on published jobs.

## Card and detail templates

Cards and detail pages for events, courses and directory listings can be designed as ordinary Elementor saved templates (Templates > Saved Templates, type Section, Container or Page) built from the widgets in the Agend Apps category:

| Widget | Purpose |
| --- | --- |
| Agend Field | One value from the current record: title, dates, price, venue, category, level, duration, opening hours, one custom field, and so on, with per-kind formatting (date format, list separator, free label, yes/no text, truncation) and an optional label before the value. |
| Agend Image | The record image as an `img` (aspect ratio, object fit, and a rounded or circular shape at a fixed width) or as a background. **Image by term** shows an image uploaded to the media library for a term the record has instead, for example one logo per membership level. Background placement `fill` stretches behind the sibling widgets of the container it is dropped into; `parent` paints the image onto the parent container; `block` is a sized box. |
| Agend Pills | A record's categories, tags or other terms, one styled pill per term, with an optional label. **Colour rules** give particular terms their own background and text colours. The pill count follows the record, where a styled Agend Field would render one chip holding a joined list. |
| Agend Link / Button | Open detail, back to catalogue, register (events), enrol (courses), add to calendar (events), or a custom URL with `{slug}` and `{title}` tokens. |
| Agend Export Report | A download control for the directory's export reports, in one of two shapes: a button that downloads one nominated report, or a menu whose trigger opens a list of reports where choosing one downloads it. The output format is set in the editor. Report parameters are chosen from a picker listing what the account's reports actually accept, and the catalogue filter each one reads from is paired automatically. A visitor who cannot reach a report is offered a sign in rather than a download, and a refusal explains itself. |
| Agend Listing Enquiry Form | A form that sends a message to the listed member, for a Directory detail template. First and Last sit side by side, then Email and Mobile, then Message across the full width, with Submit on the right. The heading defaults to "Get in touch with {listing name}"; labels, placeholders, button text and the success message are editable. It submits in place with required-field hints, inline server messages and a disabled button while sending, and shows a terms checkbox linking to the listing's terms when it has any. It renders nothing for a visitor unless Agend says the listing accepts enquiries; the editor says why it would not show and still draws the form so it can be styled. WordPress sends no email: Agend does. The captcha provider (Cloudflare Turnstile or Google reCAPTCHA v3) is set in Settings > Agend Apps > Form Captcha, and its script loads only on pages that carry the form. See the Agend Apps Core README. |
| Agend Panel | The built-in detail's composite panels: event facts, registration, tickets, sponsors; course details, learning outcomes, pricing and enrolment; listing contact, gallery, locations, badges and reviews. A panel is several values with their own headings and layout. For one value use Agend Field or Agend Pills. Was "Agend Content Block"; templates saved against its single-value blocks (about, categories, tags, hours, custom fields) keep rendering, and the editor names the field each became. |
| Agend Filter | One catalogue filter control. The directory also has a **Location search** filter (an address, suburb or postcode, a "Use my location" button and a radius) and a **List / Map switch**; see [Directory map and location search](#directory-map-and-location-search). Directory tag, badge and custom field values come from `GET /v1/directory/facets`, entitlement-scoped, so a visitor never sees a value they may not read. Filter widgets go in a filter template that a catalogue widget is pointed at, so the controls survive the move between the listing and detail views. Each filter either lists every value of a field or sends author-defined choices, where one choice can stand for several values. Style settings cover the field (background, text, border colour, width and sides, radius, height, focus colour) and buttons and checkboxes; anything left unset keeps the theme's form styling. |

There is no record-type control. The chosen field or panel names its own record type: `listing:name` can only be a listing, `event_tickets` can only be an event. Fields marked Common work in any template, resolving to that record type's equivalent, and a field belonging to one type renders nothing inside a template of another, saying so in the editor.

`Custom field` reads one Agend custom field. The picker lists the fields this site's key can enumerate; a field that does not exist yet, or that the key cannot see, is addressed by typing its key instead. Which custom fields a *visitor* receives depends on their entitlements, so a field renders empty for a visitor who is not entitled to it.

Any field can print its own name in front of its value: **Show label**, with an optional override, a separator, and its own colour and typography in the Style tab.

In the editor the widgets preview against a real record: the first upcoming event, the first course, or the first directory listing, read through its detail endpoint so the panels preview populated. Which type a widget previews follows its own Field or Panel setting first, then what the rest of the template's widgets are set to, so a directory template previews a listing throughout without the author declaring it anywhere.

- **Card template**: chosen per catalogue widget (Content > Card Template). The first page is rendered server-side, one template render per record; filtering and pagination fetch rendered fragments from `GET /wp-json/agend-apps/v1/cards/events`, `/cards/courses` and `/cards/listings`. "Whole card links to the event" wraps each card in one anchor; turn it off to let only Agend Link widgets navigate.
- **Filter template**: chosen per catalogue widget, alongside a Filter position of across the top,
  down the left or down the right. A side position puts the filters in their own column beside the
  results, collapsing to a stack under 768px. The Directory's Filter column width sets that column
  as a share of the catalogue (default 260px), and its Results list height (Layout) caps the list
  so it scrolls inside itself from 768px up, for a list beside an Agend Map. The built-in Visitor
  Filter Bar controls are hidden while a filter template is chosen, because nothing reads them then.
- **Detail template**: chosen once per type in Settings > Agend Widgets, beside the dedicated page. A configured detail template is always server-rendered on the dedicated page, whatever the legacy "Server-rendered detail pages" toggle says.
- **Require sign-in to view details** (Directory, Detail View): sends visitors who are not signed in to the sign-in page, then back to the listing, both from links to a listing and from a direct detail URL. The switch is copied to a flag on the page each time the page is saved in Elementor, because a detail request cannot read a widget. Any one Directory widget on the page with the switch on is enough. A Directory widget that lives only inside a saved template or theme-builder template is not detected, so the direct URL stays open for it. See the Agend Apps Core README.

- **Item list layout** and **Item fields** (Directory, Detail View; and on Agend Field, shown for a custom field): show an item list custom field, such as a centre's tenants, as a table or a list, and which of its item fields to show. On the Directory widget they apply to every item list on the detail; on Agend Field, to that one field. A server-rendered detail never renders the widget, so `Agend_Elementor_Detail_Settings` hands the page's first Directory widget settings to core through the `agend_apps_records_directory_detail_settings` filter, reading the page's element data when a listing with an item list is rendered (nothing is mirrored on save), and only while Elementor still builds the page (`_elementor_edit_mode` is `builder`), so a page switched back to the block editor does not keep an old choice. A Directory widget that lives only inside a saved template or theme-builder template is not seen, and the detail then uses the defaults, a table of every field. See the Agend Apps Core README.

Choosing no template keeps the built-in card and detail layouts.

### Notes for developers

- Colour controls accept the Elementor kit's global colours. Elementor resolves a global only while writing CSS for a control's `selectors`, and these widgets render colours through the core renderer, so every widget passes its settings through `Agend_Elementor_Global_Colours::resolve()` first, repeater rows included. A new widget that hands settings to core must do the same.
- Elementor's element cache is keyed by document only, so the renderer disables it for the duration of each template render. Do not remove that filter: every card would show the first record.
- A catalogue widget cannot be placed inside a card or detail template (it renders nothing on the live site and a notice in the editor).
- Record images are remote URLs, not media-library attachments; WordPress image sizes do not apply.
- A directory search card carries `custom_fields` for every custom field the organisation has flagged Show on Card, so a custom field placed on a card template renders from the search payload. A field not flagged for cards is not on the card, and renders empty there; the single-listing payload carries the fields flagged for the detail page.
- Responses for a signed-in member bypass the shared transient cache centrally in `Agend_Apps_API::get_cached()`, so entitlement-shaped fields are never served to the next visitor.

## Next follow-ups

Deferred deliberately, in rough order:

1. **Events and courses facets.** The facet endpoint exists for the directory only. Events tags
   and course categories still cannot be enumerated, and course categories are still derived from
   the distinct values of one page of courses. The facet response shape is deliberately
   record-type-neutral, so adopting it for the other two is additive on both sides.
2. **Export execution returns 500.** The Agend Export Report widget is built, and the list half
   works: reports, their audience and their parameters all come back. Every execution of a report
   fails with an internal error from the gateway, for every report and every shape tried, so no
   file is ever produced. Isolated to the gateway route: the underlying RPC returns rows when
   called directly as the anon role with the same arguments, grants are correct and the column
   tokens are valid. Reported to the API side.

## Directory map and location search

Four pieces, each usable on its own, so a directory can be laid out however its design needs:

- **Location search** (Agend Filter, filter "Location", in the catalogue's filter template). A visitor types an address, suburb or postcode, or uses their browser's location, and picks a radius. The place is resolved by the Agend geocoder (`GET /v1/directory/geocode`); the box hides itself on the live page when the account does not include place search, leaving "Use my location". Settings: the radius choices (a single value fixes the radius and hides the dropdown), the default radius, the button texts, and a **Region** added to every lookup (for example "Australia") so a suburb resolves to the right country. The chip under the controls names the active location and clears it.
- **Nearest sort.** The Sort filter offers "Nearest". A location search with no Sort choice orders nearest first; Nearest without a location falls back to relevance.
- **Distance on cards.** The built-in card shows "12.3 km" (or "850 m") during a location search (Content > Card fields > Show distance). A card template uses Agend Field > Directory listing > Distance.
- **Agend Map** (a page widget, not a template widget). Pins every listing the catalogue on the page is showing, follows its filters, location and radius, and groups nearby pins. Each pin's details link to the listing. Place it beside the catalogue in a column for a split layout, or just below it with **Show only in Map view** on and a **List / Map switch** filter in the filter template for Map / List tabs. The switch can be two separate buttons or **Joined** into one segmented control; with the Style tab's button colour, button background, selected colours, corner radius and **Align buttons** (start, centre, end), and the widget's own typography, it can match a design without custom CSS. Options: height (and a phone height), grouping (a map of more than 2,000 pins always groups them), "Update results as the map moves" (narrows the catalogue to the area shown; never during a location search), mouse-wheel zoom (off by default so the page scrolls past it: visitors zoom with Ctrl or Command + scroll and move the map with two fingers on a phone, and the map tells them so when they try), the search radius circle, full-screen and My location buttons, "Preload pin details for up to" (250, 500, 1,000 or 2,000 listings: at or under it every pin's details load with the map, above it each pin's details load when it is opened; the map always shows every matching listing), and which details a pin shows, including a "Get directions" link to Google Maps. Colours default to the catalogue's accent colour.

Map tiles come from CARTO when the site has a CARTO basemaps key (Settings > Agend Widgets > Maps), and from OpenStreetMap's own tiles otherwise. CARTO stopped serving tiles without a key in September 2026; OpenStreetMap's tiles suit light traffic only, so a busy directory should have a key.

## Search links and the Share filter

A directory catalogue with a filter template keeps its search in the page address as the visitor filters, so Back from a listing, a reload, or the address opened in a new tab shows the same filters, view and page. The address is updated in place, never as a new history entry, and not while the visitor is typing in a filter text box. In card template mode the server draws the first page of results for the address, so a link arrives with its results.

- **Share** (Agend Filter, filter "Share", in the catalogue's filter template) shows a button that opens a dialog with a link to the visitor's current search, a Copy button, a Share button where the browser can open the device's share sheet, and a Close button. When the search is limited to a map area the visitor chose, a ticked "Include the current map area" box lets them leave it out. Every text is a setting. The link is the catalogue page's own address plus the search, so campaign and ad-click parameters in the visitor's address bar are not passed on.
- Only what the page's filters write is read back. A parameter with no matching filter is dropped, so is a value whose every filter hides itself (a custom field the visitor may not read), and a value a filter no longer offers keeps filtering and shows as "Any".
- A position from the visitor's device ("Use my location", or the map's "Show my location") never enters the address. A typed place travels with its text at the precision the plugin searches at (3 decimal places, radius to 1). A map area travels only when "Update results as the map moves" is on and the visitor dragged or zoomed the map.
- Not on a page with more than one directory catalogue, or for a catalogue using the built-in filter bar; there the address is left alone and the Share filter hides itself.
- Analytics: Google Analytics 4 records a page view whenever the address changes ("Page changes based on browser history events", on by default). A site that does not want one per search can turn that option off. What reaches analytics is what the visitor typed or chose, never their device position; redact `address` and `near` in the analytics tool if typed places should not be recorded.

| Search | Parameter | Value |
| :--- | :--- | :--- |
| Search box | `q` | text, up to 200 characters |
| Category | `category_ids` | category ids, comma-joined |
| Custom field, value list | `cf_<key>` | values, comma-joined |
| Custom field, range | `rf_<key>` | `<min>:<max>`, either side may be blank |
| Organisation | `location_name` | text, up to 100 characters |
| City, State, Postcode, Country | `city`, `state`, `postcode`, `country` | values, comma-joined, up to 50 of 100 characters each |
| Tag, Badge | `tag_ids`, `badge_ids` | ids, comma-joined |
| Minimum rating | `rating` | 1 to 5 |
| Featured | `featured_only` | `true` |
| Sort by | `sort` | `relevance`, `distance`, `name`, `created_at`, `view_count` or `rating` |
| Location search, typed place | `near`, `radius`, `address` | `<lat>,<lng>`; kilometres up to 1000; the text typed |
| Map area | `bbox` | `<north>,<south>,<east>,<west>` |
| List / Map switch | `view` | `list` or `map`, when it differs from the switch's Starts on |
| Page | `pg` | a page above 1, with numbered pagination |

A comma or percent sign inside a value is written as `%2C` or `%25` before the values are joined. The names avoid WordPress's own query variables (`page`, `tag`) and `featured`, which The Events Calendar registers.

## Export report parameters

A report can declare parameters that narrow what it exports. The widget maps them with rows keyed
on the parameter's **field** rather than on its name, because a report names its parameters by an
internal condition id that differs per report, while the field is what the parameter actually
filters on. Mapping on the field means one set of rows serves every report a dropdown offers, and
a report added later inherits the mapping rather than needing new rows.

Each row supplies its value one of two ways:

- **A value set in the editor**, for a fixed export such as one state or one category.
- **From the Directory Catalogue on the page**, read when the visitor presses the button, so the
  export follows whatever they have filtered the catalogue down to. The catalogue publishes its
  live filter state for this; with more than one directory catalogue on a page, the last to
  initialise is the one an export reads.

Rows whose field no report parameter matches are ignored, and a report with no parameters ignores
them all.

## Manual QA checklist

The PHPUnit suite (`composer test`, suite `agend-elementor`) covers the pure pieces: URL resolution, redirect decision, record context, field values and formatting, template options, query builders. The following needs a WordPress site with Elementor.

Dedicated pages

1. Set an Events page. Place an Events Catalogue on the homepage. Click a card: the browser navigates to `/{events-page}/event/{slug}/`.
2. Request `/{homepage}/event/{slug}/` directly: 301 to the dedicated URL.
3. On the Events page itself, cards still open in place (pushState) and Back returns to the grid.
4. Clear the Events page setting: the homepage widget opens details in place again (legacy behaviour).

Card templates

5. Build a Section template with Agend Image (background, fill), Agend Field (Common: Title, H3), Agend Field (Event: Date range), Agend Link (Open detail). Select it as the card template on an Events Catalogue.
6. First paint shows the templated grid with no skeleton flash; two different events show two different cards (element cache is off during the render).
7. Change a filter and go to page two: cards arrive as fragments with the template stylesheet already applied (no unstyled flash).
8. Middle-click a card: opens in a new tab. With "Whole card links" off, only the Agend Link navigates.
9. Repeat 5 to 7 for a Courses Catalogue with course fields.

Detail templates

10. Build a Page template with Agend Image (background), Agend Field (title, H1), Agend Panel (event facts, registration, tickets), Agend Link (back to catalogue). Select it as the Event detail template.
11. Open `/{events-page}/event/{slug}/`: the template renders, the breadcrumb parent is the Events page, the page title is the event name, Register Now opens the registration flow.
12. Unpublish the template: the built-in detail renders again.
13. Saved Templates list shows "Agend Event detail template" against the template in use.

Editor

14. Open a card template for editing: field widgets show the preview record and a dashed outline. A Course field inside an event template shows a warning notice; on the live site it renders nothing. A directory template previews a listing throughout, including widgets set to a Common field.

Directory map and location search

15. In a directory filter template add a Location search filter and a List / Map switch, and put an Agend Map with "Show only in Map view" on below the catalogue.
16. Search a suburb: the chip reads "Within 15 km of <suburb>", the results narrow and order nearest first, and the cards show a distance. Change the radius: results refresh. Clear the chip: everything returns.
17. Search nonsense: "We could not find that place" shows and the results do not change.
18. Pick Map view: the list hides and the map shows the same listings, with the radius circle during a location search. Change a filter while on the map: the pins follow. Pick List view: the list returns.
19. Click a group of pins (it zooms in), then a pin: its details show and the link opens the listing.
20. With no CARTO key the attribution reads OpenStreetMap; add a key in Settings > Agend Widgets > Maps and the attribution reads OpenStreetMap and CARTO.

Editor selection

21. In the Elementor editor, click an Export Reports button, a catalogue card, a pagination link, a filter input and the Agend Map: each selects the widget and opens its controls, and nothing downloads, opens, focuses or pans. Edit handles and resize grips still work. Toggle Elementor's Preview (eye icon): the widgets respond to clicks again. The live page and the block editor are unaffected.
