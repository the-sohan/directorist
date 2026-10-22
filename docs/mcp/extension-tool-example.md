# Register extension MCP tools: complete example

This guide builds a small Directorist extension that reads and updates a plain-text notice through MCP. It demonstrates owner registration, typed callbacks, permissions, schemas, output projection, a confirmed mutation, and safe retries.

| MCP tool | WordPress ability | Purpose |
|---|---|---|
| `directorist-notice-demo-get-message` | `directorist/notice-demo-get-message` | Read the notice. |
| `directorist-notice-demo-set-message` | `directorist/notice-demo-set-message` | Preview and update the notice. |

The example stores its message in the extension-owned `directorist_mcp_notice_example_message` option, with a default of `Welcome to our directory.` It provides an API example; displaying that message on the frontend belongs to the extension's rendering code.

## Prerequisites and installation

1. Use a development site with PHP 7.4+, WordPress 6.9+, this Directorist MCP implementation, and WordPress MCP Adapter 0.6.1+ active. Writes require the transactional WordPress tables and advisory locking described in the [storage contract](extensions.md#storage-and-compatibility).
2. Create `wp-content/plugins/directorist-mcp-notice-example/` and save the code below as `directorist-mcp-notice-example.php`.
3. Activate **Directorist MCP Notice Example** as a normal WordPress plugin. Directorist checks the registered plugin path against active plugins; merely including its PHP file does not satisfy that lifecycle check.
4. Enable Directorist MCP under **Directorist → Settings → Other Features → MCP**, then connect using the [connection guide](README.md#enable-and-connect).
5. Use an account with `manage_options` and refresh the client's tool discovery. Both tools should appear. Custom roles work when they have the required capability.

The example is documentation source and is not loaded or activated by Directorist. Its hook is added when the example plugin loads, before Directorist builds and seals the registry at the end of `plugins_loaded`. Register inside `directorist_mcp_register`; delaying registration until `init` is too late.

## Complete plugin

```php
<?php
/**
 * Plugin Name: Directorist MCP Notice Example
 * Description: Register a reader and a confirmed editor for an extension-owned notice.
 * Version: 1.0.0
 * Requires PHP: 7.4
 * Text Domain: directorist-mcp-notice-example
 */

use Directorist\MCP\Registry;

defined( 'ABSPATH' ) || exit;

final class Directorist_MCP_Notice_Example {
    private const OWNER  = 'notice-demo';
    private const OPTION = 'directorist_mcp_notice_example_message';

    /** Register before Directorist seals its request-local inventory. */
    public static function register( Registry $registry ): void {
        global $wpdb;

        $registered = $registry->register_extension(
            [
                'owner'            => self::OWNER,
                'plugin'           => plugin_basename( __FILE__ ),
                'label'            => 'Directorist Notice Example',
                'version'          => '1.0.0',
                'contract_version' => '1',
                'api_version'      => Registry::API_VERSION,
                'capability'       => 'manage_options',
                'availability'     => [ self::class, 'available' ],
                'admission'        => [ self::class, 'admit' ],
            ]
        );
        if ( is_wp_error( $registered ) ) {
            return;
        }

        $message = [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ];
        $output  = [
            'type'                 => 'object',
            'properties'           => [ 'message' => $message ],
            'required'             => [ 'message' ],
            'additionalProperties' => false,
        ];
        $common  = [
            'owner'            => self::OWNER,
            'domain'           => 'settings',
            'group'            => 'Notice Example',
            'contract_version' => '1',
            'output_schema'    => $output,
            'discover'         => [ self::class, 'permitted' ],
            'permission'       => [ self::class, 'permitted' ],
            'project'          => [ self::class, 'project' ],
        ];

        $registered = $registry->register_tool(
            $common + [
                'action'      => 'get-message',
                'description' => 'Read the extension-owned notice message.',
                'write'       => false,
                'schema'      => [
                    'type'                 => 'object',
                    'properties'           => [],
                    'required'             => [],
                    'additionalProperties' => false,
                ],
                'read'        => [ self::class, 'read' ],
            ]
        );
        if ( is_wp_error( $registered ) ) {
            return;
        }

        $registry->register_tool(
            $common + [
                'action'         => 'set-message',
                'description'    => 'Preview and update the extension-owned notice message.',
                'write'          => true,
                'schema'         => $output,
                'preview_schema' => [
                    'type'                 => 'object',
                    'properties'           => [ 'before' => $message, 'after' => $message ],
                    'required'             => [ 'before', 'after' ],
                    'additionalProperties' => false,
                ],
                'tables'         => [ $wpdb->options ],
                'invalidate'     => [ self::class, 'invalidate' ],
                'prepare'        => [ self::class, 'prepare' ],
            ]
        );
    }

    public static function available(): bool {
        return defined( 'ATBDP_VERSION' );
    }

    public static function admit( int $user_id ): bool {
        return $user_id === get_current_user_id() && current_user_can( 'manage_options' );
    }

    public static function permitted( array $args, int $user_id ): bool {
        return self::admit( $user_id );
    }

    private static function get_message(): string {
        return (string) get_option( self::OPTION, 'Welcome to our directory.' );
    }

    public static function read( array $args ): array {
        return [ 'message' => self::get_message() ];
    }

    /** Project only declared public fields, including when replaying a result. */
    public static function project( array $result, array $args, int $user_id ): array {
        return [ 'message' => (string) $result['message'] ];
    }

    /** @return array|WP_Error A side-effect-free mutation plan, or validation error. */
    public static function prepare( array $args ) {
        $message = sanitize_text_field( $args['message'] );
        if ( '' === $message ) {
            return new WP_Error( 'notice_empty', 'Provide a nonempty plain-text message.' );
        }
        $before = self::get_message();

        return [
            'state'   => [ 'message' => $before ],
            'preview' => [ 'before' => $before, 'after' => $message ],
            'execute' => static function () use ( $message ): array {
                // Directorist invokes this only after confirmation and inside its transaction.
                $updated = update_option( self::OPTION, $message, false );
                if ( ! $updated && self::get_message() !== $message ) {
                    throw new RuntimeException( 'The notice could not be saved.' );
                }
                return [ 'message' => $message ];
            },
        ];
    }

    /** Clear option caches after commit or rollback. */
    public static function invalidate(): void {
        wp_cache_delete( self::OPTION, 'options' );
        wp_cache_delete( 'alloptions', 'options' );
        wp_cache_delete( 'notoptions', 'options' );
    }
}

add_action( 'directorist_mcp_register', [ Directorist_MCP_Notice_Example::class, 'register' ] );
```

## Read, preview, and execute

The JSON blocks below are the `params` objects for MCP `tools/call` requests after the connection has completed initialization. They are not standalone HTTP requests. The decoded result examples show Directorist's tool data; the MCP adapter may wrap that data in its protocol response.

### 1. Read the current notice

```json
{
  "name": "directorist-notice-demo-get-message",
  "arguments": {}
}
```

On a fresh installation, the decoded result is:

```json
{
  "message": "Welcome to our directory."
}
```

### 2. Preview an update

```json
{
  "name": "directorist-notice-demo-set-message",
  "arguments": {
    "message": "Bookings are open for November.",
    "dry_run": true
  }
}
```

The decoded preview has this shape. The version and token are illustrative placeholders; use the actual values returned by the server.

```json
{
  "dry_run": true,
  "changes": {
    "before": "Welcome to our directory.",
    "after": "Bookings are open for November.",
    "version": "<current-state-version>"
  },
  "confirm_token": "<confirmation-token>",
  "expires_in_seconds": 300,
  "warnings": []
}
```

`prepare()` runs without saving. It sanitizes the input, rejects text that becomes empty, and returns the current state, safe preview, and deferred writer. Review the proposed change before executing it.

### 3. Execute the reviewed change

Use the same message and authenticated account. Copy `changes.version` and `confirm_token` from the preview, and generate a fresh idempotency key for this operation:

```json
{
  "name": "directorist-notice-demo-set-message",
  "arguments": {
    "message": "Bookings are open for November.",
    "expected_version": "<current-state-version>",
    "confirm_token": "<confirmation-token>",
    "idempotency_key": "982db25c-044a-4310-a74e-258a35259a42"
  }
}
```

Execute within the five-minute confirmation window. Directorist rechecks access and state before invoking the writer inside the declared transaction. A successful decoded response is:

```json
{
  "success": true,
  "committed": true,
  "idempotent_replay": false,
  "message": "Bookings are open for November."
}
```

Read the notice again to confirm persistence. To recover the result of a successful request, resend its exact execution arguments and idempotency key within the 24-hour retry window. The result has `idempotent_replay: true`; the writer is not run again. Permissions and the current extension contract are checked again. Use a new key for a new operation, and obtain a new preview if the state or contract has changed.

## Callback responsibilities

| Callback or descriptor | What this example supplies | What Directorist handles |
|---|---|---|
| `register_extension()` | A unique owner, canonical plugin path, versions, capability, availability, and admission callbacks. | Descriptor validation, plugin/dependency lifecycle checks, and registration sealing. |
| `available()` | Whether the integration can operate. | Checks that the registered plugin and declared dependencies are active. |
| `admit(user_id)` | Whether the current account can use this extension's integration. | Authentication and core connection requirements. |
| `discover(args, user_id)` | Whether to offer the tool to this caller. | Permission-filtered discovery. |
| `permission(args, user_id)` | Whether to execute the operation now. | Core domain permission floors, checked independently of discovery. |
| `schema` / `output_schema` | Closed, bounded input and output shapes. | Input validation and result validation. |
| `read(args)` | A read-only fetch of the extension-owned option. | The shared read guard. |
| `prepare(args)` | Validation, current state, preview, and deferred writer. | State/version matching, confirmation binding, locking, and retry coordination. |
| `tables` / `invalidate()` | The participating options table and cache cleanup. | Transaction eligibility, commit/rollback, and calling the invalidator. |
| `project(result, args, user_id)` | Only the declared `message` output. | Projection during normal execution and successful-result replay. |

The `settings` domain and the example's callbacks require `manage_options`. For purchaser-owned or listing-owned features, choose the correct domain and implement the relevant object/ownership checks instead of copying this administrator-only policy.

`dry_run`, `confirm_token`, `expected_version`, and `idempotency_key` are injected by the framework. Do not declare them in the extension's input schema. Core also supplies the write response fields `success`, `committed`, and `idempotent_replay`.

## Failure handling and diagnostics

- An anonymous account cannot execute either tool. Accounts without `manage_options` do not discover them and cannot execute them by naming them directly.
- Unknown input properties, missing messages, and messages over 200 characters fail validation. Text that becomes empty after sanitization is rejected before writing.
- An intervening notice edit causes the reviewed state/version to become stale. Obtain a fresh preview before trying the new operation.
- An expired or mismatched confirmation token is rejected. Reusing an idempotency key for different arguments is also rejected.
- An unchanged message is a valid no-op. The writer distinguishes `update_option()` returning false for an unchanged value from a failed update.
- Deactivation makes the extension unavailable. Activation, deactivation, and upgrades invalidate pending confirmations through the registry lifecycle fingerprint.

Registration failures return a safe `WP_Error` and are recorded in framework diagnostics. To inspect them as an administrator, call:

```json
{
  "name": "directorist-list-mcp-extensions",
  "arguments": {
    "search": "notice-demo",
    "diagnostics": true
  }
}
```

Inspect the permitted integration metadata and `registration_errors`. A failed registration is not a reason to bypass the registry or register a separate MCP endpoint.

## Adapting the example

Use your own owner slug, plugin identity, option/storage implementation, schemas, and effective permissions. Increment `contract_version` whenever schemas, policies, or callback behavior change. Include every relevant record/dependency in the mutation's `state` so a preview cannot be applied to a changed target.

If a native admin screen or background job also updates the same state, its writer must acquire `Directorist\MCP\Write_Guard::native_lock()` before reading the state used for that write. The MCP writer already runs under the shared lock. Declare all transactional participants and provide matching cache invalidation; remote requests or irreversible effects need the separate [external-operation contract](extensions.md#external-tools-and-recovery).

When applying this to an existing extension, register only its approved fields and operations, retain its native validation and business rules, and project only data the current caller may receive. Registered PHP is trusted server code; the guards do not sandbox arbitrary plugin code.

After trying the example, deactivate and remove the example plugin. It intentionally has no uninstall routine; remove the demonstration option separately if desired. Follow the [extension API reference](extensions.md) for field adapters, restrictive policies, opt-in bulk operations, dashboard/Site Health contributions, and external-operation recovery.
