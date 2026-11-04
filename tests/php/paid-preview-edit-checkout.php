<?php
/**
 * Native URL and checkout consumer fixture. No WordPress bootstrap or database.
 * Read JSON from stdin; GBI_WP_SOURCE points to read-only WordPress source.
 *
 * @package Directorist
 */

$wp_source = getenv( 'GBI_WP_SOURCE' );
if ( ! $wp_source || ! is_file( $wp_source . '/wp-includes/functions.php' ) ) {
    throw new RuntimeException( 'Set GBI_WP_SOURCE to WordPress source; no site will be bootstrapped.' );
}
$root = getenv( 'DIRECTORIST_TEST_SOURCE_ROOT' ) ?: dirname( __DIR__, 2 );
define( 'ABSPATH', rtrim( $wp_source, '/' ) . '/' );
define( 'WPINC', 'wp-includes' );
define( 'ATBDP_DIR', $root . '/' );
define( 'CUSTOM_TAGS', false );
define( 'DAY_IN_SECONDS', 86400 );
define( 'LOGGED_IN_COOKIE', 'directorist_isolated_test_cookie' );
foreach ( [ 'AUTH', 'SECURE_AUTH', 'LOGGED_IN', 'NONCE' ] as $scheme ) {
    foreach ( [ 'KEY', 'SALT' ] as $part ) {
        define( $scheme . '_' . $part, 'public-isolated-test-' . $scheme . '-' . $part );
    }
}
require ABSPATH . WPINC . '/plugin.php';
require ABSPATH . WPINC . '/compat.php';
require ABSPATH . WPINC . '/load.php';
require ABSPATH . WPINC . '/functions.php';
if ( is_file( ABSPATH . WPINC . '/utf8.php' ) ) {
    require ABSPATH . WPINC . '/utf8.php';
}
require ABSPATH . WPINC . '/formatting.php';
require ABSPATH . WPINC . '/http.php';
require ABSPATH . WPINC . '/kses.php';
require ABSPATH . WPINC . '/class-wp-error.php';
require ABSPATH . WPINC . '/rest-api/class-wp-rest-request.php';
require ABSPATH . WPINC . '/rest-api/class-wp-rest-server.php';
require ABSPATH . WPINC . '/rest-api.php';
require ABSPATH . WPINC . '/user.php';

// Only storage/authentication/extension configuration is fixture-owned. URL
// parsing, encoding, redirect validation, signing and checkout remain native.
function wp_get_current_user() {
    return (object) [ 'ID' => 7 ]; }

function home_url( $path = '' ) {
    return 'https://checkout.example.test' . $path; }

function get_permalink( $id = 42 ) {
    $pages = [ 2 => '/add-listing/', 3 => '/checkout/', 4 => '/dashboard/' ];
    if ( ! $GLOBALS['fixture_pretty'] ) {
        return home_url( '/?page_id=' . $id );
    }
    return home_url( $pages[ $id ] ?? '/listing/' . $id . '/' );
}

function __( $value, $domain = '' ) {
    return $value; }

function esc_html__( $value, $domain = '' ) {
    return esc_html( $value ); }

function esc_html_e( $value, $domain = '' ) {
    echo esc_html( $value ); }

function user_trailingslashit( $value ) {
    return trailingslashit( $value ); }

function get_the_ID() {
    return 42; }

function get_directorist_option( $key, $default = null ) {
    return [ 'add_listing_page' => 2, 'checkout_page' => 3, 'user_dashboard' => 4 ][ $key ] ?? $default;
}

function directorist_is_listing_post_type( $id ) {
    return (int) $id === 42; }

function directorist_get_listing_directory( $id ) {
    return 29; }

function directorist_is_preview_enabled( $id ) {
    return true; }

function directorist_is_monetization_enabled() {
    return true; }

function atbdp_is_user_logged_in() {
    return true; }

function wp_enqueue_script( $handle ) {}

function atbdp_display_price( $amount ) {
    return '$' . number_format( $amount, 2 ); }

require ABSPATH . WPINC . '/pluggable.php';

add_filter(
    'pre_option', static function ( $value, $key ) {
        if ( 'permalink_structure' === $key ) {
            return $GLOBALS['fixture_pretty'] ? '/%postname%/' : ''; }
        if ( 'blog_charset' === $key ) {
            return 'UTF-8'; }
        throw new RuntimeException( 'Unexpected storage access: ' . $key );
    }, 10, 2 
);

foreach ( [ 'trait-uri-helper', 'trait-markup-helper', 'trait-icon-helper' ] as $trait ) {
    require $root . '/includes/helpers/' . $trait . '.php';
}
require $root . '/includes/class-helper.php';
require $root . '/includes/classes/class-permalink.php';
require $root . '/includes/model/SingleListing.php';
require $root . '/includes/helpers/helpers.php';
foreach ( [ 'helpers', 'date-time', 'mime', 'validator', 'template' ] as $utility ) {
    require $root . '/utils/' . $utility . '.php';
}
require $root . '/includes/checkout/class-checkout.php';

add_filter( 'directorist_checkout_types', static function () { return [ 'plan' ]; } );
add_filter( 'directorist_checkout_subtotal', static function () { return 1.0; } );
add_filter( 'directorist_checkout_show_payment_gateways', '__return_false' );

$input                     = json_decode( stream_get_contents( STDIN ), true, 512, JSON_THROW_ON_ERROR );
$GLOBALS['fixture_pretty'] = ! isset( $input['pretty'] ) || $input['pretty'];
$_GET                      = wp_slash( $input['request'] ?? [] );
$_POST                     = [];
$_FILES                    = [];
$_COOKIE                   = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$model                     = ( new ReflectionClass( '\Directorist\Directorist_Single_Listing' ) )->newInstanceWithoutConstructor();
$model->id                 = 42;
$action                    = $input['action'] ?? 'edit';
if ( 'checkout' === $action ) {
    $html    = ( new ATBDP_Checkout() )->checkout();
    $request = \Directorist\Utils\Helpers::request();
    $output  = [
        'valid'         => false !== strpos( $html, 'id="atbdp-checkout-form"' ),
        'pay_now'       => false !== strpos( $html, 'Pay Now' ),
        'invalid_type'  => false !== strpos( $html, 'Invalid checkout type.' ),
        'checkout_type' => $request->get_param( 'checkout_type' ),
        'plan_id'       => $request->get_param( 'plan_id' ),
        'listing_id'    => $request->get_param( 'listing_id' ),
        'is_featured'   => $request->get_param( 'is_featured' ),
    ];
} elseif ( 'submit' === $action ) {
    $url = html_entity_decode( $model->submit_link(), ENT_QUOTES, 'UTF-8' );
    parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
    $output = [
        'url'                     => $url,
        'query'                   => $query,
        'valid_nonce'             => isset( $query['_token'] ) && false !== wp_verify_nonce( $query['_token'], 'directorist_listing_form_redirect_url_42' ),
        'tampered_nonce_rejected' => isset( $query['_token'] ) && false === wp_verify_nonce( 'tampered-' . $query['_token'], 'directorist_listing_form_redirect_url_42' ),
    ];
} else {
    $url = $model->edit_link();
    parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
    $output = [ 'url' => $url, 'query' => $query, 'base' => ATBDP_Permalink::get_edit_listing_page_link( 42 ) ];
}
echo json_encode( $output, JSON_THROW_ON_ERROR ) . "\n";
