# Settings Panel Feasibility Report

## Summary
- Request: reorganize the MCP module and its existing connection panel without changing behavior.
- Recommended approach: a PHP composition root and feature-owned callbacks; directly shipped, dependency-ordered JavaScript components and CSS.
- Risk level: medium integration risk; compatibility-critical authorization, write coordination and transient credential behavior require regression checks.

## Easy To Achieve
- Move field/layout registration, asset registration and configuration REST handlers into dedicated classes.
- Extract pure snippet generation, clipboard handling and modal accessibility helpers.
- Split existing CSS without altering selectors, declaration order or responsive rules.

## Complex Or Risky
- Preserve registration sealing, hook timing, callback ordering and inventory ordering.
- Keep existing registry fingerprints, preview payloads and durable retry records compatible. Runtime callback bindings must remain outside fingerprinted descriptors.
- Preserve independent enablement saves, Vuex cached values, general-save isolation, focus restoration and request cleanup.
- Forward existing public PHP APIs after moving their implementation; no stored-data migration is necessary.

## Affected Areas
- PHP settings builder: existing filters move to the MCP admin integration; native settings builder remains unchanged.
- Vue app/store: retain the existing registry, mount adapter and store mutations; reorganize the MCP component only.
- SCSS/build output: unchanged. Replacement JavaScript/CSS ships directly from the MCP subtrees in assets/src and uses explicit WordPress dependencies. Packaging retains these two subtrees after excluding unrelated sources.
- Saved options/data: mcp_enabled remains in atbdp_option; no key, save payload, nonce, permission or persistence contract changes.
- Connected frontend/admin behavior: settings tools retain native validation and hooks; unrelated settings and existing listing data must be preserved.

## Questions Before Risky Work
- None outstanding. The user approved the complete refactor and selected directly loaded files without a build. This authorizes the planned compatibility forwarding layer; it performs no migration or backfill.

## Verification Plan
- Static checks: PHP syntax, project PHPCS, JavaScript syntax, inventory/schema/fingerprint parity, public API forwarding and shipped dependency-path checks.
- Browser/manual QA: root Other Features navigation/deep link, immediate toggle saving, general-save isolation, client snippets, credential clearing, request cancellation, keyboard/focus behavior and responsive drawer appearance.
- Build policy: no build, formatter over generated assets, deployment, commit or push. Replacement files are maintained source files.

## Source-location follow-up

The user requested removal of the redundant PRD package and MCP asset ownership under `assets/src`. Keep the previously selected direct-loading approach: source JavaScript lives in `assets/src/js/admin/mcp`, and browser-ready CSS lives in `assets/src/css/admin/mcp`. Asset registration and tests must use those paths. Release packaging currently excludes `assets/src`, so its WordPress distribution, Git archive and legacy ZIP rules must retain exactly these two subtrees while continuing to exclude unrelated source files. Verify those packaging rules without running an asset build. No setting, save payload, nonce, capability, Vuex or credential behavior changes are needed.

Explicit PHP casts apply to native identifier/count/text projections and already validated scalar results. Input type validation, extension permission predicates and exact monetary strings remain strict; casts must not convert malformed client values into accepted input.

## PHP type-declaration follow-up

The requested PHP readability changes include parameter, property and return type declarations. Declare concrete service dependencies and native WordPress objects in the implementation, and scalar/array types after validation. Preserve the legacy facade signatures and mixed validation boundaries; document legitimate unions with PHPDoc for PHP 7.4 compatibility. Settings integration typing must preserve filter payloads and save behavior. Verify implementation types alongside the existing compatibility, validation and transport suites; no UI or storage changes are required.

## Functional and visual review follow-up

The authorized review covers the real authenticated settings page and MCP integration suites. Browser reproduction confirmed that a rejected enablement save leaves the native checkbox in the requested state while the card still shows its persisted state. The drawer focus loop also skips the native verification disclosure. Correct these locally in the MCP component and accessibility helper, preserving the existing save action, payload, capability checks and storage. Check API error handling for credential cleanup even when a rejected response is not JSON. These are focused medium-risk interaction fixes with no new product flow, migration, asset build or unresolved product decision. Verify successful and rejected saves, disclosure keyboard access, drawer cleanup, client snippets, responsive layout, and the existing backend suites.
