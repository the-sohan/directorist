<?php
/**
 * Focused handoff regressions on a disposable, enabled local installation.
 * Run: MCP_TEST_CA=/path/to/local-ca.crt wp eval-file wp-content/plugins/directorist/tests/mcp-handoff.php
 * Tests restore fixtures and revoke their application password in finally.
 *
 * @package Directorist\MCP
 */

use Directorist\MCP\Runtime\Access_Control;
use Directorist\MCP\Runtime\Tool_Discovery;
use Directorist\MCP\Runtime\Tool_Runner;
use Directorist\MCP\Safety\Operation_Store;
use Directorist\MCP\Safety\Write_Lock;
use Directorist\MCP\Safety\Write_Readiness;
use Directorist\MCP\Support\Canonical_Data;
use Directorist\MCP\Support\Failure;
use Directorist\MCP\Tool_Providers\Directories\Builder_Operations;
use Directorist\MCP\Tool_Providers\Directories\Directory_Configuration;
use Directorist\MCP\Transport\Origin_Policy;

defined( 'ABSPATH' ) || exit;

( static function () {
    if ( 'local' !== wp_get_environment_type() || ! Directorist\MCP\Runtime\Request_Context::enabled() ) {
        throw new RuntimeException( 'Use an enabled disposable local installation.' );
    }
    global $wpdb;
    $checks    = 0;
    $expect    = static function ( $condition, $message ) use ( &$checks ) {
        ++$checks;
        if ( ! $condition ) {
            throw new RuntimeException( $message );
        }
    };
    $denied    = static function ( callable $callback ) use ( $expect ) {
        try {
            $callback();
        } catch ( Failure $error ) {
            $expect( true, '' );
            return;
        }
        $expect( false, 'Expected rejection.' );
    };
    $old_actor = get_current_user_id();
    $admin     = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0];
    wp_set_current_user( $admin->ID );
    $label             = 'mcp-handoff-' . wp_generate_uuid4();
    $operation_keys    = [];
    $listings          = [];
    $order_id          = 0;
    $builder_directory = 0;
    $password_id       = '';
    $mu_file           = WPMU_PLUGIN_DIR . '/' . $label . '.php';
    $mu_created        = ! is_dir( WPMU_PLUGIN_DIR );
    $session           = '';
    $sequence          = 0;
    $preview_tokens    = [];
    $fault             = null;
    try {
        $expect( Write_Readiness::status( [], true )['ready'], 'The control host must support MCP writes.' );
        $origin       = wp_parse_url( home_url(), PHP_URL_SCHEME ) . '://' . wp_parse_url( home_url(), PHP_URL_HOST );
        $port         = wp_parse_url( home_url(), PHP_URL_PORT );
        $origin      .= $port ? ':' . $port : '';
        $other_scheme = preg_replace( '#^https?://#', 0 === strpos( $origin, 'https://' ) ? 'http://' : 'https://', $origin );
        foreach ( [ null, $origin, str_replace( '://', '://', strtoupper( $origin ) ) ] as $value ) {
            $request = new WP_REST_Request( 'POST', '/directorist/mcp' );
            if ( null !== $value ) {
                $request->set_header( 'Origin', $value );
            }
            Origin_Policy::validate( $request );
            ++$checks;
        }
        foreach ( [ '', 'null', 'https://untrusted.example', $other_scheme, $origin . '.evil.example', $origin . '/', $origin . '?x', $origin . '#x', $origin . ':444', $origin . ' ' . $origin, $origin . ',' . $origin, 'https://user@' . wp_parse_url( home_url(), PHP_URL_HOST ), 'https://%64irectorist.local', 'https://directorist.local\\@evil.example', [ $origin, $origin ] ] as $value ) {
            $denied(
                static function () use ( $value ) {
                    $request = new WP_REST_Request();
                    $request->set_header( 'Origin', $value );
                    Origin_Policy::validate( $request );
                }
            );
        }
        $trusted = static function () { return [ 'https://browser.example:8443/subdirectory' ]; };
        add_filter( 'directorist_mcp_trusted_origins', $trusted );
        $request = new WP_REST_Request();
        $request->set_header( 'Origin', 'https://BROWSER.example:8443' );
        Origin_Policy::validate( $request );
        $request->set_header( 'Origin', 'https://browser.example' );
        $denied( static function () use ( $request ) { Origin_Policy::validate( $request ); } );
        remove_filter( 'directorist_mcp_trusted_origins', $trusted );

        $definitions = Directory_Configuration::definitions();
        $values      = array_map( [ Directory_Configuration::class, 'default_value' ], $definitions );
        foreach ( [ 'field', 'section' ] as $entity ) {
            $op = [ 'action' => 'add', 'entity' => $entity, 'configuration' => 'submission_form_fields', 'widget' => 'text', 'section_id' => 'section-0', 'changes' => [ 'label' => $label ] ];
            if ( 'section' === $entity ) {
                unset( $op['widget'] );
            }
            $denied( static function () use ( $values, $op ) { Builder_Operations::operation( $values, 'submission_form', $op ); } );
            $op['id'] = $label . '-' . $entity;
            $first    = $values;
            $second   = $values;
            Builder_Operations::operation( $first, 'submission_form', $op );
            Builder_Operations::operation( $second, 'submission_form', $op );
            $expect( $first === $second, 'Builder preparation changed stable identities.' );
            $denied( static function () use ( $first, $op ) { Builder_Operations::operation( $first, 'submission_form', $op ); } );
        }

        $directory = (int) directorist_get_default_directory();
        $args      = [ 'directory_id' => $directory, 'status' => 'draft', 'fields' => [ 'title' => $label ], 'idempotency_key' => $label ];
        $created   = Tool_Runner::execute( 'create-listing', $args );
        $expect( ! is_wp_error( $created ), 'Control listing creation failed: ' . ( is_wp_error( $created ) ? $created->get_error_message() : '' ) );
        $listings[]       = $created['listing_id'];
        $key              = '_directorist_mcp_op_' . Canonical_Data::hash( [ get_current_blog_id(), get_current_user_id(), 'create-listing', $label ] );
        $operation_keys[] = $key;
        $replay           = Tool_Runner::execute( 'create-listing', $args );
        $expect( true === $replay['idempotent_replay'] && $created['listing_id'] === $replay['listing_id'], 'Completed exact retry did not replay.' );
        $changed                     = $args;
        $changed['fields']['title'] .= '-changed';
        $conflict                    = Tool_Runner::execute( 'create-listing', $changed );
        $expect( is_wp_error( $conflict ) && 'idempotency_conflict' === $conflict->get_error_data()['code'], 'Changed payload reused a key.' );
        $record            = get_option( $key );
        $record['state']   = 'running';
        $record['expires'] = time() - 1;
        unset( $record['result'] );
        update_option( $key, $record, false );
        Operation_Store::cleanup();
        $expect( false !== get_option( $key ), 'Cleanup forgot an unresolved reservation.' );
        $unknown = Tool_Runner::execute( 'create-listing', $args );
        $expect( is_wp_error( $unknown ) && 'outcome_unknown' === $unknown->get_error_data()['code'], 'Expired unresolved operation executed again.' );
        $denied( static function () use ( $args, $admin, $label ) { Operation_Store::resolve( 'create-listing', $args, (int) $admin->ID, $label, 'wrong-correlation', 'Verified native listing' ); } );
        Operation_Store::resolve( 'create-listing', $args, (int) $admin->ID, $label, $record['request_id'], 'Verified test listing ' . $created['listing_id'] );
        Operation_Store::cleanup();
        $resolved = Tool_Runner::execute( 'create-listing', $args );
        $expect( is_wp_error( $resolved ) && 'operation_reconciled' === $resolved->get_error_data()['code'], 'Resolved marker permitted redispatch.' );
        $completed_key    = $key . '-completed';
        $operation_keys[] = $completed_key;
        add_option( $completed_key, [ 'state' => 'done', 'expires' => time() - 1 ], '', false );
        Operation_Store::cleanup();
        $expect( false === get_option( $completed_key ), 'Completed cache retention became unbounded.' );
        wp_set_current_user( 0 );
        $denied( static function () { Operation_Store::unresolved(); } );
        wp_set_current_user( $admin->ID );

        Write_Lock::unlock();
        $fault = static function ( $sql ) { return 0 === strpos( $sql, 'SELECT GET_LOCK(' ) ? 'SELECT NULL' : $sql; };
        add_filter( 'query', $fault, PHP_INT_MAX );
        $expect( ! Write_Readiness::status( [], true )['ready'], 'Unavailable locking passed preflight.' );
        $unavailable = Tool_Runner::execute( 'create-listing', $args );
        $expect( is_wp_error( $unavailable ) && 'write_storage_unavailable' === $unavailable->get_error_data()['code'], 'Unavailable MCP write was not rejected.' );
        $definition = Directorist\MCP\Registration\Core_Tool_Provider::definitions()['create-listing'];
        $expect( ! Tool_Discovery::available( 'create-listing', $definition ), 'Unavailable write remained discoverable.' );
        $ability = wp_get_ability( 'directorist/create-listing' );
        $expect( is_wp_error( $ability->check_permissions( $args ) ), 'Alternate ability permission bypassed readiness.' );
        $expect( ! is_wp_error( Tool_Runner::execute( 'get-context' ) ), 'Unsupported locking disabled reads.' );
        $native = wp_update_post( [ 'ID' => $created['listing_id'], 'post_excerpt' => $label ], true );
        $expect( $native === $created['listing_id'], 'Unsupported optional locking broke a native write.' );
        remove_filter( 'query', $fault, PHP_INT_MAX );
        $fault = null;
        $expect( Write_Readiness::status( [], true )['ready'], 'Readiness did not recover.' );
        $missing = static function ( $sql ) { return false !== strpos( $sql, 'information_schema.TABLES' ) ? "SELECT 'MyISAM'" : $sql; };
        add_filter( 'query', $missing, PHP_INT_MAX );
        $expect( ! Write_Readiness::status( [], true )['ready'], 'Nontransactional storage passed preflight.' );
        remove_filter( 'query', $missing, PHP_INT_MAX );
        Write_Readiness::status( [], true );
        $peer      = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
        $lock_name = 'directorist_mcp_' . md5( DB_NAME . ':' . $wpdb->prefix );
        $expect( '1' === (string) $peer->get_var( $peer->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) ), 'Could not acquire the contention fixture lock.' );
        $started = microtime( true );
        try {
            $expect( Write_Readiness::status( [], true )['ready'], 'A busy product lock was mistaken for unsupported storage.' );
            Write_Lock::lock();
            $expect( false, 'Contended lock was accepted.' );
        } catch ( Failure $error ) {
            $expect( 'operation_in_progress' === $error->reason && $error->details['retryable'], 'Contention was reported as incompatibility.' );
            $expect( microtime( true ) - $started < 8, 'Product lock contention was unbounded.' );
        } finally {
            $peer->get_var( $peer->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
            $peer->close();
        }
        Write_Lock::lock();
        $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
        $denied( static function () { Write_Lock::lock(); } );
        Write_Lock::unlock();
        $expect( ! Write_Readiness::status( [ $wpdb->prefix . 'mcp_handoff_missing_table' ], true )['ready'], 'A missing participant table passed readiness.' );

        list( $password, $item ) = WP_Application_Passwords::create_new_application_password( $admin->ID, [ 'name' => $label ] );
        $password_id             = $item['uuid'];
        $http                    = static function ( $method, $params = [], $http_method = 'POST', $browser_origin = null, $authenticate = true ) use ( &$session, &$sequence, $admin, $password, &$preview_tokens ) {
            Write_Lock::unlock();
            $headers = [ 'Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-06-18' ];
            if ( $authenticate ) {
                $headers['Authorization'] = 'Basic ' . base64_encode( $admin->user_login . ':' . $password );
            }
            if ( $session ) {
                $headers['Mcp-Session-Id'] = $session;
            }
            if ( null !== $browser_origin ) {
                $headers['Origin'] = $browser_origin;
            }
            $options = [ 'method' => $http_method, 'timeout' => 20, 'headers' => $headers ];
            if ( getenv( 'MCP_TEST_CA' ) ) {
                $options['sslcertificates'] = getenv( 'MCP_TEST_CA' );
            }
            if ( 'POST' === $http_method ) {
                $body = [ 'jsonrpc' => '2.0', 'method' => $method, 'params' => $params ?: (object) [] ];
                if ( 'notifications/initialized' !== $method ) {
                    $body['id'] = ++$sequence;
                }
                $options['body'] = wp_json_encode( $body );
            }
            $response = wp_remote_request( rest_url( 'directorist/mcp' ), $options );
            if ( is_wp_error( $response ) ) {
                throw new RuntimeException( $response->get_error_message() );
            }
            $session = wp_remote_retrieve_header( $response, 'mcp-session-id' ) ?: $session;
            $data    = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( isset( $data['result']['structuredContent']['confirm_token'] ) ) {
                $preview_tokens[] = $data['result']['structuredContent']['confirm_token'];
            }
            return [ 'status' => wp_remote_retrieve_response_code( $response ), 'data' => $data ];
        };
        $initialize              = [ 'protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => [ 'name' => $label, 'version' => '1.0' ] ];
        $expect( 403 === $http( 'initialize', $initialize, 'POST', 'https://untrusted.example' )['status'], 'Hostile Origin initialized over HTTP.' );
        $expect( 200 === $http( 'initialize', $initialize, 'POST', $origin )['status'] && $session, 'Trusted Origin did not initialize.' );
        $http( 'notifications/initialized' );
        foreach ( [ 'POST', 'GET', 'DELETE' ] as $method ) {
            $expect( 403 === $http( 'tools/call', [ 'name' => 'directorist-get-context', 'arguments' => (object) [] ], $method, 'null' )['status'], 'Invalid Origin reached ' . $method . ' over HTTP.' );
        }
        $read = $http( 'tools/call', [ 'name' => 'directorist-get-context', 'arguments' => (object) [] ] );
        $expect( isset( $read['data']['result']['structuredContent']['user'] ), 'Authenticated non-browser read failed.' );
        $expect( in_array( $http( 'tools/call', [ 'name' => 'directorist-get-context' ], 'POST', $origin, false )['status'], [ 401, 403 ], true ), 'Origin approval granted authentication.' );
        $forbidden = $http( 'tools/call', [ 'name' => 'directorist-get-directory', 'arguments' => [ 'directory_id' => PHP_INT_MAX ] ] );
        $expect( true === $forbidden['data']['result']['isError'] && 'not_found' === $forbidden['data']['result']['structuredContent']['code'], 'Permission failure lost its machine-readable code: ' . wp_json_encode( $forbidden ) );
        $validation = $http( 'tools/call', [ 'name' => 'directorist-get-context', 'arguments' => [ 'unknown' => true ] ] )['data']['result'];
        $expect( true === $validation['isError'] && isset( $validation['structuredContent']['code'] ) && false === $validation['structuredContent']['committed'], 'Validation failure lost its machine-readable distinction.' );

        $term = wp_insert_term( $label, ATBDP_DIRECTORY_TYPE );
        $expect( ! is_wp_error( $term ), 'Builder directory fixture failed.' );
        $builder_directory                = (int) $term['term_id'];
        $builder_args                     = [
            'directory_id' => $builder_directory,
            'area'         => 'submission_form',
            'operations'   => [
                [ 'action' => 'add', 'entity' => 'section', 'configuration' => 'submission_form_fields', 'id' => $label . '-section', 'changes' => [ 'label' => $label ] ],
                [ 'action' => 'add', 'entity' => 'field', 'configuration' => 'submission_form_fields', 'id' => $label . '-field', 'widget' => 'text', 'section_id' => $label . '-section', 'changes' => [ 'label' => $label ] ],
            ],
            'dry_run'      => true,
        ];
        $builder_preview                  = $http( 'tools/call', [ 'name' => 'directorist-update-builder', 'arguments' => $builder_args ] )['data']['result']['structuredContent'];
        $expected                         = $builder_preview['changes']['changes']['submission_form_fields'];
        $builder_args['expected_version'] = $builder_preview['changes']['version'];
        $builder_args['confirm_token']    = $builder_preview['confirm_token'];
        $builder_args['idempotency_key']  = $label . '-builder';
        unset( $builder_args['dry_run'] );
        $saved = $http( 'tools/call', [ 'name' => 'directorist-update-builder', 'arguments' => $builder_args ] )['data']['result'];
        $expect( ! ( $saved['isError'] ?? false ), 'Confirmed builder execution failed.' );
        $configuration = $http( 'tools/call', [ 'name' => 'directorist-get-directory', 'arguments' => [ 'directory_id' => $builder_directory, 'area' => 'submission_form' ] ] )['data']['result']['structuredContent']['configuration']['submission_form_fields'];
        $field_id      = $label . '-field';
        $expect( array_keys( $expected['fields'] ) === array_keys( $configuration['fields'] ) && $expected['fields'][ $field_id ]['widget_key'] === $configuration['fields'][ $field_id ]['widget_key'] && $expected['fields'][ $field_id ]['field_key'] === $configuration['fields'][ $field_id ]['field_key'], 'HTTP field identity or storage key changed after preview.' );
        $expect( array_column( $expected['groups'], 'id' ) === array_column( $configuration['groups'], 'id' ) && array_column( $expected['groups'], 'fields' ) === array_column( $configuration['groups'], 'fields' ), 'HTTP section identity or field reference changed after preview.' );
        $operation_keys[] = '_directorist_mcp_op_' . Canonical_Data::hash( [ get_current_blog_id(), get_current_user_id(), 'update-builder', $label . '-builder' ] );

        // A disposable native order and process-local MU fault exercise the real
        // durable financial response/retry path without charging or refunding.
        $dto      = ( new Directorist\DTO\Order\DTO() )->set_user_id( (int) $admin->ID )->set_amount( 19.99 )->set_sub_total( 19.99 )->set_currency( 'USD' )->set_status( 'pending' )->set_ref_type( 'featured_listing' )->set_coupon_discount( 0 )->set_coupon_discount_type( 'fixed' )->set_tax_type( 'fixed' )->set_tax_rate( 0 );
        $order_id = directorist_order_repository()->create( $dto );
        $expect( $order_id > 0, 'Native order fixture failed.' );
        wp_mkdir_p( WPMU_PLUGIN_DIR );
        $fixture = '<?php add_action("directorist_mcp_before_native_order_write", static function () { if ("local" === wp_get_environment_type() && Directorist\\MCP\\Safety\\Write_Guard::is_mutating() && ' . (int) $admin->ID . ' === get_current_user_id()) { throw new RuntimeException("Controlled local handoff fault"); } }, PHP_INT_MAX);';
        file_put_contents( $mu_file, $fixture );
        $preview        = $http( 'tools/call', [ 'name' => 'directorist-cancel-order', 'arguments' => [ 'order_id' => $order_id, 'dry_run' => true ] ] )['data']['result']['structuredContent'];
        $financial_args = [ 'order_id' => $order_id, 'expected_version' => $preview['changes']['version'], 'confirm_token' => $preview['confirm_token'], 'idempotency_key' => $label . '-financial' ];
        $failed         = $http( 'tools/call', [ 'name' => 'directorist-cancel-order', 'arguments' => $financial_args ] )['data']['result'];
        unlink( $mu_file );
        $expect( true === $failed['isError'] && 'outcome_unknown' === $failed['structuredContent']['code'] && isset( $failed['structuredContent']['operation_id'] ), 'HTTP unknown outcome lost recovery data.' );
        $identity = $failed['structuredContent'];
        $retried  = $http( 'tools/call', [ 'name' => 'directorist-cancel-order', 'arguments' => $financial_args ] )['data']['result']['structuredContent'];
        $expect( $identity['operation_id'] === $retried['operation_id'] && $identity['request_id'] === $retried['request_id'] && $identity['attempt_id'] !== $retried['attempt_id'], 'HTTP retry changed operation identity/correlation.' );
        $recovery = $http( 'tools/call', [ 'name' => 'directorist-get-order-operation', 'arguments' => [ 'operation_id' => $identity['operation_id'] ] ] )['data']['result']['structuredContent'];
        $expect( $identity['operation_id'] === $recovery['operation_id'] && 'unknown' === $recovery['state'], 'Client could not reconcile using the response alone.' );
        $expect( 'pending' === directorist_order_repository()->get_by_id( $order_id )->status, 'Fault/retry dispatched the native effect.' );
        $operation_keys[] = '_directorist_mcp_order_operation_' . $identity['operation_id'];
        $operation_keys[] = '_directorist_mcp_financial_order_' . $order_id . '_' . $identity['operation_id'];
        $operation_keys[] = '_directorist_mcp_financial_key_' . Canonical_Data::hash( [ get_current_blog_id(), get_current_user_id(), 'cancel-order', $label . '-financial' ] );
        $operation_keys[] = '_directorist_mcp_op_' . Canonical_Data::hash( [ get_current_blog_id(), get_current_user_id(), 'cancel-order', $label . '-financial' ] );
        $http( '', [], 'DELETE', $origin );
        echo 'PASS: ' . (int) $checks . ' handoff checks; adapter ' . esc_html( WP\MCP\Core\McpAdapter::VERSION ) . "; HTTP recovery, retries, Origin, builder IDs, cleanup, reconciliation and write readiness.\n";
    } finally {
        if ( $fault ) {
            remove_filter( 'query', $fault, PHP_INT_MAX );
        }
        if ( is_file( $mu_file ) ) {
            unlink( $mu_file );
        }
        if ( $mu_created && is_dir( WPMU_PLUGIN_DIR ) ) {
            rmdir( WPMU_PLUGIN_DIR );
        }
        if ( $password_id ) {
            WP_Application_Passwords::delete_application_password( $admin->ID, $password_id );
        }
        wp_set_current_user( $admin->ID );
        Write_Readiness::status( [], true );
        foreach ( $listings as $id ) {
            wp_delete_post( $id, true );
        }
        if ( $order_id ) {
            $wpdb->delete( $wpdb->prefix . 'directorist_orders', [ 'id' => $order_id ], [ '%d' ] );
        }
        if ( $builder_directory ) {
            wp_delete_term( $builder_directory, ATBDP_DIRECTORY_TYPE );
        }
        foreach ( [ 'create-listing' => $label, 'update-builder' => $label . '-builder', 'cancel-order' => $label . '-financial' ] as $tool => $input_key ) {
            $operation_keys[] = '_directorist_mcp_op_' . Canonical_Data::hash( [ get_current_blog_id(), get_current_user_id(), $tool, $input_key ] );
        }
        $financial_key = '_directorist_mcp_financial_key_' . Canonical_Data::hash( [ get_current_blog_id(), get_current_user_id(), 'cancel-order', $label . '-financial' ] );
        $financial_id  = get_option( $financial_key );
        if ( $financial_id ) {
            $operation_keys[] = $financial_key;
            $operation_keys[] = '_directorist_mcp_order_operation_' . $financial_id;
            $operation_keys[] = '_directorist_mcp_financial_order_' . $order_id . '_' . $financial_id;
        }
        foreach ( $operation_keys as $key ) {
            delete_option( $key );
        }
        foreach ( $preview_tokens as $token ) {
            delete_transient( 'directorist_mcp_preview_' . hash( 'sha256', $token ) );
        }
        Write_Lock::unlock();
        wp_set_current_user( $old_actor );
    }
} )();
