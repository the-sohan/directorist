<?php
/**
 * Authorization-only featured checkout integration regressions.
 *
 * Run with WP-CLI on a local/test site; see this directory's README.md.
 * No production code includes this file. Fixtures are removed in finally.
 *
 * @package Directorist
 */

defined( 'ABSPATH' ) && defined( 'WP_CLI' ) && WP_CLI || exit;

use Directorist\Contracts\PaymentInterface;
use Directorist\DTO\Order\DTO as OrderDTO;
use Directorist\DTO\Payment\DTO as PaymentDTO;
use Directorist\Enums\Order\Status;

if ( version_compare( get_bloginfo( 'version' ), '5.7', '<' ) ) {
    WP_CLI::error( 'These tests require WordPress 5.7+ to suppress fixture email through pre_wp_mail.' );
}

$directorist_test_environment = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
$directorist_test_host        = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
$directorist_test_local       = in_array( $directorist_test_host, [ 'localhost', '127.0.0.1', '::1', '[::1]' ], true )
    || '.test' === substr( $directorist_test_host, -5 ) || '.local' === substr( $directorist_test_host, -6 );

if ( ! $directorist_test_local && ! in_array( $directorist_test_environment, [ 'local', 'development', 'staging' ], true ) ) {
    WP_CLI::error( 'Use an authorized local/test installation. This suite refuses production sites.' );
}
if ( ! class_exists( OrderDTO::class ) || ! function_exists( 'directorist_user_can_checkout_listing' ) ) {
    WP_CLI::error( 'Activate Directorist with the checkout authorization fix before running this suite.' );
}

/** Exercise gateway dispatch while keeping payment processing entirely local. */
class Directorist_Authorization_Test_Processor implements PaymentInterface {
    public static $calls = [];

    public static function get_key(): string {
        return 'directorist_authorization_test';
    }

    public function supports_recurring(): bool {
        return false;
    }

    public function supports_trial(): bool {
        return false;
    }

    public function pay( OrderDTO $dto, array $params = [] ): ?string {
        self::$calls[] = $dto->get_id();
        directorist_payment_repository()->create(
            ( new PaymentDTO() )
                ->set_order_id( $dto->get_id() )
                ->set_amount( directorist_order_payable( $dto ) )
                ->set_currency( $dto->get_currency() )
                ->set_status( Status::PENDING )
                ->set_method( self::get_key() )
                ->set_transaction_id( 'local-authorization-fixture' )
        );
        return home_url( '/?directorist-authorization-fixture=' . $dto->get_id() );
    }
}

/** Real REST/repository checks with isolated configuration and fixture ownership. */
class Directorist_Checkout_Authorization_Test {
    private $users = [];

    private $posts = [];

    private $results = [];

    private $options;

    private $original_user;

    private $prefix;

    private $route;

    private $last_response;

    public function __construct() {
        $this->original_user                      = get_current_user_id();
        $this->prefix                             = 'directorist-auth-' . substr( wp_generate_uuid4(), 0, 8 );
        $this->options                            = (array) get_option( 'atbdp_option', [] );
        $this->options['enable_monetization']     = 1;
        $this->options['enable_featured_listing'] = 1;
        $this->options['featured_listing_price']  = '0';
        add_filter( 'pre_option_atbdp_option', [ $this, 'options' ], PHP_INT_MAX );
        add_filter( 'pre_wp_mail', [ $this, 'block_mail' ], PHP_INT_MAX );
        add_filter( 'pre_http_request', [ $this, 'block_http' ], PHP_INT_MAX );
        add_filter( 'directorist_payment_processors', [ $this, 'processors' ], PHP_INT_MAX );
    }

    public function options() {
        return $this->options;
    }

    public function block_mail() {
        return true;
    }

    public function block_http() {
        return new WP_Error( 'test_http_blocked', 'Authorization tests do not make outbound HTTP requests.' );
    }

    public function processors( $processors ) {
        $processors[ Directorist_Authorization_Test_Processor::get_key() ] = Directorist_Authorization_Test_Processor::class;
        return $processors;
    }

    private function check( $condition, $message ) {
        if ( ! $condition && $this->last_response ) {
            $message .= ' (last REST response: ' . $this->last_response->get_status() . ' ' . wp_json_encode( $this->last_response->get_data() ) . ')';
        }
        $this->results[] = [ (bool) $condition, $message ];
    }

    public function failure( Throwable $error ) {
        $this->check( false, get_class( $error ) . ': ' . $error->getMessage() );
    }

    private function scenario( callable $test ) {
        try {
            $test();
        } catch ( Throwable $error ) {
            $this->failure( $error );
        }
    }

    private function listing( $owner ) {
        $id = wp_insert_post( [ 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'pending', 'post_author' => $owner, 'post_title' => $this->prefix . ' ' . count( $this->posts ) ], true );
        if ( is_wp_error( $id ) ) {
            throw new RuntimeException( $id->get_error_message() );
        }
        $this->posts[] = $id;
        update_post_meta( $id, '_featured', '0' );
        return $id;
    }

    private function request( $user, array $params, $retry = false ) {
        wp_set_current_user( $user );
        $request = new WP_REST_Request( 'POST', $this->route . ( $retry ? '/retry-payment' : '' ) );
        $request->set_body_params( $params );
        $this->last_response = rest_do_request( $request );
        return $this->last_response;
    }

    private function checkout( $user, $listing, $gateway = false ) {
        $params = [ 'checkout_type' => 'featured_listing', 'listing_id' => $listing ];
        if ( $gateway ) {
            $params['payment_gateway'] = Directorist_Authorization_Test_Processor::get_key();
        }
        return $this->request( $user, $params );
    }

    private function orders() {
        return $this->users ? directorist_order_repository()->get_query_builder()->select( 'd_order.*' )->where_in( 'd_order.user_id', array_values( $this->users ) )->order_by( 'd_order.id' )->get() : [];
    }

    private function latest_order() {
        $orders = $this->orders();
        if ( ! $orders ) {
            throw new RuntimeException( 'Expected a fixture order, but none was created.' );
        }
        return end( $orders );
    }

    private function snapshot( $listing ) {
        $orders   = $this->orders();
        $payments = [];
        foreach ( $orders as $order ) {
            $payments[ $order->id ] = directorist_payment_repository()->get( $order->id );
        }
        return serialize( [ get_post( $listing, ARRAY_A ), get_post_meta( $listing ), $orders, $payments ] );
    }

    private function deny( $user, $listing, array $params, $message, $retry = false, $expected = 403 ) {
        $before = $this->snapshot( $listing );
        $calls  = count( Directorist_Authorization_Test_Processor::$calls );
        $result = $this->request( $user, $params, $retry );
        $this->check( $expected === $result->get_status(), $message . ': HTTP ' . $expected );
        $this->check( $before === $this->snapshot( $listing ), $message . ': listing, metadata, order and payment rows unchanged' );
        $this->check( $calls === count( Directorist_Authorization_Test_Processor::$calls ), $message . ': gateway never invoked' );
    }

    private function order( $user, $listing, $featured = true, $amount = 25 ) {
        $dto = ( new OrderDTO() )->set_user_id( $user )->set_listing_id( $listing )->set_is_featured_listing( $featured ? 1 : 0 )
            ->set_ref_type( $featured ? 'featured_listing' : 'test_other_product' )->set_status( Status::PENDING )
            ->set_currency( 'USD' )->set_amount( $amount )->set_sub_total( $amount );
        directorist_order_repository()->create( $dto );
        return $dto;
    }

    private function add_payment( OrderDTO $order ) {
        ( new Directorist_Authorization_Test_Processor() )->pay( $order );
    }

    public function run() {
        $registered = rest_get_server()->get_routes();
        $routes     = array_values(
            array_filter(
                [ '/directorist/v2/checkout', '/directorist/v1/checkout' ], static function ( $route ) use ( $registered ) {
                    return isset( $registered[ $route ], $registered[ $route . '/retry-payment' ] );
                }
            )
        );
        if ( ! $routes || ! in_array( 'featured_listing', directorist_get_checkout_types(), true ) ) {
            throw new RuntimeException( 'Core featured checkout and retry routes must be registered; skip Pricing Plans for this test.' );
        }
        $this->route = $routes[0];
        $this->check( true, 'Registered checkout routes: ' . implode( ', ', $routes ) );

        foreach ( [ 'owner' => 'subscriber', 'stranger' => 'subscriber', 'editor' => 'editor', 'admin' => 'administrator' ] as $name => $role ) {
            $id = wp_insert_user( [ 'user_login' => $this->prefix . '-' . $name, 'user_pass' => wp_generate_password( 40 ), 'user_email' => $this->prefix . '-' . $name . '@example.invalid', 'role' => $role ] );
            if ( is_wp_error( $id ) ) {
                throw new RuntimeException( $id->get_error_message() );
            }
            $this->users[ $name ] = (int) $id;
        }
        $owner    = $this->users['owner'];
        $stranger = $this->users['stranger'];
        $editor   = $this->users['editor'];
        $admin    = $this->users['admin'];
        // Grant only this fixture user the listing editor capabilities; never edit a role.
        $editor_user = get_userdata( $editor );
        $caps        = get_post_type_object( ATBDP_POST_TYPE )->cap;
        foreach ( [ 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'edit_private_posts' ] as $cap ) {
            $editor_user->add_cap( $caps->$cap );
        }

        $this->scenario(
            function () use ( $routes, $stranger, $admin ) {
                $listing = $this->listing( $admin );
                $params  = [ 'checkout_type' => 'featured_listing', 'listing_id' => $listing, 'payment_gateway' => Directorist_Authorization_Test_Processor::get_key() ];
                foreach ( $routes as $route ) {
                      $this->route = $route;
                      $this->deny( 0, $listing, $params, 'Anonymous checkout ' . $route, false, 401 );
                    foreach ( [ 'zero' => '0', 'paid' => '25', 'blank' => '', 'unset' => null ] as $label => $price ) {
                        $this->options['featured_listing_price'] = $price;
                        if ( null === $price ) {
                              unset( $this->options['featured_listing_price'] );
                        }
                        $this->deny( $stranger, $listing, $params, 'Nonowner checkout with ' . $label . ' price ' . $route );
                    }
                }
                $this->route                             = $routes[0];
                $this->options['featured_listing_price'] = '0';
            }
        );

        $this->scenario(
            function () use ( $owner, $editor, $admin ) {
                foreach ( [ 'owner' => $owner, 'editor' => $editor, 'administrator' => $admin ] as $label => $actor ) {
                      $listing                                 = $this->listing( $owner );
                      $this->options['featured_listing_price'] = '0';
                    if ( $actor !== $owner ) {
                        $this->check( user_can( $actor, 'edit_post', $listing ), $label . ' fixture can edit the other user listing' );
                    }
                    $result = $this->checkout( $actor, $listing );
                    $order  = $this->latest_order();
                    $this->check( 200 === $result->get_status() && (int) $order->user_id === $actor && Status::PAID === $order->status && 0.0 === (float) $order->sub_total, 'Authorized ' . $label . ' zero-price checkout retains paid order behavior' );
                    $this->check( 'publish' === get_post_status( $listing ) && directorist_is_listing_featured( $listing ), 'Authorized ' . $label . ' checkout retains existing publication and featuring behavior' );
                }
                unset( $this->options['featured_listing_price'] );
                $listing = $this->listing( $owner );
                $result  = $this->checkout( $owner, $listing );
                $this->check( 200 === $result->get_status() && Status::PAID === $this->latest_order()->status, 'Owner checkout retains the existing unset-price fallback' );
                $this->options['featured_listing_price'] = '0';
            }
        );

        $this->scenario(
            function () use ( $owner ) {
                $listing  = $this->listing( $owner );
                $restrict = static function ( $caps, $cap, $user, $args ) use ( $owner, $listing ) {
                      return 'edit_post' === $cap && $owner === $user && $listing === (int) ( $args[0] ?? 0 ) ? [ 'do_not_allow' ] : $caps;
                };
                add_filter( 'map_meta_cap', $restrict, PHP_INT_MAX, 4 );
                try {
                      $this->check( ! user_can( $owner, 'edit_post', $listing ), 'Restricted owner fixture explicitly lacks WordPress edit_post capability' );
                      $result = $this->checkout( $owner, $listing );
                      $this->check( 200 === $result->get_status() && directorist_is_listing_featured( $listing ), 'Frontend ownership still authorizes checkout without WordPress editor capability' );
                } finally {
                    remove_filter( 'map_meta_cap', $restrict, PHP_INT_MAX );
                }
            }
        );

        $this->scenario(
            function () use ( $owner, $stranger, $admin ) {
                $this->options['featured_listing_price'] = '25';
                $listing                                 = $this->listing( $owner );
                $calls                                   = count( Directorist_Authorization_Test_Processor::$calls );
                $result                                  = $this->checkout( $owner, $listing, true );
                $order                                   = $this->latest_order();
                $this->check( 200 === $result->get_status() && Status::PENDING === $order->status && 25.0 === (float) $order->sub_total && $calls + 1 === count( Directorist_Authorization_Test_Processor::$calls ), 'Owner paid checkout retains gateway initiation and stored price' );
                $this->check( 'pending' === get_post_status( $listing ) && ! directorist_is_listing_featured( $listing ), 'Pending payment has not applied featured entitlement' );
                $params = [ 'order_id' => $order->id ];
                $this->deny( $stranger, $listing, $params, 'Another user cannot retry the owner order', true );
                $this->deny( 0, $listing, $params, 'Anonymous user cannot retry the owner order', true, 401 );
                $this->options['featured_listing_price'] = '';
                foreach ( [ 'owner' => $owner, 'administrator' => $admin ] as $label => $actor ) {
                      $calls  = count( Directorist_Authorization_Test_Processor::$calls );
                      $result = $this->request( $actor, $params, true );
                      $this->check( 200 === $result->get_status() && $calls + 1 === count( Directorist_Authorization_Test_Processor::$calls ), 'Authorized ' . $label . ' retry uses existing order despite changed catalog price' );
                }
                directorist_order_repository()->update_status( $order->id, Status::FAILED );
                $result = $this->request( $owner, $params, true );
                $this->check( 200 === $result->get_status(), 'Owner can retry a failed order' );
                wp_set_current_user( 0 );
                directorist_order_repository()->update_status( $order->id, Status::PAID );
                $this->check( 'publish' === get_post_status( $listing ) && directorist_is_listing_featured( $listing ), 'Callback without login authorizes the persisted customer and preserves completion behavior' );
                $before = get_post_meta( $listing );
                wp_set_current_user( $stranger );
                directorist_order_repository()->update_status( $order->id, Status::PAID );
                $this->check( $before === get_post_meta( $listing ), 'Repeated callback with unrelated current actor retains valid entitlement' );
                $this->options['featured_listing_price'] = '0';
            }
        );

        $this->scenario(
            function () use ( $owner, $stranger, $admin ) {
                $listing = $this->listing( $admin );
                $order   = $this->order( $stranger, $listing );
                $this->add_payment( $order );
                foreach ( [ 'order owner' => $stranger, 'administrator' => $admin ] as $label => $actor ) {
                      $this->deny( $actor, $listing, [ 'order_id' => $order->get_id() ], 'Poisoned featured order retry by ' . $label, true );
                }
                $this->deny( $stranger, $listing, [ 'checkout_type' => 'payment', 'order_id' => $order->get_id(), 'payment_gateway' => Directorist_Authorization_Test_Processor::get_key() ], 'Existing-order payment rejects unauthorized linked listing' );
                $before = serialize( [ get_post( $listing, ARRAY_A ), get_post_meta( $listing ) ] );
                wp_set_current_user( $admin );
                directorist_order_repository()->update_status( $order->get_id(), Status::PAID );
                $this->check( $before === serialize( [ get_post( $listing, ARRAY_A ), get_post_meta( $listing ) ] ), 'Even an administrator current actor cannot make a poisoned customer order feature or publish a listing' );
                directorist_set_listing_featured( $listing, true );
                $before = get_post_meta( $listing );
                wp_set_current_user( 0 );
                directorist_order_repository()->update_status( $order->get_id(), Status::REFUNDED );
                $this->check( $before === get_post_meta( $listing ), 'Unauthorized historical order cannot revoke another user featured entitlement' );
                $listing = $this->listing( $owner );
                $order   = $this->order( $owner, $listing );
                $this->add_payment( $order );
                wp_update_post( [ 'ID' => $listing, 'post_author' => $stranger ] );
                $this->deny( $owner, $listing, [ 'order_id' => $order->get_id() ], 'Former owner cannot retry after listing ownership transfer', true );
                $before = get_post_meta( $listing );
                wp_set_current_user( 0 );
                directorist_order_repository()->update_status( $order->get_id(), Status::PAID );
                $this->check( $before === get_post_meta( $listing ) && 'pending' === get_post_status( $listing ), 'Callback rechecks persisted customer after listing ownership transfer' );
            }
        );

        $this->scenario(
            function () use ( $owner, $stranger ) {
                $listing = $this->listing( $owner );
                $order   = $this->order( $owner, $listing );
                $params  = [ 'checkout_type' => 'payment', 'order_id' => $order->get_id(), 'payment_gateway' => Directorist_Authorization_Test_Processor::get_key() ];
                $this->deny( $stranger, $listing, $params, 'Existing-order payment rejects another user' );
                $this->options['featured_listing_price'] = '';
                $calls                                   = count( Directorist_Authorization_Test_Processor::$calls );
                $result                                  = $this->request( $owner, $params );
                $this->check( 200 === $result->get_status() && $calls + 1 === count( Directorist_Authorization_Test_Processor::$calls ) && 25.0 === (float) directorist_get_order_by_id( $order->get_id() )->sub_total, 'Existing featured order payment keeps its original price and owner workflow' );
                $order = $this->order( $owner, 0, false );
                $this->add_payment( $order );
                $result = $this->request( $owner, [ 'order_id' => $order->get_id() ], true );
                $this->check( 200 === $result->get_status(), 'Unrelated owned product with no listing still permits payment retry' );
                $result = $this->request( $owner, [ 'checkout_type' => 'payment', 'order_id' => $order->get_id(), 'payment_gateway' => Directorist_Authorization_Test_Processor::get_key() ] );
                $this->check( 200 === $result->get_status(), 'Unrelated owned product with no listing retains existing payment checkout' );
                $this->options['featured_listing_price'] = '0';
            }
        );

        $this->scenario(
            function () use ( $owner ) {
                global $post;
                $original_post = $post;
                try {
                      $listing = $this->listing( $owner );
                      $post    = get_post( $listing );
                      $this->check( ! directorist_user_can_checkout_listing( 0, $owner ), 'Listing ID zero cannot inherit authorization from the global post' );
                      $before = get_post_meta( $listing );
                      $order  = $this->order( $owner, 0 );
                      wp_set_current_user( 0 );
                      directorist_order_repository()->update_status( $order->get_id(), Status::PAID );
                      $this->check( $before === get_post_meta( $listing ) && 'pending' === get_post_status( $listing ), 'Malformed persisted featured order cannot modify the global listing' );
                } finally {
                    $post = $original_post;
                }
            }
        );
    }

    public function cleanup() {
        global $wpdb;
        foreach ( $this->orders() as $order ) {
            $wpdb->delete( $wpdb->prefix . 'directorist_payments', [ 'order_id' => $order->id ], [ '%d' ] );
            $wpdb->delete( $wpdb->prefix . 'directorist_orders', [ 'id' => $order->id ], [ '%d' ] );
            $this->check( ! directorist_get_order_by_id( $order->id ) && ! directorist_payment_repository()->get_last_payment( $order->id ), 'Fixture order and payments removed' );
        }
        foreach ( $this->posts as $id ) {
            wp_delete_post( $id, true );
        }
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ( $this->users as $id ) {
            wp_delete_user( $id );
        }
        $this->check( ! array_filter( $this->posts, 'get_post' ) && ! array_filter( $this->users, 'get_userdata' ), 'Fixture listings, metadata, users and per-user capabilities removed' );
        wp_set_current_user( $this->original_user );
        remove_filter( 'pre_option_atbdp_option', [ $this, 'options' ], PHP_INT_MAX );
        remove_filter( 'pre_wp_mail', [ $this, 'block_mail' ], PHP_INT_MAX );
        remove_filter( 'pre_http_request', [ $this, 'block_http' ], PHP_INT_MAX );
        remove_filter( 'directorist_payment_processors', [ $this, 'processors' ], PHP_INT_MAX );
    }

    public function report() {
        $failed = 0;
        foreach ( $this->results as $result ) {
            WP_CLI::log( ( $result[0] ? 'PASS: ' : 'FAIL: ' ) . $result[1] );
            $failed += $result[0] ? 0 : 1;
        }
        if ( $failed ) {
            WP_CLI::error( $failed . ' of ' . count( $this->results ) . ' authorization checks failed.' );
        }
        WP_CLI::success( count( $this->results ) . ' authorization and cleanup checks passed.' );
    }
}

$directorist_authorization_test = new Directorist_Checkout_Authorization_Test();
try {
    $directorist_authorization_test->run();
} catch ( Throwable $directorist_test_error ) {
    $directorist_authorization_test->failure( $directorist_test_error );
} finally {
    $directorist_authorization_test->cleanup();
}
$directorist_authorization_test->report();
