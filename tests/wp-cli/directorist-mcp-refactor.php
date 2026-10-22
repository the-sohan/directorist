<?php
/**
 * Read-only refactor contracts captured before moving the MCP implementation.
 *
 * @package Directorist\MCP
 */

use Directorist\MCP\Admin\Assets;
use Directorist\MCP\Catalog;
use Directorist\MCP\Contracts\Tool_Provider as Provider_Contract;
use Directorist\MCP\Init;
use Directorist\MCP\Registration\Core_Tool_Provider;
use Directorist\MCP\Registration\Registry_State;
use Directorist\MCP\Registration\Tool_Registry;
use Directorist\MCP\Transport\Server;
use WP\MCP\Core\McpAdapter;
use WP\MCP\Core\McpServer;

defined( 'WP_CLI' ) && WP_CLI || exit;

$baseline = json_decode( file_get_contents( __DIR__ . '/../fixtures/mcp-refactor-contract.json' ), true );
$checks   = 0;
$assert   = static function ( $condition, $message ) use ( &$checks ) {
    ++$checks;
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
};
try {
    \Directorist\MCP\Support\Validation::fail( 'fixture_failure', 'Disposable catch contract.' );
} catch ( \Directorist\MCP\Failure $error ) {
    $assert( 'fixture_failure' === $error->reason, 'Legacy exception catches must work before any reflection or explicit class lookup.' );
}
$definitions = Catalog::definitions();
$assert( array_keys( $baseline['definitions'] ) === array_keys( $definitions ), 'Tool names and ordering must match the original inventory.' );
foreach ( $baseline['definitions'] as $tool => $digest ) {
    $assert( hash_equals( $digest, hash( 'sha256', wp_json_encode( $definitions[ $tool ] ) ) ), 'Public descriptor changed: ' . $tool );
}
foreach ( $baseline['classes'] as $class => $expected ) {
    $reflection = new ReflectionClass( $class );
    $methods    = [];
    foreach ( $reflection->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
        $parameters = [];
        foreach ( $method->getParameters() as $parameter ) {
            $parameters[] = [ $parameter->name, $parameter->isOptional(), (string) $parameter->getType(), $parameter->isVariadic() ];
        }
        $methods[ $method->name ] = [ 'static' => $method->isStatic(), 'parameters' => $parameters ];
    }
    $assert( $methods === $expected['methods'], 'Legacy public method signatures changed: ' . $class );
    $assert( $reflection->getConstants() === $expected['constants'], 'Legacy public constants changed: ' . $class );
}
foreach ( Core_Tool_Provider::providers() as $provider ) {
    $assert( is_subclass_of( $provider, Provider_Contract::class ), 'Every core provider must implement the typed provider contract.' );
    foreach ( $provider::callbacks() as $tool => $callback ) {
        $assert( isset( $definitions[ $tool ] ) && is_callable( $callback ), 'Every provider callback must resolve to a registered tool: ' . $tool );
        $reflection = is_array( $callback ) ? new ReflectionMethod( $callback[0], $callback[1] ) : new ReflectionFunction( $callback );
        $assert( 'array' === (string) $reflection->getReturnType(), 'Tool callbacks must declare their structured result/plan type: ' . $tool );
    }
}
// The implementation declares real dependencies while legacy facade parameters remain compatible.
$contracts = [
    [ Server::class, 'server', [ McpAdapter::class ], 'void' ],
    [ Server::class, 'filter_tools', [ 'array', McpServer::class ], 'array' ],
    [ Provider_Contract::class, 'definitions', [], 'array' ],
    [ Provider_Contract::class, 'callbacks', [], 'array' ],
    [ Provider_Contract::class, 'authorize', [ 'string', 'array', 'string' ], 'void' ],
    [ Provider_Contract::class, 'discover', [ 'string' ], 'bool' ],
];
foreach ( $contracts as $contract ) {
    list( $class, $method, $parameters, $return_type ) = $contract;
    $reflection                                        = new ReflectionMethod( $class, $method );
    $actual                                            = array_map( static function ( ReflectionParameter $parameter ): string { return (string) $parameter->getType(); }, $reflection->getParameters() );
    $assert( $parameters === $actual && $return_type === (string) $reflection->getReturnType(), 'Implementation contract must identify its input and output types: ' . $class . '::' . $method );
}
foreach ( [ 'Registration_Api', 'Descriptor_Validator', 'Extension_Lifecycle', 'Extension_Callbacks', 'Integration_Callbacks' ] as $service ) {
    $reflection = new ReflectionMethod( 'Directorist\\MCP\\Extensions\\' . $service, '__construct' );
    $parameters = $reflection->getParameters();
    $assert( [ Registry_State::class, Tool_Registry::class ] === [ (string) $parameters[0]->getType(), (string) $parameters[1]->getType() ], 'Extension services must declare their shared state and registry dependencies: ' . $service );
}
$wrong_adapter = new class() {
    public bool $called = false;

    public function create_server(): void {
        $this->called = true;
    }
};
$rejected      = false;
try {
    Server::server( $wrong_adapter );
} catch ( TypeError $error ) {
    $rejected = true;
}
$assert( $rejected && ! $wrong_adapter->called, 'The server must reject an unrelated object before invoking it.' );
$assert( null === \Directorist\MCP\Module::server( $wrong_adapter ) && ! $wrong_adapter->called, 'The legacy adapter entry point retains its unsupported-object no-op.' );
// Native scalar normalization must never weaken client input validation.
$collection = \Directorist\MCP\Support\Pagination::collection( [], '21', '2', '10' );
$assert( [ 2, 10, 21, 3, true ] === [ $collection['page'], $collection['per_page'], $collection['total'], $collection['total_pages'], $collection['has_more'] ], 'Native pagination fields have explicit integer/boolean types.' );
$assert( 12 === \Directorist\MCP\Support\Validation::id( 12 ), 'Validated IDs retain their integer type.' );
foreach ( [ '12', 12.5, true, [] ] as $invalid_id ) {
    $rejected = false;
    try {
        \Directorist\MCP\Support\Validation::id( $invalid_id );
    } catch ( \Directorist\MCP\Failure $error ) {
        $rejected = 'validation_failed' === $error->reason;
    }
    $assert( $rejected, 'An explicit ID cast must not accept malformed client IDs.' );
}
foreach ( [ [ 'page' => '2' ], [ 'per_page' => '20' ], [ 'page' => 2.5 ] ] as $invalid_paging ) {
    $rejected = false;
    try {
        \Directorist\MCP\Support\Pagination::paging( $invalid_paging );
    } catch ( \Directorist\MCP\Failure $error ) {
        $rejected = 'validation_failed' === $error->reason;
    }
    $assert( $rejected, 'Pagination request types must be validated before casts.' );
}
global $wp_filter;
$before = count( $wp_filter['wp_abilities_api_init']->callbacks[10] );
Init::boot();
require ATBDP_INC_DIR . 'modules/mcp/bootstrap.php';
$assert( $before === count( $wp_filter['wp_abilities_api_init']->callbacks[10] ), 'Repeated entry-point loading must not duplicate hooks.' );

$old_actor = get_current_user_id();
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- CLI test snapshots request state; no web action is processed.
$old_request = $_REQUEST;
$old_screen  = $GLOBALS['current_screen'] ?? null;
try {
    wp_set_current_user( get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0]->ID );
    // A CLI screen double permits inspection without rendering or saving a page.
    $GLOBALS['current_screen'] = new class() {
        public function in_admin() {
            return true;
        }
    };
    $_REQUEST['post_type']     = ATBDP_POST_TYPE;
    $_REQUEST['page']          = 'atbdp-settings';
    Assets::assets();
    $scripts = wp_scripts();
    $visited = [];
    $visit   = static function ( $handle, $stack = [] ) use ( &$visit, &$visited, $scripts, $assert ) {
        if ( 0 !== strpos( $handle, 'directorist-mcp-' ) || isset( $visited[ $handle ] ) ) {
            return;
        }
        $assert( ! in_array( $handle, $stack, true ), 'MCP asset dependency graph must be acyclic.' );
        $asset = $scripts->registered[ $handle ];
        $assert( 0 === strpos( $asset->src, ATBDP_URL . 'assets/src/js/admin/mcp/' ) && is_readable( ATBDP_DIR . substr( $asset->src, strlen( ATBDP_URL ) ) ), 'Every MCP script must ship in the plugin: ' . $handle );
        foreach ( $asset->deps as $dependency ) {
            $visit( $dependency, array_merge( $stack, [ $handle ] ) );
        }
        $visited[ $handle ] = true;
    };
    $visit( 'directorist-mcp-settings' );
    $assert( 9 === count( $visited ), 'The entry point must load all nine MCP source scripts.' );
    foreach ( [ 'directorist-mcp-base', 'directorist-mcp-field', 'directorist-mcp-settings' ] as $handle ) {
        $source = wp_styles()->registered[ $handle ]->src;
        $assert( is_readable( ATBDP_DIR . substr( $source, strlen( ATBDP_URL ) ) ), 'Every MCP stylesheet must ship in the plugin.' );
    }
} finally {
    wp_set_current_user( $old_actor );
    $_REQUEST                  = $old_request;
    $GLOBALS['current_screen'] = $old_screen;
}
WP_CLI::success( 'Directorist MCP refactor: ' . $checks . ' contract assertions passed.' );
