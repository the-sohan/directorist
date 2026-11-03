<?php
/**
 * Read Callbacks for listings.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Tool_Providers\Listings;

use Directorist\MCP\Runtime\Access_Control;
use Directorist\MCP\Support\Failure;
use Directorist\MCP\Support\Pagination;
use Directorist\MCP\Support\Validation;


defined( 'ABSPATH' ) || exit;

/** Read Callbacks. */
class Read_Callbacks {
    const LISTING_BUDGET = 20000;

    /**
     * Read.
     *
     * @param string $tool Unprefixed tool action name.
     * @param array $args Validated tool input.
     */
    public static function read( string $tool, array $args ): array {
        if ( 'get-listing-schema' === $tool ) {
            return Listing_Schema::schema( $args );
        }
        $scope = $args['scope'] ?? 'management';
        if ( 'get-listing' === $tool ) {
            return Listing_Schema::data( Access_Control::listing( $args['listing_id'], 'read', $scope ), $scope );
        }
        list( $page, $size ) = Pagination::paging( $args );
        $type                = get_post_type_object( ATBDP_POST_TYPE );
        $query               = [
            'post_type'      => ATBDP_POST_TYPE,
            'post_status'    => 'public' === $scope ? 'publish' : [ 'publish', 'pending', 'draft', 'private', 'trash', 'expired', 'rejected' ],
            's'              => $args['search'] ?? '',
            'posts_per_page' => $size,
            'paged'          => $page,
            'orderby'        => [ [ 'id' => 'ID', 'title' => 'title', 'date' => 'date', 'modified' => 'modified' ][ $args['orderby'] ?? 'id' ] => strtoupper( $args['order'] ?? 'asc' ), 'ID' => 'ASC' ]
        ];
        if ( isset( $args['author'] ) ) {
            $query['author'] = Validation::id( $args['author'] );
        }
        if ( 'management' === $scope ) {
            Validation::check( current_user_can( $type->cap->edit_posts ), __( 'Listing management access is required.', 'directorist' ), 'forbidden' );
            if ( ! current_user_can( $type->cap->edit_others_posts ) ) {
                Validation::check( ! isset( $args['author'] ) || $args['author'] === get_current_user_id(), __( 'Listing not found.', 'directorist' ), 'not_found' );
                $query['author'] = get_current_user_id();
            }
            if ( current_user_can( $type->cap->edit_others_posts ) && ! current_user_can( $type->cap->read_private_posts ) ) {
                // A query-level condition keeps protected records out of totals as well as rows.
                $query['post_status'] = [ 'publish', 'pending', 'draft', 'trash', 'expired', 'rejected' ];
            }
        }
        if ( isset( $args['status'] ) ) {
            Validation::check( in_array( $args['status'], (array) $query['post_status'], true ) || $args['status'] === $query['post_status'], __( 'This status is unavailable in this read scope.', 'directorist' ) );
            $query['post_status'] = $args['status'];
        }
        if ( isset( $args['ids'] ) ) {
            $query['post__in'] = Validation::ids( $args['ids'] );
            if ( ! $query['post__in'] ) {
                return Pagination::collection( [], 0, $page, $size );
            }
        }
        $tax_query = [];
        foreach ( [ 'directory_id' => ATBDP_DIRECTORY_TYPE, 'categories' => ATBDP_CATEGORY, 'locations' => ATBDP_LOCATION, 'tags' => ATBDP_TAGS ] as $key => $taxonomy ) {
            if ( isset( $args[ $key ] ) ) {
                $tax_query[] = [ 'taxonomy' => $taxonomy, 'terms' => 'directory_id' === $key ? [ Validation::directory( $args[ $key ] )->term_id ] : Validation::ids( $args[ $key ] ), 'include_children' => false ];
            }
        }
        if ( $tax_query ) {
            $query['tax_query'] = $tax_query;
        }
        if ( isset( $args['featured'] ) ) {
            $query['meta_query'][] = [ 'key' => '_featured', 'value' => $args['featured'] ? '1' : '0' ];
        }
        foreach ( [ 'created' => 'post_date_gmt', 'modified' => 'post_modified_gmt' ] as $prefix => $column ) {
            $date = [ 'column' => $column, 'inclusive' => true ];
            foreach ( [ 'after', 'before' ] as $bound ) {
                if ( isset( $args[ $prefix . '_' . $bound ] ) ) {
                    Validation::check( false !== strtotime( $args[ $prefix . '_' . $bound ] ), __( 'Invalid date filter.', 'directorist' ) );
                    $date[ $bound ] = $args[ $prefix . '_' . $bound ];
                }
            }
            if ( count( $date ) > 2 ) {
                $query['date_query'][] = $date;
            }
        }
        if ( 'public' === $scope ) {
            $query['has_password'] = false;
            Validation::check( ! get_directorist_option( 'disable_single_listing', false ), __( 'Public listing access is disabled.', 'directorist' ), 'forbidden' );
        }
        $query                   = apply_filters( 'directorist_mcp_listing_read_query', $query, $scope, get_current_user_id() );
        $query['posts_per_page'] = 128;
        $query['no_found_rows']  = true;
        $items                   = [];
        $total                   = 0;
        $scanned                 = 0;
        $batch                   = 0;
        // Object policies cannot be expressed in SQL. Filter before counting or paging.
        do {
            $query['paged'] = ++$batch;
            $results        = new \WP_Query( $query );
            $scanned       += count( $results->posts );
            Validation::check( $scanned <= self::LISTING_BUDGET, __( 'This listing scope exceeds the native object-permission scan budget. Select a directory or use a narrower filter.', 'directorist' ), 'unsupported_operation' );
            foreach ( $results->posts as $post ) {
                try {
                    Access_Control::listing( (int) $post->ID, 'read', $scope );
                } catch ( Failure $error ) {
                    if ( ! in_array( $error->reason, [ 'not_found', 'forbidden' ], true ) ) {
                        throw $error;
                    }
                    continue;
                }
                if ( $total >= ( $page - 1 ) * $size && $total < $page * $size ) {
                    $items[] = Listing_Schema::data( $post, $scope, true );
                }
                ++$total;
            }
        } while ( count( $results->posts ) === 128 );
        return Pagination::collection( $items, $total, $page, $size );
    }

    /**
     * Handle list-listings through the feature read contract.
     *
     * @param array $args Validated tool input.
     */
    public static function list_listings( array $args = [] ): array {
        return self::read( 'list-listings', $args );
    }

    /**
     * Handle get-listing through the feature read contract.
     *
     * @param array $args Validated tool input.
     */
    public static function get_listing( array $args = [] ): array {
        return self::read( 'get-listing', $args );
    }

    /**
     * Handle get-listing-schema through the feature read contract.
     *
     * @param array $args Validated tool input.
     */
    public static function get_listing_schema( array $args = [] ): array {
        return self::read( 'get-listing-schema', $args );
    }
}
