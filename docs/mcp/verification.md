# Directorist MCP implementation verification

Historical record: the automated MCP test suites, fixtures, and transport smoke script were removed from this branch on 22 October 2026. Counts and suite descriptions below record checks performed before removal; they are not instructions for running files included in this branch. Follow the [manual request flow](extension-tool-example.md#read-preview-and-execute) and current [architecture checks](architecture.md#verification) for ongoing review.

Latest scope: the initial runtime now contains 53 core tools and additive read-adapter API 1. The original order/extension evidence below is retained as a dated baseline. Dashboard/Site Health evidence follows in the 19 October update.

Implementation reviewed: 18 October 2026. Core tool inventory: 45 definitions plus validated registered extension tools. Registry API major: 1. MCP remains opt-in and was restored to its original disabled state after local verification.

## Evidence

| Check | Scope/result |
|---|---|
| Original core integration suite | Historical checks of the 45-tool inventory and narrow purchaser admission |
| Order/extension integration suite | Historical checks with disposable customers/custom staff, native tables, typed field/widget/item fixtures and fake providers |
| HTTPS transport | Initialization, negotiated session, 45-tool discovery, context execution, secret-setting denial and application-password revocation |
| Local stdio bridge | Pinned @automattic/mcp-wordpress-remote 0.5.1 over verified TLS; initialization, discovery and authenticated context |
| Static checks | Focused PHP syntax/coding standards and Markdown links/catalog traceability; no generated asset build |

Final local assertion counts and static-check outcomes are recorded below. Stateful fixture suites now share a database advisory test lock and must run sequentially before taking configuration snapshots.

The suites cover unpaid creation/recalculation, explicit legacy lookup, purchaser isolation/cancellation, native offline fulfillment, partial/manual refunds, synchronous fake settlement, accepted-then-timeout reservations, financial replay after cache expiry, native completion proof, mixed batches, custom-role revocation, safe discovery/diagnostics, invalid API/colliding registrations, hidden-write rejection, field/widget/item adapters, stale previews, additional policies, extension lifecycle invalidation and alternate ability execution. Generic external tools also have fake dispatch/reconciliation and verified native result completion.

The HTTPS account and application password are disposable and removed/revoked in cleanup. CLI fixtures suppress mail and restore settings, metadata, roles, users, orders, native ledger entries and operational records in finally. No real gateway charge/refund, commercial extension activation, role migration or plugin build was performed.

## Compatibility and remaining release gates

The inspected environment uses Directorist 8.10.5, WordPress 7.1.3, adapter 0.6.1, PHP 8.2 CLI / PHP 8.0 web and Node.js 22. This records tested versions, not an unconditional guarantee for every later release.

- Current custom-table storage and explicit previously migrated legacy mappings are supported. Unmigrated/ambiguous legacy records require native administration; MCP performs no migration.
- Monetary strings/minor-unit comparisons are exact. Native DTOs/tables/calculators still constrain native writes to at most two decimal places and DECIMAL(10,2); unsupported precision/range is refused. Core refunds require a single settled receipt because native refunds lack payment allocation metadata.
- Real gateways need an explicitly registered native adapter and their own sandbox/provider certification. Registered callback contracts are tested with fake providers; installing an extension alone does not certify its policy, credentials or financial behavior.
- Asynchronous provider settlement is separate from native ledger completion. Read tools never dispatch or rerun native writers. A trusted administrator integration can bind verified already-committed native results using the documented local PHP completion APIs.
- Native REST handlers, checkout and native order-table writes coordinate through the MCP advisory lock. Additional stores/writers must declare tables/cache behavior and join the lock before reading state used for a later write. Full multi-process webhook/third-party-store races still require staging verification.
- Supported WordPress/adapter/runtime minima, every named desktop client, mobile browser behavior, large ledgers, multi-currency native/provider combinations, external hook/email failures and the entire PRD acceptance matrix remain release criteria. Focused checks are not completion of every QA row.

## Final run

- Original core suite: **160 assertions passed**.
- Order/extension suite: **188 assertions passed**; combined **348 assertions**.
- HTTPS and pinned stdio bridge: initialization, 45-tool discovery, authenticated execution, protected-setting denial and revocation passed with TLS verification enabled.
- Focused coding standards and PHP syntax passed; formatting patches were checked for identical non-whitespace PHP tokens. No generated asset build was run.
- All 12 PRD files retain 61 unique stories, 208 acceptance criteria and 45 core tool definitions with exact criterion-to-story-row coverage. Local documentation/source links resolve.
- Final HTTP cleanup verified disabled MCP and an unchanged hash of every sibling setting. Fixture users/orders/options and temporary HTTP credentials were removed; no MCP cleanup job was left scheduled.

One intermediate overlap between fixture runs left the enablement flag on. It was detected and corrected, and the final stateful runs were sequential. A shared test-suite advisory lock now prevents this overlap. The final sibling-settings check passed.

## Dashboard and Site Health update — 19 October 2026

The [implementation guide](dashboard-health.md) records eight implemented read tools, their native sources, exact scope/period meanings, supported bounds and additive read-adapter API 1. Native pages, settings keys/save flow, scheduled tests/updates, credentials and provider workflows were preserved.

Focused checks passed on the local Directorist/WordPress installation:

- Core suite updated for 53 tools: **166 assertions**.
- Existing order/extension regression suite: **188 assertions**.
- New dashboard/Site Health fixture suite: **140 assertions**, including typed schemas, actor/capability boundaries, own listing/purchaser separation, exact currencies, ambiguous legacy mapping, DST dates, database-session TIMESTAMP normalization, safe view distributions, malformed counters, safe public guidance hosts, redaction, text/JSON parity, report bounds, hidden SQL/HTTP denial, extension faults/deactivation, current runtime facts and alternate abilities. Combined: **494 assertions**.
- The 1,005-order source fixture returned a 100-item page and an explicitly truncated 1,000-item window using **4 queries in approximately 0.006 seconds**, within the fixture's 50-query/5-second local budget. This does not establish the performance of maximum-size listing scopes or arbitrary extension collectors.
- Real verified HTTPS: initialization/session negotiation, 53-tool discovery, all eight reads, protected-setting denial and revocation of a live session passed. Pinned stdio bridge 0.5.1 initialization/discovery/context also passed over verified TLS.
- PHP syntax passed for **34 files** covering the MCP module, three native repositories, three CLI suites and the controlled extension fixture. Focused PHPCS passed. Formatting was performed on temporary copies and verified for unchanged non-whitespace PHP tokens before applying whitespace edits; no generated asset build ran.
- Static PRD validation passed for 14 files, 78 unique stories, 275 acceptance criteria, 53 catalog entries and 90 QA scenarios; exact criterion-to-story-row coverage and local links/anchors passed. These are documentation checks, separate from runtime scenario acceptance.

Fixture suites run sequentially under the shared test lock, suppress mail and restore their original enablement/settings, timezone, users, posts, orders and roles in finally. HTTPS cleanup removed the temporary administrator and all its application passwords and verified an unchanged hash of unrelated settings. Each suite restored the enablement state captured before its run. Final inspection found MCP enabled with its existing cleanup schedule; the product default remains disabled.

No live financial action, account/license refresh, Site Health Status test, external collector request, recursive size scan, automatic migration, role grant or persistent diagnostic/report cache was required. Optional analytics/enquiry/recommendation sources require explicit compatible registration; unmapped legacy financial currency and unsupported storage snapshots remain honestly unavailable/incomplete. Native object-permission scans cap at 20,000 listing/review candidates and reject broader scopes rather than returning partial totals.

Actual multisite installations and network policies, minimum/current WordPress/adapter/runtime combinations, real extension products, restricted hosts, maximum listing scopes and every individual PRD release scenario remain staging/release gates. Focused passing assertions do not certify the entire matrix.

## PRD review and repairs — 19 October 2026

The [review report](dashboard-health-review.md) maps repaired gaps to PRD requirements and reproduction cases. The implementation guide now describes separate past/upcoming activity windows, typed discovery/defaults, stable field-selected inventory paging, manifest-only compatibility rules, update ages, redaction policy 1.1 and contribution bounds.

Final sequential verification after the repairs:

- Original core suite: **166 assertions passed**.
- Order/extension regression suite: **188 assertions passed**.
- Dashboard/Site Health suite: **198 assertions passed**, up from the initial 140; combined **552 assertions**. New cases cover native prepaid status, independent expiry reminders under a full past window, future timestamp exclusion, numeric tie order, native restrictive listing policies, expired setup-window completion, standalone object/identity checks, guarded authorization/direct abilities, typed field schema/units/order, field-selected inventory page parity, regular/MU filename collisions, cached theme/update age, limited manifest compatibility, empty state, Unicode/redaction and GD GIF/WebP support.
- The 1,005-order performance fixture used **7 queries in approximately 0.007 seconds**, below the existing 50-query/5-second local limit. Optional detail-tool visibility is evaluated once per source, rather than once per record.
- Verified HTTPS initialization/session, **53-tool** discovery, all eight read tools, protected-setting denial and live-session credential revocation passed. Pinned stdio bridge **0.5.1** initialization/discovery/context passed over verified TLS.
- **35 PHP files** passed syntax checks. MCP code and all three fixture suites plus the extension fixture passed focused PHPCS with zero errors/warnings. The native activity service retains 18 pre-existing alignment warnings, reduced from its 21-warning baseline; no new native-source warnings were introduced. Its original public setup method signature, early-exit work and default payload remain intact.
- Whitespace formatting was applied only after temporary-copy token comparison. No asset build ran. **286 local documentation links/anchors** resolved, and root PRDs retained their Directorist-only wording. PRD acceptance criteria were not relaxed or marked as universally verified.

The fixtures and HTTPS wrapper restored their captured settings/enablement, removed disposable data/credentials, and retained the existing schedule state. Multisite/network execution and other release gates listed above remain outstanding validation, rather than claimed passing scenarios.

## Module refactor — 22 October 2026

The implementation now starts at `includes/modules/mcp/class-init.php`. Feature providers own schemas, named callbacks, discovery and capability/object gates. One registry drives transport, abilities and execution. Compatibility facades/aliases preserve the original public PHP signatures and constants; the original exception type is aliased at startup so legacy catch clauses work before any explicit class lookup. The existing connection UI is split into directly shipped components, helpers and styles. See the [architecture and complete file tree](architecture.md).

Observed local verification:

- Existing core, order/extension and dashboard/Site Health suites: **166 + 188 + 198 = 552 assertions passed**. Stateful suites ran sequentially.
- New read-only refactor contract suite: **183 assertions passed**, covering exact tool descriptor hashes/order, the original public PHP signatures/constants, named callback coverage, repeat initialization, the legacy exception catch and shipped asset dependencies.
- New UI helper/component suite: **9 tests passed**, covering Unicode/quoted credentials, nonce/error/cancellation behavior, single-key native saves, credential cleanup, late-response isolation, clipboard fallback cleanup, modal cleanup, keyboard tabs and general-save isolation.
- Cross-version check using isolated copies of the pre-refactor classes: a preview produced by the original implementation committed through the refactored runner; an original successful operation record replayed through it. Public descriptors, registry fingerprint, saved settings hash and enabled state matched the captured baseline afterward.
- Real verified HTTPS: initialization, 53-tool discovery, execution, all eight dashboard/Site Health reads, sensitive-setting denial and credential revocation passed. Pinned stdio bridge 0.5.1 initialization, discovery and context passed with TLS verification. Its disposable account and application password were removed/revoked.
- All **101 module PHP files** passed syntax checks and the PHP 7.4+ compatibility scan. Project PHPCS passed for the module and all three changed test files without warnings. Checking every changed PHP file reported zero errors; `directorist-base.php` retains the same **28 pre-existing alignment warnings** as HEAD, with identical warning sources. Its functional change is the module entry-point path.
- **1,647 internal static method references** resolved. All nine shipped JavaScript files passed syntax checks. The three CSS files concatenate exactly to the original stylesheet, preserving declaration order and content. All **554 checked local documentation links** resolved.
- Browser verification used an isolated local fixture with the repository's bundled Vue runtime and dummy credentials: drawer opening, five client tabs, locally filled snippets, copy action, Escape/close, Configure focus restoration, password clearing on reopen, toggle states and 390px layout passed. No console warnings/errors were observed. Fixture closure restored body scrolling/background inert state; the mobile drawer fit without document overflow. The real authenticated settings-page interaction remains a manual release QA item; PHP asset/registry contracts and native save behavior are covered separately.

No asset build, data migration, deployment, commit or push was performed. Normal settings storage, native save contracts, tool wire names and extension API versions remain unchanged. This refactor verification supplements the existing release gates; it does not certify every client, extension/provider combination or the full PRD matrix.

## Source assets and PHP casts follow-up

The redundant `docs/directorist-mcp` packages were removed from the plugin and the original workspace documentation location. MCP JavaScript now lives in `assets/src/js/admin/mcp/`; browser-ready CSS lives in `assets/src/css/admin/mcp/`. Native enqueue registration and tests reference those source paths. No MCP asset depends on the deprecated `assets/js` or `assets/css` trees. The three packaging paths retain only these MCP source subtrees while continuing to exclude unrelated sources.

Observed checks for this follow-up:

- Core, order/extension, dashboard/Site Health and updated refactor suites passed **166 + 188 + 198 + 192 = 744 PHP assertions**. The added cases verify scalar pagination output and rejection of malformed IDs/pagination before casting.
- **12 JavaScript tests passed**, including three packaging checks: the real rsync distribution filters, a Git archive using an isolated temporary index, and the legacy ZIP packaging sequence simulated without running webpack. All retain exactly the twelve MCP source asset files. The normal staging area and branch were not changed by the archive test.
- All twelve source asset URLs returned their exact local file contents over verified HTTPS. The source-path enqueue/dependency checks passed in WordPress.
- Project coding-standard rules passed for the module and refactor test. For additional coverage, the existing admin-directory exclusion was removed only in a temporary ruleset; all four MCP admin classes also passed. PHP syntax, JavaScript syntax and PHP 7.4+ compatibility checks passed.
- Explicit casts apply to native identifier/count/text projections and validated scalar results. Strict schema/type checks, exact monetary strings, nullable diagnostics and literal-true extension permission decisions are preserved. No request value is accepted merely by coercing it.

No asset build, data migration, commit, push or deployment was performed. Authenticated settings-page interaction remains the manual QA item documented above; this follow-up changes source locations and scalar normalization without changing the connection UI presentation.

## PHP type declarations follow-up

Implementation signatures now declare concrete adapter, server, registration-service and native object dependencies, along with scalar, array, nullable and return types. The provider interface and every registered core tool callback declare their contracts. Typed registry properties identify its collaborators. Legacy public parameters and strict raw-input validators remain compatible; PHPDoc documents polymorphic values and union results supported by the PHP 7.4 runtime.

Observed verification:

- All four PHP suites passed **166 + 188 + 198 + 267 = 819 assertions**. The refactor suite now checks typed transport/provider/service contracts, declared callback result types, rejection of an unrelated adapter object, and the legacy unsupported-adapter fallback. Original public parameters, tool descriptors and ordering still match the baseline fixture.
- Verified HTTPS and pinned local bridge checks passed: initialization, 53-tool discovery, execution, all eight dashboard/Site Health reads, sensitive-setting denial and credential revocation. The disposable account and application password were removed/revoked.
- An isolated process without the MCP adapter or Abilities API loaded the module, transport class and legacy fallback successfully. Original previews still committed and original retry records replayed through the refactored runner.
- All 102 module/test PHP files passed syntax checks. Project PHPCS reported no errors or warnings for the 98 normally included files; the four admin classes also passed the same rules with the admin exclusion removed in a temporary configuration. The PHP 7.4+ compatibility scan passed. All 12 existing JavaScript and packaging tests passed.

No build, migration, commit, push or deployment was performed for this correction.

## Functional and visual review — 22 October 2026

The [review report](refactor-review.md) records four reproduced/fixed UI issues: rejected saves leaving an incorrect switch position, keyboard focus skipping the verification disclosure, non-JSON authentication failures bypassing drawer cleanup, and late save continuations starting requests after component destruction.

The real authenticated settings page passed success/failure save checks, deep-link/reload behavior, all client tabs/snippets, copy feedback, credential clearing, keyboard/focus handling and responsive layout. Disposable records created through MCP appeared in the native directory builder, listing, category/location/tag and unpaid-order screens. Native dashboard figures matched MCP output; native Site Health Info and the HTTPS diagnostic tools were checked. The four PHP suites passed **819 assertions** and the UI/packaging suite passed **16 tests**. Verified HTTPS/bridge, internal-reference and original-preview/retry compatibility checks also passed.

Test data, temporary accounts/credentials, browser tabs and viewport overrides were cleaned up. Original enablement, unrelated settings, preview epoch and the cleanup schedule were preserved. Actual third-party AI clients, live gateways and every extension/multisite combination remain outside this local review.

## Empty single listing builder — 23 October 2026

The Car directory exposed a native `form-builder` configuration whose value was `[]`. MCP dispatched operations by the presence of `fields` and `groups`, routing the empty form to the card-layout handler. Adding its first section failed with `This configuration contains layout widgets.`

Empty registered form builders now expand to `fields` and `groups` in memory for defaults, discovery and editing. Dispatch uses the registered builder type and validates the form structure. Existing populated values and card layouts are retained; reading does not migrate stored metadata. A follow-up MCP preview also exposed rejection of registered icon options. Builder icon options now validate as strings, without expanding the global settings registry.

Observed checks through the connected Directorist MCP server:

- `directorist_get_builder_schema` exposed the normalized empty content configuration with an unchanged directory version and header.
- `directorist_update_builder` with `dry_run: true` successfully added the first section, then previewed the complete layout. Reading before execution confirmed that previews had not changed the directory configuration or version.
- Invalid section operations on the header, widget-slot operations on content, and unknown submission-field references were rejected.
- The reviewed layout committed with a confirmation token, expected version and idempotency key. `directorist_get_directory` and schema discovery confirmed six ordered sections and 29 fields. Every field belongs to exactly one section and references an existing submission field of the same widget type.
- Only `single_listings_contents` changed. Submission, search, cards, header and other directory configuration remained unchanged. Preview/readback differences were limited to native label escaping and disabled toggles becoming empty strings.
- Updating existing content and header widgets to their current values produced a successful preview with no changes. String icon options succeeded; an object icon value was rejected.

The Car layout contains vehicle overview, specifications, mileage/history, equipment/safety, walkaround video, and seller/viewing-location sections. Title, highlights, price, make, city and photos remain in the existing header. No browser, direct REST, database or CLI alternative was used to configure or verify the directory. There are no Car listings yet, so these checks establish saved builder configuration rather than visual rendering of a populated listing.

Both changed PHP files passed syntax checks on PHP 7.4 and PHP 8.0; `git diff --check` passed. The historical suites above are absent from this branch and were not rerun. No build, migration, commit or push was performed.

## Image media and native directory preview — 23 October 2026

The new [media contract](media.md) adds image upload/import and authorized image inspection. The directory adapter now maps an authorized preview attachment ID to its URL and reads/writes native `general_config` grouping, preserving siblings in both REST and admin request contexts. Directory reads expose the native default-image resolver result for verification.

Observed checks used initialization, `tools/list` and `tools/call` against the configured Directorist MCP endpoint with its existing authentication and TLS certificate verification. No native WordPress REST media endpoints, browser actions, direct database operations or WordPress CLI calls were used to create, assign or verify media.

- All three media tools appeared in protocol discovery, with read/write annotations and an open-world annotation on import.
- A valid 68-byte PNG upload preview returned real dimensions, MIME and checksum. Invalid base64/data URIs, executable/SVG extensions, paths, MIME mismatch, decoded byte overflow and excessive pixel counts were rejected. Upload execution was not separately exercised; import exercised the shared native attachment writer.
- Import previews rejected local/private/metadata addresses, HTTP, embedded credentials and custom ports. A non-attachment inspection was denied. Reference and directory snapshots proved that previews did not create attachments or change Car.
- The first Unsplash request negotiated a format inconsistent with its `.jpg` filename. Reference queries confirmed no attachment before retrying. The final fixed JPEG request imported as attachment 481, 1600 × 1000 pixels, 113,261 bytes, with author credit, source page, caption and alt text. Retrying the successful execution returned the same attachment with `idempotent_replay: true`; only one matching image existed.
- An execution-time MIME mismatch now returned its specific validation error, rather than an unknown persistence outcome. Media references remained unchanged. The new exception path releases reservations only for trusted failures before product persistence starts; uncertain persistence retains the original conservative guard.
- Car's preview-image update was previewed and committed through MCP with a version, confirmation token and idempotency key. Both the saved URL and native `default_preview_image_url` matched attachment inspection. The file existed and its SHA-256 matched import output. Only `preview_image` changed in directory configuration; its single listing layout and other builders were preserved.
- Clearing with attachment ID 0 previewed an empty URL; assigning a non-image was rejected. Subsequent MCP readback confirmed that those validation checks left the configured default unchanged.

The ten changed/new PHP files passed syntax checks on PHP 7.4 and PHP 8.0, and focused project PHPCS with zero errors or warnings. Formatting used temporary copies and preserved every non-whitespace PHP token. QA dependencies were installed outside the repository; no project dependency files changed. The historical fixture suites remain absent. No build, commit or push was performed for this change.
