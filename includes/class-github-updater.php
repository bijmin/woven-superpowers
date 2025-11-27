<?php

namespace Woven\Superpowers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GitHub_Updater {
	protected $plugin_file;
	protected $slug;
	protected $repo;
	protected $branch;
	protected $cache_key;

	public function __construct( $plugin_file, $repo, $branch = 'main' ) {
		$this->plugin_file = $plugin_file;
		$this->slug        = plugin_basename( $plugin_file );
		$this->repo        = trim( $repo );
		$this->branch      = $branch ?: 'main';
		$this->cache_key   = 'wsp_github_release_' . md5( $this->repo . $this->branch );

		if ( ! is_admin() ) {
			return;
		}

		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );
		add_filter( 'plugins_api', [ $this, 'plugins_api' ], 10, 3 );
		add_filter( 'upgrader_source_selection', [ $this, 'rename_package' ], 10, 4 );
	}

	public function check_for_update( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked[ $this->slug ] ) ) {
			return $transient;
		}

		$release = $this->get_release();
		if ( empty( $release['version'] ) || empty( $release['package'] ) ) {
			return $transient;
		}

		$current_version = ltrim( $transient->checked[ $this->slug ], 'v' );
		$remote_version  = ltrim( $release['version'], 'v' );

		if ( version_compare( $remote_version, $current_version, '<=' ) ) {
			return $transient;
		}

		$transient->response[ $this->slug ] = (object) [
			'slug'        => $this->slug,
			'plugin'      => $this->slug,
			'new_version' => $remote_version,
			'url'         => $release['url'] ?? '',
			'package'     => $release['package'],
			'tested'      => get_bloginfo( 'version' ),
			'requires'    => '5.0',
		];

		return $transient;
	}

	public function plugins_api( $result, $action, $args ) {
		if ( $action !== 'plugin_information' || ( $args->slug ?? '' ) !== $this->slug ) {
			return $result;
		}

		$release = $this->get_release();
		if ( empty( $release['version'] ) ) {
			return $result;
		}

		return (object) [
			'name'          => 'Woven Superpowers',
			'slug'          => $this->slug,
			'version'       => ltrim( $release['version'], 'v' ),
			'author'        => 'Woven Social',
			'homepage'      => $release['url'] ?? '',
			'download_link' => $release['package'],
			'sections'      => [
				'description' => __( 'Updates are delivered from GitHub releases.', 'wsp' ),
			],
		];
	}

	public function rename_package( $source, $remote_source, $upgrader, $hook_extra ) {
		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->slug ) {
			return $source;
		}

		$folder = trailingslashit( WP_PLUGIN_DIR ) . dirname( $this->slug );

		if ( ! is_dir( $folder ) ) {
			wp_mkdir_p( $folder );
		}

		$normalized = trailingslashit( $folder );
		if ( rename( $source, $normalized ) ) {
			return $normalized;
		}

		return $source;
	}

	protected function get_release(): array {
		$cached = get_site_transient( $this->cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$release = $this->fetch_release();
		set_site_transient( $this->cache_key, $release, HOUR_IN_SECONDS * 2 );

		return $release;
	}

	protected function fetch_release(): array {
		if ( empty( $this->repo ) ) {
			return [];
		}

		$headers = [
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'wsp-updater',
		];

		if ( $token = $this->get_token() ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . $this->repo . '/releases/latest',
			[ 'headers' => $headers, 'timeout' => 15 ]
		);

		if ( wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return $this->fallback_branch_release( $headers );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['zipball_url'] ) || empty( $body['tag_name'] ) ) {
			return $this->fallback_branch_release( $headers );
		}

		return [
			'version' => $body['tag_name'],
			'package' => $body['zipball_url'],
			'url'     => $body['html_url'] ?? '',
		];
	}

	protected function fallback_branch_release( array $headers ): array {
		$package = sprintf( 'https://api.github.com/repos/%s/zipball/%s', $this->repo, $this->branch );
		$version = $this->read_branch_version( $headers );

		return [
			'version' => $version,
			'package' => $package,
			'url'     => sprintf( 'https://github.com/%s/tree/%s', $this->repo, $this->branch ),
		];
	}

	protected function read_branch_version( array $headers ): string {
		$plugin_basename = plugin_basename( $this->plugin_file );
		$plugin_filename = basename( $plugin_basename );
		$path            = sprintf( 'https://raw.githubusercontent.com/%s/%s/%s', $this->repo, $this->branch, $plugin_filename );

		$response = wp_remote_get( $path, [ 'headers' => $headers, 'timeout' => 15 ] );
		if ( wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return '0.0.0';
		}

		$body = wp_remote_retrieve_body( $response );
		if ( preg_match( '/^[ \\t\\/*#@]*Version:\\s*(.*)$/mi', $body, $matches ) ) {
			return trim( $matches[1] );
		}

		return '0.0.0';
	}

	protected function get_token(): ?string {
		$token = defined( 'WSP_GITHUB_TOKEN' ) ? constant( 'WSP_GITHUB_TOKEN' ) : null;
		return apply_filters( 'wsp/github_updater/token', $token );
	}
}
