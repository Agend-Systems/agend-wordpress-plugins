# Opt-in trusted LMS OAuth adapter (#4114)

This adapter implements the provider side of the version-1 Agend trusted WordPress authorization-request contract. It is disabled by default and does not replace SAML, credential login, the gateway member session or their bearer consumers. A PHPUnit positive is not evidence of the complete WordPress → LMS login/MFA/consent → callback round trip.

## Server configuration and entry point

After the dashboard request/consent transaction and confidential-client verifier have shipped, an authorised operator can configure these constants in server-only `wp-config.php` or its secret-loading configuration:

- `AGEND_LMS_OAUTH_ENABLED`: boolean `true` (omit or false to disable new starts).
- `AGEND_LMS_OAUTH_ORIGIN`: HTTPS LMS origin, with no credentials, path, query or fragment.
- `AGEND_LMS_OAUTH_CLIENT_ID`: the registered confidential integration client.
- `AGEND_LMS_OAUTH_CLIENT_SECRET`: its server-held secret; never put this in a page, URL or JavaScript.
- `AGEND_LMS_OAUTH_REDIRECT_URI`: the exact registered HTTPS callback, equal to `admin_url('admin-post.php?action=agend_lms_oauth_callback', 'https')` for this site.

No credential settings UI or production configuration is introduced. A cloned site must not inherit an enabled production integration's server configuration. The adapter sends only to the configured origin, with TLS verification, a ten-second timeout, response-size cap and no followed redirects.

The explicit authenticated start entry is **POST `/wp-json/agend-apps/v1/lms/oauth/start`**, with the existing WordPress `X-WP-Nonce` for `wp_rest` and same-origin cookies. Send no identity or OAuth configuration fields. A successful response contains only `status: continue` and an `authorization_url`; a consuming page can navigate to that URL. Use the site's `rest_url('agend-apps/v1/lms/oauth/start')` rather than hardcoding `/wp-json` on installations with a different REST prefix.

A minimal consuming page helper follows the existing `window.agendApps.nonce` pattern (the endpoint URL is server-rendered with `rest_url()`):

```js
async function connectLms(endpoint) {
  const response = await fetch(endpoint, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'X-WP-Nonce': window.agendApps.nonce },
  });
  const result = await response.json();
  if (response.ok && result.status === 'continue') {
    window.location.assign(result.authorization_url);
  }
}
```

This documentation helper is not a newly deployed button or live activation. Errors contain only local retry/unavailable status; the page must not render a backchannel response or credentials.

## Identity, storage and callback

The adapter derives the numeric ID, email and display name from `wp_get_current_user()`. It rejects browser-supplied identity/configuration overrides. New random state and PKCE S256 verifier are stored in a non-autoloaded server option, bound to the current WP user and the hash of the current WP login-session token, with a maximum ten-minute lifetime. A scheduled expiry hook removes abandoned options. WP-Cron delays do not extend eligibility: callback checks the stored expiry before exchange.

The backchannel posts JSON to `/api/v1/oauth/requests`. The browser authorization URL contains only the registered client ID and the opaque `request_uri`. State, verifier, client secret and identity fields are not returned by the start endpoint.

The callback uses WordPress's `admin_post_agend_lms_oauth_callback`, allowing WordPress to resolve its login cookie without an externally supplied REST nonce. It requires the same logged-in user AND login-session token, the exact state, unchanged origin/client/callback and an unexpired transaction. Unknown state, swapped user/session and changed configuration do not exchange a code or repoint another user.

The option is consumed with one database compare-and-delete BEFORE a token exchange. A concurrent worker holding stale cached data cannot win the same delete twice. Expired, denied, missing-code and failed-exchange callbacks remain consumed; retry requires a new start. The compare-and-delete pattern reuses the maintained directory job-lock mechanism, rather than a non-atomic transient get/delete sequence.

The token exchange is form-encoded to `/api/v1/oauth/token`, with the original server-held verifier and configured client secret. Tokens are stored only in the initiating user's private `_agend_lms_oauth_tokens` user meta. This is a separate LMS envelope, not the existing Supabase member-session store; it is not connected to SAML or general gateway bearer consumers. The browser callback redirects to a fixed local success/retry marker without tokens or upstream errors. No OAuth request, reference, secret or token is logged by this adapter.

## Verification and rollout boundary

The unit harness uses WordPress function/database/HTTP doubles; it is not a WordPress core bootstrap. Tests cover current-user positive, forged identity/start nonce, user/session swap, state swap, expiry, denial, callback replay, a stale-cache competing consume, sanitized upstream failure and configuration/HTTPS rejection. A real database concurrency test and actual PHP-to-LMS/browser round trip remain separate acceptance gates.

For an owned local HTTPS harness with a private CA, trust configuration belongs in that fixture only, scoped to the exact fixture origin. Do not disable production TLS or weaken this adapter's HTTP checks. WordPress's safe-HTTP private-host/port restrictions may also require an exact fixture-only allowlist when testing loopback/nonstandard ports.

Ship #4113 confidential-client validation and #4114 dashboard migration/transaction code before enabling this adapter. Validate with a genuine current WP user, Agend login/MFA/consent, same-session callback and token-backed read for an external learner. Human security, migration, plugin release and provider-activation decisions remain separate. Merging this repository to main with its version bump triggers the existing automatic plugin release workflow; no release or merge has been performed by this work.

Rollback disables new starts through the opt-in constant. It must not restore unsigned new-user admission. Pending transactions expire; existing managed links and SAML consumers retain their separate paths.
