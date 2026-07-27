# Agend Content Access

Restricts WordPress content to Agend members and membership plans.

Implements SPEC-CMS-20260727-wordpress-member-content-access.

## The one rule

**WordPress declares the intended audience. Agend decides whether the current
visitor belongs to it.**

The browser never makes that decision, and never receives protected material it
cannot display. No access decision in this plugin reads WordPress user metadata,
WordPress roles, or WooCommerce.

## Requirements

| Dependency | Required? | Why |
| --- | --- | --- |
| WordPress 6.0+ | yes | |
| PHP 7.4+ | yes | |
| Agend Apps Core | **yes** | Transport, API key storage, member bearer contract, plan catalogue |
| Elementor Pro | optional | Fragment-level policies. Without it, whole post and page policies still work |

Explicitly NOT required, and never to be added: ACF, WooCommerce, WooCommerce
Memberships, the Iugo plugin, or any particular theme. The reference environment
is a plain WordPress install with Elementor Pro (Decision 2.16).

### Elementor versions

- **Build target: 3.x.** Fragment work is written against the 3.x control and
  rendering APIs.
- **Validated on: 4.x.** The first target site runs Elementor 4.2.0, so 4.x is a
  release gate rather than an afterthought (US-4.4).

A version outside the supported range surfaces an admin notice. Support is not
implied by the code happening to run.

## What this plugin owns

Document policies, Elementor fragment policies, the source connector, direct
WordPress request gating, protected assets, and the legacy migration.

## What it does NOT own

Transport, API keys, environment URLs, portal resolution, member login, or token
minting. All of that is Agend Apps Core's, consumed through its published
interfaces:

| Interface | Used for |
| --- | --- |
| `agend_apps_api()` | Outbound gateway calls |
| `agend_apps_get_bearer_token()` / the `agend_apps_bearer_token` filter | Member identity |
| `agend_apps_crm_get_tiers()` | Membership plan catalogue for the editor |
| `Agend_Apps_Cache` / `Agend_Apps_Settings::get_cache_ttl()` | Caching |

Reaching into Core internals rather than these is a review failure.

## Failure behaviour

Fail closed, without exception. An empty or malformed policy restricts. An
unresolvable tier never grants. An entitlement lookup failure redacts and logs.
A missing dependency registers nothing rather than half-registering, because a
half-registered access-control plugin is indistinguishable from one that decided
the visitor may proceed.

## Status

Under construction. Currently: the scaffold and dependency gate (US-3.1). No
policy is authored or enforced yet.
