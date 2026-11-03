<?php
/**
 * Listing Data for dashboard data.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Tool_Providers\Dashboard;

use Directorist\MCP\Runtime\Access_Control;
use Directorist\MCP\Support\Failure;
use Directorist\MCP\Support\Read_Support;
use Directorist\MCP\Support\Validation;

defined( 'ABSPATH' ) || exit;

/** Listing Data. */
class Listing_Data {
    const LISTING_BUDGET = 20000;

    /**
     * Listings.
     *
     * @param array $args Validated tool input.
     * @param array $range Range.
     * @param bool $activity Activity.
     */
    public static function listings( array $args, array $range, bool $activity = false ): array {
        global $wpdb;
        Validation::check( Read_Callbacks::listing_access(), __( 'Listing access is required.', 'directorist' ), 'forbidden' );
        $where  = $wpdb->prepare( 'p.post_type = %s', ATBDP_POST_TYPE );
        $where .= " AND p.post_status IN ('publish','pending','private','expired','rejected','draft','trash')";
        if ( 'own' === $args['scope'] ) {
            $where .= $wpdb->prepare( ' AND p.post_author = %d', get_current_user_id() );
        }
        if ( isset( $args['directory_id'] ) ) {
            $where .= $wpdb->prepare( " AND EXISTS (SELECT 1 FROM {$wpdb->term_relationships} r JOIN {$wpdb->term_taxonomy} t ON t.term_taxonomy_id=r.term_taxonomy_id WHERE r.object_id=p.ID AND t.taxonomy=%s AND t.term_id=%d)", ATBDP_DIRECTORY_TYPE, $args['directory_id'] );
        }
        $out          = [ 'counts' => array_fill_keys( [ 'publish', 'pending', 'private', 'expired', 'rejected', 'draft', 'trash' ], 0 ), 'views' => 0, 'views_complete' => true, 'expiring' => 0, 'daily' => [], 'distribution' => [], 'activity' => [], 'upcoming' => [] ];
        $cursor       = 0;
        $scanned      = 0;
        $listing_tool = $activity ? Read_Support::tool_reference( 'directorist-get-listing' ) : null;
        $now          = current_datetime();
        $end          = $now->modify( '+7 days' );
        do {
            $rows     = Read_Support::query( "SELECT p.* FROM {$wpdb->posts} p WHERE {$where} AND p.ID > " . (int) $cursor . ' ORDER BY p.ID ASC LIMIT 128' );
            $scanned += count( $rows );
            Validation::check( $scanned <= self::LISTING_BUDGET, __( 'This listing scope exceeds the native object-permission scan budget. Select a directory or use a narrower source.', 'directorist' ), 'unsupported_operation' );
            if ( ! $rows ) {
                break;
            }
            $ids       = array_map( 'intval', array_column( $rows, 'ID' ) );
            $keys      = [ '_expiry_date', '_never_expire', directorist_get_listing_views_count_meta_key() ];
            $meta_rows = Read_Support::query( $wpdb->prepare( "SELECT post_id,meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id IN (" . implode( ',', $ids ) . ') AND meta_key IN (%s,%s,%s) ORDER BY meta_id ASC', ...$keys ) );
            $meta      = [];
            foreach ( $meta_rows as $item ) {
                if ( ! isset( $meta[ $item['post_id'] ][ $item['meta_key'] ] ) ) {
                    $meta[ $item['post_id'] ][ $item['meta_key'] ] = $item['meta_value'];
                }
            }
            foreach ( $rows as $row ) {
                $id     = (int) $row['ID'];
                $cursor = $id;
                $cached = wp_cache_get( $id, 'posts' );
                if ( ! $cached ) {
                    wp_cache_add( $id, new \WP_Post( (object) $row ), 'posts' );
                }
                try {
                    Access_Control::listing( $id );
                    $status = $row['post_status'];
                    ++$out['counts'][ $status ];
                    if ( 'publish' === $status ) {
                        $views = $meta[ $id ][ $keys[2] ] ?? '0';
                        if ( ! ctype_digit( (string) $views ) || strlen( (string) $views ) >= 15 ) {
                            $out['views_complete'] = false;
                        }
                        $views                 = ctype_digit( (string) $views ) && strlen( (string) $views ) < 15 ? (int) $views : 0;
                        $out['views']         += $views;
                        $out['distribution'][] = $views;
                        $date                  = substr( $row['post_date'], 0, 10 );
                        if ( $date >= $range['start_date'] && $date <= $range['end_date'] ) {
                            $out['daily'][ $date ] = ( $out['daily'][ $date ] ?? 0 ) + 1;
                        }
                        $expiry  = $meta[ $id ]['_expiry_date'] ?? '';
                        $expires = self::expiry( $expiry );
                        if ( empty( $meta[ $id ]['_never_expire'] ) && $expires && $expires >= $now && $expires < $end ) {
                            ++$out['expiring'];
                            if ( $activity ) {
                                $out['upcoming'][] = Activity_Data::event( 'expiry', $id, 'listing', $expires->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ), true, $listing_tool );
                            }
                        }
                    }
                    if ( $activity ) {
                        $timestamp = $row['post_modified_gmt'] !== '0000-00-00 00:00:00' ? $row['post_modified_gmt'] : $row['post_date_gmt'];
                        if ( $timestamp >= $range['start_utc'] && $timestamp < $range['end_exclusive_utc'] ) {
                            $out['activity'][] = Activity_Data::event( 'listing', $id, 'listing', $timestamp, false, $listing_tool );
                        }
                    }
                } catch ( Failure $error ) {
                    if ( ! in_array( $error->reason, [ 'not_found', 'forbidden' ], true ) ) {
                        throw $error;
                    }
                } finally {
                    if ( ! $cached ) {
                        wp_cache_delete( $id, 'posts' );
                    }
                }
            }
            Activity_Data::trim( $out['activity'] );
            Activity_Data::trim( $out['upcoming'] );
        } while ( count( $rows ) === 128 );
        // Equal-count buckets of listings ranked by lifetime views, never time buckets.
        $distribution = $out['distribution'];
        rsort( $distribution, SORT_NUMERIC );
        $out['distribution'] = [];
        if ( $distribution ) {
            foreach ( array_chunk( $distribution, (int) ceil( count( $distribution ) / 8 ) ) as $bucket ) {
                $out['distribution'][] = array_sum( $bucket );
            }
        }
        return $out;
    }

    /**
     * Expiry.
     *
     * @param mixed $value Value.
     */
    public static function expiry( $value ): ?\DateTimeImmutable {
        if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}(?: \d{2}:\d{2}:\d{2})?$/', $value ) ) {
            return null;
        }
        $format = strlen( $value ) === 10 ? '!Y-m-d' : '!Y-m-d H:i:s';
        $date   = \DateTimeImmutable::createFromFormat( $format, $value, wp_timezone() );
        return $date && $date->format( substr( $format, 1 ) ) === $value ? $date : null;
    }
}
