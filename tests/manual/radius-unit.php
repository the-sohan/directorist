<?php
/** wp eval-file tests/manual/radius-unit.php — read-only query regression. */
require_once ATBDP_INC_DIR . 'model/Listings.php';

$saved_request = $_REQUEST;
$saved_get     = $_GET;
$saved_options = get_option( 'atbdp_option' );
$unit          = 'kilometers';
$global_unit   = 'miles';
$based_on      = 'address';
$term_filter   = static function( $value, $id, $key ) use ( &$unit, &$based_on ) {
    if ( 'search_form_fields' === $key ) {
        return [
            [
                'fields' => [
                    'radius_search' => [
                        'radius_search_unit'     => $unit,
                        'radius_search_based_on' => $based_on,
                    ],
                ],
            ],
        ];
    }
    return $value;
};
$option_filter = static function( $value, $key ) use ( &$global_unit ) {
    return 'radius_search_unit' === $key ? $global_unit : $value;
};
add_filter( 'get_term_metadata', $term_filter, 10, 3 );
add_filter( 'directorist_option', $option_filter, 10, 2 );
try {
    $cases = [ [ 'kilometers', 'miles', 'kilometers' ], [ 'miles', 'kilometers', 'miles' ], [ '', 'miles', 'miles' ], [ 'invalid', 'kilometers', 'kilometers' ], [ [ 'bad' ], 'miles', 'miles' ] ];
    foreach ( $cases as $case ) {
        list( $unit, $global_unit, $expected ) = $case;
        foreach ( [ 'address', 'zip' ] as $based_on ) {
            $_REQUEST = $_GET = [ 'address' => 'Selected location', 'cityLat' => '42.2', 'cityLng' => '12.5', 'zip_cityLat' => '42.2', 'zip_cityLng' => '12.5', 'miles' => '0-25' ];
            $model    = new \Directorist\Directorist_Listings( [], 'search_result' );
            if ( $expected !== $model->radius_search_unit || $expected !== ( $model->query_args['atbdp_geo_query']['units'] ?? null ) ) {
                throw new RuntimeException( 'Wrong query unit for ' . $based_on );
            }
        }
    }
    if ( get_option( 'atbdp_option' ) !== $saved_options ) {
        throw new RuntimeException( 'Saved options changed.' );
    }
    WP_CLI::success( 'Ten address/ZIP query cases passed; saved options unchanged.' );
} finally {
    remove_filter( 'get_term_metadata', $term_filter, 10 );
    remove_filter( 'directorist_option', $option_filter, 10 );
    $_REQUEST = $saved_request;
    $_GET     = $saved_get;
}
