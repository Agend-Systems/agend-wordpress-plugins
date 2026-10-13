# Build plan: clickable phone and website in the Agend Field widget

Status: built (branch `feat/record-field-contact-links`)
Plugin: `agend-apps-core` only (the Elementor widget needs no change)

## The ask

A directory site uses the Agend Field Elementor widget to show a listing's
phone number and website. Both render as plain text. The ask:

- **Website**: optionally linked, opening in a new tab, with the new tab
  togglable.
- **Phone**: optionally linked as `tel:` so mobile visitors can tap to call.
- **Both**: optional custom link text (for example "Visit website", "Call us")
  in place of the raw value.

Confirmed: these are the built-in fields `listing:phone` and `listing:website`,
not custom fields.

## How it works today

The Agend Field widget
(`agend-elementor/includes/widgets/class-agend-elementor-record-field.php`) is a
thin adapter. It registers its Content controls from the shared surface schema
and echoes the core renderer's output. So controls added to the schema appear in
Elementor (and the block editor) without touching the widget.

| Piece | File |
|---|---|
| Content-settings schema | `agend-apps-core/includes/records/schema/record-field.php` |
| Renderer | `agend-apps-core/includes/records/render/record-field.php` |
| Field registry and formatter | `agend-apps-core/includes/records/fields.php` |
| Schema vocabulary (conditions etc.) | `agend-apps-core/includes/records/schema.php` |
| Tests | `agend-apps-core/tests/RecordFieldRenderTest.php` |

Why the values are plain text:

- `listing:phone` is registered with kind `text` (`fields.php`, listing block),
  so it is just `esc_html()`'d.
- `listing:website` is kind `url`, and `agend_apps_records_format_field()`
  returns `esc_url( $value )` for that kind: a sanitised string, never an
  anchor.
- The only link option the widget has is `link_to_detail`, which wraps the whole
  value in a link to the record's detail page. The renderer suppresses it when
  `$extra['in_card_link']` is set, because a card that is already one big link
  cannot contain another anchor.

The built-in listing detail already links these fields, so there is precedent
to match:

- `fragments.php` `agend_apps_records_fragment_listing_contact()`: phone as
  `href="tel:' . esc_attr( preg_replace( '/[^+\d]/', '', $phone ) ) . '"`;
  website and socials only when they match `#^https?://#i`, with
  `target="_blank" rel="noopener noreferrer"`.
- `fragments.php` location block: the same `tel:` stripping, inline in a
  template.

Also useful: the widget's Style-tab "Colour" control already targets
`{{WRAPPER}} .agend-field a`, so new links pick up the widget colour.

## Design

### 1. A `phone` field kind

- Add `'phone'` to `AGEND_APPS_RECORDS_FIELD_KINDS` in `fields.php`.
- Change `listing:phone` to `'kind' => 'phone'`.
- In `agend_apps_records_format_field()`, let `phone` fall through to the
  `text` branch (escaped text, truncation honoured). Simplest: add
  `case 'phone':` directly above `case 'text':`.

Checked for fallout: nothing filters fields on the `text` kind (the only
`agend_apps_records_field_options( $kinds )` caller is Agend Image, with
`array( 'url' )`), and no CSS targets `.agend-field--text`. The wrapper class
changes from `agend-field--text` to `agend-field--phone`; the key class
`agend-field--listing-phone` is unchanged.

### 2. Mark which fields can be linked

Kind alone cannot decide this: `url` also covers image fields
(`listing:logo_url`, `listing:hero_image_url`, `common:image`, the event and
course image fields) and `common:detail_url`, none of which should offer
"Link the value".

Add a registry flag, in the same style as the existing `'pill' => true`:

- `'linkable' => true` on `listing:phone`, `listing:website`,
  `listing:linkedin_url`, `listing:facebook_url`, `listing:instagram_url`,
  `listing:twitter_url`, `listing:youtube_url`.
- A helper returning the linkable keys, optionally filtered by kind, for the
  schema conditions below, e.g.
  `agend_apps_records_linkable_field_keys( ?array $kinds = null ): array`.

Including the social URLs costs nothing and is where link text is most useful
("LinkedIn" rather than a long profile URL).

### 3. New settings in `schema/record-field.php`

Setting `name`s are persisted in every saved page; pick them once.

| name | type | default | shown when | notes |
|---|---|---|---|---|
| `link_value` | toggle | `false` | `field` is a linkable key, and `link_to_detail!` = `yes` | Label "Link the value". Description: "Websites link to the address. Phone numbers become tap-to-call links. Ignored when the whole card is already a link." |
| `link_text` | text | `''` | `link_value` = `yes` | Label "Link text". Description: "Shown instead of the value, for example Visit website or Call us. Leave empty to show the value." |
| `link_new_tab` | toggle | `true` | `link_value` = `yes` and `field` is a linkable `url` key | Label "Open in new tab". Not shown for phone. |

Place them directly after `link_to_detail` so the two link options sit
together.

**`link_value` must default to off.** Elementor does not store settings left at
their default, so a default of on would turn every existing Agend Field on
every site into a link at the next release. Each site enables it per
widget.

Conditions use Elementor's shape, documented in `schema.php` ("Condition =
Elementor's `condition` array shape", including the `'key!'` not-equal form).
Multiple keys in one condition are ANDed. Confirm the block editor adapter also
honours the `!` form when testing there.

### 4. Renderer changes in `render/record-field.php`

In `agend_apps_records_render_record_field()`, after `$html` is built and
before `$inner` is assembled with before/after text:

1. Work out the value's link, only when all of these hold:
   - `'yes' === $settings['link_value']`
   - `'yes' !== $settings['link_to_detail']` (detail link keeps precedence, so
     existing behaviour is untouched if both were somehow saved on)
   - the field is not gated and `$html` is not the fallback text
   - the registry marks `$key` as linkable
2. Build the `href` from the **raw** value
   (`agend_apps_records_field_value( $key, $ctx['type'], $ctx['record'], $extra )`),
   not the formatted `$html`:
   - kind `url`: `esc_url_raw()`, and only if it matches `#^https?://#i`
     (as `fragments.php` does). Otherwise no link. `esc_url_raw()` adds
     `http://` to a schemeless `www.example.com`, so that is linked over
     http, as the built-in detail already does.
   - kind `phone`: `'tel:' . preg_replace( '/[^+\d]/', '', $raw )`. No link if
     no digit is left after stripping.
3. Label: `esc_html( trim( $settings['link_text'] ) )` when set, otherwise the
   existing escaped `$html`.
4. If `$extra['in_card_link']` is set, leave the value untouched: no anchor,
   and the raw value rather than the link text (decision 1 below). Otherwise:
   `<a class="agend-field__link agend-field__link--{kind}" href="...">label</a>`,
   adding `target="_blank" rel="noopener noreferrer"` for `url` kind when
   `link_new_tab` is on.
5. Link the value only, not the before/after text: with "Text before" set to
   `Phone: `, only the number is the link. So this replaces `$html` before
   `$inner = esc_html( $before ) . $html . esc_html( $after )`.

Put the href logic in a small helper (e.g.
`agend_apps_records_record_field_value_href( string $kind, $raw ): string`) so
it is unit-testable on its own.

**Shared `tel:` helper.** Add one helper for the phone-to-`tel:` conversion
(e.g. `agend_apps_records_tel_href( string $phone ): string` in `format.php`,
which both renderers already load) and use it in the two `fragments.php` call
sites as well, so all three stay identical.

### 5. Links inside a catalogue card

When "Link the whole card" is off, a templated card is a `<div>` and the
catalogue scripts delegate clicks on it to open the detail, calling
`preventDefault()` on any click inside, inner links included. So a phone or
website link in such a card would open the detail instead. In
`onTemplatedCardClick` (`directory-catalogue.js`, `events-catalogue.js`,
`courses-catalogue.js`), a click on an inner link that goes anywhere but the
card's own detail (the card's `data-agend-href`) now keeps its own behaviour:
a `tel:` link, a website, an Agend Link "Add to Calendar", "Enrol" or custom
URL. Links to the detail, such as an Agend Link "Open detail" button, still
open the in-page detail. The events "Register" action is a button, not a link,
so it is unaffected.

The `tel:` helper takes only the first number: it stops at an extension or a
second number (`ext`, `x`, `,`, `;`, `/`) that follows a digit, so a label such
as "Ph/Fax" before the number is not cut, and drops a `(0)` written after a
`+` country code.

### 6. Optional style control

A "Link hover colour" control in the widget's Style tab
(`{{WRAPPER}} .agend-field a:hover`). This would be the one change in
`agend-elementor`, which would then need its own version bump. Skip until
someone asks.

## Decisions (2026-10-13)

1. **Link text inside a linked card.** When the whole card is a link, the
   field shows the raw value as plain text and ignores the link text. A
   "Call us" that cannot be tapped tells a visitor less than the number.
2. **Display of website when no link text is set.** Shown as stored.
   Stripping `https://` and `www.` could be a later toggle.

## Tests to add (`RecordFieldRenderTest.php`)

- Phone with `link_value` on: `+61 3 9876 5432` renders
  `href="tel:+61398765432"`, and `(03) 9876-5432` renders
  `href="tel:0398765432"`.
- Website with `link_value` on renders an anchor with `target="_blank"` and
  `rel="noopener noreferrer"`; with `link_new_tab` off, no `target`.
- A website value with another scheme (e.g. `javascript:alert(1)`,
  `ftp://`) renders as text, no anchor. A schemeless `www.x.com` links over
  http.
- `link_text` replaces the label and is escaped; empty `link_text` shows the
  value.
- Before/after text stays outside the anchor.
- `in_card_link` set: no anchor, and the raw value rather than the link text.
- `link_to_detail` on: detail link only, `link_value` ignored.
- `link_value` off (default): output identical to today.
- Fallback text (no value) is never linked.
- `listing:phone` wrapper class is `agend-field--phone`.

## Release

- Bump `agend-apps-core` header `Version` and `AGEND_APPS_CORE_VERSION` in
  `agend-apps-core/agend-apps-core.php` from `1.37.4` to `1.38.0` (new feature).
  The `versions` and `bump` CI jobs enforce both; see `docs/RELEASING.md`.
- Release notes are generated from commits, so write commit messages that read
  well as notes.
- Branch from `main` as `feat/record-field-contact-links`.

## After deploy (each site using it)

On each Agend Field showing Phone or Website in the directory card and detail
templates: turn on "Link the value", set link text if wanted, and for Website
confirm "Open in new tab". Then check that a tap on the phone number on a
mobile opens the dialler.
