<?php

if (!defined('ABSPATH')) exit;

class Woven_SP_Post_Types {

    public function __construct() {
        add_action('init', [$this, 'register']);
    }

    public function register() {
        // Register custom post types here
    }
}

new Woven_SP_Post_Types();
