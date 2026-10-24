# Settings Panel Feasibility Report

## Summary
- Request: replace the external MCP Adapter setup link with an AJAX install/activate action and loading animation.
- Recommended approach: expose installed/active/capability state in the existing admin status response and use WordPress's nonce-protected `install-plugin` and `activate-plugin` AJAX handlers through the existing MCP request client.
- Risk level: medium interaction/integration risk. The user explicitly authorized this setup action. No settings save or existing permission contract changes are needed.

## Easy To Achieve
- Add a focused adapter-status class and installer service without combining them with connection configuration or snippet generation.
- Show Install & Activate when missing, Activate when installed but inactive, and a spinner with the current action while pending.
- Refresh status in a fresh request after installation/activation so newly loaded plugin classes determine readiness.

## Complex Or Risky
- Respect separate installation and plugin-specific activation capabilities, multisite policy, filesystem errors, nonce expiry and WordPress/plugin compatibility.
- Handle installation succeeding but activation failing: refresh state and offer activation rather than attempting installation again.
- Prevent duplicate requests and state updates after the component is destroyed. Activation is not cancelled server-side by abandoning a page; later status reads must reconcile actual state.
- An active incompatible adapter requires an explicit update through native plugin management, not repeated installation or an unrelated credential flow.

## Affected Areas
- PHP settings builder: unchanged; existing `mcp_enabled` field and Other Features menu remain intact.
- Vue app/store: add component-local installation state and reuse the existing asynchronous status/persist path. Keep general-save isolation.
- SCSS/build output: scoped directly loaded CSS and JavaScript under `assets/src`; no generated output changes.
- Saved options/data: no new option, migration, save payload or authentication behavior. Setup does not enable Directorist MCP automatically.
- Connected frontend/admin behavior: WordPress owns plugin download, compatibility checks, activation and errors. The existing MCP dependency checks still gate enablement.

## Questions Before Risky Work
- None. The requested install/activate interaction is explicit authorization. Reuse existing WordPress endpoints and nonce/capability checks; do not add arbitrary installation endpoints or weaken settings permissions.

## Verification Plan
- Static checks: PHP/JavaScript syntax, focused project PHPCS where available, registered script dependency paths and request/response behavior checks.
- Browser/manual QA: missing and installed-inactive states, visible loading/disabled controls, successful readiness refresh, denied permissions, failed install/activation, retry after partial success, and unchanged settings save behavior.
- Build policy: no asset build or generated assets. Maintain source files directly. Verification harnesses stay outside the repository; do not recreate the removed test folder.

## Inspected source
- Existing module admin status/assets and `assets/src/js/admin/mcp/components/configuration-field.js` own this field.
- WordPress `wp-admin/includes/ajax-actions.php` provides both setup actions; both require the `updates` nonce, with separate installation and plugin activation capabilities. The MCP minimum WordPress version exceeds activation AJAX's introduction in WordPress 6.5.
- The general settings shell/store, source styles, settings option catalog and current legacy webpack entry map were inspected. The old skill reference `webpack-entry-list.js` is absent in this checkout; `webpack.legacy.dev.js` declares the settings entry.

## Implementation verification — 2026-10-24

- Real authenticated browser checks passed for installed/inactive activation and missing-plugin installation plus activation from WordPress.org. Loading text/spinner and disabled controls were visible; readiness refreshed without reloading or automatically enabling MCP.
- The original adapter files and active state were restored after temporary setup fixtures. Exact before/after hashes of `atbdp_option` matched.
- Ten temporary JavaScript behavior checks passed for installation sequencing, activation-only behavior, permission denial, native JSON error envelopes, partial-success retries, duplicate clicks, late results after destruction, preserving enabled MCP state, expired-session feedback and cancellation. The harness stayed outside the repository.
- Native WordPress checks passed for inactive plugin metadata, denied activation capability, unsupported WordPress version and the authenticated status route.
- PHP syntax, JavaScript syntax, whitespace and focused PHPCS checks passed. The repository rules exclude `admin` paths, so the focused temporary PHPCS ruleset removed only that path exclusion to actually scan the three touched PHP files; all project coding rules were retained.
- Script dependency/packaging inspection confirmed both MCP source subtrees remain included. No build, generated asset edit, test folder, commit or push was performed. Multisite installations and hosts requiring filesystem credentials still need environment-specific QA; WordPress errors are shown without claiming setup succeeded.
