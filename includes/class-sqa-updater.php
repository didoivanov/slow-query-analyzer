<?php
/**
 * Updates from GitHub releases, so new versions show up in wp-admin → Plugins
 * like any wordpress.org plugin.
 *
 * Uses the plugin's "Update URI" header (WordPress 5.8+): WordPress asks this
 * class instead of wordpress.org whether a newer version exists. Private
 * repositories work too when a GitHub token is saved in the plugin settings
 * or defined as SQA_GITHUB_TOKEN in wp-config.php.
 */

defined( 'ABSPATH' ) || exit;

class SQA_Updater {

	const SLUG         = 'slow-query-analyzer';
	const ASSET_NAME   = 'slow-query-analyzer.zip';
	const CACHE_KEY    = 'sqa_github_release';
	const TOKEN_OPTION = 'sqa_github_token';

	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check_update' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'download_private_asset' ), 10, 2 );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );
		add_action( 'admin_post_sqa_check_updates', array( __CLASS__, 'handle_check_updates' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'clear_cache' ) );
	}

	public static function token() {
		if ( defined( 'SQA_GITHUB_TOKEN' ) && SQA_GITHUB_TOKEN ) {
			return (string) SQA_GITHUB_TOKEN;
		}
		return (string) get_option( self::TOKEN_OPTION, '' );
	}

	private static function api_url( $path ) {
		return 'https://api.github.com/repos/' . SQA_GITHUB_REPO . $path;
	}

	private static function headers( $accept ) {
		$headers = array(
			'Accept'     => $accept,
			'User-Agent' => 'slow-query-analyzer/' . SQA_VERSION,
		);
		if ( self::token() ) {
			$headers['Authorization'] = 'Bearer ' . self::token();
		}
		return $headers;
	}

	public static function clear_cache() {
		delete_site_transient( self::CACHE_KEY );
	}

	/**
	 * Latest GitHub release that has the plugin zip attached.
	 *
	 * @return array{version:string,package:string,url:string,notes:string,published:string}|null
	 */
	public static function latest_release() {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached ? $cached : null;
		}

		$response = wp_remote_get(
			self::api_url( '/releases/latest' ),
			array(
				'timeout' => 10,
				'headers' => self::headers( 'application/vnd.github+json' ),
			)
		);

		$release = null;
		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			foreach ( isset( $body['assets'] ) ? (array) $body['assets'] : array() as $asset ) {
				if ( isset( $asset['name'] ) && self::ASSET_NAME === $asset['name'] ) {
					$release = array(
						'version'   => ltrim( (string) $body['tag_name'], 'vV' ),
						// Private repos must download through the API with the token.
						'package'   => self::token() ? $asset['url'] : $asset['browser_download_url'],
						'url'       => (string) $body['html_url'],
						'notes'     => (string) $body['body'],
						'published' => (string) $body['published_at'],
					);
					break;
				}
			}
		}

		// Cache failures briefly so a GitHub outage doesn't slow down wp-admin.
		set_site_transient( self::CACHE_KEY, $release ? $release : array(), $release ? 6 * HOUR_IN_SECONDS : 15 * MINUTE_IN_SECONDS );

		return $release;
	}

	public static function check_update( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( SQA_FILE ) !== $plugin_file ) {
			return $update;
		}

		$release = self::latest_release();
		if ( ! $release ) {
			return $update;
		}

		return array(
			'slug'         => self::SLUG,
			'version'      => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'requires'     => '5.8',
			'requires_php' => '7.4',
		);
	}

	/**
	 * The "View details" popup on the Plugins screen.
	 */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}

		$release = self::latest_release();
		$notes   = $release && $release['notes'] ? $release['notes'] : __( 'No release notes.', 'slow-query-analyzer' );

		return (object) array(
			'name'          => 'Slow Query Analyzer',
			'slug'          => self::SLUG,
			'version'       => $release ? $release['version'] : SQA_VERSION,
			'homepage'      => 'https://github.com/' . SQA_GITHUB_REPO,
			'download_link' => $release ? $release['package'] : '',
			'last_updated'  => $release ? $release['published'] : '',
			'requires'      => '5.8',
			'requires_php'  => '7.4',
			'sections'      => array(
				'description' => esc_html__( 'Finds slow and repeated MySQL queries and explains why they are slow. Profiles only your own admin session — visitors are never affected.', 'slow-query-analyzer' ),
				'changelog'   => wpautop( esc_html( $notes ) ),
			),
		);
	}

	/**
	 * Private repos: GitHub answers the asset API URL with a redirect to a
	 * signed download URL, which must be fetched without our token.
	 */
	public static function download_private_asset( $reply, $package ) {
		if ( false !== $reply || 0 !== strpos( (string) $package, self::api_url( '/releases/assets/' ) ) ) {
			return $reply;
		}

		$response = wp_remote_get(
			$package,
			array(
				'timeout'     => 30,
				'redirection' => 0,
				'headers'     => self::headers( 'application/octet-stream' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
			return download_url( wp_remote_retrieve_header( $response, 'location' ) );
		}
		if ( 200 === $code ) {
			$file = wp_tempnam( self::ASSET_NAME );
			file_put_contents( $file, wp_remote_retrieve_body( $response ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return $file;
		}

		/* translators: %d: HTTP status code */
		return new WP_Error( 'sqa_download_failed', sprintf( __( 'GitHub refused the download (HTTP %d). Check the GitHub token in Tools → Slow Queries → Settings.', 'slow-query-analyzer' ), $code ) );
	}

	public static function row_meta( $links, $plugin_file ) {
		if ( plugin_basename( SQA_FILE ) === $plugin_file && current_user_can( 'update_plugins' ) ) {
			$url     = wp_nonce_url( admin_url( 'admin-post.php?action=sqa_check_updates' ), 'sqa_check_updates' );
			$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Check for updates', 'slow-query-analyzer' ) . '</a>';
		}
		return $links;
	}

	public static function handle_check_updates() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'slow-query-analyzer' ), 403 );
		}
		check_admin_referer( 'sqa_check_updates' );

		self::clear_cache();
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();

		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;
	}
}
