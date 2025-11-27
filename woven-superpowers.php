<?php
/**
 * Plugin Name: Woven Superpowers
 * Description: Extends Voxel with new filtering and timeline features.
 * Version: 0.1
 * Author: Woven Social
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WOVEN_SUPERPOWERS_PATH', plugin_dir_path( __FILE__ ) );
defined( 'WSP_GITHUB_REPO' ) || define( 'WSP_GITHUB_REPO', 'your-account/woven-superpowers' );
defined( 'WSP_GITHUB_BRANCH' ) || define( 'WSP_GITHUB_BRANCH', 'main' );

require_once __DIR__ . '/includes/class-github-updater.php';
require_once __DIR__ . '/includes/class-timeline-filter.php';

if ( is_admin() ) {
	new \Woven\Superpowers\GitHub_Updater( __FILE__, WSP_GITHUB_REPO, WSP_GITHUB_BRANCH );
}

add_action( 'init', function() {
    // Future stuff: dynamic tags, modules, etc.
});
