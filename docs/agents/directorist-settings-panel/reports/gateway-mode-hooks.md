# Settings Panel Feasibility Report

## Summary
- Request: Allow extensions to register their gateway mode selectors through a generic hook instead of adding gateway names to core source.
- Recommended approach: Add `directorist_settings_gateway_mode_keys` to the PHP builder config and read the resulting keys in credential search and section styling. Retain the existing Stripe/PayPal defaults.
- Risk level: Low to medium; additive runtime configuration with unchanged setting keys and storage.

## Easy To Achieve
- Reuse the existing settings builder JSON and Vuex config.
- Keep gateway-specific registrations inside each extension.
- Preserve existing behavior when no extension registers additional keys.

## Complex Or Risky
- Both credential-search visibility and section styling must consume the same config.
- The committed settings-manager bundle must be rebuilt with the source change.

## Affected Areas
- PHP settings builder: One documented filter supplying gateway mode setting keys.
- Vue app/store: Two consumers of the config; existing store initialization is reused.
- SCSS/build output: No SCSS change; settings-manager JavaScript bundle only.
- Saved options/data: None; no migration or save contract change.
- Connected frontend/admin behavior: Extension credential search and Sandbox/Live section styling.

## Questions Before Risky Work
- No unresolved behavior questions. The user explicitly authorized generic extension hooks and approved a focused production build of the settings-manager bundle.

## Verification Plan
- Static checks: PHP syntax, focused JavaScript checks, hook registration/default tests, and diff checks.
- Browser/manual QA: Sandbox and Live credential visibility, search results, and enabled section styling without saving credentials or mode changes.
- Build policy: Run only the approved settings-manager production build; exclude packaging and unrelated entries.
