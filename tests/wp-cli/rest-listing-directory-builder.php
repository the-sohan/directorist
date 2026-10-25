<?php
/**
 * Regression checks for REST listing directory resolution and builder data.
 *
 * Run as an administrator on a disposable local site with Directorist active:
 * wp --user=<admin> eval-file wp-content/plugins/directorist/tests/wp-cli/rest-listing-directory-builder.php
 * Add "single" to test single-directory mode. Add "http" to exercise the running
 * site's authenticated HTTP endpoint (its plugin source must match this checkout).
 *
 * @package Directorist
 */
defined( 'ABSPATH' ) || exit;

$host = wp_parse_url( home_url(), PHP_URL_HOST );
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! preg_match( '/(?:\\.local|\\.test)$|^(?:localhost|127\\.0\\.0\\.1)$/i', $host ) || ! current_user_can( 'manage_options' ) ) {
    throw new RuntimeException( 'Run as an administrator on a disposable .local, .test or localhost site.' );
}

$args = isset( $args ) ? $args : [];

$checks = 0;
$failures = 0;
$posts = [];
$terms = [];
$original_user = get_current_user_id();
// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Snapshot CLI globals, not a form submission.
$original_post = $_POST;
$original_cookies = $_COOKIE;
$token = null;
$single_directory = static function ( $value, $name ) {
    return 'enable_multi_directory' === $name ? false : $value;
};
if ( in_array( 'single', $args, true ) ) {
    add_filter( 'directorist_option', $single_directory, 10, 2 );
}
add_filter( 'pre_wp_mail', '__return_true' );
$check = static function ( $pass, $label ) use ( &$checks, &$failures ) {
    ++$checks;
    $failures += $pass ? 0 : 1;
    WP_CLI::log( ( $pass ? 'PASS ' : 'FAIL ' ) . $label );
};
$dispatch = static function ( $method, $path, $body = [] ) {
    $_POST = [];
    $request = new WP_REST_Request( $method, '/directorist/' . $path );
    $request->set_header( 'Content-Type', 'application/json' );
    $request->set_body( wp_json_encode( $body ) );
    try {
        $response = rest_do_request( $request );
        $result = [ 'status' => $response->get_status(), 'data' => $response->get_data() ];
    } catch ( Throwable $error ) {
        $result = [ 'status' => 500, 'fatal' => get_class( $error ) . ': ' . $error->getMessage() ];
    }
    WP_CLI::log( $method . ' ' . $path . ': ' . wp_json_encode( isset( $result['fatal'] ) ? $result : [ 'status' => $result['status'], 'code' => $result['data']['code'] ?? null ] ) );
    return $result;
};
$new_term = static function ( $taxonomy, $label ) use ( &$terms ) {
    $result = wp_insert_term( 'REST directory regression ' . $label . ' ' . wp_generate_uuid4(), $taxonomy );
    if ( is_wp_error( $result ) ) {
        throw new RuntimeException( $result->get_error_message() );
    }
    $id = (int) $result['term_id'];
    $terms[] = [ $id, $taxonomy ];
    return $id;
};

WP_CLI::log( 'Directorist ' . ATBDP_VERSION . '; WordPress ' . get_bloginfo( 'version' ) . '; PHP ' . PHP_VERSION . '; multisite=' . (int) is_multisite() . '; multi_directory=' . (int) directorist_is_multi_directory_enabled() );
try {
    $directory = $new_term( ATBDP_DIRECTORY_TYPE, 'directory' );
    $category = $new_term( ATBDP_CATEGORY, 'category' );
    $location = $new_term( ATBDP_LOCATION, 'location' );
    $fields = [];
    foreach ( [ 'title' => 'listing_title', 'description' => 'listing_content', 'category' => 'category', 'location' => 'location', 'map' => 'map', 'text' => 'rest-qa-text' ] as $widget => $key ) {
        $fields[ $widget ] = [ 'widget_group' => 'preset', 'widget_name' => $widget, 'widget_key' => $widget, 'field_key' => $key, 'label' => $widget, 'type' => 'text', 'required' => 'text' !== $widget ];
    }
    $form = [ 'fields' => $fields, 'groups' => [ [ 'label' => 'QA', 'fields' => array_keys( $fields ) ] ] ];
    update_term_meta( $directory, 'submission_form_fields', $form );
    update_term_meta( $directory, 'single_listings_contents', $form );
    update_term_meta( $directory, 'new_listing_status', 'draft' );
    update_term_meta( $directory, 'edit_listing_status', 'pending' );
    $listing = wp_insert_post( [ 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'draft', 'post_title' => 'REST directory regression fixture', 'post_content' => 'Existing content', 'post_author' => $original_user ], true );
    if ( is_wp_error( $listing ) ) {
        throw new RuntimeException( $listing->get_error_message() );
    }
    $posts[] = $listing;
    directorist_set_listing_directory( $listing, $directory );
    $full = [ 'fields' => [ 'title' => 'REST directory regression updated', 'description' => 'QA content', 'categories' => [ [ 'id' => $category ] ], 'locations' => [ [ 'id' => $location ] ], 'latitude' => '23.8', 'longitude' => '90.4', 'rest-qa-text' => 'Keep this value' ] ];

    if ( in_array( 'http', $args, true ) ) {
        $expiry = time() + 300;
        $token = WP_Session_Tokens::get_instance( $original_user )->create( $expiry );
        $cookie = wp_generate_auth_cookie( $original_user, $expiry, 'logged_in', $token );
        $_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;
        $response = wp_remote_post(
            rest_url( 'directorist/v2/listings/' . $listing ), [
                'timeout' => 30,
                'headers' => [ 'Content-Type' => 'application/json', 'Cookie' => LOGGED_IN_COOKIE . '=' . $cookie, 'X-WP-Nonce' => wp_create_nonce( 'wp_rest' ) ],
                'body' => wp_json_encode( [ 'fields' => [ 'title' => 'Same title' ] ] ),
            ]
        );
        $status = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        WP_CLI::log( 'Authenticated HTTP title-only update: ' . $status );
        $check( 400 === $status && 'directorist_invalid_field' === ( $body['code'] ?? '' ), 'real authenticated HTTP request returns validation error, not fatal' );
        $_COOKIE = $original_cookies;
    }

    $result = $dispatch( 'POST', 'v2/listings/' . $listing, [ 'fields' => [ 'title' => 'Same title' ] ] );
    $check( 400 === $result['status'] && 'directorist_invalid_field' === ( $result['data']['code'] ?? '' ), 'title-only update without directory returns normal required-field validation, not fatal' );

    $result = $dispatch( 'POST', 'v2/listings/' . $listing, $full );
    $check( 201 === $result['status'], 'complete update without directory succeeds using stored directory' );
    $check( $directory === directorist_get_listing_directory( $listing ), 'existing non-default directory preserved' );
    $check( 'REST directory regression updated' === get_post_field( 'post_title', $listing ), 'requested title saved' );
    $check( 'Keep this value' === get_post_meta( $listing, '_rest-qa-text', true ), 'submitted custom field saved' );
    $result = $dispatch( 'POST', 'v2/listings/' . $listing, [ 'directory' => 0 ] + $full );
    $check( 400 === $result['status'], 'explicit invalid directory rejected' );
    $result = $dispatch( 'POST', 'v2/listings/' . $listing, [ 'directory' => $directory ] + $full );
    $check( 201 === $result['status'], 'explicit directory update still works' );
    $invalid = $full;
    $invalid['fields']['title'] = '';
    $result = $dispatch( 'POST', 'v2/listings/' . $listing, $invalid );
    $check( 400 === $result['status'] && 'REST directory regression updated' === get_post_field( 'post_title', $listing ), 'required title validation still prevents writes' );
    delete_post_meta( $listing, '_directory_type' );
    $result = $dispatch( 'POST', 'v2/listings/' . $listing, $full );
    $check( 400 === $result['status'], 'missing stored directory is rejected, not silently reassigned' );
    directorist_set_listing_directory( $listing, $directory );
    wp_set_current_user( 0 );
    $result = $dispatch( 'POST', 'v2/listings/' . $listing, $full );
    $check( in_array( $result['status'], [ 401, 403 ], true ), 'unauthenticated update remains denied' );
    wp_set_current_user( $original_user );
    $result = $dispatch( 'POST', 'v2/listings', [ 'directory' => $directory ] + $full );
    if ( ! empty( $result['data']['id'] ) ) {
        $posts[] = (int) $result['data']['id'];
    }
    $check( 201 === $result['status'], 'valid create still works' );
    $result = $dispatch( 'POST', 'v2/listings', [ 'directory' => 0 ] + $full );
    $check( 400 === $result['status'], 'invalid create directory is validation error' );

    foreach ( [ 'v1/builder/single-listing/' . $directory, 'v1/builder/single-listing' ] as $path ) {
        $result = $dispatch( 'GET', $path );
        $check( 200 === $result['status'] && is_array( $result['data']['groups'] ?? null ) && is_array( $result['data']['fields'] ?? null ), 'single-listing builder returns valid groups and fields' );
        if ( 200 === $result['status'] && false !== strpos( $path, (string) $directory ) ) {
            $check( $form['groups'] === $result['data']['groups'] && $form['fields'] === $result['data']['fields'], 'single-listing response matches canonical builder configuration' );
        }
    }
    $result = $dispatch( 'GET', 'v1/builder/single-listing/0' );
    $check( 400 === $result['status'], 'invalid builder directory remains rejected' );
    $result = $dispatch( 'GET', 'v1/builder/listing-form/' . $directory );
    $check( 200 === $result['status'] && ! empty( $result['data']['fields'] ), 'listing-form endpoint remains functional' );
    delete_term_meta( $directory, 'single_listings_contents' );
    $result = $dispatch( 'GET', 'v1/builder/single-listing/' . $directory );
    $check( 200 === $result['status'] && [] === $result['data']['groups'] && [] === $result['data']['fields'], 'unconfigured single-listing builder returns empty collections without notices' );
} finally {
    wp_set_current_user( $original_user );
    $_POST = $original_post;
    $_COOKIE = $original_cookies;
    if ( $token ) {
        WP_Session_Tokens::get_instance( $original_user )->destroy( $token );
        $check( ! WP_Session_Tokens::get_instance( $original_user )->verify( $token ), 'temporary HTTP authentication revoked' );
    }
    remove_filter( 'directorist_option', $single_directory, 10 );
    foreach ( array_unique( $posts ) as $id ) {
        wp_delete_post( $id, true );
        $check( null === get_post( $id ), 'temporary listing removed: ' . $id );
    }
    foreach ( array_reverse( $terms ) as [ $id, $taxonomy ] ) {
        wp_delete_term( $id, $taxonomy );
        $check( ! term_exists( $id, $taxonomy ), 'temporary taxonomy term removed: ' . $id );
    }
    remove_filter( 'pre_wp_mail', '__return_true' );
}
if ( $failures ) {
    WP_CLI::error( "$failures of $checks assertions failed." );
}
WP_CLI::success( "$checks assertions passed; temporary fixtures removed; mail suppressed." );
