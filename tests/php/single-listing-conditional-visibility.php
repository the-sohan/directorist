<?php
/**
 * Regression cases for saved listing values used by single listing visibility.
 *
 * Run with: php tests/php/single-listing-conditional-visibility.php
 *
 * @package Directorist
 */

define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
define( 'ATBDP_CATEGORY', 'at_biz_dir-category' );
define( 'ATBDP_TAGS', 'at_biz_dir-tags' );
define( 'ATBDP_LOCATION', 'at_biz_dir-location' );

function wp_get_post_terms( $post_id, $taxonomy, $args ) {
    unset( $post_id, $args );
    return ATBDP_CATEGORY === $taxonomy ? [ 7 ] : [];
}

function is_wp_error( $value ) {
    unset( $value );
    return false;
}

function get_the_title( $post_id ) {
    unset( $post_id );
    return 'Tuition listing';
}

function get_post_meta( $post_id, $key, $single ) {
    unset( $post_id, $single );
    return '_institution' === $key ? 'Oxford' : '';
}

function directorist_single_listings_contents( $type, $args ) {
    unset( $type, $args );
    return $GLOBALS['single_listing_fields'];
}

function directorist_submission_form_fields_raw( $type ) {
    unset( $type );
    return $GLOBALS['submission_form_fields'];
}

require ABSPATH . 'includes/fields/class-directorist-conditional-logic.php';
require ABSPATH . 'includes/model/SingleListing.php';

function directorist_test_condition( $field, $operator, $value = '' ) {
    return [
        'enabled' => true,
        'action'  => 'show',
        'groups'  => [
            [ 'conditions' => [ [ 'field' => $field, 'operator' => $operator, 'value' => $value ] ] ],
        ],
    ];
}

function directorist_test_assert( $condition, $message ) {
    if ( $condition ) {
        return;
    }

    fwrite( STDERR, "FAIL: {$message}\n" );
    exit( 1 );
}

$GLOBALS['submission_form_fields'] = [
    'fields' => [
        'title_widget'       => [ 'field_key' => 'listing_title' ],
        'description_widget' => [ 'field_key' => 'listing_content' ],
        'institution_widget' => [ 'field_key' => 'institution' ],
        'title_match'        => [ 'field_key' => 'title_match', 'conditional_logic' => directorist_test_condition( 'title', 'contains', 'Tuition' ) ],
        'description_match'  => [ 'field_key' => 'description_match', 'conditional_logic' => directorist_test_condition( 'description', 'contains', 'Math' ) ],
        'title_empty'        => [ 'field_key' => 'title_empty', 'conditional_logic' => directorist_test_condition( 'listing_title', 'empty' ) ],
        'category_match'     => [ 'field_key' => 'category_match', 'conditional_logic' => directorist_test_condition( 'category', 'contains', '7' ) ],
        'custom_match'       => [ 'field_key' => 'custom_match', 'conditional_logic' => directorist_test_condition( 'institution', 'is', 'Oxford' ) ],
    ],
];

$single_fields = [];
foreach ( array_keys( $GLOBALS['submission_form_fields']['fields'] ) as $key ) {
    $single_fields[ $key ] = [ 'widget_group' => 'general', 'original_widget_key' => $key ];
}

$GLOBALS['single_listing_fields'] = [
    'fields' => $single_fields,
    'groups' => [ [ 'type' => 'general_group', 'fields' => array_keys( $single_fields ) ] ],
];

$reflection = new ReflectionClass( 'Directorist\\Directorist_Single_Listing' );
$listing    = $reflection->newInstanceWithoutConstructor();
$listing->id   = 42;
$listing->post = (object) [ 'post_content' => 'Math tuition' ];
$listing->type = 1;
$listing->author_id = 2;

$value_method = $reflection->getMethod( 'get_conditional_field_values' );
$values       = $value_method->invoke( $listing, $GLOBALS['submission_form_fields'] );

directorist_test_assert( 'Tuition listing' === $values['listing_title'], 'Title must come from the post.' );
directorist_test_assert( 'Tuition listing' === $values['title_widget'], 'Title widget alias must keep the post title.' );
directorist_test_assert( 'Math tuition' === $values['listing_content'], 'Description must come from the post.' );
directorist_test_assert( 'Math tuition' === $values['description_widget'], 'Description widget alias must keep the post content.' );
directorist_test_assert( 'Oxford' === $values['institution'], 'Custom field meta must remain available.' );

$sections = $listing->build_content_data();
$visible  = $sections[0]['fields'];

foreach ( [ 'title_match', 'description_match', 'category_match', 'custom_match' ] as $key ) {
    directorist_test_assert( isset( $visible[ $key ] ), "{$key} should remain visible." );
}

directorist_test_assert( ! isset( $visible['title_empty'] ), 'A populated title must not match an empty-title condition.' );

echo "PASS: title, description, category, custom-field and inverse visibility cases.\n";
