<?php
/**
 * Exercise Popular query predicates through WP_Query and native review aggregates.
 * Run only on a disposable WordPress site with the candidate Directorist active:
 * wp eval-file wp-content/plugins/directorist/tests/wp-cli/popular-listings-rating-boundaries.php
 *
 * All fixtures are drafts and are deleted in finally; no settings are changed.
 *
 * @package Directorist
 */

defined( 'ABSPATH' ) || exit;

$posts     = [];
$failures  = [];
$results   = [];
$directory = directorist_get_default_directory();
$check     = static function ( $condition, $message ) use ( &$failures, &$results ) {
    $results[ $message ] = (bool) $condition;
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

try {
    if ( ! directorist_is_directory( $directory ) ) {
        throw new RuntimeException( 'The disposable site must have a configured directory.' );
    }
    $cases = [
        'below'          => [ [ 3 ], 100 ],
        'equal'          => [ [ 4 ], 100 ],
        'above'          => [ [ 5 ], 100 ],
        'fraction_below' => [ [ 4, 4, 4, 5, 5 ], 100 ],
        'fraction_equal' => [ [ 4, 5 ], 100 ],
        'fraction_above' => [ [ 4, 4, 5, 5, 5 ], 100 ],
        'views_below'    => [ [ 5 ], 99 ],
        'views_above'    => [ [ 5 ], 101 ],
    ];
    $expected = [];
    foreach ( [ 0, 1 ] as $featured ) {
        foreach ( $cases as $label => $case ) {
            $post_id = wp_insert_post( [ 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'draft', 'post_title' => 'Popular threshold regression ' . $label ], true );
            if ( is_wp_error( $post_id ) ) {
                throw new RuntimeException( $post_id->get_error_message() );
            }
            $posts[] = $post_id;
            update_post_meta( $post_id, '_directory_type', $directory );
            update_post_meta( $post_id, '_featured', $featured );
            update_post_meta( $post_id, directorist_get_listing_views_count_meta_key(), $case[1] );
            foreach ( $case[0] as $rating ) {
                $comment_id = wp_insert_comment( [ 'comment_post_ID' => $post_id, 'comment_type' => 'review', 'comment_parent' => 0, 'comment_approved' => 1, 'comment_content' => 'Popular threshold regression' ] );
                if ( ! $comment_id ) {
                    throw new RuntimeException( 'Failed to create a regression review.' );
                }
                update_comment_meta( $comment_id, 'rating', $rating );
            }
            Directorist\Review\Comment::maybe_clear_transients( $post_id );
            $expected[ $post_id ] = [ array_sum( $case[0] ) / count( $case[0] ), $case[1] ];
            $check( abs( $expected[ $post_id ][0] - directorist_get_listing_rating( $post_id, 2 ) ) < .001, 'Native review aggregate: ' . $label . ', featured=' . $featured );
        }
    }

    $method = new ReflectionMethod( Directorist\Directorist_Listings::class, 'execute_meta_query_args' );
    $method->setAccessible( true );
    foreach ( [ false, true ] as $featured ) {
        foreach ( [ 'average_rating', 'both', 'view_count', 'unfiltered' ] as $mode ) {
            foreach ( [ 4, 4.5 ] as $threshold ) {
                $listings                            = ( new ReflectionClass( Directorist\Directorist_Listings::class ) )->newInstanceWithoutConstructor();
                $listings->type                      = 'listing';
                $listings->default_directory_type    = $directory;
                $listings->has_featured              = $featured;
                $listings->filterby                  = '';
                $listings->featured_only             = 'no';
                $listings->popular_only              = 'unfiltered' === $mode ? 'no' : 'yes';
                $listings->popular_by                = $mode;
                $listings->average_review_for_popular = $threshold;
                $listings->view_to_popular           = 100;
                $listings->orderby                   = 'date';
                $listings->order                     = 'desc';
                $args                               = [];
                $meta                               = [];
                $method->invokeArgs( $listings, [ &$args, &$meta ] );
                $query = new WP_Query(
                    [
                        'post_type'        => ATBDP_POST_TYPE,
                        'post_status'      => 'draft',
                        'posts_per_page'   => -1,
                        'post__in'         => $posts,
                        'fields'           => 'ids',
                        'meta_query'       => array_merge( [ 'relation' => 'AND' ], $meta ),
                        'suppress_filters' => true,
                    ]
                );
                $actual = array_map( 'intval', $query->posts );
                $wanted = [];
                foreach ( $expected as $post_id => $values ) {
                    $rating_matches = 'view_count' === $mode || 'unfiltered' === $mode || $values[0] >= $threshold;
                    $views_match    = 'average_rating' === $mode || 'unfiltered' === $mode || $values[1] >= 100;
                    if ( $rating_matches && $views_match ) {
                        $wanted[] = $post_id;
                    }
                }
                sort( $actual );
                sort( $wanted );
                $check( $wanted === $actual, 'WP_Query boundaries: ' . $mode . ', minimum=' . $threshold . ', featured=' . (int) $featured );
            }
        }
    }
} finally {
    foreach ( $posts as $post_id ) {
        wp_delete_post( $post_id, true );
    }
}

foreach ( $results as $message => $passed ) {
    WP_CLI::log( ( $passed ? 'PASS: ' : 'FAIL: ' ) . $message );
}
if ( $failures ) {
    WP_CLI::error( implode( '; ', $failures ) );
}
WP_CLI::success( 'Popular boundaries match real review data; all fixtures removed.' );
