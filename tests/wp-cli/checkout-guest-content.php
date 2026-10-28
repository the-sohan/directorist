<?php
/**
 * Checkout/receipt output-contract regression coverage (no database writes).
 *
 * Run on a local WordPress installation with Directorist active:
 * wp eval-file wp-content/plugins/directorist/tests/wp-cli/checkout-guest-content.php
 *
 * @package Directorist
 */

defined( 'ABSPATH' ) || exit;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    return;
}

global $directorist_checkout_checks;

$directorist_checkout_checks   = [];
$directorist_checkout_user_id  = get_current_user_id();
$directorist_checkout_order_id = get_query_var( 'atbdp_order_id' );
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- CLI-only snapshot; restored after assertions.
$directorist_checkout_request = $_REQUEST;

/** Record a regression assertion without stopping before the remaining cases. */
function directorist_checkout_content_check( $condition, $message ) {
    $GLOBALS['directorist_checkout_checks'][] = [
        'passed'  => (bool) $condition,
        'message' => $message,
    ];
}

/** Capture accidental echoed output separately from a callback's return value. */
function directorist_checkout_content_capture( $callback ) {
    $level = ob_get_level();
    ob_start();
    try {
        $result  = call_user_func( $callback );
        $echoed  = ob_get_contents();
        $balance = ob_get_level() - $level - 1;
    } finally {
        while ( ob_get_level() > $level ) {
            ob_end_clean();
        }
    }
    return [ $result, $echoed, $balance ];
}

try {
    wp_set_current_user( 0 );
    $checkout           = new ATBDP_Checkout();
    $filtered_login     = static function () {
        return '<a href="https://example.invalid/sign-in/">Sign in</a>';
    };
    $filtered_container = static function () {
        return 'checkout-guest-filtered-container';
    };
    add_filter( 'atbdp_login_page_link', $filtered_login );
    add_filter( 'atbdp_login_message_container_fluid', $filtered_container );

    try {
        list( $logged_in, $expected_notice ) = directorist_checkout_content_capture( 'atbdp_is_user_logged_in' );
        directorist_checkout_content_check( false === $logged_in, 'Legacy helper still denies guests.' );
        directorist_checkout_content_check( false !== strpos( $expected_notice, 'https://example.invalid/sign-in/' ), 'Login-link filter remains in the notice.' );
        directorist_checkout_content_check( false !== strpos( $expected_notice, 'checkout-guest-filtered-container' ), 'Container filter remains in the notice.' );

        foreach ( [ 'checkout', 'payment_receipt' ] as $method ) {
            $block_name = str_replace( '_', '-', $method );
            $shortcode  = '[directorist_' . $method . ']';
            $block      = '<!-- wp:directorist/' . $block_name . ' /-->';
            foreach ( [
                'callback'  => static function () use ( $checkout, $method ) { return $checkout->$method(); },
                'shortcode' => static function () use ( $shortcode ) { return do_shortcode( $shortcode ); },
                'block'     => static function () use ( $block ) { return do_blocks( $block ); },
            ] as $kind => $render ) {
                $level                             = ob_get_level();
                list( $result, $echoed, $balance ) = directorist_checkout_content_capture( $render );
                directorist_checkout_content_check( '' === $echoed, $method . ' ' . $kind . ' does not echo before its container.' );
                directorist_checkout_content_check( is_string( $result ) && trim( $expected_notice ) === trim( $result ), $method . ' ' . $kind . ' returns the original filtered guest notice.' );
                directorist_checkout_content_check( $level === ob_get_level() && 0 === $balance, $method . ' ' . $kind . ' leaves the output-buffer stack balanced.' );
            }
            foreach ( [ $block, '<!-- wp:shortcode -->' . $shortcode . '<!-- /wp:shortcode -->' ] as $inner ) {
                $markup                          = '<!-- wp:group {"className":"checkout-guest-native-container","layout":{"type":"constrained"}} --><div class="wp-block-group checkout-guest-native-container"><!-- wp:paragraph --><p>Before checkout</p><!-- /wp:paragraph -->' . $inner . '<!-- wp:paragraph --><p>After checkout</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
                list( $html, $echoed, $balance ) = directorist_checkout_content_capture( static function () use ( $markup ) { return apply_filters( 'the_content', $markup ); } );
                $document                        = new DOMDocument();
                $previous                        = libxml_use_internal_errors( true );
                $document->loadHTML( '<html><body>' . $html . '</body></html>' );
                libxml_clear_errors();
                libxml_use_internal_errors( $previous );
                $xpath   = new DOMXPath( $document );
                $notices = $xpath->query( '//*[contains(concat(" ",normalize-space(@class)," ")," directory_wrapper ")]' );
                $nested  = $xpath->query( '//*[contains(concat(" ",normalize-space(@class)," ")," checkout-guest-native-container ")]//*[contains(concat(" ",normalize-space(@class)," ")," directory_wrapper ")]' );
                directorist_checkout_content_check( '' === $echoed && 0 === $balance && 1 === $notices->length && 1 === $nested->length, $method . ' notice stays inside its native Group through the_content.' );
                directorist_checkout_content_check( strpos( $html, 'Before checkout' ) < strpos( $html, 'directory_wrapper' ) && strpos( $html, 'directory_wrapper' ) < strpos( $html, 'After checkout' ), $method . ' preserves before/notice/after content order.' );
            }
        }
    } finally {
        remove_filter( 'atbdp_login_page_link', $filtered_login );
        remove_filter( 'atbdp_login_message_container_fluid', $filtered_container );
    }

    // Exercise authenticated early exits without creating an order or payment.
    $users = get_users( [ 'number' => 1, 'fields' => 'ID' ] );
    directorist_checkout_content_check( ! empty( $users ), 'An existing user is available for authenticated early-exit coverage.' );
    if ( $users ) {
        wp_set_current_user( (int) $users[0] );
        list( $logged_in, $echoed ) = directorist_checkout_content_capture( 'atbdp_is_user_logged_in' );
        directorist_checkout_content_check( true === $logged_in && '' === $echoed, 'Legacy helper still accepts authenticated users without notice output.' );
        $disabled = '__return_false';
        add_filter( 'atbdp_enable_monetization_checkout', $disabled );
        try {
            list( $html, $echoed, $balance ) = directorist_checkout_content_capture( [ $checkout, 'checkout' ] );
            directorist_checkout_content_check( '' === $echoed && 0 === $balance && false !== strpos( $html, __( 'Monetization is not active on this site. if you are an admin, you can enable it from the settings panel.', 'directorist' ) ) && false === strpos( $html, 'directory_wrapper' ), 'Authenticated checkout retains its existing monetization validation result.' );
        } finally {
            remove_filter( 'atbdp_enable_monetization_checkout', $disabled );
        }
        set_query_var( 'atbdp_order_id', 0 );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- CLI-only fixture; no submitted data is processed.
        unset( $_REQUEST['order'] );
        list( $html, $echoed, $balance ) = directorist_checkout_content_capture( [ $checkout, 'payment_receipt' ] );
        directorist_checkout_content_check( '' === $echoed && 0 === $balance && __( 'Sorry! No order id has been provided.', 'directorist' ) === $html, 'Authenticated receipt retains its missing-order validation result.' );
    }
} finally {
    wp_set_current_user( $directorist_checkout_user_id );
    set_query_var( 'atbdp_order_id', $directorist_checkout_order_id );
    $_REQUEST = $directorist_checkout_request;
}

$directorist_checkout_failures = array_values( array_filter( $directorist_checkout_checks, static function ( $check ) { return ! $check['passed']; } ) );
WP_CLI::line( wp_json_encode( [ 'theme' => get_stylesheet(), 'block_theme' => wp_is_block_theme(), 'checks' => count( $directorist_checkout_checks ), 'failures' => $directorist_checkout_failures ], JSON_PRETTY_PRINT ) );
if ( $directorist_checkout_failures ) {
    WP_CLI::error( 'Checkout/receipt output-contract regression failed.' );
}
WP_CLI::success( 'Checkout/receipt notices remain in returned content; authentication and filters are preserved.' );
