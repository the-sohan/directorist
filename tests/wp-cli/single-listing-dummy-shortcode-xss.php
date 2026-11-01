<?php
/**
 * Regression checks for XSS in single listing shortcode placeholders.
 * Run with Directorist active: wp eval-file tests/wp-cli/single-listing-dummy-shortcode-xss.php
 * Does not create or modify database records.
 *
 * @package Directorist
 */

defined( 'ABSPATH' ) || exit;

$failures = [];
$checks   = 0;
$check    = static function ( $condition, $message ) use ( &$failures, &$checks ) {
    ++$checks;
    if ( ! $condition ) {
        $failures[] = $message;
        WP_CLI::warning( $message );
    }
};

$original_query = $GLOBALS['wp_query'];
$GLOBALS['wp_query'] = new WP_Query();

try {
    $tags = [
        'directorist_single_listing_section',
        'directorist_single_listings_header',
        'directorist_single_listing_field',
    ];
    $vectors = [
        '\\x3cimg src=x onerror=window.directoristXss=1\\x3e',
        '\\074img src=x onerror=window.directoristXss=1\\076',
        '\\x3csvg onload=window.directoristXss=1\\x3e\\x3c/svg\\x3e',
        '\\x22\\x3e\\x3cimg src=x onerror=window.directoristXss=1\\x3e',
    ];

    foreach ( [ 'wp_editor', 'textarea' ] as $type ) {
        $field = new Directorist\Fields\Description_Field( [ 'type' => $type, 'field_key' => 'listing_content' ] );
        foreach ( $tags as $tag ) {
            foreach ( $vectors as $index => $vector ) {
                $payload = '[' . $tag . ' field_key="description" title=\'' . $vector . '\']';
                // Match submission sanitization, then wp_insert_post's unslash without saving.
                $stored = wp_unslash( $field->sanitize( [ 'listing_content' => wp_slash( $payload ) ] ) );
                $check( $payload === $stored, "$type / $tag / $index: payload reaches rendering" );
                $rendered = apply_filters( 'the_content', $stored );
                $check( false === strpos( $rendered, '<img' ) && false === strpos( $rendered, '<svg' ), "$type / $tag / $index: no injected HTML elements" );
                $check( false !== strpos( $rendered, '&lt;' ), "$type / $tag / $index: decoded markup is escaped" );
            }
        }
    }

    foreach ( $tags as $tag ) {
        $rendered = do_shortcode( '[' . $tag . ' field_key="description" title="Coffee & Tea"]' );
        $expected = '<div class="directorist-single-dummy-shortcode">' . $tag . ' field_key=&quot;description&quot; title=&quot;Coffee &amp; Tea&quot;</div>';
        $check( $expected === $rendered, "$tag: ordinary attributes and placeholder wrapper preserved" );
        $check( is_callable( $GLOBALS['shortcode_tags'][ $tag ] ), "$tag: original handler remains registered" );
    }

    $label = 'Coffee & Tea — বাংলা "quotes"';
    $placeholder = Directorist\Helper::single_listing_dummy_shortcode( 'layout', [ 'title' => $label ] );
    $check( 'layout title="' . $label . '"' === html_entity_decode( wp_strip_all_tags( $placeholder ), ENT_QUOTES, 'UTF-8' ), 'Unicode and punctuation remain visible as original text' );
    $placeholder = Directorist\Helper::single_listing_dummy_shortcode( '<img src=x onerror=alert(1)>', [ '<svg onload=alert(1)>' => '<img src=x onerror=alert(1)>' ] );
    $check( false === strpos( $placeholder, '<img' ) && false === strpos( $placeholder, '<svg' ), 'Shortcode name, attribute keys and values are all escaped' );
    $check( '<div class="directorist-single-dummy-shortcode">layout</div>' === Directorist\Helper::single_listing_dummy_shortcode( 'layout' ), 'Empty attributes retain the existing wrapper' );
} finally {
    $GLOBALS['wp_query'] = $original_query;
}

if ( $failures ) {
    WP_CLI::error( count( $failures ) . ' of ' . $checks . ' checks failed.' );
}
WP_CLI::success( $checks . ' shortcode XSS regression checks passed.' );
