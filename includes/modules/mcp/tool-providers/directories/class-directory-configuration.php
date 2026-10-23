<?php
/**
 * Directory Configuration for directories.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Tool_Providers\Directories;

use Directorist\MCP\Runtime\Access_Control;
use Directorist\MCP\Support\Canonical_Data;
use Directorist\MCP\Support\Validation;
use Directorist\Multi_Directory\Multi_Directory_Manager as Manager;

defined( 'ABSPATH' ) || exit;

/** Directory Configuration. */
class Directory_Configuration {
    const AREAS = [
        'general'         => [ 'icon', 'preview_image', 'default_expiration', 'new_listing_status', 'edit_listing_status' ],
        'submission_form' => [ 'submission_form_fields' ],
        'search_form'     => [ 'search_form_fields' ],
        'single_listing'  => [ 'single_listing_header', 'single_listings_contents', 'enable_single_listing_page', 'single_listing_page', 'single_listing_page_title' ],
        'listing_cards'   => [ 'listings_card_grid_view', 'listings_card_list_view' ],
    ];

    /** Definitions. */
    public static function definitions(): array {
        Manager::load_builder_data();
        return Manager::$fields;
    }

    /**
     * Configuration.
     *
     * @param int $id Id.
     */
    public static function configuration( int $id ): array {
        $values = [];
        foreach ( self::definitions() as $key => $definition ) {
            if ( 'import_export' === $key ) {
                continue;
            }
            $value          = metadata_exists( 'term', $id, $key ) ? get_term_meta( $id, $key, true ) : self::default_value( $definition );
            $values[ $key ] = self::normalize_value( $value, $definition );
        }
        return $values;
    }

    /**
     * Default value.
     *
     * @param array $definition Registered descriptor.
     * @return mixed Native builder default, retaining the registered configuration type.
     */
    public static function default_value( array $definition ) {
        if ( ! empty( $definition['value'] ) || ( array_key_exists( 'value', $definition ) && 'card-builder' !== ( $definition['type'] ?? '' ) ) ) {
            $value = self::normalize_value( $definition['value'], $definition );
            if ( is_array( $value ) && isset( $value['fields'] ) ) {
                foreach ( $value['fields'] as $id => &$field ) {
                    $field['widget_key'] = $field['widget_key'] ?? $id;
                }
            }
            return $value;
        }
        if ( ! empty( $definition['card_templates'] ) ) {
            $templates = $definition['card_templates'];
            $key       = key( $templates );
            return [ 'active_template' => $key, 'template_data' => [ $key => self::layout_default( $templates[ $key ]['layout'] ?? [], Builder_Schema::widgets( $definition ), false ) ] ];
        }
        if ( isset( $definition['layout'] ) ) {
            return self::layout_default( $definition['layout'], Builder_Schema::widgets( $definition ), true );
        }
        return null;
    }

    /**
     * Expand the native empty form default without rewriting stored metadata.
     *
     * @param mixed $value Configuration value.
     * @param array $definition Registered descriptor.
     * @return mixed Normalized configuration value.
     */
    public static function normalize_value( $value, array $definition ) {
        if ( 'form-builder' === ( $definition['type'] ?? '' ) && [] === $value ) {
            return [ 'fields' => [], 'groups' => [] ];
        }
        return $value;
    }

    /**
     * Layout default.
     *
     * @param array $layout Layout.
     * @param array $widgets Widgets.
     * @param bool $header Header.
     */
    public static function layout_default( array $layout, array $widgets, bool $header ): array {
        if ( isset( $layout['acceptedWidgets'] ) ) {
            $selected = [];
            foreach ( $layout['selectedWidgetList'] ?? $layout['selectedWidgets'] ?? [] as $name ) {
                if ( isset( $widgets[ $name ] ) ) {
                    $selected[] = Builder_Operations::new_widget( $name, $name, $widgets[ $name ] );
                }
            }
            if ( ! $header ) {
                return $selected;
            }
            $layout['selectedWidgets']    = $selected;
            $layout['selectedWidgetList'] = array_column( $selected, 'widget_key' );
            return $layout;
        }
        foreach ( $layout as &$child ) {
            if ( is_array( $child ) ) {
                $child = self::layout_default( $child, $widgets, $header );
            }
        }
        return $layout;
    }

    /**
     * Version.
     *
     * @param mixed $id Id.
     */
    public static function version( $id ): string {
        $term = Validation::directory( $id );
        return Canonical_Data::hash( [ $term->to_array(), get_term_meta( $id ), directorist_get_default_directory(), self::dependencies( $id ) ] );
    }

    /**
     * Dependencies.
     *
     * @param mixed $id Id.
     */
    public static function dependencies( $id ): array {
        global $wpdb;
        $term          = Validation::directory( $id );
        $relationships = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d", $term->term_taxonomy_id ) );
        $meta          = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_directory_type' AND meta_value = %s", (string) $id ) );
        $associations  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_key = '_directory_type' AND (meta_value LIKE %s OR meta_value LIKE %s)", '%' . $wpdb->esc_like( 'i:' . $id . ';' ) . '%', '%' . $wpdb->esc_like( '"' . $id . '"' ) . '%' ) );
        return [
            'listing_relationships' => $relationships,
            'listing_metadata'      => $meta,
            'term_associations'     => $associations,
            'protected'             => (bool) apply_filters( 'directorist_mcp_directory_has_dependencies', false, $id )
        ];
    }

    /**
     * Summary.
     *
     * @param \WP_Term $term Term.
     */
    public static function summary( \WP_Term $term ): array {
        return [
            'id'            => (int) $term->term_id,
            'name'          => (string) $term->name,
            'slug'          => (string) $term->slug,
            'is_default'    => (int) directorist_get_default_directory() === (int) $term->term_id,
            'listing_count' => (int) $term->count
        ];
    }

    /**
     * General.
     *
     * @param array $changes Changes.
     */
    public static function general( array $changes ): array {
        Validation::allowed( $changes, self::AREAS['general'] );
        foreach ( $changes as $key => &$value ) {
            if ( 'preview_image' === $key ) {
                if ( $value ) {
                    Access_Control::attachment( $value, true );
                }
                continue;
            }
            if ( 'default_expiration' === $key ) {
                Validation::check( is_int( $value ) && $value >= 0 && $value <= 36500, __( 'Expiration must be 0–36500 days.', 'directorist' ) );
                continue;
            }
            if ( in_array( $key, [ 'new_listing_status', 'edit_listing_status' ], true ) ) {
                $options = self::definitions()[ $key ]['options'] ?? [];
                $allowed = array_column( $options, 'value' );
                Validation::check( in_array( $value, $allowed ?: [ 'publish', 'pending' ], true ), __( 'Unsupported listing status policy.', 'directorist' ) );
            } else {
                $value = Validation::clean_text( $value );
            }
        }
        return $changes;
    }
}
