<?php
/** Read-only checks for scalar subfields in conditional logic. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
$checks = 0;
$check = static function ( $ok, $label ) use ( &$checks ) {
    if ( ! $ok ) {
        throw new RuntimeException( $label );
    }
    ++$checks;
    WP_CLI::log( "PASS $label" );
};
$props = static function ( $key, $value ) {
    return [ 'conditional_logic' => [ 'enabled' => true, 'action' => 'show', 'groups' => [ [ 'conditions' => [ [ 'field' => $key, 'operator' => 'is', 'value' => $value ] ] ] ] ] ];
};
foreach ( [ 'event[attendance]', 'group-name[child_key]', 'group_name[child-key]' ] as $path ) {
    preg_match( '/^([^[]+)\[([^]]+)\]$/', $path, $parts );
    $check( \Directorist\Fields\Conditional_Logic::should_show_field( $props( $path, 'online' ), [ $parts[1] => [ $parts[2] => 'online' ] ] ), "$path reads the actual child" );
    $check( ! \Directorist\Fields\Conditional_Logic::should_show_field( $props( $path, 'online' ), [ $parts[1] => [ $parts[2] => 'hybrid' ] ] ), "$path compares child values exactly" );
}
foreach ( [ [], [ 'event' => 'online' ], [ 'event' => new stdClass() ], [ 'event' => [ 'other' => 'online' ] ] ] as $input ) {
    $check( ! \Directorist\Fields\Conditional_Logic::should_show_field( $props( 'event[attendance]', 'online' ), $input ), 'absent/malformed child is empty' );
}
$check( \Directorist\Fields\Conditional_Logic::should_show_field( $props( 'title', 'Sample' ), [ 'listing_title' => 'Sample' ] ), 'ordinary alias behavior is unchanged' );
$check( \Directorist\Fields\Conditional_Logic::should_show_field( $props( 'custom_field[custom-text]', 'Sample' ), [ 'custom_field' => [ 'custom-text' => 'Sample' ] ] ), 'existing custom-field bracket names still work' );
$check( \Directorist\Fields\Conditional_Logic::should_show_field( $props( 'event[attendance]', 'literal' ), [ 'event[attendance]' => 'literal', 'event' => [ 'attendance' => 'nested' ] ] ), 'existing literal top-level keys retain precedence' );
foreach ( [ 'event[attendance][deep]', 'event[]', 'event[attendance', 'event->attendance' ] as $key ) {
    $check( ! \Directorist\Fields\Conditional_Logic::should_show_field( $props( $key, 'online' ), [ 'event' => [ 'attendance' => [ 'deep' => 'online' ] ] ] ), 'unsupported nested syntax is not traversed: ' . $key );
}
WP_CLI::log( "$checks nested conditional value checks passed." );
