# Agend Elementor Widgets

Elementor widgets that surface Agend Events, Learning and Directory data inside WordPress pages, powered by the Agend gateway via Agend Apps Core.

## Dedicated catalogue pages

Settings > Agend Widgets > Catalogue Pages lets a site nominate one **Events page**, one **Courses page** and one **Directory page**. With a page set:

- every Events, Courses or Directory Catalogue widget, wherever it sits, links items to `{page}/event/{slug}/`, `{page}/course/{slug}/` or `{page}/listing/{slug}/` (or the `?agend_event=` / `?agend_course=` / `?agend_listing=` form without pretty permalinks);
- a widget on any other page (a homepage CTA, for example) navigates to the dedicated page instead of opening the detail in place;
- a detail URL requested on any other page is redirected (301) to the dedicated page;
- the server-rendered detail only ever replaces the dedicated page.

With no page set, behaviour is unchanged: the page hosting the widget acts as its own detail page.

## Card and detail templates

Cards and detail pages for events, courses and directory listings can be designed as ordinary Elementor saved templates (Templates > Saved Templates, type Section, Container or Page) built from five widgets in the Agend Apps category:

| Widget | Purpose |
| --- | --- |
| Agend Field | One value from the current record: title, dates, price, venue, category, level, duration, and so on, with per-kind formatting (date format, list separator, free label, yes/no text, truncation). |
| Agend Image | The record image as an `img` (aspect ratio, object fit) or as a background. Background placement `fill` stretches behind the sibling widgets of the container it is dropped into; `parent` paints the image onto the parent container; `block` is a sized box. |
| Agend Pills | A record's categories, tags or other terms, one styled pill per term. The pill count follows the record, and a record with no terms renders nothing rather than an empty pill. |
| Agend Link / Button | Open detail, back to catalogue, register (events), enrol (courses), add to calendar (events), or a custom URL with `{slug}` and `{title}` tokens. |
| Agend Content Block | The built-in detail panels as reusable blocks: event facts, registration, tickets, sponsors; course details, learning outcomes, pricing and enrolment. |

Fields marked Common work in any template, resolving to that record type's equivalent. `Custom field (by key)` reads an Agend custom field by its key; which custom fields a visitor receives depends on their entitlements, so it renders empty for a visitor who is not entitled to that field. In the editor the widgets show the first upcoming event or first course as preview data.

- **Card template**: chosen per catalogue widget (Content > Card Template). The first page is rendered server-side, one template render per record; filtering and pagination fetch rendered fragments from `GET /wp-json/agend-elementor/v1/cards/events`, `/cards/courses` and `/cards/listings`. "Whole card links to the event" wraps each card in one anchor; turn it off to let only Agend Link widgets navigate.
- **Detail template**: chosen once per type in Settings > Agend Widgets, beside the dedicated page. A configured detail template is always server-rendered on the dedicated page, whatever the legacy "Server-rendered detail pages" toggle says.

Choosing no template keeps the built-in card and detail layouts.

### Notes for developers

- Elementor's element cache is keyed by document only, so the renderer disables it for the duration of each template render. Do not remove that filter: every card would show the first record.
- A catalogue widget cannot be placed inside a card or detail template (it renders nothing on the live site and a notice in the editor).
- Record images are remote URLs, not media-library attachments; WordPress image sizes do not apply.
- Directory `custom_fields` are returned on the single-listing payload only, not on the search payload the catalogue reads, so a custom field placed on a card renders empty until the search endpoint returns them.
- Responses for a signed-in member bypass the shared transient cache centrally in `Agend_Apps_API::get_cached()`, so entitlement-shaped fields are never served to the next visitor.

## Next follow-ups

Deferred deliberately, in rough order:

1. **Filter widget.** Split each catalogue's filter bar into a dedicated Elementor filter widget,
   placed in a saved template that the catalogue widget is pointed at. Filters either enumerate
   every value of a field (categories, tags, badges, custom fields) or send an author-defined
   selection ("Between 50 and 100", "All States"). The API work this needs is written up in the
   dashboard repo at `.docs/reports/elementor-filter-widget-api-requirements.md`; the WordPress
   half includes forwarding `tag_ids`, `badge_ids`, `location` and `sponsor_level` through the
   Agend Apps Core directory proxy, which currently drops them.
2. **Directory content blocks.** Events and courses expose their built-in detail panels as Agend
   Content Block options; the directory does not, so a listing detail template composes from
   fields and pills only. Extract the reviews, gallery, contact, badges and custom-field panels
   from `agend_elementor_render_directory_detail()` the same way the events and course fragments
   were extracted.
3. **Export reports.** Make the directory export reports interactive. Not started, and a separate
   surface from card and detail templating.
4. **Empty styled wrappers.** A widget that renders nothing still leaves its Elementor wrapper, so
   a styled chip (the events "Sold Out" badge, for example) shows as an empty pill. Agend Pills
   solves this for terms by rendering nothing at all; the same treatment would suit any
   conditionally-empty styled field.

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

10. Build a Page template with Agend Image (background), Agend Field (title, H1), Agend Content Block (event facts, registration, tickets), Agend Link (back to catalogue). Select it as the Event detail template.
11. Open `/{events-page}/event/{slug}/`: the template renders, the breadcrumb parent is the Events page, the page title is the event name, Register Now opens the registration flow.
12. Unpublish the template: the built-in detail renders again.
13. Saved Templates list shows "Agend Event detail template" against the template in use.

Editor

14. Open a card template for editing: field widgets show the preview record and a dashed outline. A Course field inside an event template shows a warning notice; on the live site it renders nothing.
