<?php
/**
 * Tools → Slow Queries screen.
 */

defined( 'ABSPATH' ) || exit;

class SQA_Admin {

	const SLUG       = 'slow-query-analyzer';
	const CAPABILITY = 'manage_options';
	const PER_PAGE   = 50;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( 'SQA_Store', 'maybe_upgrade' ) );
		add_action( 'admin_post_sqa_start', array( __CLASS__, 'handle_start' ) );
		add_action( 'admin_post_sqa_stop', array( __CLASS__, 'handle_stop' ) );
		add_action( 'admin_post_sqa_settings', array( __CLASS__, 'handle_settings' ) );
		add_action( 'admin_post_sqa_clear', array( __CLASS__, 'handle_clear' ) );
		add_action( 'wp_ajax_sqa_explain', array( __CLASS__, 'ajax_explain' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SQA_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function menu() {
		$hook = add_management_page(
			__( 'Slow Query Analyzer', 'slow-query-analyzer' ),
			__( 'Slow Queries', 'slow-query-analyzer' ),
			self::CAPABILITY,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
		add_action( 'admin_print_styles-' . $hook, array( __CLASS__, 'assets' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Open', 'slow-query-analyzer' ) . '</a>' );
		return $links;
	}

	public static function assets() {
		wp_enqueue_style( 'sqa-admin', SQA_URL . 'assets/admin.css', array(), SQA_VERSION );
		wp_enqueue_script( 'sqa-admin', SQA_URL . 'assets/admin.js', array(), SQA_VERSION, true );
		wp_localize_script(
			'sqa-admin',
			'sqaAdmin',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'sqa_explain' ),
				'analyzing' => __( 'Analyzing…', 'slow-query-analyzer' ),
				'failed'    => __( 'Analysis failed.', 'slow-query-analyzer' ),
			)
		);
	}

	private static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'tools.php' ) );
	}

	private static function check( $nonce_action ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'slow-query-analyzer' ), 403 );
		}
		check_admin_referer( $nonce_action );
	}

	/* ---------------------------------------------------------------------
	 * Actions
	 * ------------------------------------------------------------------- */

	public static function handle_start() {
		self::check( 'sqa_start' );
		$settings = SQA_Store::settings();
		$minutes  = isset( $_POST['duration'] ) ? absint( $_POST['duration'] ) : $settings['duration'];
		$minutes  = max( 5, min( 1440, $minutes ) );

		if ( $minutes !== (int) $settings['duration'] ) {
			$settings['duration'] = $minutes;
			SQA_Store::save_settings( $settings );
		}

		SQA_Store::maybe_upgrade();
		SQA_Session::start( get_current_user_id(), $minutes );
		wp_safe_redirect( self::url( array( 'sqa_msg' => 'started' ) ) );
		exit;
	}

	public static function handle_stop() {
		self::check( 'sqa_stop' );
		SQA_Session::clear_cookie();
		wp_safe_redirect( self::url( array( 'sqa_msg' => 'stopped' ) ) );
		exit;
	}

	public static function handle_settings() {
		self::check( 'sqa_settings' );
		$current = SQA_Store::settings();
		SQA_Store::save_settings(
			array(
				'threshold_ms'   => isset( $_POST['threshold_ms'] ) ? absint( $_POST['threshold_ms'] ) : $current['threshold_ms'],
				'retention_days' => isset( $_POST['retention_days'] ) ? absint( $_POST['retention_days'] ) : $current['retention_days'],
				'max_requests'   => isset( $_POST['max_requests'] ) ? absint( $_POST['max_requests'] ) : $current['max_requests'],
				'duration'       => $current['duration'],
			)
		);

		// An empty field keeps the saved token; the checkbox removes it.
		$token = isset( $_POST['github_token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['github_token'] ) ) ) : '';
		if ( ! empty( $_POST['github_token_remove'] ) ) {
			delete_option( SQA_Updater::TOKEN_OPTION );
			SQA_Updater::clear_cache();
		} elseif ( '' !== $token ) {
			update_option( SQA_Updater::TOKEN_OPTION, $token, false );
			SQA_Updater::clear_cache();
		}

		wp_safe_redirect( self::url( array( 'tab' => 'settings', 'sqa_msg' => 'saved' ) ) );
		exit;
	}

	public static function handle_clear() {
		self::check( 'sqa_clear' );
		SQA_Store::clear();
		wp_safe_redirect( self::url( array( 'tab' => 'settings', 'sqa_msg' => 'cleared' ) ) );
		exit;
	}

	public static function ajax_explain() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( null, 403 );
		}
		check_ajax_referer( 'sqa_explain' );

		$fingerprint = isset( $_POST['fingerprint'] ) ? sanitize_key( wp_unslash( $_POST['fingerprint'] ) ) : '';
		$sample      = $fingerprint ? SQA_Store::worst_sample( $fingerprint ) : null;
		if ( ! $sample ) {
			wp_send_json_error( array( 'html' => '<p>' . esc_html__( 'Query not found — the log may have been cleared.', 'slow-query-analyzer' ) . '</p>' ) );
		}

		$explain = SQA_Analyzer::explain( $sample['sql_text'] );
		$hints   = SQA_Analyzer::hints( $sample['sql_text'], $explain['rows'] );

		ob_start();
		self::render_analysis( $sample, $explain, $hints );
		wp_send_json_success( array( 'html' => ob_get_clean() ) );
	}

	/* ---------------------------------------------------------------------
	 * Screen
	 * ------------------------------------------------------------------- */

	public static function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$tab     = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'queries';
		$request = isset( $_GET['request'] ) ? absint( $_GET['request'] ) : 0;
		$message = isset( $_GET['sqa_msg'] ) ? sanitize_key( $_GET['sqa_msg'] ) : '';
		// phpcs:enable

		$tabs = array(
			'queries'  => __( 'Slow queries', 'slow-query-analyzer' ),
			'requests' => __( 'Profiled pages', 'slow-query-analyzer' ),
			'health'   => __( 'Database health', 'slow-query-analyzer' ),
			'settings' => __( 'Settings', 'slow-query-analyzer' ),
		);
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'queries';
		}
		?>
		<div class="wrap sqa-wrap">
			<h1><?php esc_html_e( 'Slow Query Analyzer', 'slow-query-analyzer' ); ?></h1>

			<?php self::render_message( $message ); ?>
			<?php self::render_status(); ?>

			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( self::url( array( 'tab' => $key ) ) ); ?>" class="nav-tab <?php echo $key === $tab ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<div class="sqa-tab">
				<?php
				if ( 'requests' === $tab && $request ) {
					self::render_request( $request );
				} else {
					call_user_func( array( __CLASS__, 'render_' . $tab ) );
				}
				?>
			</div>
		</div>
		<?php
	}

	private static function render_message( $message ) {
		$messages = array(
			'started' => __( 'Profiling started. Browse the pages you want to check — front end or admin — then come back here.', 'slow-query-analyzer' ),
			'stopped' => __( 'Profiling stopped.', 'slow-query-analyzer' ),
			'saved'   => __( 'Settings saved.', 'slow-query-analyzer' ),
			'cleared' => __( 'All logged data was deleted.', 'slow-query-analyzer' ),
		);
		if ( isset( $messages[ $message ] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $messages[ $message ] ) );
		}
	}

	private static function render_status() {
		$cookie   = SQA_Session::get_cookie();
		$active   = $cookie && get_current_user_id() === $cookie['user_id'];
		$settings = SQA_Store::settings();

		if ( defined( 'SAVEQUERIES' ) && ! SAVEQUERIES ) {
			echo '<div class="notice notice-error"><p>' . wp_kses_post( __( '<strong>SAVEQUERIES is set to false in wp-config.php</strong>, so WordPress will not log queries and nothing can be recorded. Remove that line to use this plugin.', 'slow-query-analyzer' ) ) . '</p></div>';
		} elseif ( defined( 'SAVEQUERIES' ) && SAVEQUERIES && ! $active ) {
			echo '<div class="notice notice-warning"><p>' . wp_kses_post( __( '<strong>SAVEQUERIES is enabled in wp-config.php</strong> for every request, which slows the site down for visitors too. You can remove it — this plugin turns query logging on only for your own profiling session.', 'slow-query-analyzer' ) ) . '</p></div>';
		}
		?>
		<div class="sqa-status <?php echo $active ? 'is-on' : 'is-off'; ?>">
			<?php if ( $active ) : ?>
				<p>
					<span class="sqa-dot"></span>
					<strong><?php esc_html_e( 'Profiling is ON for your browser session', 'slow-query-analyzer' ); ?></strong>
					<?php
					printf(
						/* translators: 1: time, 2: human time difference */
						esc_html__( 'until %1$s (%2$s left). Only your requests are recorded; visitors are not affected.', 'slow-query-analyzer' ),
						esc_html( wp_date( get_option( 'time_format' ), $cookie['expires'] ) ),
						esc_html( human_time_diff( time(), $cookie['expires'] ) )
					);
					?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="sqa_stop">
					<?php wp_nonce_field( 'sqa_stop' ); ?>
					<?php submit_button( __( 'Stop profiling', 'slow-query-analyzer' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php else : ?>
				<p>
					<span class="sqa-dot"></span>
					<strong><?php esc_html_e( 'Profiling is OFF.', 'slow-query-analyzer' ); ?></strong>
					<?php esc_html_e( 'Nothing runs for visitors. Start profiling to record queries for pages you open in this browser while logged in.', 'slow-query-analyzer' ); ?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="sqa_start">
					<?php wp_nonce_field( 'sqa_start' ); ?>
					<label for="sqa-duration"><?php esc_html_e( 'Run for', 'slow-query-analyzer' ); ?></label>
					<select name="duration" id="sqa-duration">
						<?php
						$durations = array(
							15   => __( '15 minutes', 'slow-query-analyzer' ),
							30   => __( '30 minutes', 'slow-query-analyzer' ),
							60   => __( '1 hour', 'slow-query-analyzer' ),
							240  => __( '4 hours', 'slow-query-analyzer' ),
							1440 => __( '24 hours', 'slow-query-analyzer' ),
						);
						foreach ( $durations as $minutes => $label ) {
							printf( '<option value="%d" %s>%s</option>', (int) $minutes, selected( (int) $settings['duration'], $minutes, false ), esc_html( $label ) );
						}
						?>
					</select>
					<?php submit_button( __( 'Start profiling', 'slow-query-analyzer' ), 'primary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_queries() {
		$summary  = SQA_Store::summary();
		$groups   = SQA_Store::slow_groups( 100 );
		$settings = SQA_Store::settings();
		?>
		<div class="sqa-cards">
			<?php
			self::card( __( 'Profiled pages', 'slow-query-analyzer' ), number_format_i18n( $summary['requests'] ) );
			self::card( __( 'Slow queries logged', 'slow-query-analyzer' ), number_format_i18n( $summary['slow'] ) );
			self::card( __( 'Distinct slow queries', 'slow-query-analyzer' ), number_format_i18n( $summary['patterns'] ) );
			self::card( __( 'Slowest query', 'slow-query-analyzer' ), self::ms( $summary['slowest'] ) );
			?>
		</div>

		<p class="description">
			<?php
			printf(
				/* translators: %d: threshold in milliseconds */
				esc_html__( 'Queries taking %d ms or longer, grouped by shape (values replaced with ?) and sorted by total time spent. Click Analyze to run EXPLAIN on the slowest instance and get suggestions.', 'slow-query-analyzer' ),
				(int) $settings['threshold_ms']
			);
			?>
		</p>

		<?php if ( ! $groups ) : ?>
			<div class="sqa-empty">
				<p><?php esc_html_e( 'No slow queries recorded yet. Start profiling, browse your site (the pages that feel slow are the best place to start), then come back.', 'slow-query-analyzer' ); ?></p>
			</div>
			<?php
			return;
		endif;
		?>

		<table class="widefat striped sqa-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Query', 'slow-query-analyzer' ); ?></th>
					<th class="num"><?php esc_html_e( 'Times', 'slow-query-analyzer' ); ?></th>
					<th class="num"><?php esc_html_e( 'Avg', 'slow-query-analyzer' ); ?></th>
					<th class="num"><?php esc_html_e( 'Max', 'slow-query-analyzer' ); ?></th>
					<th class="num"><?php esc_html_e( 'Total', 'slow-query-analyzer' ); ?></th>
					<th><?php esc_html_e( 'Source', 'slow-query-analyzer' ); ?></th>
					<th><?php esc_html_e( 'Last seen', 'slow-query-analyzer' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $groups as $group ) : ?>
					<tr>
						<td class="sqa-sql-cell"><?php self::sql_block( $group['normalized'] ); ?></td>
						<td class="num"><?php echo esc_html( number_format_i18n( $group['hits'] ) ); ?></td>
						<td class="num"><?php echo esc_html( self::ms( $group['avg_time'] ) ); ?></td>
						<td class="num"><?php echo esc_html( self::ms( $group['max_time'] ) ); ?></td>
						<td class="num"><strong><?php echo esc_html( self::ms( $group['total_time'] ) ); ?></strong></td>
						<td><?php echo self::components( $group['components'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td><?php echo esc_html( self::when( $group['last_seen'] ) ); ?></td>
						<td><button type="button" class="button sqa-analyze" data-fingerprint="<?php echo esc_attr( $group['fingerprint'] ); ?>"><?php esc_html_e( 'Analyze', 'slow-query-analyzer' ); ?></button></td>
					</tr>
					<tr class="sqa-analysis-row" hidden>
						<td colspan="8"><div class="sqa-analysis"></div></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_requests() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$order = isset( $_GET['order'] ) && 'slowest' === $_GET['order'] ? 'slowest' : 'recent';
		// phpcs:enable

		$summary = SQA_Store::summary();
		$rows    = SQA_Store::recent_requests( self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE, $order );
		$pages   = (int) ceil( $summary['requests'] / self::PER_PAGE );
		?>
		<p class="sqa-toolbar">
			<?php esc_html_e( 'Sort:', 'slow-query-analyzer' ); ?>
			<a href="<?php echo esc_url( self::url( array( 'tab' => 'requests' ) ) ); ?>" class="<?php echo 'recent' === $order ? 'current' : ''; ?>"><?php esc_html_e( 'Most recent', 'slow-query-analyzer' ); ?></a> |
			<a href="<?php echo esc_url( self::url( array( 'tab' => 'requests', 'order' => 'slowest' ) ) ); ?>" class="<?php echo 'slowest' === $order ? 'current' : ''; ?>"><?php esc_html_e( 'Most query time', 'slow-query-analyzer' ); ?></a>
		</p>

		<?php if ( ! $rows ) : ?>
			<div class="sqa-empty"><p><?php esc_html_e( 'No pages profiled yet.', 'slow-query-analyzer' ); ?></p></div>
			<?php
			return;
		endif;
		?>

		<table class="widefat striped sqa-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'When', 'slow-query-analyzer' ); ?></th>
					<th><?php esc_html_e( 'Page', 'slow-query-analyzer' ); ?></th>
					<th class="num"><?php esc_html_e( 'Queries', 'slow-query-analyzer' ); ?></th>
					<th class="num"><?php esc_html_e( 'Query time', 'slow-query-analyzer' ); ?></th>
					<th class="num"><?php esc_html_e( 'Slow', 'slow-query-analyzer' ); ?></th>
					<th class="num"><?php esc_html_e( 'Duplicates', 'slow-query-analyzer' ); ?></th>
					<th class="num"><?php esc_html_e( 'Page time', 'slow-query-analyzer' ); ?></th>
					<th class="num"><?php esc_html_e( 'Memory', 'slow-query-analyzer' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( self::when( $row['created_at'] ) ); ?></td>
						<td class="sqa-url">
							<span class="sqa-badge sqa-ctx-<?php echo esc_attr( $row['context'] ); ?>"><?php echo esc_html( $row['context'] ); ?></span>
							<span class="sqa-method"><?php echo esc_html( $row['method'] ); ?></span>
							<a href="<?php echo esc_url( self::url( array( 'tab' => 'requests', 'request' => $row['id'] ) ) ); ?>"><?php echo esc_html( self::short_url( $row['url'] ) ); ?></a>
						</td>
						<td class="num"><?php echo esc_html( number_format_i18n( $row['query_count'] ) ); ?></td>
						<td class="num"><?php echo esc_html( self::ms( $row['query_time'] ) ); ?></td>
						<td class="num <?php echo $row['slow_count'] ? 'sqa-bad' : ''; ?>"><?php echo esc_html( number_format_i18n( $row['slow_count'] ) ); ?></td>
						<td class="num"><?php echo esc_html( number_format_i18n( $row['duplicate_count'] ) ); ?></td>
						<td class="num"><?php echo esc_html( self::ms( $row['page_time'] ) ); ?></td>
						<td class="num"><?php echo esc_html( size_format( $row['peak_memory'], 1 ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $paged,
						'total'   => $pages,
					)
				)
			);
			echo '</div></div>';
		}
	}

	private static function render_request( $id ) {
		$request = SQA_Store::get_request( $id );
		echo '<p><a href="' . esc_url( self::url( array( 'tab' => 'requests' ) ) ) . '">&larr; ' . esc_html__( 'All profiled pages', 'slow-query-analyzer' ) . '</a></p>';

		if ( ! $request ) {
			echo '<div class="sqa-empty"><p>' . esc_html__( 'This request is no longer in the log.', 'slow-query-analyzer' ) . '</p></div>';
			return;
		}

		$queries = SQA_Store::request_queries( $id );
		$share   = $request['page_time'] > 0 ? min( 100, $request['query_time'] / $request['page_time'] * 100 ) : 0;
		?>
		<h2 class="sqa-request-title">
			<span class="sqa-badge sqa-ctx-<?php echo esc_attr( $request['context'] ); ?>"><?php echo esc_html( $request['context'] ); ?></span>
			<?php echo esc_html( $request['method'] . ' ' . $request['url'] ); ?>
		</h2>
		<p class="description"><?php echo esc_html( self::when( $request['created_at'] ) ); ?></p>

		<div class="sqa-cards">
			<?php
			self::card( __( 'Queries', 'slow-query-analyzer' ), number_format_i18n( $request['query_count'] ) );
			self::card( __( 'Query time', 'slow-query-analyzer' ), self::ms( $request['query_time'] ), sprintf( /* translators: %s: percentage */ __( '%s%% of page time', 'slow-query-analyzer' ), number_format_i18n( $share, 0 ) ) );
			self::card( __( 'Page time', 'slow-query-analyzer' ), self::ms( $request['page_time'] ) );
			self::card( __( 'Slow queries', 'slow-query-analyzer' ), number_format_i18n( $request['slow_count'] ) );
			self::card( __( 'Exact duplicates', 'slow-query-analyzer' ), number_format_i18n( $request['duplicate_count'] ) );
			?>
		</div>

		<h3><?php esc_html_e( 'Slow queries on this page', 'slow-query-analyzer' ); ?></h3>
		<?php if ( ! $queries ) : ?>
			<p><?php esc_html_e( 'None — every query was below the slow threshold.', 'slow-query-analyzer' ); ?></p>
		<?php else : ?>
			<table class="widefat striped sqa-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Query', 'slow-query-analyzer' ); ?></th>
						<th class="num"><?php esc_html_e( 'Time', 'slow-query-analyzer' ); ?></th>
						<th><?php esc_html_e( 'Source', 'slow-query-analyzer' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $queries as $query ) : ?>
						<tr>
							<td class="sqa-sql-cell">
								<?php self::sql_block( $query['sql_text'] ); ?>
								<div class="sqa-caller"><?php echo esc_html( $query['caller'] ); ?></div>
							</td>
							<td class="num"><strong><?php echo esc_html( self::ms( $query['duration'] ) ); ?></strong></td>
							<td><?php echo self::components( $query['component'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							<td><button type="button" class="button sqa-analyze" data-fingerprint="<?php echo esc_attr( $query['fingerprint'] ); ?>"><?php esc_html_e( 'Analyze', 'slow-query-analyzer' ); ?></button></td>
						</tr>
						<tr class="sqa-analysis-row" hidden>
							<td colspan="4"><div class="sqa-analysis"></div></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h3><?php esc_html_e( 'Repeated queries', 'slow-query-analyzer' ); ?></h3>
		<p class="description"><?php esc_html_e( 'The same query shape running many times in one page load is often worse than a single slow query (the "N+1" problem).', 'slow-query-analyzer' ); ?></p>
		<?php if ( ! $request['patterns'] ) : ?>
			<p><?php esc_html_e( 'No query ran 3 or more times.', 'slow-query-analyzer' ); ?></p>
		<?php else : ?>
			<table class="widefat striped sqa-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Query shape', 'slow-query-analyzer' ); ?></th>
						<th class="num"><?php esc_html_e( 'Times', 'slow-query-analyzer' ); ?></th>
						<th class="num"><?php esc_html_e( 'Total time', 'slow-query-analyzer' ); ?></th>
						<th><?php esc_html_e( 'Called from', 'slow-query-analyzer' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $request['patterns'] as $pattern ) : ?>
						<tr>
							<td class="sqa-sql-cell">
								<?php self::sql_block( $pattern['normalized'] ); ?>
								<?php $advice = SQA_Analyzer::pattern_hint( (int) $pattern['count'] ); ?>
								<?php if ( $advice ) : ?>
									<div class="sqa-hint sqa-hint-<?php echo $pattern['count'] >= 20 ? 'high' : 'medium'; ?>"><?php echo esc_html( $advice ); ?></div>
								<?php endif; ?>
							</td>
							<td class="num"><strong><?php echo esc_html( number_format_i18n( $pattern['count'] ) ); ?></strong></td>
							<td class="num"><?php echo esc_html( self::ms( $pattern['time'] ) ); ?></td>
							<td>
								<?php foreach ( (array) $pattern['callers'] as $caller => $count ) : ?>
									<code><?php echo esc_html( $caller ); ?></code> &times;<?php echo esc_html( number_format_i18n( $count ) ); ?><br>
								<?php endforeach; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	private static function render_health() {
		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );

		$autoload_values = "'yes','on','auto-on','auto'";
		$autoload        = $wpdb->get_row( "SELECT COUNT(*) AS cnt, COALESCE(SUM(LENGTH(option_value)),0) AS size FROM {$wpdb->options} WHERE autoload IN ({$autoload_values})", ARRAY_A );
		$autoload_top    = $wpdb->get_results( "SELECT option_name, LENGTH(option_value) AS size FROM {$wpdb->options} WHERE autoload IN ({$autoload_values}) ORDER BY size DESC LIMIT 10", ARRAY_A );
		$expired         = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				time()
			)
		);
		$revisions       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" );
		$tables          = $wpdb->get_results(
			"SELECT TABLE_NAME AS name, ENGINE AS engine, TABLE_ROWS AS row_count,
				DATA_LENGTH AS data_size, INDEX_LENGTH AS index_size, DATA_FREE AS free_size
			FROM information_schema.TABLES
			WHERE TABLE_SCHEMA = DATABASE()
			ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC
			LIMIT 15",
			ARRAY_A
		);
		$variables       = $wpdb->get_results( "SHOW VARIABLES WHERE Variable_name IN ('slow_query_log','long_query_time','innodb_buffer_pool_size','max_connections','version_comment')", ARRAY_A );

		$wpdb->suppress_errors( $suppress );

		$vars = array();
		foreach ( (array) $variables as $variable ) {
			$vars[ $variable['Variable_name'] ] = $variable['Value'];
		}

		$autoload_size = isset( $autoload['size'] ) ? (int) $autoload['size'] : 0;
		$checks        = array();

		$checks[] = array(
			'level' => $autoload_size > 800 * KB_IN_BYTES ? 'high' : ( $autoload_size > 400 * KB_IN_BYTES ? 'medium' : 'ok' ),
			'label' => __( 'Autoloaded options', 'slow-query-analyzer' ),
			'value' => sprintf(
				/* translators: 1: size, 2: number of options */
				__( '%1$s in %2$s options', 'slow-query-analyzer' ),
				size_format( $autoload_size, 1 ),
				number_format_i18n( isset( $autoload['cnt'] ) ? $autoload['cnt'] : 0 )
			),
			'help'  => __( 'Loaded on every single page view. Keep it well under 800 KB; large entries usually belong to plugins that store caches or logs in options.', 'slow-query-analyzer' ),
		);
		$checks[] = array(
			'level' => wp_using_ext_object_cache() ? 'ok' : 'medium',
			'label' => __( 'Persistent object cache', 'slow-query-analyzer' ),
			'value' => wp_using_ext_object_cache() ? __( 'Active', 'slow-query-analyzer' ) : __( 'Not active', 'slow-query-analyzer' ),
			'help'  => __( 'Redis or Memcached lets WordPress skip many repeated queries between page loads. Ask your host if it is available.', 'slow-query-analyzer' ),
		);
		$checks[] = array(
			'level' => $expired > 1000 ? 'medium' : 'ok',
			'label' => __( 'Expired transients', 'slow-query-analyzer' ),
			'value' => number_format_i18n( $expired ),
			'help'  => __( 'Leftover cache entries in the options table. Large numbers bloat it; they are safe to delete.', 'slow-query-analyzer' ),
		);
		$checks[] = array(
			'level' => $revisions > 10000 ? 'medium' : 'ok',
			'label' => __( 'Post revisions', 'slow-query-analyzer' ),
			'value' => number_format_i18n( $revisions ),
			'help'  => __( 'Old revisions grow the posts and postmeta tables. Limit them with WP_POST_REVISIONS in wp-config.php.', 'slow-query-analyzer' ),
		);
		if ( isset( $vars['slow_query_log'] ) ) {
			$checks[] = array(
				'level' => 'info',
				'label' => __( 'MySQL slow query log', 'slow-query-analyzer' ),
				'value' => sprintf(
					/* translators: 1: ON/OFF, 2: seconds */
					__( '%1$s (long_query_time = %2$ss)', 'slow-query-analyzer' ),
					$vars['slow_query_log'],
					isset( $vars['long_query_time'] ) ? $vars['long_query_time'] : '?'
				),
				'help'  => __( 'The server-level log, independent of this plugin. Useful for catching slow queries from cron jobs and visitors.', 'slow-query-analyzer' ),
			);
		}
		?>
		<h2><?php esc_html_e( 'Checks', 'slow-query-analyzer' ); ?></h2>
		<table class="widefat sqa-table sqa-health">
			<tbody>
				<?php foreach ( $checks as $check ) : ?>
					<tr>
						<td class="sqa-health-status"><span class="sqa-pill sqa-pill-<?php echo esc_attr( $check['level'] ); ?>"><?php echo esc_html( self::level_label( $check['level'] ) ); ?></span></td>
						<th scope="row"><?php echo esc_html( $check['label'] ); ?></th>
						<td><strong><?php echo esc_html( $check['value'] ); ?></strong><p class="description"><?php echo esc_html( $check['help'] ); ?></p></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $autoload_top ) : ?>
			<h2><?php esc_html_e( 'Largest autoloaded options', 'slow-query-analyzer' ); ?></h2>
			<table class="widefat striped sqa-table">
				<thead><tr><th><?php esc_html_e( 'Option', 'slow-query-analyzer' ); ?></th><th class="num"><?php esc_html_e( 'Size', 'slow-query-analyzer' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $autoload_top as $option ) : ?>
						<tr><td><code><?php echo esc_html( $option['option_name'] ); ?></code></td><td class="num"><?php echo esc_html( size_format( (int) $option['size'], 1 ) ); ?></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( $tables ) : ?>
			<h2><?php esc_html_e( 'Largest tables', 'slow-query-analyzer' ); ?></h2>
			<table class="widefat striped sqa-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Table', 'slow-query-analyzer' ); ?></th>
						<th><?php esc_html_e( 'Engine', 'slow-query-analyzer' ); ?></th>
						<th class="num"><?php esc_html_e( 'Rows (approx.)', 'slow-query-analyzer' ); ?></th>
						<th class="num"><?php esc_html_e( 'Data', 'slow-query-analyzer' ); ?></th>
						<th class="num"><?php esc_html_e( 'Indexes', 'slow-query-analyzer' ); ?></th>
						<th class="num"><?php esc_html_e( 'Reclaimable', 'slow-query-analyzer' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $tables as $table ) : ?>
						<tr>
							<td><code><?php echo esc_html( $table['name'] ); ?></code></td>
							<td class="<?php echo 'MyISAM' === $table['engine'] ? 'sqa-bad' : ''; ?>" title="<?php echo 'MyISAM' === $table['engine'] ? esc_attr__( 'MyISAM locks the whole table on writes. Converting to InnoDB is usually recommended.', 'slow-query-analyzer' ) : ''; ?>"><?php echo esc_html( $table['engine'] ); ?></td>
							<td class="num"><?php echo esc_html( number_format_i18n( (int) $table['row_count'] ) ); ?></td>
							<td class="num"><?php echo esc_html( size_format( (int) $table['data_size'], 1 ) ); ?></td>
							<td class="num"><?php echo esc_html( size_format( (int) $table['index_size'], 1 ) ); ?></td>
							<td class="num"><?php echo (int) $table['free_size'] ? esc_html( size_format( (int) $table['free_size'], 1 ) ) : '—'; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Server', 'slow-query-analyzer' ); ?></h2>
		<table class="widefat striped sqa-table">
			<tbody>
				<tr><th scope="row"><?php esc_html_e( 'Database server', 'slow-query-analyzer' ); ?></th><td><?php echo esc_html( trim( $wpdb->db_server_info() . ' ' . ( isset( $vars['version_comment'] ) ? '(' . $vars['version_comment'] . ')' : '' ) ) ); ?></td></tr>
				<?php if ( isset( $vars['innodb_buffer_pool_size'] ) ) : ?>
					<tr><th scope="row">innodb_buffer_pool_size</th><td><?php echo esc_html( size_format( (int) $vars['innodb_buffer_pool_size'], 1 ) ); ?></td></tr>
				<?php endif; ?>
				<?php if ( isset( $vars['max_connections'] ) ) : ?>
					<tr><th scope="row">max_connections</th><td><?php echo esc_html( $vars['max_connections'] ); ?></td></tr>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_settings() {
		$settings = SQA_Store::settings();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="sqa_settings">
			<?php wp_nonce_field( 'sqa_settings' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="sqa-threshold"><?php esc_html_e( 'Slow query threshold', 'slow-query-analyzer' ); ?></label></th>
					<td>
						<input type="number" min="1" max="60000" id="sqa-threshold" name="threshold_ms" value="<?php echo esc_attr( $settings['threshold_ms'] ); ?>" class="small-text"> ms
						<p class="description"><?php esc_html_e( 'Queries at or above this time are logged as slow. 50 ms is a good start; lower it to 10–20 ms on a fast server to catch more.', 'slow-query-analyzer' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="sqa-retention"><?php esc_html_e( 'Keep data for', 'slow-query-analyzer' ); ?></label></th>
					<td><input type="number" min="1" max="365" id="sqa-retention" name="retention_days" value="<?php echo esc_attr( $settings['retention_days'] ); ?>" class="small-text"> <?php esc_html_e( 'days', 'slow-query-analyzer' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><label for="sqa-max"><?php esc_html_e( 'Keep at most', 'slow-query-analyzer' ); ?></label></th>
					<td><input type="number" min="50" max="100000" id="sqa-max" name="max_requests" value="<?php echo esc_attr( $settings['max_requests'] ); ?>" class="small-text"> <?php esc_html_e( 'profiled pages', 'slow-query-analyzer' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><label for="sqa-token"><?php esc_html_e( 'GitHub token', 'slow-query-analyzer' ); ?></label></th>
					<td>
						<?php if ( defined( 'SQA_GITHUB_TOKEN' ) && SQA_GITHUB_TOKEN ) : ?>
							<p><?php esc_html_e( 'Set by SQA_GITHUB_TOKEN in wp-config.php.', 'slow-query-analyzer' ); ?></p>
						<?php else : ?>
							<?php $has_token = '' !== SQA_Updater::token(); ?>
							<input type="password" id="sqa-token" name="github_token" value="" class="regular-text" autocomplete="off" placeholder="<?php echo $has_token ? esc_attr__( '•••••••• (saved — leave empty to keep)', 'slow-query-analyzer' ) : ''; ?>">
							<?php if ( $has_token ) : ?>
								<label><input type="checkbox" name="github_token_remove" value="1"> <?php esc_html_e( 'Remove saved token', 'slow-query-analyzer' ); ?></label>
							<?php endif; ?>
						<?php endif; ?>
						<p class="description">
							<?php
							printf(
								/* translators: %s: GitHub repository */
								esc_html__( 'Only needed if %s is a private repository: a fine-grained token with read-only "Contents" access to that repository. Updates appear under Plugins like any other plugin.', 'slow-query-analyzer' ),
								'<code>' . esc_html( SQA_GITHUB_REPO ) . '</code>'
							);
							?>
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<hr>
		<h2><?php esc_html_e( 'Clear log', 'slow-query-analyzer' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Delete all recorded pages and queries?', 'slow-query-analyzer' ) ); ?>');">
			<input type="hidden" name="action" value="sqa_clear">
			<?php wp_nonce_field( 'sqa_clear' ); ?>
			<p><?php esc_html_e( 'Deletes every recorded page and query. Useful after you fixed something and want a clean comparison.', 'slow-query-analyzer' ); ?></p>
			<?php submit_button( __( 'Delete all logged data', 'slow-query-analyzer' ), 'delete', 'submit', false ); ?>
		</form>
		<?php
	}

	private static function render_analysis( array $sample, array $explain, array $hints ) {
		?>
		<div class="sqa-analysis-body">
			<h4><?php esc_html_e( 'Findings', 'slow-query-analyzer' ); ?></h4>
			<?php if ( $hints ) : ?>
				<ul class="sqa-hints">
					<?php foreach ( $hints as $hint ) : ?>
						<li class="sqa-hint sqa-hint-<?php echo esc_attr( $hint['level'] ); ?>"><?php echo esc_html( $hint['text'] ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p><?php esc_html_e( 'No obvious problem in the query plan. If it is still slow, the server may be under load, the tables locked, or the result set simply large — see whether it repeats on every page and consider caching it.', 'slow-query-analyzer' ); ?></p>
			<?php endif; ?>

			<h4><?php esc_html_e( 'Slowest recorded instance', 'slow-query-analyzer' ); ?></h4>
			<p>
				<?php
				printf(
					/* translators: 1: duration, 2: URL */
					esc_html__( '%1$s on %2$s', 'slow-query-analyzer' ),
					'<strong>' . esc_html( self::ms( $sample['duration'] ) ) . '</strong>',
					'<code>' . esc_html( self::short_url( (string) $sample['url'] ) ) . '</code>'
				);
				?>
			</p>
			<pre class="sqa-sql sqa-sql-full"><?php echo esc_html( $sample['sql_text'] ); ?></pre>
			<p class="sqa-caller"><strong><?php esc_html_e( 'Call stack:', 'slow-query-analyzer' ); ?></strong> <?php echo esc_html( $sample['caller'] ); ?></p>

			<h4><?php esc_html_e( 'EXPLAIN', 'slow-query-analyzer' ); ?></h4>
			<?php if ( $explain['error'] ) : ?>
				<p class="sqa-bad"><?php echo esc_html( $explain['error'] ); ?></p>
			<?php elseif ( $explain['rows'] ) : ?>
				<div class="sqa-scroll">
					<table class="widefat sqa-explain">
						<thead><tr>
							<?php foreach ( array_keys( $explain['rows'][0] ) as $column ) : ?>
								<th><?php echo esc_html( $column ); ?></th>
							<?php endforeach; ?>
						</tr></thead>
						<tbody>
							<?php foreach ( $explain['rows'] as $row ) : ?>
								<tr>
									<?php foreach ( $row as $column => $value ) : ?>
										<?php $flag = ( 'type' === $column && 'ALL' === $value ) || ( 'Extra' === $column && preg_match( '/filesort|temporary/i', (string) $value ) ); ?>
										<td class="<?php echo $flag ? 'sqa-bad' : ''; ?>"><?php echo null === $value ? '<span class="sqa-null">NULL</span>' : esc_html( $value ); ?></td>
									<?php endforeach; ?>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	private static function card( $label, $value, $note = '' ) {
		?>
		<div class="sqa-card">
			<div class="sqa-card-value"><?php echo esc_html( $value ); ?></div>
			<div class="sqa-card-label"><?php echo esc_html( $label ); ?></div>
			<?php if ( $note ) : ?>
				<div class="sqa-card-note"><?php echo esc_html( $note ); ?></div>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function sql_block( $sql ) {
		$sql   = (string) $sql;
		$short = strlen( $sql ) > 300 ? substr( $sql, 0, 300 ) . '…' : $sql;
		if ( $short === $sql ) {
			echo '<code class="sqa-sql">' . esc_html( $sql ) . '</code>';
			return;
		}
		echo '<details class="sqa-details"><summary><code class="sqa-sql">' . esc_html( $short ) . '</code></summary><pre class="sqa-sql sqa-sql-full">' . esc_html( $sql ) . '</pre></details>';
	}

	private static function components( $list ) {
		$out = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', (string) $list ) ) ) as $component ) {
			$type  = strstr( $component, ':', true );
			$type  = false === $type ? $component : $type;
			$out[] = '<span class="sqa-badge sqa-src-' . esc_attr( sanitize_html_class( $type ) ) . '">' . esc_html( $component ) . '</span>';
		}
		return $out ? implode( ' ', $out ) : '<span class="sqa-null">' . esc_html__( 'unknown', 'slow-query-analyzer' ) . '</span>';
	}

	private static function ms( $seconds ) {
		$ms = (float) $seconds * 1000;
		return $ms >= 1000
			? number_format_i18n( $ms / 1000, 2 ) . ' s'
			: number_format_i18n( $ms, $ms < 10 ? 2 : 1 ) . ' ms';
	}

	private static function when( $gmt ) {
		$timestamp = strtotime( $gmt . ' UTC' );
		if ( ! $timestamp ) {
			return '';
		}
		if ( time() - $timestamp < DAY_IN_SECONDS ) {
			/* translators: %s: human time difference */
			return sprintf( __( '%s ago', 'slow-query-analyzer' ), human_time_diff( $timestamp ) );
		}
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	private static function short_url( $url ) {
		$home = untrailingslashit( home_url() );
		$path = 0 === strpos( $url, $home ) ? substr( $url, strlen( $home ) ) : $url;
		$path = '' === $path ? '/' : $path;
		return strlen( $path ) > 120 ? substr( $path, 0, 120 ) . '…' : $path;
	}

	private static function level_label( $level ) {
		$labels = array(
			'high'   => __( 'Problem', 'slow-query-analyzer' ),
			'medium' => __( 'Check', 'slow-query-analyzer' ),
			'ok'     => __( 'Good', 'slow-query-analyzer' ),
			'info'   => __( 'Info', 'slow-query-analyzer' ),
		);
		return isset( $labels[ $level ] ) ? $labels[ $level ] : $level;
	}
}
