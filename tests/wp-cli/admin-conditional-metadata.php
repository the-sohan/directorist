<?php
/**
 * Regression coverage for conditionally hidden metadata in the admin editor.
 * Run with wp --user=<administrator> eval-file on a disposable local site.
 *
 * @package Directorist
 */
defined( 'ABSPATH' ) || exit;
if ( 'local' !== wp_get_environment_type() ) {
    throw new RuntimeException( 'Local WordPress required.' );
}
add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
set_current_screen( 'at_biz_dir' );
$results       = array();
$posts         = array();
$directory     = 0;
$original_post = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- CLI fixture snapshots its synthetic request.
$user          = get_current_user_id();
$check         = static function ( $ok, $label ) use ( &$results ) {
    $results[ $label ] = (bool) $ok;
};
try {
    $term = wp_insert_term( 'Admin conditional QA ' . wp_generate_uuid4(), ATBDP_DIRECTORY_TYPE );
    if ( is_wp_error( $term ) ) {
        throw new RuntimeException( $term->get_error_message() ); }
    $directory = (int) $term['term_id'];
    $logic     = array(
        'enabled' => true,
        'action'  => 'show',
        'groups'  => array(
            array(
                'conditions' => array(
                    array(
                        'field'    => 'listing_title',
                        'operator' => 'is',
                        'value'    => 'Event',
                    ),
                ),
            ),
        ),
    );
    $fields    = array();
    foreach ( array( 'phone', 'text', 'number', 'map', 'pricing', 'image_upload' ) as $key ) {
        $fields[ $key ] = array(
            'widget_group'      => 'preset',
            'widget_name'       => $key,
            'widget_key'        => $key,
            'field_key'         => $key,
            'label'             => $key,
            'type'              => $key,
            'conditional_logic' => $logic,
        );
    }
    $form = array(
        'fields' => $fields,
        'groups' => array(
            array(
                'type'   => 'general_group',
                'label'  => 'Conditional QA',
                'fields' => array_keys( $fields ),
            ),
        ),
    );
    update_term_meta( $directory, 'submission_form_fields', $form );
    $id         = wp_insert_post(
        array(
            'post_type'   => ATBDP_POST_TYPE,
            'post_title'  => 'Event',
            'post_status' => 'draft',
            'post_author' => $user,
        )
    );
    $posts[]    = $id;
    $attachment = wp_insert_attachment(
        array(
            'post_title'     => 'Conditional thumbnail QA',
            'post_status'    => 'inherit',
            'post_mime_type' => 'image/png',
        )
    );
    $posts[]    = $attachment;
    $values     = array(
        '_phone'                => '0123456789',
        '_text'                 => "Owner's details",
        '_number'               => '0',
        '_hide_map'             => '1',
        '_manual_lat'           => '23.5',
        '_manual_lng'           => '90.4',
        '_price'                => '50',
        '_price_range'          => 'moderate',
        '_atbd_listing_pricing' => 'price',
        '_listing_img'          => array( $attachment ),
        '_listing_prv_img'      => $attachment,
        '_thumbnail_id'         => $attachment,
    );
    $seed       = static function () use ( $id, $directory, $values ) {
        update_post_meta( $id, '_directory_type', $directory );
        foreach ( $values as $key => $value ) {
            update_post_meta( $id, $key, $value ); }
    };
    $seed();
    $baseline = array();
    foreach ( $values as $key => $value ) {
        $baseline[ $key ] = get_post_meta( $id, $key, true ); }
    $handler = new ATBDP_Metabox();
    $save    = static function ( $payload, $nonce = true ) use ( $id, $directory, $handler ) {
        $_POST = array_merge(
            array(
                'post_ID'            => $id,
                'directory_type'     => $directory,
                'never_expire'       => 1,
                'listing_info_nonce' => $nonce ? wp_create_nonce( 'listing_info_action' ) : 'invalid',
            ),
            $payload
        );
        $handler->save_post_meta( $id, get_post( $id ) );
    };
    foreach ( array(
        'omitted' => array(),
        'forged'  => array(
            'phone'           => 'overwrite',
            'text'            => 'overwrite',
            'number'          => '99',
            'listing_prv_img' => '999999',
            'price'           => '100',
        ),
    ) as $name => $payload ) {
        $seed();
        $save( array_merge( $payload, array( 'post_title' => 'Ordinary' ) ) );
        foreach ( $baseline as $key => $value ) {
            $check( get_post_meta( $id, $key, true ) === $value, $name . ' hidden field preserves ' . $key ); }
    }
    $seed();
    $save(
        array(
            'post_title'      => 'Event',
            'phone'           => '987654321',
            'text'            => wp_slash( "Author's update" ),
            'number'          => '0',
            'listing_prv_img' => $attachment,
        )
    );
    $check( '987654321' === get_post_meta( $id, '_phone', true ), 'visible phone updates' );
    $check( "Author's update" === get_post_meta( $id, '_text', true ), 'visible slashed text updates normally' );
    $check( '0' === get_post_meta( $id, '_number', true ), 'visible numeric zero persists' );
    $seed();
    $save( array( 'post_title' => 'Event' ) );
    foreach ( array_keys( $values ) as $key ) {
        $check( ! metadata_exists( 'post', $id, $key ), 'visible empty field clears ' . $key ); }
    $seed();
    $save(
        array(
            'post_title' => 'Event',
            'phone'      => 'wrong',
        ),
        false
    );
    $check( get_post_meta( $id, '_phone', true ) === $baseline['_phone'], 'invalid nonce cannot change metadata' );
    wp_set_current_user( 0 );
    $save(
        array(
            'post_title' => 'Event',
            'phone'      => 'wrong',
        )
    );
    wp_set_current_user( $user );
    $check( get_post_meta( $id, '_phone', true ) === $baseline['_phone'], 'unauthorized request cannot change metadata' );
    $form['fields']['phone']['conditional_logic']['enabled'] = false;
    update_term_meta( $directory, 'submission_form_fields', $form );
    $save( array( 'post_title' => 'Ordinary' ) );
    $check( ! metadata_exists( 'post', $id, '_phone' ), 'disabled conditions retain normal clearing behavior' );
    foreach ( $results as $label => $ok ) {
        echo ( $ok ? 'PASS ' : 'FAIL ' ) . esc_html( $label ) . "\n"; }
    if ( in_array( false, $results, true ) ) {
        throw new RuntimeException( 'Admin conditional metadata regression failed.' ); }
    echo count( $results ) . " admin metadata checks passed.\n";
} finally {
    wp_set_current_user( $user );
    $_POST = $original_post;
    foreach ( $posts as $post_id ) {
        wp_delete_post( $post_id, true ); }
    if ( $directory ) {
        wp_delete_term( $directory, ATBDP_DIRECTORY_TYPE ); }
}
