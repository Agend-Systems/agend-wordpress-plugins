# Agend Embed

Embeds Agend app surfaces (LMS, Loop) in an iframe with host-assisted single
sign-on. This plugin is the reference implementation of the host side of the
Agend embed SSO contract: any CMS or site that can render an iframe and a
small message listener can implement the same contract.

## Usage

```
[agend_embed src="https://learn.agend.dev/embed/<account-id>/courses"]
```

Attributes:

| Attribute | Required | Description |
| :--- | :--- | :--- |
| `src` | Yes | Full https URL of the Agend embed surface. |
| `sp` | No | IdP-specific Service Provider selector. Entity id for `agend-saml-idp`, SP name for miniOrange. Defaults to the sole registered SP where discoverable. |
| `kickoff_url` | No | Explicit kick-off URL template override (must contain `%RETURN_URL%`). |
| `height` | No | Initial iframe height in px. Default `600`. |
| `title` | No | Iframe title. Default `Agend embed`. |

The embed works without this plugin (a plain `<iframe>` is enough): members
then sign in once inside the frame. What this plugin adds is **seamless**
sign-on for members who already hold a session on this site.

## Why the host must start the SSO navigation

Browsers only attach the WordPress `SameSite=Lax` login cookie to an iframe
navigation when the navigation is **initiated by the host page itself** and
**does not pass through a cross-site redirect**. A navigation started by the
cross-origin embed document is stripped of the cookie, so the IdP would show
the member a login form despite an active session. This is browser cookie
policy and cannot be worked around from the embed or the Agend gateway; the
host page is the only party that can carry the session into the SSO flow.

## The postMessage contract

All messages are exchanged between the embed iframe and its direct parent.

1. The embed loads, finds no session, and posts to the parent:

   ```json
   { "type": "agend:embed:sso-initiate", "returnUrl": "<current embed URL>" }
   ```

2. The host validates the message. It MUST check all three:
   - `event.source` is the embed iframe's `contentWindow`;
   - `event.origin` equals the embed's origin (from the iframe `src`);
   - `returnUrl` parses as a URL whose origin equals the embed's origin.

3. The host immediately acks, BEFORE navigating:

   ```json
   { "type": "agend:embed:sso-initiate-ack" }
   ```

   The ack matters: the embed keeps a short fallback timer and would otherwise
   self-navigate mid-flight, aborting the host's SSO navigation. On ack the
   embed cancels the fallback and waits.

4. The host navigates the **iframe** (never the top window) to the site's own
   IdP-initiated SSO URL, with the return URL as the SAML RelayState. The
   navigation must be a direct, same-site URL: no hop through another origin.

5. The IdP posts the SAMLResponse to the Agend ACS, which validates it, mints
   the embed session in a partitioned (CHIPS) cookie, and redirects the iframe
   back to `returnUrl`. The embed finds the session and renders member data.

If the host never acks (no listener on the page), the embed falls back after
~1.5s to self-navigating the SP-initiated flow, which shows the IdP's login
form inside the frame: functional, one extra login, not seamless.

## IdP drivers

The IdP-specific part is a single value: the **kick-off URL template**, an
absolute same-site URL that starts IdP-initiated SSO, containing the literal
placeholder `%RETURN_URL%` where the URL-encoded return URL belongs.

Built-in drivers:

| IdP | Template shape |
| :--- | :--- |
| Agend SAML IdP (`agend-saml-idp`) | `/saml/idp-sso?idp_initiated=1&sp=<entity id>&_wpnonce=<nonce>&RelayState=%RETURN_URL%` |
| miniOrange SAML IDP | `/?option=saml_user_login&sp=<SP name>&relayState=%RETURN_URL%` |

### Adding a driver for another IdP

Hook `agend_embed_sso_kickoff_url` and return the template. Example for a
hypothetical IdP whose IdP-initiated endpoint is `/sso/start`:

```php
add_filter(
	'agend_embed_sso_kickoff_url',
	function ( $template, $atts ) {
		// Respect an earlier resolution (explicit attr or built-in driver).
		if ( null !== $template ) {
			return $template;
		}

		if ( ! class_exists( 'My_IdP_Plugin' ) ) {
			return null;
		}

		return home_url( '/sso/start?sp=agend&relay=' ) . '%RETURN_URL%';
	},
	10,
	2
);
```

Rules for a driver:

- The URL must be on THIS site (same-site), or the login cookie will not be
  attached and the flow degrades to a login form.
- Append the placeholder raw; the browser substitutes an already URL-encoded
  value, so the template itself must not encode it.
- Return `null` when the IdP is not available or not configured; the embed
  then uses its own in-frame sign-in fallback.
- The Agend Service Provider must have IdP-initiated SSO enabled on the
  Agend connection (`allow_idp_initiated`), and the embed origin must be in
  the gateway's allowed origins so the RelayState is honoured.

## Requirements

- The Agend account has an active SSO connection to this site's IdP, with
  IdP-initiated SSO enabled.
- The embed origin (e.g. `https://learn.agend.dev`) is registered in the
  Agend gateway's allowed origins.
- The account's embed configuration has SSO auth enabled and this site's
  origin in its allowed embed origins.
