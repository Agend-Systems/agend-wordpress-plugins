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
| Agend Pills | A record's categories, tags or other terms, one styled pill per term. The pill count follows the record, where a styled Agend Field would render one chip holding a joined list. |
| Agend Link / Button | Open detail, back to catalogue, register (events), enrol (courses), add to calendar (events), or a custom URL with `{slug}` and `{title}` tokens. |
| Agend Content Block | The built-in detail panels as reusable blocks: event facts, registration, tickets, sponsors; course details, learning outcomes, pricing and enrolment; listing about, contact, categories, tags, gallery, locations, hours, custom fields, badges and reviews. |
| Agend Filter | One catalogue filter control. Directory tag, badge and custom field values come from `GET /v1/directory/facets`, entitlement-scoped, so a visitor never sees a value they may not read. Filter widgets go in a filter template that a catalogue widget is pointed at, so the controls survive the move between the listing and detail views. Each filter either lists every value of a field or sends author-defined choices, where one choice can stand for several values. |

Fields marked Common work in any template, resolving to that record type's equivalent. `Custom field (by key)` reads an Agend custom field by its key; which custom fields a visitor receives depends on their entitlements, so it renders empty for a visitor who is not entitled to that field. In the editor the widgets show the first upcoming event or first course as preview data.

- **Card template**: chosen per catalogue widget (Content > Card Template). The first page is rendered server-side, one template render per record; filtering and pagination fetch rendered fragments from `GET /wp-json/agend-elementor/v1/cards/events`, `/cards/courses` and `/cards/listings`. "Whole card links to the event" wraps each card in one anchor; turn it off to let only Agend Link widgets navigate.
- **Filter template**: chosen per catalogue widget, alongside a Filter position of across the top,
  down the left or down the right. A side position puts the filters in their own column beside the
  results, collapsing to a stack under 768px; set the column width and the stacking direction on
  the filter template's own container. The built-in Visitor Filter Bar controls are hidden while a
  filter template is chosen, because nothing reads them then.
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

1. **Custom field filtering does not filter.** The directory tag, badge and custom field filters
   are wired to the facets endpoint and send correctly encoded, validated parameters, but a
   `custom_fields[...]` filter returns the full result set for an anonymous caller: three
   mutually exclusive education values each returned all 20 listings. Tag filtering on the same
   request path works (20 to 16), so the plugin side is sound. Reported to the API side; likely
   the search RPC skipping the custom-field predicate when the caller holds no viewer field
   grant for it.
2. **Events and courses facets.** The facet endpoint exists for the directory only. Events tags
   and course categories still cannot be enumerated, and course categories are still derived from
   the distinct values of one page of courses. The facet response shape is deliberately
   record-type-neutral, so adopting it for the other two is additive on both sides.
3. **Export reports.** Make the directory export reports interactive. Not started, and a separate
   surface from card and detail templating.

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
