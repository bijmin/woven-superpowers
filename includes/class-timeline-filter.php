<?php

namespace Woven\Superpowers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Timeline_Filter {
    protected $active_filter_ids = null;
    protected $filters_attached  = false;

    public static function boot(): void {
        if ( static::is_enabled() ) {
            new static();
        }
    }

    public static function is_enabled(): bool {
        $settings = get_option( 'wsp_settings', [] );
        return ! empty( $settings['timeline_filters'] );
    }

    public function __construct() {
        add_action( 'elementor/widgets/widgets_registered', [ $this, 'extend_timeline_widget' ] );
        add_filter( 'voxel/timeline/query_args', [ $this, 'filter_timeline_by_search' ], 20, 2 );
    }

    /**
     * Add new controls to the Timeline widget for filtered mode.
     */
    public function extend_timeline_widget( $widgets_manager ) {
        $widget = $widgets_manager->get_widget_types()['ts-timeline'] ?? null;
        if ( ! $widget ) {
            return;
        }

        $widget->start_controls_section(
            'wsp_timeline_filters',
            [
                'label' => __( 'Woven Superpowers: Filtered Feed', 'wsp' ),
                'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
            ]
        );

        $widget->add_control( 'wsp_feed_mode', [
            'label'   => __( 'Feed mode', 'wsp' ),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'options' => [
                'default'  => __( 'Use Voxel mode', 'wsp' ),
                'filtered' => __( 'Filtered feed (Woven Superpowers)', 'wsp' ),
            ],
            'default' => 'default',
        ] );

        $widget->add_control( 'wsp_search_form', [
            'label'       => __( 'Link to search form', 'wsp' ),
            'type'        => 'voxel-relation',
            'vx_group'    => 'timelineToSearch',
            'vx_target'   => 'elementor-widget-ts-search-form',
            'vx_side'     => 'right',
            'description' => __( 'Connect this timeline to a Voxel Search Form widget.', 'wsp' ),
            'condition'   => [ 'wsp_feed_mode' => 'filtered' ],
        ] );

        $widget->add_control( 'wsp_search_form_id', [
            'label'       => __( 'Fallback search form ID', 'wsp' ),
            'type'        => \Elementor\Controls_Manager::TEXT,
            'description' => __( 'Optional: use when relation is not available.', 'wsp' ),
            'condition'   => [ 'wsp_feed_mode' => 'filtered' ],
        ] );

        $widget->end_controls_section();
    }

    /**
     * Inject the filtered search results into the Timeline query.
     */
    public function filter_timeline_by_search( $args, $context ) {
        if ( ! $this->should_filter( $context ) ) {
            return $args;
        }

        $ids = $this->get_search_post_ids( $context );
        if ( $ids === null ) {
            // no connected search form; leave feed untouched
            return $args;
        }

        $this->active_filter_ids = $ids;
        $this->attach_sql_filters();

        if ( empty( $ids ) ) {
            // search returned no posts, force empty feed
            $args['wsp_filtered_ids'] = [ 0 ];
            $args['post__in'] = [ 0 ];
            return $args;
        }

        $args['wsp_filtered_ids'] = $ids;

        return $args;
    }

    /**
     * Add WHERE clause for WP_Query-based timelines.
     */
    public function filter_wp_timeline_query( $where, $query ) {
        $ids = $query->get( 'wsp_filtered_ids' );
        if ( empty( $ids ) || ! is_array( $ids ) ) {
            return $where;
        }

        global $wpdb;

        $ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
        if ( empty( $ids ) ) {
            $where .= ' AND 1=0';
            return $where;
        }

        $ids_sql = implode( ',', $ids );
        $clauses = [
            "{$wpdb->posts}.post_author IN ({$ids_sql})",
            "{$wpdb->posts}.ID IN (
                SELECT pm.post_id FROM {$wpdb->postmeta} pm
                WHERE pm.meta_key = 'parent_post_id' AND pm.meta_value IN ({$ids_sql})
            )",
        ];

        $where .= ' AND ( ' . implode( ' OR ', $clauses ) . ' )';

        return $where;
    }

    /**
     * Add WHERE clause for the voxel_timeline table queries.
     */
    public function filter_status_table_sql( $sql ) {
        if ( empty( $this->active_filter_ids ) && $this->active_filter_ids !== [] ) {
            return $sql;
        }

        global $wpdb;

        if ( strpos( $sql, $wpdb->prefix . 'voxel_timeline' ) === false ) {
            return $sql;
        }

        $ids = array_values( array_filter( array_map( 'absint', (array) $this->active_filter_ids ) ) );
        $condition = empty( $ids )
            ? '1=0'
            : sprintf(
                '(statuses.user_id IN (%1$s) OR statuses.post_id IN (%1$s))',
                implode( ',', $ids )
            );

        if ( preg_match( '/\\bWHERE\\b/i', $sql ) ) {
            return preg_replace( '/\\bWHERE\\b/i', 'WHERE ' . $condition . ' AND ', $sql, 1 );
        }

        if ( preg_match( '/(FROM\\s+[^\\s]+voxel_timeline[^\\s]*\\s+AS\\s+statuses)/i', $sql, $matches ) ) {
            return str_replace( $matches[1], $matches[1] . ' WHERE ' . $condition, $sql );
        }

        return $sql . ' WHERE ' . $condition;
    }

    /**
     * Fallback filter if results need trimming post-query.
     */
    public function filter_timeline_items( $items ) {
        if ( ! is_array( $items ) || ( empty( $this->active_filter_ids ) && $this->active_filter_ids !== [] ) ) {
            return $items;
        }

        $ids = array_values( array_filter( array_map( 'absint', (array) $this->active_filter_ids ) ) );
        return array_values( array_filter( $items, function( $status ) use ( $ids ) {
            if ( method_exists( $status, 'get_post_id' ) && in_array( (int) $status->get_post_id(), $ids, true ) ) {
                return true;
            }

            if ( method_exists( $status, 'get_user_id' ) && in_array( (int) $status->get_user_id(), $ids, true ) ) {
                return true;
            }

            return false;
        } ) );
    }

    protected function should_filter( $context ): bool {
        $settings = $context['element_settings'] ?? [];
        return ( $settings['wsp_feed_mode'] ?? 'default' ) === 'filtered';
    }

    protected function get_search_post_ids( array $context ): ?array {
        // Try connected search form via relation
        if ( $search_form = $this->locate_related_search_form( $context ) ) {
            if ( $ids = $this->get_ids_from_search_widget( $search_form ) ) {
                return $ids;
            }
        }

        // Fallback to manually provided search form ID
        $manual_id = $context['element_settings']['wsp_search_form_id'] ?? null;
        if ( $manual_id && class_exists( '\Voxel\Dynamic_Data\Search' ) && is_callable( [ '\Voxel\Dynamic_Data\Search', 'get_form_results' ] ) ) {
            $results = \Voxel\Dynamic_Data\Search::get_form_results( $manual_id );
            if ( ! empty( $results['ids'] ) && is_array( $results['ids'] ) ) {
                return array_values( array_filter( array_map( 'absint', $results['ids'] ) ) );
            }

            return [];
        }

        return null;
    }

    protected function locate_related_search_form( array $context ) {
        $widget = $context['widget'] ?? $context['element'] ?? null;
        if ( ! ( $widget instanceof \Elementor\Widget_Base ) ) {
            return null;
        }

        $template_id = $widget->_get_template_id();

        $search_form = \Voxel\get_related_widget( $widget, $template_id, 'timelineToSearch', 'right' );
        if ( $search_form ) {
            return $search_form;
        }

        // Support the default feedToSearch relation as a fallback
        return \Voxel\get_related_widget( $widget, $template_id, 'feedToSearch', 'right' );
    }

    protected function get_ids_from_search_widget( array $search_form ): array {
        try {
            $widget = new \Voxel\Widgets\Search_Form( $search_form, [] );
            if ( method_exists( $widget, 'add_instance_controls' ) ) {
                $widget->add_instance_controls();
            }

            $post_type = $widget->_get_default_post_type();
            if ( ! $post_type ) {
                return [];
            }

            $config = $widget->_get_post_type_config( $post_type );
            $args   = [ 'type' => $post_type->get_key() ];

            foreach ( $config['filters'] as $filter ) {
                if ( $filter['value'] !== null ) {
                    $args[ $filter['key'] ] = $filter['value'];
                }
            }

            if ( $widget->_update_url() ) {
                $args['pg'] = $_GET['pg'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            }

            $limit   = apply_filters( 'voxel/get_search_results/max_limit', 500 );
            $results = \Voxel\get_search_results( $args, [
                'limit'                  => $limit,
                'render'                 => false,
                'get_total_count'        => false,
                'preload_additional_ids' => 0,
                'apply_conditional_logic'=> true,
            ] );

            return array_values( array_filter( array_map( 'absint', $results['ids'] ?? [] ) ) );
        } catch ( \Throwable $e ) {
            return [];
        }
    }

    protected function attach_sql_filters(): void {
        if ( $this->filters_attached ) {
            return;
        }

        add_filter( 'posts_where', [ $this, 'filter_wp_timeline_query' ], 10, 2 );
        add_filter( 'query', [ $this, 'filter_status_table_sql' ] );
        add_filter( 'voxel/frontend/timeline/get_items', [ $this, 'filter_timeline_items' ], 20, 1 );

        $this->filters_attached = true;
    }
}

Timeline_Filter::boot();
