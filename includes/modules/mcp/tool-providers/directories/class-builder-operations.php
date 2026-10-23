<?php
/**
 * Builder Operations for directories.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Tool_Providers\Directories;

use Directorist\MCP\Tool_Providers\Settings\Settings_Schema;
use Directorist\MCP\Registration\Tool_Registry;
use Directorist\MCP\Support\Validation;


defined( 'ABSPATH' ) || exit;

/** Builder Operations. */
class Builder_Operations {
    /**
     * Operation.
     *
     * @param array $values Values.
     * @param string $area Area.
     * @param array $op Op.
     */
    public static function operation( array &$values, string $area, array $op ): void {
        Validation::allowed( $op, [ 'action', 'entity', 'configuration', 'id', 'widget', 'section_id', 'slot', 'target_slot', 'position', 'changes', 'order', 'template' ] );
        $key = $op['configuration'] ?? '';
        Validation::check( in_array( $key, Directory_Configuration::AREAS[ $area ], true ), __( 'Select a discovered configuration key in this builder area.', 'directorist' ) );
        $action = $op['action'] ?? '';
        Validation::check( in_array( $action, [ 'add', 'update', 'move', 'reorder', 'remove' ], true ), __( 'Unsupported builder action.', 'directorist' ) );
        $definition = Directory_Configuration::definitions()[ $key ];
        if ( 'options' === ( $op['entity'] ?? '' ) ) {
            $schema = Settings_Schema::field_schema( $definition );
            Validation::check( null !== $schema && 'update' === $action, __( 'This builder option is not writable.', 'directorist' ), 'unsupported_operation' );
            $value = $op['changes']['value'] ?? null;
            Validation::unwrap( rest_validate_value_from_schema( $value, $schema ) );
            if ( 'single_listing_page' === $key && $value ) {
                Validation::check( 'page' === get_post_type( (int) $value ), __( 'Select an existing page.', 'directorist' ) );
            }
            $values[ $key ] = is_string( $value ) ? Validation::clean_text( $value ) : $value;
            return;
        }
        $config  = (array) Directory_Configuration::normalize_value( $values[ $key ], $definition );
        $widgets = Builder_Schema::widgets( $definition );
        if ( 'form-builder' === ( $definition['type'] ?? '' ) ) {
            Validation::check( isset( $config['groups'], $config['fields'] ) && is_array( $config['groups'] ) && is_array( $config['fields'] ), __( 'This form builder configuration must contain fields and groups.', 'directorist' ) );
            self::form_operation( $config, $op, $widgets );
        } else {
            self::layout_operation( $config, $op, $widgets, $definition );
        }
        $values[ $key ] = $config;
    }

    /**
     * Form operation.
     *
     * @param array $config Config.
     * @param array $op Op.
     * @param array $widgets Widgets.
     */
    public static function form_operation( array &$config, array $op, array $widgets ): void {
        foreach ( $config['groups'] as $index => &$group ) {
            $group['id'] = Builder_Schema::section_id( $group, $index );
        }
        unset( $group );
        $action = $op['action'];
        $id     = $op['id'] ?? '';
        if ( 'section' === ( $op['entity'] ?? '' ) ) {
            if ( 'add' === $action ) {
                $changes = Validation::object( $op['changes'] ?? [] );
                Validation::allowed( $changes, [ 'label', 'icon', 'section_id' ] );
                foreach ( $changes as &$value ) {
                    $value = Validation::clean_text( $value );
                }
                unset( $value );
                Validation::check( ! $id || ( is_string( $id ) && preg_match( '/^[A-Za-z0-9_-]{1,100}$/', $id ) ), __( 'Use a stable section ID with letters, numbers, hyphens or underscores.', 'directorist' ) );
                Validation::check( ! $id || ! in_array( $id, array_column( $config['groups'], 'id' ), true ), __( 'Section ID is already in use.', 'directorist' ) );
                $config['groups'][] = [ 'id' => $id ?: wp_generate_uuid4(), 'type' => 'general_group', 'fields' => [] ] + $changes;
                return;
            }
            $index = array_search( $id, array_column( $config['groups'], 'id' ), true );
            Validation::check( false !== $index, __( 'Builder section not found.', 'directorist' ) );
            if ( 'remove' === $action ) {
                Validation::check( empty( $config['groups'][ $index ]['fields'] ) && empty( $config['groups'][ $index ]['lock'] ), __( 'Move/remove section items first; locked sections cannot be removed.', 'directorist' ), 'dependency_blocked' );
                array_splice( $config['groups'], $index, 1 );
            } elseif ( 'update' === $action ) {
                $changes = Validation::object( $op['changes'] );
                Validation::allowed( $changes, [ 'label', 'icon' ] );
                foreach ( $changes as &$v ) {
                    $v = Validation::clean_text( $v );
                }
                $config['groups'][ $index ] = array_replace( $config['groups'][ $index ], $changes );
            } elseif ( 'reorder' === $action ) {
                self::reorder_objects( $config['groups'], $op['order'], 'id' );
            } else {
                Validation::fail( 'unsupported_operation', __( 'Use section reorder to move sections.', 'directorist' ) );
            }
            return;
        }
        Validation::check( 'field' === ( $op['entity'] ?? '' ), __( 'This configuration contains fields and sections.', 'directorist' ) );
        if ( 'add' === $action ) {
            $name = $op['widget'] ?? '';
            Validation::check( isset( $widgets[ $name ] ) && $widgets[ $name ]['writable'], __( 'This widget is not supported for MCP editing.', 'directorist' ), 'unsupported_operation' );
            $id = $id ?: $name . '-' . substr( wp_generate_uuid4(), 0, 8 );
            Validation::check( is_string( $id ) && preg_match( '/^[A-Za-z0-9_-]{1,100}$/', $id ), __( 'Use a stable field ID with letters, numbers, hyphens or underscores.', 'directorist' ) );
            Validation::check( ! isset( $config['fields'][ $id ] ), __( 'Field ID is already in use.', 'directorist' ) );
            $field = self::new_widget( $name, $id, $widgets[ $name ] );
            if ( 'custom' === ( $field['widget_group'] ?? '' ) ) {
                $field['field_key'] = 'custom-' . sanitize_key( $id );
            }
            $config['fields'][ $id ] = self::update_widget( $field, $op['changes'] ?? [], $widgets[ $name ], true );
        } else {
            Validation::check( isset( $config['fields'][ $id ] ), __( 'Builder field not found.', 'directorist' ) );
            $field = $config['fields'][ $id ];
            $name  = $field['widget_name'] ?? '';
            Validation::check( isset( $widgets[ $name ] ) && $widgets[ $name ]['writable'], __( 'This widget is read-only.', 'directorist' ), 'unsupported_operation' );
            if ( 'update' === $action ) {
                $config['fields'][ $id ] = self::update_widget( $field, $op['changes'] ?? [], $widgets[ $name ] );
                return;
            }
            if ( 'remove' === $action ) {
                Validation::check( ! empty( $widgets[ $name ]['canTrash'] ) && 'title' !== $name, __( 'A mandatory preset cannot be removed.', 'directorist' ) );
                unset( $config['fields'][ $id ] );
            }
        }
        if ( 'reorder' === $action ) {
            foreach ( $config['groups'] as &$group ) {
                if ( $group['id'] === ( $op['section_id'] ?? '' ) ) {
                    self::reorder_ids( $group['fields'], $op['order'] );
                    return;
                }
            }
            Validation::fail( 'validation_failed', __( 'Select a section to reorder.', 'directorist' ) );
        }
        if ( in_array( $action, [ 'add', 'move', 'remove' ], true ) ) {
            foreach ( $config['groups'] as &$group ) {
                $group['fields'] = array_values( array_diff( $group['fields'], [ $id ] ) );
            }
            unset( $group );
            if ( 'remove' !== $action ) {
                $target = array_search( $op['section_id'] ?? '', array_column( $config['groups'], 'id' ), true );
                Validation::check( false !== $target, __( 'Select a target section.', 'directorist' ) );
                $position = $op['position'] ?? count( $config['groups'][ $target ]['fields'] );
                Validation::check( is_int( $position ) && $position >= 0, __( 'Invalid insertion position.', 'directorist' ) );
                array_splice( $config['groups'][ $target ]['fields'], $position, 0, [ $id ] );
            }
        }
    }

    /**
     * New widget.
     *
     * @param string $name Registered action or object name.
     * @param string $id Id.
     * @param array $definition Registered descriptor.
     */
    public static function new_widget( string $name, string $id, array $definition ): array {
        $widget = [ 'widget_name' => $name, 'widget_key' => $id, 'widget_group' => $definition['widget_group'] ?? '', 'type' => $definition['type'] ?? 'text', 'label' => $definition['label'] ?? $name ];
        if ( isset( $definition['hook'] ) ) {
            $widget['hook'] = $definition['hook'];
        }
        $options = $definition['options']['fields'] ?? $definition['options'] ?? [];
        foreach ( $options as $key => $option ) {
            if ( is_array( $option ) && array_key_exists( 'value', $option ) ) {
                $widget[ $key ] = $option['value'];
            }
        }
        return $widget;
    }

    /**
     * Update widget.
     *
     * @param array $widget Widget.
     * @param mixed $changes Changes.
     * @param array $definition Registered descriptor.
     * @param bool $new New.
     */
    public static function update_widget( array $widget, $changes, array $definition, bool $new = false ): array {
        $changes = Validation::object( $changes );
        if ( isset( $definition['mcp_owner'] ) ) {
            $adapters   = Tool_Registry::instance()->fields( 'builder', $definition );
            $definition = $adapters[ $widget['widget_name'] ] ?? null;
            Validation::check( $definition && true === call_user_func( $definition['permission'], $definition, 'write' ), __( 'This widget integration is unavailable.', 'directorist' ), 'extension_unavailable' );
            Validation::unwrap( rest_validate_value_from_schema( $changes ?: (object) [], $definition['schema'] ) );
            $changes = Validation::object( Validation::unwrap( call_user_func( $definition['validate'], $changes, $widget ) ) );
            Validation::unwrap( rest_validate_value_from_schema( $changes ?: (object) [], $definition['schema'] ) );
        }
        $options = $definition['options']['fields'] ?? $definition['options'] ?? [];
        Validation::allowed( $changes, array_merge( array_keys( $options ), [ 'label', 'original_widget_key' ] ) );
        foreach ( $changes as $key => $value ) {
            Validation::check( $new || ! in_array( $key, [ 'field_key', 'type' ], true ) || $value === ( $widget[ $key ] ?? null ), __( 'Existing field storage keys and types cannot be changed.', 'directorist' ) );
            $schema = isset( $options[ $key ] ) ? Settings_Schema::field_schema( $options[ $key ] ) : [ 'type' => 'string' ];
            if ( 'icon' === ( $options[ $key ]['type'] ?? '' ) ) {
                $schema = [ 'type' => 'string' ];
            }
            if ( 'conditional_logic' === $key ) {
                $schema = Builder_Schema::conditional_schema();
            }
            if ( 'options' === $key && 'multi-fields' === ( $options[ $key ]['type'] ?? '' ) ) {
                $schema = [ 'type' => 'array', 'maxItems' => 200, 'items' => [ 'type' => 'object', 'required' => [ 'option_value', 'option_label' ], 'additionalProperties' => false, 'properties' => [ 'option_value' => [ 'type' => 'string', 'minLength' => 1 ], 'option_label' => [ 'type' => 'string' ] ] ] ];
                Validation::unwrap( rest_validate_value_from_schema( $value, $schema, $key ) );
                Validation::check( count( array_unique( array_column( $value, 'option_value' ) ) ) === count( $value ), __( 'Choice values must be unique.', 'directorist' ) );
            }
            if ( null !== $schema ) {
                Validation::unwrap( rest_validate_value_from_schema( $value, $schema, $key ) );
            } elseif ( ! is_array( $value ) && ! is_object( $value ) ) {
                Validation::fail( 'validation_failed', __( 'Unsupported widget option type.', 'directorist' ) );
            }
            $widget[ $key ] = map_deep(
                json_decode( wp_json_encode( $value ), true ), static function ( $v ) {
                    return is_string( $v ) ? sanitize_text_field( $v ) : $v;
                }
            );
        }
        return $widget;
    }

    /**
     * Layout operation.
     *
     * @param array $config Config.
     * @param array $op Op.
     * @param array $widgets Widgets.
     * @param array $definition Registered descriptor.
     */
    public static function layout_operation( array &$config, array $op, array $widgets, array $definition ): void {
        if ( 'template' === ( $op['entity'] ?? '' ) ) {
            $template = $op['template'] ?? '';
            Validation::check( isset( $definition['card_templates'][ $template ] ), __( 'Unknown card template.', 'directorist' ) );
            Validation::check( 'update' === $op['action'], __( 'Use update to select a card template.', 'directorist' ) );
            $config['template_data'][ $template ] = $config['template_data'][ $template ] ?? Directory_Configuration::layout_default( $definition['card_templates'][ $template ]['layout'], $widgets, false );
            $config['active_template']            = $template;
            return;
        }
        Validation::check( 'widget' === ( $op['entity'] ?? '' ), __( 'This configuration contains layout widgets.', 'directorist' ) );
        $path        = $op['slot'] ?? [];
        $constraints = Builder_Schema::slots( $config, $definition );
        $valid       = array_column( $constraints, 'path' );
        Validation::check( in_array( $path, $valid, true ), __( 'Use a discovered widget slot.', 'directorist' ) );
        $slot =& $config;
        foreach ( $path as $segment ) {
            $slot =& $slot[ $segment ];
        }
        $action = $op['action'];
        $id     = $op['id'] ?? '';
        if ( isset( $op['position'] ) ) {
            Validation::check( is_int( $op['position'] ) && $op['position'] >= 0, __( 'Invalid widget position.', 'directorist' ) );
        }
        if ( 'reorder' === $action ) {
            self::reorder_objects( $slot, $op['order'], 'widget_key' );
        } elseif ( 'add' === $action ) {
            $name = $op['widget'] ?? '';
            Validation::check( isset( $widgets[ $name ] ) && $widgets[ $name ]['writable'], __( 'Unsupported layout widget.', 'directorist' ) );
            $widget = self::update_widget( self::new_widget( $name, $id ?: $name, $widgets[ $name ] ), $op['changes'] ?? [], $widgets[ $name ], true );
            Builder_Schema::placement( $slot, $constraints[ array_search( $path, $valid, true ) ], $widget );
            array_splice( $slot, $op['position'] ?? count( $slot ), 0, [ $widget ] );
        } else {
            $index = array_search( $id, array_column( $slot, 'widget_key' ), true );
            Validation::check( false !== $index, __( 'Layout widget not found in this slot.', 'directorist' ) );
            $widget = $slot[ $index ];
            $name   = $widget['widget_name'];
            Validation::check( isset( $widgets[ $name ] ) && $widgets[ $name ]['writable'], __( 'This widget is read-only.', 'directorist' ) );
            if ( 'update' === $action ) {
                $slot[ $index ] = self::update_widget( $widget, $op['changes'] ?? [], $widgets[ $name ] );
            } elseif ( 'remove' === $action || 'move' === $action ) {
                Validation::check( ! isset( $widget['can_move'] ) || false !== $widget['can_move'], __( 'This widget cannot be moved or removed.', 'directorist' ) );
                array_splice( $slot, $index, 1 );
                if ( 'move' === $action ) {
                    $target = $op['target_slot'] ?? null;
                    Validation::check( in_array( $target, $valid, true ), __( 'Unknown target slot.', 'directorist' ) );
                    $target_slot =& $config;
                    foreach ( $target as $segment ) {
                        $target_slot =& $target_slot[ $segment ];
                    }
                    Builder_Schema::placement( $target_slot, $constraints[ array_search( $target, $valid, true ) ], $widget );
                    array_splice( $target_slot, $op['position'] ?? count( $target_slot ), 0, [ $widget ] );
                }
            }
        }
        // Header UI maintains both arrays; keep its native round-trip representation consistent.
        $sync = static function ( &$node ) use ( &$sync ) {
            if ( ! is_array( $node ) ) {
                return;
            }
            if ( isset( $node['selectedWidgets'] ) ) {
                $node['selectedWidgetList'] = array_column( $node['selectedWidgets'], 'widget_key' );
            }
            foreach ( $node as &$child ) {
                if ( is_array( $child ) ) {
                    $sync( $child );
                }
            }
        };
        if ( ! isset( $config['active_template'] ) ) {
            $sync( $config );
        }
    }

    /**
     * Reorder ids.
     *
     * @param array $items Items.
     * @param mixed $order Order.
     */
    public static function reorder_ids( array &$items, $order ): void {
        Validation::check( is_array( $order ) && count( $order ) === count( $items ) && count( array_unique( $order ) ) === count( $order ) && ! array_diff( $order, $items ), __( 'Reorder must include every sibling ID exactly once.', 'directorist' ) );
        $items = array_values( $order );
    }

    /**
     * Reorder objects.
     *
     * @param array $items Items.
     * @param mixed $order Order.
     * @param string $key Key.
     */
    public static function reorder_objects( array &$items, $order, string $key ): void {
        $ids = array_column( $items, $key );
        self::reorder_ids( $ids, $order );
        $map   = array_column( $items, null, $key );
        $items = array_map(
            static function ( $id ) use ( $map ) {
                return $map[ $id ];
            }, $ids
        );
    }
}
