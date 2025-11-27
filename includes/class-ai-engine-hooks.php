<?php

if (!defined('ABSPATH')) exit;

class Woven_SP_AI_Engine {

    public function __construct() {
        add_action('init', [$this, 'connect_ai']);
    }

    public function connect_ai() {
        // AI Engine hooks go here
    }
}

new Woven_SP_AI_Engine();
