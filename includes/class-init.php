<?php

if (!defined('ABSPATH')) exit;

class Woven_SP_Init {

    public function __construct() {
        add_action('init', [$this, 'register_features']);
    }

    public function register_features() {
        // Init hooks go here
    }
}

new Woven_SP_Init();
