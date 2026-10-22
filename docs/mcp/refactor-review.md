# MCP refactor functional and visual review

Reviewed on 22 October 2026 against the current working tree, including the `tool-providers/` rename, PHP type declarations and directly loaded assets.

## Issues reproduced and fixed

| Issue | Reproduction and impact | Fix |
|---|---|---|
| Rejected save displays the wrong switch state | Open the settings page, revoke the disposable administrator's capability, and click the switch. The server rejects the save, but the browser checkbox previously changed while the badge and Configure button retained the saved state. | [Configuration field](../../assets/src/js/admin/mcp/components/configuration-field.js) restores the checkbox to the confirmed state before submitting. Only a successful status refresh changes the displayed value. Reproduced and retested on the real settings page. |
| Keyboard focus skips verification instructions | Press Tab from Copy snippet. The focus loop previously returned to Close without reaching the native details disclosure. | [Modal accessibility](../../assets/src/js/admin/mcp/utilities/modal-accessibility.js) includes summary elements and excludes negative tab indexes. Tab now reaches Verify the connection; Enter expands it; the final focus stop wraps to Close. |
| Authentication cleanup depends on a JSON error body | A 401/403 response containing HTML previously failed JSON parsing before the unauthorized callback ran, leaving an open drawer and its transient credential inputs. | [API client](../../assets/src/js/admin/mcp/services/api-client.js) performs unauthorized cleanup before parsing. Malformed bodies produce a safe error without response-content disclosure. Regression cases cover both authentication statuses and null JSON errors. |
| A destroyed field can start another request | Complete an in-flight successful save after the component has been destroyed. The old continuation started a new status request after cancellation. | The configuration field checks its lifetime before continuing and after refreshing. A failed confirmation refresh also retains its existing error. A deferred-response regression test verifies that no follow-up request starts. |

The client-template return PHPDoc was also corrected. These changes preserve the native AJAX action, payload, nonce, capabilities, stored setting and tool schemas.

## Verification coverage

| Area | Observed checks |
|---|---|
| MCP settings and setup | Real authenticated Other Features → MCP navigation and reload/deep link; enabled and disabled states; Configure visibility; successful independent disable save; capability-rejected save and Retry recovery. The successful save changed only `mcp_enabled`. |
| Connection drawer | All five client tabs; valid JSON templates and locally filled dummy credentials; copy feedback; Escape and focus restoration; password clearing after reopening; verification disclosure keyboard access; 390px mobile layout without horizontal overflow; desktop/tablet rendering. No unexpected console errors or warnings were observed. |
| Settings management | Existing schema/read/update/reset integration cases, secret exclusions, unrelated-value preservation and general-save isolation. |
| Directory builder | Created a disposable directory through MCP, then opened it in the native builder and exercised General, Add Listing Form, Single Page Layout, All Listing Layout and Search Form. Existing tests cover structural writes, stable IDs, validation, stale previews and blocked deletion. |
| Listings | Created a native draft through MCP using its discovered field schema; verified its directory, owner and draft state in the native listings table. Existing integration cases cover edits, media, assignments, status, trash/restore, permissions and bulk results. |
| Taxonomies | Created category, location and tag fixtures through MCP; verified them and their directory association in the native taxonomy screens. Hierarchy, cycles, protected dependencies and bulk cases pass automatically. |
| Orders | Created an unpaid order through MCP and verified its Pending status, price and customer in the native Orders UI. The order/extension suite covers permitted lifecycle changes, exact money, offline/refund fixtures, retries and mixed bulk outcomes without live financial transactions. |
| Extension framework | Existing registered-tool, settings/field/read adapter, policy, registration failure, deactivation and asynchronous provider fixture cases passed. A third-party extension installation was not required. |
| Dashboard | Native dashboard and MCP agreed on published-listing stock, lifetime views and paid-order value. The temporary draft also appeared in native recent activity. Range, scope, privacy and extension-source cases pass automatically. |
| Site Health Info | Opened the native Info screen. All eight dashboard/Site Health MCP read tools passed over verified HTTPS; redaction, runtime identity, bounded reports and absence/failure cases pass automatically. |
| Transport and compatibility | Verified HTTPS initialization, 53-tool discovery, execution, sensitive-setting denial and credential revocation. The pinned local bridge passed over verified TLS. Original previews still commit and original successful retry records replay. All 1,647 checked internal static references resolve. |

The four PHP suites passed **819 assertions**. The UI/packaging suite passed **16 tests**, including four new regressions for the issues above. PHP syntax, project coding standards, PHP 7.4 compatibility and JavaScript syntax checks passed.

## Cleanup and limits

The disposable administrator, directory, listing, three terms, unpaid order, preview/retry records and temporary transport credentials were removed. The original MCP enablement, unrelated settings, preview epoch and cleanup schedule were verified afterward. Browser tabs and viewport overrides were closed/reset. No mail, live gateway action, asset build, migration, deployment, commit or push was performed.

This review verifies the available local configuration and fixture integrations. It does not certify every third-party client version, installed extension/provider combination, multisite configuration or live payment gateway. Client tabs were tested as configuration generators; actual Claude, Cursor and Codex application connections were not exercised.
