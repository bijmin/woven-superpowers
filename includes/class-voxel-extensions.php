<?php

if (!defined('ABSPATH')) exit;

class Woven_SP_Voxel_Extensions {

    public function __construct() {
        add_action('init', [$this, 'extend_voxel']);
    }

    public function extend_voxel() {
        // Extend Voxel functionality here
    }
}

new Woven_SP_Voxel_Extensions();
