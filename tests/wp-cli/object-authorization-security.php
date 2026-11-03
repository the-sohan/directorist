<?php
/** Local regression checks for listing, media, receipt and user authorization. */
defined( 'ABSPATH' ) && defined( 'WP_CLI' ) && WP_CLI || exit;
$host = wp_parse_url( home_url(), PHP_URL_HOST );
if ( ! in_array( wp_get_environment_type(), [ 'local', 'development', 'staging' ], true ) && ! preg_match( '/(\.local|\.test|^localhost|^127\.0\.0\.1)$/', $host ) ) {
    WP_CLI::error( 'Use a disposable local/test installation.' );
}
add_filter( 'pre_wp_mail', '__return_true' );
add_filter( 'pre_http_request', function () { return new WP_Error( 'test_blocked', 'No external requests during tests.' ); } );
class Directorist_Test_Form_Authorization extends ATBDP_Metabox {
    public function render_listing_meta_fields( $type, $id ) {
        throw new RuntimeException( 'authorized_form' );
    }
}
$original_user = get_current_user_id();
$users         = [];
$posts         = [];
$orders        = [];
$failures      = [];
$checks        = 0;
$check         = function ( $ok, $message ) use ( &$failures, &$checks ) {
    ++$checks;
    if ( ! $ok ) {
        $failures[] = $message; }
    WP_CLI::log( ( $ok ? 'PASS ' : 'FAIL ' ) . $message );
};
try {
    foreach ( [ 'subscriber', 'subscriber', 'administrator' ] as $role ) {
        $id = wp_insert_user( [ 'user_login' => 'directorist-security-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => $role ] );
        if ( is_wp_error( $id ) ) {
            throw new RuntimeException( $id->get_error_message() ); }
        $users[] = $id;
    }
    list( $owner, $stranger, $admin ) = $users;
    foreach ( [ $owner, $stranger ] as $id ) {
        $user = get_user_by( 'id', $id );
        foreach ( [ 'edit_at_biz_dirs', 'publish_at_biz_dirs', 'edit_published_at_biz_dirs' ] as $cap ) {
            $user->add_cap( $cap ); }
    }
    $listing = wp_insert_post( [ 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'pending', 'post_author' => $owner, 'post_title' => 'Authorization fixture' ] );
    $posts[] = $listing;
    foreach ( [ $owner, $admin ] as $author ) {
        $posts[] = wp_insert_attachment( [ 'post_title' => 'Authorization media fixture', 'post_author' => $author, 'post_mime_type' => 'application/pdf' ] );
    }
    list( , $own_media, $other_media ) = $posts;
    wp_set_current_user( $owner );
    $check( [ $own_media ] === directorist_authorize_listing_images( $listing, [ $own_media, $other_media ] ), 'Reject foreign attachment injection; retain owned media' );
    update_post_meta( $listing, '_listing_img', [ $other_media ] );
    $check( [ $other_media ] === directorist_authorize_listing_images( $listing, [ $other_media ] ), 'Retain existing admin-supplied listing media' );
    foreach ( [ 'ATBDP_Add_Listing', 'Directorist\\AddListingForm\\SubmissionController' ] as $class ) {
        $method = new ReflectionMethod( $class, 'clean_unselected_images' );
        $method->setAccessible( true );
        $method->invoke( null, $listing, [] );
        $check( null !== get_post( $other_media ), $class . ': poisoned saved ID cannot delete foreign media' );
    }
    $image_form = function () {
        return [ 'fields' => [ 'image_upload' => [ 'type' => 'media', 'field_key' => 'listing_img', 'widget_name' => 'image_upload' ] ], 'groups' => [ [ 'fields' => [ 'image_upload' ] ] ] ];
    };
    add_filter( 'directorist_submission_form_fields', $image_form, PHP_INT_MAX );
    foreach ( [ 'ATBDP_Add_Listing', 'Directorist\\AddListingForm\\SubmissionController' ] as $class ) {
        delete_post_meta( $listing, '_listing_img' );
        delete_post_meta( $listing, '_listing_prv_img' );
        $class::upload_images( $listing, [ 'directory_id' => 0, 'listing_img' => [], 'listing_img_old' => [ $own_media, $other_media ] ] );
        $check( [ $own_media ] === atbdp_get_listing_attachment_ids( $listing ), $class . ': save rejects foreign ID while keeping owned selection' );
        update_post_meta( $listing, '_listing_img', [ $other_media ] );
        $class::upload_images( $listing, [ 'directory_id' => 0, 'listing_img' => [], 'listing_img_old' => [ $own_media ] ] );
        $check( get_post( $other_media ) && [ $own_media ] === atbdp_get_listing_attachment_ids( $listing ), $class . ': removed foreign image is unlinked without deleting its media' );
        $removable = wp_insert_attachment( [ 'post_title' => 'Owned removal fixture', 'post_author' => $owner, 'post_mime_type' => 'image/jpeg' ] );
        $posts[]   = $removable;
        update_post_meta( $listing, '_listing_img', [ $removable ] );
        $class::upload_images( $listing, [ 'directory_id' => 0, 'listing_img' => [], 'listing_img_old' => [ $own_media ] ] );
        $check( ! get_post( $removable ), $class . ': ordinary owner image deletion still works' );
    }
    remove_filter( 'directorist_submission_form_fields', $image_form, PHP_INT_MAX );
    $check( directorist_user_can_delete_listing_image( $own_media ), 'Owner can remove own media' );
    wp_set_current_user( $admin );
    $check( directorist_user_can_delete_listing_image( $other_media ), 'Admin retains media deletion access' );

    rest_get_server()->get_routes();
    $controller = new Directorist\Rest_Api\Controllers\Version1\Listings_Controller();
    foreach ( [ 'publish', 'private' ] as $status ) {
        $request = new WP_REST_Request( 'POST' );
        $request->set_param( 'id', $listing );
        $request->set_param( 'status', $status );
        wp_set_current_user( $owner );
        $check( is_wp_error( $controller->update_status_permissions_check( $request ) ), 'Owner cannot move pending listing to ' . $status );
        wp_set_current_user( $admin );
        $check( true === $controller->update_status_permissions_check( $request ), 'Admin may approve pending listing to ' . $status );
    }
    wp_update_post( [ 'ID' => $listing, 'post_status' => 'private' ] );
    wp_set_current_user( $owner );
    $request->set_param( 'status', 'publish' );
    $check( true === $controller->update_status_permissions_check( $request ), 'Owner retains private/published listing toggle' );
    wp_set_current_user( $stranger );
    $check( is_wp_error( $controller->update_status_permissions_check( $request ) ), 'Another user cannot toggle listing' );

    $dto = ( new Directorist\DTO\Order\DTO() )->set_user_id( $owner )->set_listing_id( $listing )->set_is_featured_listing( 0 )->set_ref_type( 'test' )->set_status( 'pending' )->set_currency( 'USD' )->set_amount( 10 )->set_sub_total( 10 );
    directorist_order_repository()->create( $dto );
    $orders[]          = $dto->get_id();
    $_REQUEST['order'] = $dto->get_id();
    $receipt           = new ATBDP_Checkout();
    $guard             = function () { throw new RuntimeException( 'authorized_receipt' ); };
    add_filter( 'directorist_payment_receipt_order_dto', $guard );
    foreach ( [ $stranger, $owner, $admin ] as $actor ) {
        wp_set_current_user( $actor );
        $reached = false;
        try {
            $result = $receipt->payment_receipt(); } catch ( RuntimeException $e ) {
            $reached = 'authorized_receipt' === $e->getMessage(); }
            $check( ( $actor !== $stranger ) === $reached, 'Receipt authorized only for owner/admin: ' . $actor );
    }
    remove_filter( 'directorist_payment_receipt_order_dto', $guard );

    if ( ! defined( 'DOING_AJAX' ) ) {
        define( 'DOING_AJAX', true ); }
    add_filter( 'wp_die_ajax_handler', function () { return function () { throw new RuntimeException( 'denied_form' ); }; } );
    $form = new Directorist_Test_Form_Authorization();
    foreach ( [ [ $stranger, $listing, false ], [ $owner, $listing, true ], [ $admin, $listing, true ], [ $owner, 0, true ] ] as $case ) {
        wp_set_current_user( $case[0] );
        $_REQUEST['directorist_nonce'] = wp_create_nonce( directorist_get_nonce_key() );
        $_POST['listing_id']           = $case[1];
        $reached                       = false;
        ob_start();
        try {
            $form->atbdp_dynamic_admin_listing_form(); } catch ( RuntimeException $e ) {
            $reached = 'authorized_form' === $e->getMessage(); }
            ob_end_clean();
            $check( $case[2] === $reached, 'Dynamic form permission: actor ' . $case[0] . ', listing ' . $case[1] );
    }

    $handler = ( new ReflectionClass( 'ATBDP_User' ) )->newInstanceWithoutConstructor();
    wp_set_current_user( $stranger );
    delete_user_meta( $owner, 'directorist_user_email_unverified' );
    $token = WP_Session_Tokens::get_instance( $owner )->create( time() + 3600 );
    $handler->filter_handle_bulk_actions_users( '', 'directorist_mark_as_email_unverified', [ $owner ] );
    $check( ! get_user_meta( $owner, 'directorist_user_email_unverified', true ) && WP_Session_Tokens::get_instance( $owner )->verify( $token ), 'Unauthorized bulk action preserves verification and sessions' );
    wp_set_current_user( $admin );
    $handler->filter_handle_bulk_actions_users( '', 'directorist_mark_as_email_unverified', [ $owner ] );
    $check( get_user_meta( $owner, 'directorist_user_email_unverified', true ) && ! WP_Session_Tokens::get_instance( $owner )->verify( $token ), 'Authorized admin action still unverifies and logs out target' );
    $handler->filter_handle_bulk_actions_users( '', 'directorist_mark_as_email_verified', [ $owner ] );
    $check( ! get_user_meta( $owner, 'directorist_user_email_unverified', true ), 'Authorized verification still works' );
    // Model the object-level denial WordPress applies to a subsite administrator.
    $deny_target = function ( $caps, $cap, $actor, $args ) use ( $owner ) {
        return 'edit_user' === $cap && $owner === (int) ( $args[0] ?? 0 ) ? [ 'do_not_allow' ] : $caps;
    };
    add_filter( 'map_meta_cap', $deny_target, 10, 4 );
    $handler->filter_handle_bulk_actions_users( '', 'directorist_mark_as_email_unverified', [ $owner, $stranger ] );
    $check( ! get_user_meta( $owner, 'directorist_user_email_unverified', true ) && get_user_meta( $stranger, 'directorist_user_email_unverified', true ), 'Mixed bulk action skips forbidden target and processes permitted target' );
    update_user_meta( $owner, 'directorist_user_email_unverified', true );
    $handler->filter_handle_bulk_actions_users( '', 'directorist_mark_as_email_verified', [ $owner ] );
    $check( (bool) get_user_meta( $owner, 'directorist_user_email_unverified', true ), 'Forbidden target cannot be marked verified' );
    $mail_count = 0;
    $count_mail = function () use ( &$mail_count ) { ++$mail_count; return true; };
    add_filter( 'pre_wp_mail', $count_mail );
    $handler->filter_handle_bulk_actions_users( '', 'directorist_request_email_verification', [ $owner ] );
    $check( 0 === $mail_count, 'Forbidden target cannot be sent verification request' );
    remove_filter( 'pre_wp_mail', $count_mail );
    remove_filter( 'map_meta_cap', $deny_target );
} catch ( Throwable $e ) {
    $failures[] = $e->getMessage();
} finally {
    wp_set_current_user( $original_user );
    foreach ( $orders as $id ) {
        directorist_order_repository()->delete_by_id( $id ); }
    foreach ( array_reverse( $posts ) as $id ) {
        wp_delete_post( $id, true ); }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ( $users as $id ) {
        wp_delete_user( $id ); }
    $check( ! array_filter( $posts, 'get_post' ) && ! array_filter( $users, 'get_userdata' ), 'Fixture posts and users removed' );
    foreach ( $orders as $id ) {
        $check( ! directorist_order_repository()->get_by_id( $id ), 'Fixture order removed' ); }
}
if ( $failures ) {
    WP_CLI::error( implode( "\n", $failures ) ); }
WP_CLI::success( $checks . ' authorization checks passed; fixtures removed.' );
