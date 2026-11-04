# WP-CLI regression tests

## Featured checkout authorization

`featured-checkout-security.php` tests the authorization-only checkout fix. It is test code, is excluded from distribution by `.distignore`, and is not loaded by the plugin.

Use an authorized disposable local/test WordPress installation with Directorist active, its database tables installed, WordPress 5.7 or newer, PHP 7.4 or newer, and WP-CLI. The WordPress minimum applies to this test suite so its `pre_wp_mail` hook can suppress fixture email. The script refuses production environments unless the site uses a loopback, `.local`, or `.test` hostname. For a test server, configure WordPress's `WP_ENVIRONMENT_TYPE` as `local`, `development`, or `staging`; do not override a production site's classification just to run the tests.

Core Featured Listing checkout must be available. Skip `directorist-pricing-plans` and `directorist-woocommerce-pricing-plans` if installed, because they replace the core flow. Skip unrelated extensions/themes when isolating this core regression:

```sh
wp --path=/path/to/wordpress --skip-themes \
  --skip-plugins=directorist-pricing-plans,directorist-woocommerce-pricing-plans,directorist-advanced-review,directorist-events,mcp-adapter,wp-reset \
  eval-file /path/to/wordpress/wp-content/plugins/directorist/tests/wp-cli/featured-checkout-security.php
```

The test discovers the registered REST checkout namespace. It checks exact 401/403 responses and unchanged listing, metadata, order, and payment rows for denied requests; owner/editor/admin access; owner paid/free checkout; retry and existing-order payment; poisoned/transferred orders; and callbacks that authorize the persisted customer without a logged-in user. Existing authorized publication and price behavior is retained, including the legacy unset-price fallback. The suite does not impose a new moderation or price-validation policy.

Requests use in-process WordPress REST dispatch. A local fake processor and repository updates simulate payment initiation/completion; this does not replace browser tests or verify a live provider's webhook signature. Outbound HTTP and email are blocked during fixture work. Site settings are overridden only in memory; no site options, plugin activation states, or global role capabilities are changed. Unique fixture users (including any per-user capabilities), listings, orders, and payments are removed in `finally`, and cleanup is checked. Do not interrupt the process; forced termination cannot run cleanup.

The script prints its results and exits nonzero on failure. It creates no reports, screenshots, or other saved artifacts.

## Listing, media, receipt and user authorization

Run `object-authorization-security.php` using the same local/test WP-CLI command above, replacing the test filename. It blocks outbound HTTP/email and cleans up its temporary users, per-user capabilities, posts and order in `finally`. It checks foreign attachment selection and both deletion paths (including poisoned saved IDs), existing admin-supplied media, pending approval and owner visibility toggles, receipt authorization, existing/new form authorization, and all three user bulk-action permission gates.

Receipt and form checks stop at the rendering boundary; they do not test browser layout. The mixed-target bulk-action test models a multisite object-capability denial with `map_meta_cap`; it does not provision a live multisite network. Run the suite on a disposable single-site installation. No site settings or global role capabilities are changed.
