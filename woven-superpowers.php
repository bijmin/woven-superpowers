<?php
/*
 * Plugin Name: Woven Superpowers
 * Plugin URI: https://github.com/bijmin/woven-superpowers
 * Description: Extends Voxel with new filtering and timeline features.
 * Version: 1.0.0
 * Author: Woven Social
 * Author URI: https://wovensocial.nz
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WOVEN_SUPERPOWERS_PATH', plugin_dir_path( __FILE__ ) );

// GitHub auto-updater config
defined( 'WSP_GITHUB_REPO' ) || define( 'WSP_GITHUB_REPO', 'bijmin/woven-superpowers' );
defined( 'WSP_GITHUB_BRANCH' ) || define( 'WSP_GITHUB_BRANCH', 'main' );

require_once __DIR__ . '/includes/class-github-updater.php';
require_once __DIR__ . '/includes/class-timeline-filter.php';
require_once __DIR__ . '/admin/class-admin-menu.php';


if ( is_admin() ) {
    new \Woven\Superpowers\GitHub_Updater( __FILE__, WSP_GITHUB_REPO, WSP_GITHUB_BRANCH );
}

add_action( 'init', function() {
    // Future stuff: dynamic tags, modules, etc.
});
