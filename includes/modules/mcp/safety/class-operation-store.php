<?php
/**
 * Clean expired preview and replay records without removing durable financial intents.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Safety;

defined( 'ABSPATH' ) || exit;

/** Clean expired preview and replay records without removing durable financial intents. */
class Operation_Store {
    /**
     * Cleanup.
     *
     * @param int $cursor Cursor.
     */
    public static function cleanup( int $cursor = 0 ): void {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_id, option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_id > %d ORDER BY option_id ASC LIMIT 500", $wpdb->esc_like( '_directorist_mcp_op_' ) . '%', (int) $cursor ) );
        foreach ( $rows as $row ) {
            $name   = $row->option_name;
            $record = get_option( $name );
            if ( is_array( $record ) && ( $record['expires'] ?? 0 ) < time() ) {
                delete_option( $name );
            }
        }
        if ( 500 === count( $rows ) ) {
            wp_schedule_single_event( time() + 60, 'directorist_mcp_cleanup', [ (int) end( $rows )->option_id ] );
        }
    }
}
