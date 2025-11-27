<?php

namespace Woven\Superpowers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Timeline_Filter {
    protected $active_filter_ids = null;
    protected $filters_attached  = false;
    protected static $instance   = null;

    public static function boot(): void {
        if ( static::is_enabled() ) {
            static::$instance = new static();
        }
    }

    public static function is_enabled(): bool {
        $settings = get_option( 'wsp_settings', [] );
        return ! empty( $settings['timeline_filters'] );
    }

    public function __construct() {
        add_action( 'elementor/widgets/widgets_registered', [ $this, 'extend_timeline_widget' ] );
        add_action( 'voxel_ajax_timeline/v2/get_feed', [ $this, 'maybe_handle_filtered_feed' ], 5 );
        add_action( 'voxel_ajax_nopriv_timeline/v2/get_feed', [ $this, 'maybe_handle_filtered_feed' ], 5 );
    }

    /**
     * Add new controls to the Timeline widget for filtered mode.
     */
    public function extend_timeline_widget( $widgets_manager ) {
        $widget = $widgets_manager->get_widget_types()['ts-timeline'] ?? null;
        if ( ! $widget ) {
            return;
        }

        // Add new mode to the existing Voxel mode selector.
        $widget->update_control( 'ts_mode', [
            'options' => array_merge(
                $widget->get_controls()['ts_mode']['options'] ?? [],
                [ 'filtered_feed' => __( 'Filtered feed (Woven Superpowers)', 'wsp' ) ]
            ),
        ] );

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

    public function maybe_handle_filtered_feed() {
        $mode = $_REQUEST['mode'] ?? null;
        if ( $mode !== 'filtered_feed' ) {
            return;
        }

        $ids = $this->resolve_search_ids_from_request();
        if ( $ids === null ) {
            wp_send_json( [
                'success' => true,
                'data' => [],
                'has_more' => false,
                'meta' => [ 'review_config' => [] ],
            ] );
        }

        $this->active_filter_ids = $ids;
        $this->attach_sql_filters();

        $per_page = absint( \Voxel\get( 'settings.timeline.posts.per_page', 10 ) );
        $args = [
            'limit' => $per_page + 1,
            'with_user_like_status' => true,
            'with_user_repost_status' => true,
            'moderation' => 1,
            'with_current_user_visibility_checks' => true,
            'with_no_reposts' => true,
        ];

        $args = $this->apply_ordering( $args );
        $args = $this->apply_timeframe( $args );

        $query = \Voxel\Timeline\Status::query( $args );
        $statuses = $query['items'];
        $has_more = $query['count'] > $per_page;
        if ( $has_more && $query['count'] === count( $statuses ) ) {
            array_pop( $statuses );
        }

        $data = array_map( function( $status ) {
            return $status->get_frontend_config();
        }, $statuses );

        wp_send_json( [
            'success' => true,
            'data' => $data,
            'has_more' => $has_more,
            'meta' => [ 'review_config' => [] ],
        ] );
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

    protected function resolve_search_ids_from_request(): ?array {
        $referer = wp_get_referer();
        if ( ! $referer ) {
            return null;
        }

        $page_id = url_to_postid( $referer );
        if ( ! $page_id ) {
            return null;
        }

        $document = \Elementor\Plugin::$instance->documents->get_doc_for_frontend( $page_id );
        if ( ! $document ) {
            return null;
        }

        $timeline_element = $this->find_filtered_timeline( $document->get_elements_data() );
        if ( ! $timeline_element ) {
            return null;
        }

        $backup_get = $_GET;
        parse_str( (string) wp_parse_url( $referer, PHP_URL_QUERY ), $referer_query );
        $_GET = $referer_query;

        $ids = [];

        if ( $search_form = $this->locate_related_search_form( $timeline_element, $document->get_main_id() ) ) {
            $ids = $this->get_ids_from_search_widget( $search_form );
        } else {
            $manual_id = $timeline_element['settings']['wsp_search_form_id'] ?? null;
            if ( $manual_id ) {
                $ids = $this->get_ids_from_form_id( $manual_id );
            }
        }

        $_GET = $backup_get;

        return $ids;
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

    protected function get_ids_from_form_id( $form_id ): array {
        try {
            $form_id = absint( $form_id );
            if ( ! $form_id ) {
                return [];
            }

            $template = get_post( $form_id );
            if ( ! $template ) {
                return [];
            }

            $document = \Elementor\Plugin::$instance->documents->get_doc_for_frontend( $form_id );
            if ( ! $document ) {
                return [];
            }

            $elements = $document->get_elements_data();
            $search_widget = $this->find_first_search_form( $elements );
            if ( ! $search_widget ) {
                return [];
            }

            return $this->get_ids_from_search_widget( $search_widget );
        } catch ( \Throwable $e ) {
            return [];
        }
    }

    protected function find_first_search_form( array $elements ) {
        foreach ( $elements as $element ) {
            if ( isset( $element['widgetType'] ) && $element['widgetType'] === 'ts-search-form' ) {
                return $element;
            }

            if ( ! empty( $element['elements'] ) ) {
                if ( $found = $this->find_first_search_form( $element['elements'] ) ) {
                    return $found;
                }
            }
        }

        return null;
    }

    protected function locate_related_search_form( array $timeline_element, $template_id ) {
        try {
            $widget = new \Voxel\Widgets\Timeline( $timeline_element, [] );
            $search_form = \Voxel\get_related_widget( $widget, $template_id, 'timelineToSearch', 'right' );
            if ( $search_form ) {
                return $search_form;
            }

            return \Voxel\get_related_widget( $widget, $template_id, 'feedToSearch', 'right' );
        } catch ( \Throwable $e ) {
            return null;
        }
    }

    protected function find_filtered_timeline( array $elements ) {
        foreach ( $elements as $element ) {
            if ( isset( $element['widgetType'] ) && $element['widgetType'] === 'ts-timeline' ) {
                $mode = $element['settings']['ts_mode'] ?? 'user_feed';
                if ( $mode === 'filtered_feed' || ( $element['settings']['wsp_feed_mode'] ?? '' ) === 'filtered' ) {
                    return $element;
                }
            }

            if ( ! empty( $element['elements'] ) ) {
                if ( $found = $this->find_filtered_timeline( $element['elements'] ) ) {
                    return $found;
                }
            }
        }

        return null;
    }

    protected function apply_ordering( array $args ): array {
        $order = $_REQUEST['order_type'] ?? 'latest';

        if ( $order === 'earliest' ) {
            $args['order_by'] = 'created_at';
            $args['order'] = 'asc';
        } elseif ( $order === 'most_liked' ) {
            $args['order_by'] = 'like_count';
            $args['order'] = 'desc';
        } elseif ( $order === 'most_discussed' ) {
            $args['order_by'] = 'reply_count';
            $args['order'] = 'desc';
        } elseif ( $order === 'most_popular' ) {
            $args['order_by'] = 'interaction_count';
            $args['order'] = 'desc';
        } elseif ( $order === 'best_rated' ) {
            $args['order_by'] = 'rating';
            $args['order'] = 'desc';
        } elseif ( $order === 'worst_rated' ) {
            $args['order_by'] = 'rating';
            $args['order'] = 'asc';
        } else {
            $args['order_by'] = 'created_at';
            $args['order'] = 'desc';
        }

        return $args;
    }

    protected function apply_timeframe( array $args ): array {
        $time = $_REQUEST['order_time'] ?? 'all_time';

        if ( $time === 'today' ) {
            $args['created_at'] = \Voxel\utc()->modify( '-24 hours' )->format( 'Y-m-d H:i:s' );
        } elseif ( $time === 'this_week' ) {
            $args['created_at'] = \Voxel\utc()->modify( 'first day of this week' )->format( 'Y-m-d 00:00:00' );
        } elseif ( $time === 'this_month' ) {
            $args['created_at'] = \Voxel\utc()->modify( 'first day of this month' )->format( 'Y-m-d 00:00:00' );
        } elseif ( $time === 'this_year' ) {
            $args['created_at'] = \Voxel\utc()->modify( 'first day of this year' )->format( 'Y-m-d 00:00:00' );
        } elseif ( $time === 'custom' ) {
            $custom_time = absint( $_REQUEST['order_time_custom'] ?? null );
            if ( $custom_time ) {
                $args['created_at'] = \Voxel\utc()->modify( sprintf( '-%d days', $custom_time ) )->format( 'Y-m-d 00:00:00' );
            }
        }

        return $args;
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
