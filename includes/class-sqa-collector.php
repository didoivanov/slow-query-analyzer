<?php
/**
 * Records queries for requests made by the administrator who switched
 * profiling on. Only loaded when a valid profiling cookie is present.
 */

defined( 'ABSPATH' ) || exit;

class SQA_Collector {

	/** Repeated query shapes at or above this count are reported as patterns (likely N+1). */
	const PATTERN_MIN_COUNT = 3;

	private static $threshold = 0.05;
	private static $roots     = null;

	public static function boot() {
		if ( ! defined( 'SAVEQUERIES' ) ) {
			define( 'SAVEQUERIES', true );
		}
		if ( ! SAVEQUERIES ) {
			// Explicitly disabled in wp-config.php; the admin screen explains this.
			return;
		}

		$settings        = SQA_Store::settings();
		self::$threshold = $settings['threshold_ms'] / 1000;

		add_filter( 'log_query_custom_data', array( __CLASS__, 'tag_query' ), 10, 3 );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar_node' ), 999 );
		add_action( 'wp_footer', array( __CLASS__, 'admin_bar_script' ), PHP_INT_MAX );
		add_action( 'admin_print_footer_scripts', array( __CLASS__, 'admin_bar_script' ), PHP_INT_MAX );
		add_action( 'shutdown', array( __CLASS__, 'record' ), PHP_INT_MAX - 10 );
	}

	/**
	 * The cookie only says profiling was switched on in this browser; this
	 * confirms the request really belongs to that administrator.
	 */
	private static function is_authorized() {
		if ( ! did_action( 'plugins_loaded' ) || ! function_exists( 'get_current_user_id' ) ) {
			return false;
		}
		$user_id = SQA_Session::get_cookie_user_id();
		return $user_id && get_current_user_id() === $user_id && current_user_can( 'manage_options' );
	}

	/**
	 * Don't profile the plugin's own screens, they'd only add noise.
	 */
	private static function is_own_request() {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( is_admin() && isset( $_GET['page'] ) && 'slow-query-analyzer' === $_GET['page'] ) {
			return true;
		}
		$action = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? $_REQUEST['action'] : '';
		// phpcs:enable
		return 0 === strpos( $action, 'sqa_' );
	}

	/**
	 * Attach the plugin/theme responsible for a slow query. Runs inside
	 * wpdb::log_query(); the backtrace is only taken for slow queries.
	 */
	public static function tag_query( $data, $query, $time ) {
		if ( $time >= self::$threshold ) {
			$data['sqa_component'] = self::detect_component();
		}
		return $data;
	}

	private static function detect_component() {
		if ( null === self::$roots ) {
			self::$roots = array(
				'plugin'    => wp_normalize_path( WP_PLUGIN_DIR ) . '/',
				'mu-plugin' => wp_normalize_path( WPMU_PLUGIN_DIR ) . '/',
				'theme'     => wp_normalize_path( get_theme_root() ) . '/',
			);
		}
		$self = wp_normalize_path( SQA_DIR );

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
			if ( empty( $frame['file'] ) ) {
				continue;
			}
			$file = wp_normalize_path( $frame['file'] );
			if ( 0 === strpos( $file, $self ) ) {
				continue;
			}
			foreach ( self::$roots as $type => $root ) {
				if ( 0 === strpos( $file, $root ) ) {
					$relative = substr( $file, strlen( $root ) );
					$slug     = false !== strpos( $relative, '/' ) ? strstr( $relative, '/', true ) : basename( $relative, '.php' );
					return $type . ':' . $slug;
				}
			}
		}
		return 'core';
	}

	private static function context() {
		if ( wp_doing_cron() ) {
			return 'cron';
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'rest';
		}
		if ( wp_doing_ajax() ) {
			return 'ajax';
		}
		return is_admin() ? 'admin' : 'front';
	}

	/**
	 * Totals for the queries logged so far in this request.
	 */
	private static function live_stats() {
		global $wpdb;
		$stats = array(
			'count' => 0,
			'time'  => 0.0,
			'slow'  => 0,
		);
		foreach ( (array) $wpdb->queries as $query ) {
			$stats['count']++;
			$stats['time'] += $query[1];
			if ( $query[1] >= self::$threshold ) {
				$stats['slow']++;
			}
		}
		return $stats;
	}

	public static function admin_bar_node( $admin_bar ) {
		if ( ! self::is_authorized() ) {
			return;
		}
		$admin_bar->add_node(
			array(
				'id'    => 'sqa',
				'title' => '<span class="ab-icon dashicons dashicons-database" style="margin-top:2px"></span><span class="ab-label" id="sqa-ab-label">' . esc_html__( 'SQL profiling', 'slow-query-analyzer' ) . '</span>',
				'href'  => admin_url( 'tools.php?page=slow-query-analyzer' ),
				'meta'  => array( 'title' => __( 'Slow Query Analyzer is profiling your session', 'slow-query-analyzer' ) ),
			)
		);
	}

	/**
	 * Fill the admin bar node with the numbers known at the end of the page.
	 */
	public static function admin_bar_script() {
		if ( ! self::is_authorized() || ! is_admin_bar_showing() ) {
			return;
		}
		$stats = self::live_stats();
		$label = sprintf(
			/* translators: 1: number of queries, 2: total query time in ms, 3: number of slow queries */
			__( '%1$d queries · %2$s ms · %3$d slow', 'slow-query-analyzer' ),
			$stats['count'],
			number_format_i18n( $stats['time'] * 1000, 1 ),
			$stats['slow']
		);
		?>
		<script>
		(function () {
			var label = document.getElementById('sqa-ab-label');
			if (!label) { return; }
			label.textContent = <?php echo wp_json_encode( $label ); ?>;
			<?php if ( $stats['slow'] > 0 ) : ?>
			label.parentNode.style.background = '#b32d2e';
			<?php endif; ?>
		})();
		</script>
		<?php
	}

	public static function record() {
		try {
			if ( ! self::is_authorized() || self::is_own_request() ) {
				return;
			}
			self::save();
		} catch ( Throwable $e ) {
			// Never break the page because of profiling.
			return;
		}
	}

	private static function save() {
		global $wpdb;

		// Copy the log first; our own inserts below would otherwise be added to it.
		$log = (array) $wpdb->queries;
		if ( ! $log ) {
			return;
		}

		$total    = 0.0;
		$slow     = array();
		$exact    = array();
		$patterns = array();

		foreach ( $log as $entry ) {
			$sql    = (string) $entry[0];
			$time   = (float) $entry[1];
			$caller = isset( $entry[2] ) ? (string) $entry[2] : '';
			$data   = isset( $entry[4] ) && is_array( $entry[4] ) ? $entry[4] : array();

			$total += $time;

			$hash           = md5( $sql );
			$exact[ $hash ] = isset( $exact[ $hash ] ) ? $exact[ $hash ] + 1 : 1;

			$normalized  = SQA_Analyzer::normalize( $sql );
			$fingerprint = md5( $normalized );
			$short       = SQA_Analyzer::short_caller( $caller );

			if ( ! isset( $patterns[ $fingerprint ] ) ) {
				$patterns[ $fingerprint ] = array(
					'normalized' => $normalized,
					'count'      => 0,
					'time'       => 0.0,
					'callers'    => array(),
				);
			}
			$patterns[ $fingerprint ]['count']++;
			$patterns[ $fingerprint ]['time'] += $time;
			$patterns[ $fingerprint ]['callers'][ $short ] = isset( $patterns[ $fingerprint ]['callers'][ $short ] ) ? $patterns[ $fingerprint ]['callers'][ $short ] + 1 : 1;

			if ( $time >= self::$threshold ) {
				$slow[] = array(
					'fingerprint' => $fingerprint,
					'normalized'  => $normalized,
					'sql'         => $sql,
					'duration'    => $time,
					'caller'      => $caller,
					'component'   => isset( $data['sqa_component'] ) ? $data['sqa_component'] : '',
				);
			}
		}

		$duplicates = 0;
		foreach ( $exact as $count ) {
			$duplicates += $count - 1;
		}

		$repeated = array_filter(
			$patterns,
			function ( $pattern ) {
				return $pattern['count'] >= self::PATTERN_MIN_COUNT;
			}
		);
		uasort(
			$repeated,
			function ( $a, $b ) {
				return $b['count'] <=> $a['count'];
			}
		);
		$repeated = array_slice( $repeated, 0, 15 );

		// wp-admin polls AJAX/REST endpoints constantly (heartbeat, editor);
		// keep those only when they show a problem, so they don't bury real pages.
		$context = self::context();
		if ( in_array( $context, array( 'ajax', 'rest' ), true ) && ! $slow ) {
			$top = reset( $repeated );
			if ( ! $top || $top['count'] < 5 ) {
				return;
			}
		}

		foreach ( $repeated as &$pattern ) {
			arsort( $pattern['callers'] );
			$pattern['callers']    = array_slice( $pattern['callers'], 0, 3, true );
			$pattern['normalized'] = substr( $pattern['normalized'], 0, 2000 );
		}
		unset( $pattern );

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput
		$url = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$url = substr( esc_url_raw( home_url( $url ) ), 0, 2000 );
		$url = $url ? $url : '(unknown)';
		$start = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true );
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? substr( sanitize_key( $_SERVER['REQUEST_METHOD'] ), 0, 10 ) : '';
		// phpcs:enable

		SQA_Store::save_request(
			array(
				'url'             => $url,
				'method'          => strtoupper( $method ),
				'context'         => $context,
				'user_id'         => get_current_user_id(),
				'query_count'     => count( $log ),
				'query_time'      => $total,
				'duplicate_count' => $duplicates,
				'page_time'       => microtime( true ) - $start,
				'peak_memory'     => memory_get_peak_usage(),
				'patterns'        => array_values( $repeated ),
			),
			$slow
		);

		if ( false === get_transient( 'sqa_pruned' ) ) {
			SQA_Store::prune();
			set_transient( 'sqa_pruned', 1, HOUR_IN_SECONDS );
		}
	}
}
