<?php
/**
 * Regression checks for the Popular Listings model's minimum rating query.
 *
 * Run with: php tests/php/popular-listings-query.php
 *
 * @package Directorist
 */

define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
define( 'ATBDP_POST_TYPE', 'at_biz_dir' );

function atbdp_get_listings_current_order( $order ) {
    return $order;
}

function directorist_get_rating_field_meta_key() {
    return '_directorist_listing_rating';
}

function directorist_get_listing_views_count_meta_key() {
    return '_atbdp_post_views_count';
}

function get_directorist_option( $key, $default = null ) {
    return $GLOBALS['directorist_popular_test_options'][ $key ] ?? $default;
}

function directorist_get_listing_rating( $listing_id ) {
    return $GLOBALS['directorist_popular_test_ratings'][ $listing_id ];
}

function directorist_get_listing_views_count( $listing_id ) {
    return $GLOBALS['directorist_popular_test_views'][ $listing_id ];
}

require ABSPATH . 'includes/model/Listings.php';
require ABSPATH . 'includes/helpers/trait-uri-helper.php';
require ABSPATH . 'includes/helpers/trait-markup-helper.php';
require ABSPATH . 'includes/helpers/trait-icon-helper.php';
require ABSPATH . 'includes/class-helper.php';

class Directorist_Popular_Query_Test_Listings extends Directorist\Directorist_Listings {
    public function get_current_listing_type() {
        return 29;
    }
}

$failures = [];
$checks   = 0;
$check    = static function ( $condition, $message ) use ( &$failures, &$checks ) {
    ++$checks;
    if ( ! $condition ) {
        $failures[] = $message;
    }
};
$method = new ReflectionMethod( Directorist\Directorist_Listings::class, 'execute_meta_query_args' );
$method->setAccessible( true );
$query = static function ( $featured, $mode, $threshold = 4, $popular_only = 'yes', $order = 'date-desc' ) use ( $method ) {
    $listings                            = ( new ReflectionClass( Directorist_Popular_Query_Test_Listings::class ) )->newInstanceWithoutConstructor();
    $listings->has_featured              = $featured;
    $listings->filterby                  = '';
    $listings->featured_only             = 'no';
    $listings->popular_only              = $popular_only;
    $listings->popular_by                = $mode;
    $listings->average_review_for_popular = $threshold;
    $listings->view_to_popular           = 100;
    $order_parts                        = explode( '-', $order );
    $listings->orderby                   = $order_parts[0];
    $listings->order                     = $order_parts[1];
    $args                               = [];
    $meta                               = [];
    $method->invokeArgs( $listings, [ &$args, &$meta ] );

    return [ $args, $meta ];
};

// Evaluate the numeric predicates returned by the real model, not copied query logic.
$matches = static function ( $clause, $value ) {
    if ( empty( $clause['type'] ) || 'NUMERIC' === $clause['type'] ) {
        $value = empty( $clause['type'] ) ? (string) $value : (int) $value;
    } else {
        $value = (float) $value;
    }
    if ( '>=' === $clause['compare'] ) {
        return $value >= $clause['value'];
    }
    if ( '<=' === $clause['compare'] ) {
        return $value <= $clause['value'];
    }
    throw new RuntimeException( 'Unexpected comparison in a minimum threshold clause.' );
};

foreach ( [ false, true ] as $featured ) {
    foreach ( [ 'average_rating', 'both' ] as $mode ) {
        $label = $mode . ', featured=' . ( $featured ? 'yes' : 'no' );
        foreach ( [ 4, 4.5 ] as $threshold ) {
            list( $args, $meta ) = $query( $featured, $mode, $threshold );
            $rating             = $meta['_rating'];
            $check( '>=' === $rating['compare'], $label . ': rating uses a minimum threshold' );
            $check( 'DECIMAL(3,2)' === ( $rating['type'] ?? '' ), $label . ': rating comparison retains fractional averages' );
            foreach ( [ [ $threshold - .1, false ], [ $threshold, true ], [ $threshold + .1, true ] ] as $case ) {
                $check( $case[1] === $matches( $rating, $case[0] ), $label . ': rating ' . $case[0] . ' at threshold ' . $threshold );
            }
            if ( 'both' === $mode ) {
                $check( isset( $meta['views'] ), $label . ': combined criteria retain the view clause' );
                foreach ( [ [ 99, false ], [ 100, true ], [ 101, true ] ] as $case ) {
                    $check( $case[1] === $matches( $meta['views'], $case[0] ), $label . ': view boundary ' . $case[0] );
                }
            } else {
                $check( ! isset( $meta['views'] ), $label . ': rating-only does not add a view condition' );
            }
            $check( $featured === isset( $meta['_featured'] ), $label . ': featured query shape is preserved' );
        }
    }

    list( $args, $meta ) = $query( $featured, 'view_count', 4, 'yes', 'views-desc' );
    $check( ! isset( $meta['_rating'] ), 'View-only never adds a rating condition.' );
    $check( [ 'key' => '_atbdp_post_views_count', 'value' => 100, 'type' => 'NUMERIC', 'compare' => '>=' ] === $meta['views'], 'View-only threshold is unchanged.' );
    $check( ( $featured ? [ '_featured' => 'DESC', 'views' => 'DESC' ] : [ 'views' => 'DESC' ] ) === $args['orderby'], 'View-only ordering is unchanged.' );

    list( $args, $meta ) = $query( $featured, 'average_rating', 4, 'no' );
    $check( ! isset( $meta['_rating'] ) && ! isset( $meta['views'] ), 'An unfiltered date query has no popularity conditions.' );
    $check( [ 'key' => '_directory_type', 'value' => 29, 'compare' => '=' ] === $meta['directory_type'], 'Directory isolation is unchanged.' );
    $check( ( $featured ? [ '_featured' => 'DESC', 'date' => 'DESC' ] : 'date' ) === $args['orderby'], 'Unfiltered date ordering is unchanged.' );

    list( $args, $meta ) = $query( $featured, 'average_rating', 4, 'no', 'views-desc' );
    $check( '>=' === $meta['_rating']['compare'], 'The existing views-desc popularity path uses the same minimum rating.' );
}

// Check the actual badge helper's existing integer setting semantics separately.
$GLOBALS['directorist_popular_test_ratings'] = [ 1 => 3.9, 2 => 4, 3 => 4.1 ];
$GLOBALS['directorist_popular_test_views']   = [ 1 => 100, 2 => 100, 3 => 100 ];
foreach ( [ 'average_rating', 'both' ] as $mode ) {
    $GLOBALS['directorist_popular_test_options'] = [ 'listing_popular_by' => $mode, 'average_review_for_popular' => '4', 'views_for_popular' => '100' ];
    foreach ( [ 1 => false, 2 => true, 3 => true ] as $listing_id => $wanted ) {
        $check( $wanted === Directorist\Helper::is_popular( $listing_id ), 'Native badge helper boundary, ' . $mode . ', fixture=' . $listing_id );
    }
}
$GLOBALS['directorist_popular_test_options']['average_review_for_popular'] = '4.5';
$check( Directorist\Helper::is_popular( 2 ), 'The unchanged helper casts a half-star setting to integer; this separate existing gap remains explicit.' );

foreach ( $failures as $failure ) {
    fwrite( STDERR, "FAIL: {$failure}\n" );
}
fwrite( STDOUT, sprintf( "%s: %d Popular query/helper checks, %d failures.\n", $failures ? 'FAIL' : 'PASS', $checks, count( $failures ) ) );
exit( $failures ? 1 : 0 );
