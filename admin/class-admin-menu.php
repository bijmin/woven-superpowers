<?php

namespace Woven\Superpowers;

if (!defined('ABSPATH')) exit;

class Admin_Menu {

    private $option_name = 'wsp_settings';

    public function enqueue_assets() {
    wp_enqueue_style(
        'wsp-admin-styles',
        plugin_dir_url(__FILE__) . 'admin-menu.css',
        [],
        '1.0'
    );
}


    public function __construct() {
         add_action('admin_menu', [$this, 'add_menu']);
    add_action('admin_init', [$this, 'register_settings']);
    add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function add_menu() {
        add_menu_page(
            'Woven Superpowers',
            'Woven Superpowers',
            'manage_options',
            'wsp-settings',
            [$this, 'settings_page'],
            'dashicons-superhero-alt',
            56
        );
    }

    public function register_settings() {
        register_setting( 'wsp_settings_group', $this->option_name );

        add_settings_section(
            'wsp_main_section',
            'Enable or disable experimental features.',
            null,
            'wsp-settings'
        );

        $features = [
            'timeline_filters' => 'Timeline Search Filtering',
            'dynamic_tags'     => 'Custom Dynamic Tags',
            'ai_enhancements'  => 'AI Engine Enhancements',
            'voxel_hooks'      => 'Voxel Template Hooks',
        ];

        foreach ( $features as $key => $label ) {
            add_settings_field(
                $key,
                $label,
                [$this, 'render_toggle'],
                'wsp-settings',
                'wsp_main_section',
                [ 'key' => $key ]
            );
        }
    }

    public function render_toggle($args) {
        $settings = get_option($this->option_name, []);
        $key = $args['key'];
        $value = $settings[$key] ?? false;

        echo '<label class="wsp-switch">';
        echo sprintf(
            '<input type="checkbox" name="%s[%s]" value="1" %s>',
            esc_attr($this->option_name),
            esc_attr($key),
            checked(1, $value, false)
        );
        echo '<span class="wsp-slider"></span>';
        echo '</label>';
    }

    public function settings_page() {
        echo '<div class="wrap">';
        echo '<h1>Woven Superpowers</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields( 'wsp_settings_group' );
        do_settings_sections( 'wsp-settings' );
        submit_button();
        echo '</form>';
        echo '</div>';
    }
}

new Admin_Menu();
