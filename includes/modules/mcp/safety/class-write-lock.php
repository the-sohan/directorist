<?php
/**
 * Serialize cooperating native and MCP writes using one database advisory lock.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Safety;

use Directorist\MCP\Runtime\Request_Context;
use Directorist\MCP\Support\Failure;
use Directorist\MCP\Support\Validation;

defined( 'ABSPATH' ) || exit;

/** Serialize cooperating native and MCP writes using one database advisory lock. */
class Write_Lock {
    private static $locked = false;

    /** Is locked. */
    public static function is_locked(): bool {
        return (bool) self::$locked;
    }

    /** Lock. */
    public static function lock(): void {
        if ( self::$locked ) {
            return;
        }
        global $wpdb;
        $name = 'directorist_mcp_' . md5( DB_NAME . ':' . $wpdb->prefix );
        $got  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $name ) );
        Validation::check( '1' === (string) $got, __( 'Another Directorist operation is in progress. Retry shortly.', 'directorist' ), 'operation_in_progress' );
        self::$locked = $name;
    }

    /** Unlock. */
    public static function unlock(): void {
        if ( self::$locked ) {
            global $wpdb;
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::$locked ) );
            self::$locked = false;
        }
    }

    /** Native lock. */
    public static function native_lock(): void {
        if ( ! self::$locked && Request_Context::enabled() && Request_Context::dependencies() ) {
            try {
                self::lock();
            } catch ( Failure $error ) {
                wp_die( esc_html( $error->getMessage() ), esc_html__( 'Directorist is busy', 'directorist' ), [ 'response' => 409 ] );
            }
            register_shutdown_function( [ self::class, 'unlock' ] );
        }
    }
}
