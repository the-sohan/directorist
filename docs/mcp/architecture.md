# Directorist MCP architecture

## Where to start

[class-init.php](../../includes/modules/mcp/class-init.php) is the module entry point. `Directorist_Base` loads it, and `Init::boot()` registers hooks once. The former `bootstrap.php` remains a compatibility loader.

The module uses WordPress hooks and explicit composition. It introduces no container, framework, database table, migration, generated asset bundle or changed MCP protocol contract.

## Ownership and request flow

| Area | Responsibility |
|---|---|
| `registration/` | Common tool inventory, explicit core providers, schema validation and request-local registration state. |
| `tool-providers/` | Each domain's tool descriptors, named callbacks, validation, policies and native data access. |
| `runtime/` | Shared input validation, connection/access checks, permission-filtered discovery and execution. |
| `transport/` | WordPress Abilities API registration and MCP Adapter server integration. |
| `admin/` | Existing settings filters, directly shipped assets and connection-configuration REST endpoints. |
| `extensions/` | Descriptor validation, registration failure isolation, lifecycle/policies, field/read adapters and external-operation reconciliation. |
| `safety/` | Read guards, preview/commit orchestration, advisory locks, native write hooks, transactions, cleanup and bulk checkpoints. |
| `support/` | Structured failures, canonical hashing, pagination, result envelopes, operation events and read contracts. |
| `compatibility/` | Public PHP forwarding facades and aliases, with no product or authorization logic. |

```text
WordPress ability / MCP transport
  → Tool_Runner: resolve schema and validate inputs
  → Access_Control: connection gate
  → feature provider: existing capability/domain gates
  → extension policies
  → feature provider: existing object gates
  → Read_Guard or Write_Guard
  → Tool_Registry: invoke the registered callback
  → feature Read_Callbacks / Write_Callbacks
  → native Directorist workflow
  → output validation, projection and existing response envelope
```

Bulk execution uses the same preparation and authorization path per item. Native writers coordinate through the shared advisory lock before reading state used by a subsequent write. External financial effects retain their separate durable dispatch/reconciliation workflow.

## Registration and design patterns

`Tool_Provider` implementations declare public descriptors and internal named callback bindings. Providers also supply discovery predicates and authorization phases. They never execute tools during registration.

Domain providers and their supporting callbacks/services live under `tool-providers/`, using the `Directorist\MCP\Tool_Providers` namespace. The autoloader maps underscores to hyphens, so these classes follow the same namespace-to-directory convention as the rest of the module.

`Tool_Registry` composes core providers in the historical inventory order, invokes `directorist_mcp_register` with the existing `Directorist\MCP\Registry` facade, seals registration, and finalizes diagnostic output schemas. Extension descriptors enter that same inventory. One contribution's catchable registration failure cannot prevent another registration callback from running.

Internal callback/provider maps are separate from descriptor storage. Public schemas, canonical hashes, extension fingerprints, preview binding and retry records retain their existing representation. `Registry_State` is an internal, request-local data holder shared only by the registry's composed collaborators; it is never persisted or exposed as MCP output.

The **Registry** pattern provides one inventory, **Strategy** selects feature callbacks and gates, **Adapter** isolates native workflows, and **Facade** preserves published PHP APIs. Existing cohesive helpers use class aliases so they retain one copy of their static state. Split public classes use explicit forwarding methods with their original parameter names, defaults and constants.

## Connection UI without a build

`admin/Assets` registers the directly shipped scripts with explicit WordPress dependencies, including `wp-i18n` and script translations for the three UI components. `assets/src/js/admin/mcp/init.js` installs the field through `DirectoristSettingsPanel`; it waits for the existing registry-ready event when needed.

- `configuration-field.js` owns enablement, confirmed status and independent native AJAX saves.
- `connection-drawer.js` retains its filename/factory/event API but renders the inline three-step setup. It owns temporary current/alternate account credentials, template requests and action-specific copy feedback. Closing or destroying it clears credential inputs, timers and requests; obsolete copy results cannot overwrite the current action.
- `client-configuration.js` renders selectable app cards, the credential slot and the immediately available derived snippet. It emits selection/copy events and stores no credential state.
- `api-client.js` owns cancellation and nonce-authenticated requests. Configuration is passed into its factory.
- `adapter-installer.js` sequences native WordPress `install-plugin`/`activate-plugin` AJAX calls with the separate `updates` nonce. `admin/Adapter_Setup` reports installed/active state and setup permissions. The field owns the spinner and refreshes readiness after setup without changing enablement.
- `snippets.js` constructs configuration locally; it retains no arguments/results. `clipboard.js` clears temporary fallback textareas in `finally`.
- `modal-accessibility.js` is retained as a legacy helper and is no longer a connection component dependency. The inline region uses normal page focus flow; the field restores focus after collapsing it.
- `settings-panel-adapter.js` preserves the established menu/store integration and excludes only `mcp_enabled` from general settings saves.

The `DirectoristMcp` namespace contains factories and helpers, never credential or snippet storage. Three scoped CSS files style the field, inline setup and responsive controls. MCP sources live under `assets/src/js/admin/mcp/` and `assets/src/css/admin/mcp/`. Distribution filters and the legacy ZIP packaging step retain exactly those two source subtrees; unrelated source files remain excluded. No bundler or settings-manager rebuild is required.

## Adding or modifying a tool

1. Edit the matching feature's provider; retain public schemas unless intentionally changing the contract.
2. Put reads and mutation preparation in its feature callback classes. Return the existing mutation plan (`state`, `preview`, callable `execute`, and applicable confirmation/table/cache controls).
3. Bind the named callback and keep capability/object checks in the feature's authorization strategy or native policy service. Discovery never grants execution permission.
4. Reuse the shared runner and guards. Do not add alternate execution routes or bypass per-item bulk checks.
5. For an extension, continue using [extension API 1](extensions.md) and the existing registration hook. Installing an extension does not automatically expose tools.
6. Exercise the affected tools manually, including permission failures, previews, stale-state rejection, and safe retries. Review public schemas, response fields, and compatibility whenever a contract changes.

## Verification

Use a disposable local test site to exercise discovery, reads, previews, confirmed writes, denied access, stale state, and duplicate retries. Compare the results with native Directorist administration and frontend behavior. The [complete extension example](extension-tool-example.md#read-preview-and-execute) demonstrates the manual request flow.

Run focused PHP syntax and coding-standard checks for changed implementation files:

```sh
vendor/bin/phpcs --standard=phpcs.xml includes/modules/mcp
```

The MCP automated test suites and fixtures are not included in this branch; `/tests` is excluded from version control and distribution. Local regression checks for the builder fixes covered native search/single/card rendering, submission defaults, editable display modes, source references, section widgets, reordering, header widgets, all card templates and custom page selection. Historical results in the verification record describe checks performed before the earlier suites were removed.

Builder operations infer a submission source for a new search or layout widget when exactly one matching field exists, or its ID matches a source field. With multiple matching fields, provide `changes.original_widget_key` using the submission field ID. Explicit links must match the widget type. Add standalone single-listing widgets such as `author_info` and `related_listings` with `entity: "section"` and `widget` set to their native widget name; their options are updated through the section ID. Normal sections receive native `section_id` values for shortcode use. Reorder operations require every sibling ID in `order`, and field reorders also require the target `section_id`.

See the [verification record](verification.md) for observed checks and remaining release gates.

## File tree

```text
includes/modules/mcp/
├── admin/
│   ├── class-adapter-setup.php
│   ├── class-assets.php
│   ├── class-configuration-controller.php
│   ├── class-connection-configuration.php
│   └── class-settings-integration.php
├── compatibility/
│   ├── class-catalog.php
│   ├── class-dashboard-data.php
│   ├── class-dashboard.php
│   ├── class-directories.php
│   ├── class-legacy-class-map.php
│   ├── class-listings.php
│   ├── class-module.php
│   ├── class-orders.php
│   ├── class-registry.php
│   ├── class-settings.php
│   ├── class-site-health.php
│   ├── class-support.php
│   ├── class-taxonomies.php
│   ├── class-tools.php
│   └── class-write-guard.php
├── contracts/
│   └── interface-tool-provider.php
├── extensions/
│   ├── class-callback-invoker.php
│   ├── class-descriptor-validator.php
│   ├── class-domain-fields.php
│   ├── class-extension-callbacks.php
│   ├── class-extension-lifecycle.php
│   ├── class-external-operations.php
│   ├── class-integration-callbacks.php
│   ├── class-read-contributions.php
│   ├── class-registration-api.php
│   └── class-tool-provider.php
├── tool-providers/
│   ├── context/
│   │   ├── class-context-callbacks.php
│   │   ├── class-reference-callbacks.php
│   │   └── class-tool-provider.php
│   ├── dashboard/
│   │   ├── class-activity-data.php
│   │   ├── class-financial-data.php
│   │   ├── class-guidance.php
│   │   ├── class-listing-data.php
│   │   ├── class-read-callbacks.php
│   │   └── class-tool-provider.php
│   ├── directories/
│   │   ├── class-builder-operations.php
│   │   ├── class-builder-schema.php
│   │   ├── class-builder-validator.php
│   │   ├── class-directory-configuration.php
│   │   ├── class-read-callbacks.php
│   │   ├── class-tool-provider.php
│   │   └── class-write-callbacks.php
│   ├── listings/
│   │   ├── class-listing-policy.php
│   │   ├── class-listing-schema.php
│   │   ├── class-read-callbacks.php
│   │   ├── class-submission-adapter.php
│   │   ├── class-tool-provider.php
│   │   └── class-write-callbacks.php
│   ├── orders/
│   │   ├── class-financial-operations.php
│   │   ├── class-money.php
│   │   ├── class-order-policy.php
│   │   ├── class-order-quote.php
│   │   ├── class-read-callbacks.php
│   │   ├── class-tool-provider.php
│   │   └── class-write-callbacks.php
│   ├── settings/
│   │   ├── class-read-callbacks.php
│   │   ├── class-settings-schema.php
│   │   ├── class-settings-validator.php
│   │   ├── class-tool-provider.php
│   │   └── class-write-callbacks.php
│   ├── media/
│   │   ├── class-image-validation.php
│   │   ├── class-media-callbacks.php
│   │   └── class-tool-provider.php
│   ├── site-health/
│   │   ├── class-diagnostic-collector.php
│   │   ├── class-read-callbacks.php
│   │   ├── class-report-formatter.php
│   │   ├── class-section-catalog.php
│   │   └── class-tool-provider.php
│   └── taxonomies/
│       ├── class-read-callbacks.php
│       ├── class-term-validator.php
│       ├── class-tool-provider.php
│       └── class-write-callbacks.php
├── registration/
│   ├── class-core-tool-provider.php
│   ├── class-registry-state.php
│   ├── class-schema-validator.php
│   └── class-tool-registry.php
├── runtime/
│   ├── class-access-control.php
│   ├── class-request-context.php
│   ├── class-tool-discovery.php
│   └── class-tool-runner.php
├── safety/
│   ├── class-bulk-executor.php
│   ├── class-native-write-hooks.php
│   ├── class-operation-store.php
│   ├── class-read-guard.php
│   ├── class-transaction.php
│   ├── class-write-guard.php
│   └── class-write-lock.php
├── support/
│   ├── class-canonical-data.php
│   ├── class-failure.php
│   ├── class-operation-events.php
│   ├── class-pagination.php
│   ├── class-read-contracts.php
│   ├── class-read-support.php
│   ├── class-result.php
│   ├── class-uncommitted-failure.php
│   └── class-validation.php
├── transport/
│   ├── class-ability-registrar.php
│   └── class-server.php
├── README.md
├── bootstrap.php
├── class-autoloader.php
└── class-init.php
```

## PHP type declarations and scalar normalization

Declare concrete object dependencies in implementation signatures: `Server::server(McpAdapter $adapter): void` receives `WP\MCP\Core\McpAdapter`, and extension registration services receive `Registry_State` and `Tool_Registry`. Feature services declare native listing/term objects as `WP_Post`/`WP_Term`; order services use the `stdClass` rows returned by the current order repository. Providers share typed definitions, callback, authorization and discovery methods. Tool callbacks return arrays containing read results or mutation plans.

Use parameter, property and return declarations supported by PHP 7.4. Use nullable declarations for optional values and PHPDoc for legitimate union results such as `array|WP_Error`. Keep mixed input where validation must inspect its original type, and document the expected value and validation rule. For example, typing an untrusted ID as `int` would let PHP coerce a numeric string before the strict validator sees it. Compatibility facades retain their original public parameters; legacy aliases retain their public parameter contracts and identify expected objects through PHPDoc.

Cast native WordPress identifiers, counts and text to their intended scalar types when projecting responses. Validated IDs and taxonomy names also return explicit scalar casts. Keep request schemas and strict `is_int`/`is_string` checks before casting: numeric strings, fractional IDs and string booleans are not substitutes for correctly typed client inputs. Extension permission callbacks must still return literal `true`. Monetary values remain exact decimal strings/minor units, never floating-point casts. Canonical hashing, registry fingerprints and nullable diagnostic values retain their existing types.

## MCP source assets

```text
assets/src/
├── js/admin/mcp/
│   ├── init.js
│   ├── settings-panel-adapter.js
│   ├── services/
│   │   ├── api-client.js
│   │   └── adapter-installer.js
│   ├── utilities/
│   │   ├── snippets.js
│   │   ├── clipboard.js
│   │   └── modal-accessibility.js
│   └── components/
│       ├── configuration-field.js
│       ├── connection-drawer.js
│       └── client-configuration.js
└── css/admin/mcp/
    ├── base.css
    ├── configuration-field.css
    └── connection-drawer.css
```

These are browser-ready maintained sources. `.distignore` includes the two MCP subtrees before excluding other sources, `.gitattributes` retains them in Git archives, and legacy ZIP packaging restores them after its general source-pruning step. The packaging tests exercise all three rules without compiling or rebuilding assets.
