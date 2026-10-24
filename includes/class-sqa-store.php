<?php
/**
 * Settings and storage for profiled requests and slow queries.
 */

defined( 'ABSPATH' ) || exit;

class SQA_Store {

	const DB_VERSION       = '1';
	const SETTINGS_OPTION  = 'sqa_settings';
	const MAX_SQL_LENGTH   = 65535;

	public static function requests_table() {
		global $wpdb;
		return $wpdb->prefix . 'sqa_requests';
	}

	public static function queries_table() {
		global $wpdb;
		return $wpdb->prefix . 'sqa_queries';
	}

	public static function defaults() {
		return array(
			'threshold_ms'   => 50,
			'retention_days' => 7,
			'max_requests'   => 2000,
			'duration'       => 60,
		);
	}

	public static function settings() {
		$saved = get_option( self::SETTINGS_OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function save_settings( array $settings ) {
		$clean = array(
			'threshold_ms'   => max( 1, min( 60000, (int) $settings['threshold_ms'] ) ),
			'retention_days' => max( 1, min( 365, (int) $settings['retention_days'] ) ),
			'max_requests'   => max( 50, min( 100000, (int) $settings['max_requests'] ) ),
			'duration'       => max( 5, min( 1440, (int) $settings['duration'] ) ),
		);
		update_option( self::SETTINGS_OPTION, $clean, false );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'sqa_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset  = $wpdb->get_charset_collate();
		$requests = self::requests_table();
		$queries  = self::queries_table();

		dbDelta(
			"CREATE TABLE {$requests} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  url text NOT NULL,
  method varchar(10) NOT NULL DEFAULT '',
  context varchar(20) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  query_count int(10) unsigned NOT NULL DEFAULT 0,
  query_time double NOT NULL DEFAULT 0,
  slow_count int(10) unsigned NOT NULL DEFAULT 0,
  duplicate_count int(10) unsigned NOT NULL DEFAULT 0,
  page_time double NOT NULL DEFAULT 0,
  peak_memory bigint(20) unsigned NOT NULL DEFAULT 0,
  patterns longtext NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at)
) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$queries} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  request_id bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  fingerprint char(32) NOT NULL,
  normalized text NOT NULL,
  sql_text longtext NOT NULL,
  duration double NOT NULL DEFAULT 0,
  caller text NOT NULL,
  component varchar(191) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY request_id (request_id),
  KEY fingerprint (fingerprint),
  KEY created_at (created_at)
) {$charset};"
		);

		update_option( 'sqa_db_version', self::DB_VERSION, false );

		if ( false === get_option( self::SETTINGS_OPTION ) ) {
			add_option( self::SETTINGS_OPTION, self::defaults(), '', 'no' );
		}
	}

	/**
	 * @param array $request Request summary row.
	 * @param array $slow    Slow queries: fingerprint, normalized, sql, duration, caller, component.
	 */
	public static function save_request( array $request, array $slow ) {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$inserted = $wpdb->insert(
			self::requests_table(),
			array(
				'created_at'      => $now,
				'url'             => $request['url'],
				'method'          => $request['method'],
				'context'         => $request['context'],
				'user_id'         => $request['user_id'],
				'query_count'     => $request['query_count'],
				'query_time'      => $request['query_time'],
				'slow_count'      => count( $slow ),
				'duplicate_count' => $request['duplicate_count'],
				'page_time'       => $request['page_time'],
				'peak_memory'     => $request['peak_memory'],
				'patterns'        => wp_json_encode( $request['patterns'] ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%f', '%d', '%d', '%f', '%d', '%s' )
		);

		if ( ! $inserted ) {
			return 0;
		}

		$request_id = (int) $wpdb->insert_id;

		foreach ( $slow as $query ) {
			$wpdb->insert(
				self::queries_table(),
				array(
					'request_id'  => $request_id,
					'created_at'  => $now,
					'fingerprint' => $query['fingerprint'],
					'normalized'  => self::truncate( $query['normalized'], 20000 ),
					'sql_text'    => self::truncate( $query['sql'], self::MAX_SQL_LENGTH ),
					'duration'    => $query['duration'],
					'caller'      => self::truncate( (string) $query['caller'], 10000 ),
					'component'   => self::truncate( (string) $query['component'], 191 ),
				),
				array( '%d', '%s', '%s', '%s', '%s', '%f', '%s', '%s' )
			);
		}

		return $request_id;
	}

	private static function truncate( $text, $length ) {
		return strlen( $text ) > $length ? substr( $text, 0, $length ) : $text;
	}

	public static function prune() {
		global $wpdb;

		$settings = self::settings();
		$requests = self::requests_table();
		$queries  = self::queries_table();
		$cutoff   = gmdate( 'Y-m-d H:i:s', time() - (int) $settings['retention_days'] * DAY_IN_SECONDS );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$requests} WHERE created_at < %s", $cutoff ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$queries} WHERE created_at < %s", $cutoff ) );

		$oldest_kept = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$requests} ORDER BY id DESC LIMIT 1 OFFSET %d", (int) $settings['max_requests'] - 1 )
		);
		if ( $oldest_kept ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$requests} WHERE id < %d", $oldest_kept ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$queries} WHERE request_id < %d", $oldest_kept ) );
		}
	}

	public static function clear() {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . self::requests_table() );
		$wpdb->query( 'TRUNCATE TABLE ' . self::queries_table() );
	}

	public static function summary() {
		global $wpdb;
		$requests = self::requests_table();
		$queries  = self::queries_table();

		return array(
			'requests' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$requests}" ),
			'slow'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$queries}" ),
			'patterns' => (int) $wpdb->get_var( "SELECT COUNT(DISTINCT fingerprint) FROM {$queries}" ),
			'slowest'  => (float) $wpdb->get_var( "SELECT MAX(duration) FROM {$queries}" ),
		);
	}

	/**
	 * Slow queries grouped by their normalized shape, worst total time first.
	 */
	public static function slow_groups( $limit = 100 ) {
		global $wpdb;
		$queries = self::queries_table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT fingerprint,
					MAX(normalized) AS normalized,
					COUNT(*) AS hits,
					AVG(duration) AS avg_time,
					MAX(duration) AS max_time,
					SUM(duration) AS total_time,
					MAX(created_at) AS last_seen,
					GROUP_CONCAT(DISTINCT component ORDER BY component SEPARATOR ', ') AS components
				FROM {$queries}
				GROUP BY fingerprint
				ORDER BY total_time DESC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * The slowest recorded instance of a query pattern — the best one to EXPLAIN.
	 */
	public static function worst_sample( $fingerprint ) {
		global $wpdb;
		$queries = self::queries_table();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT q.*, r.url FROM {$queries} q
				LEFT JOIN " . self::requests_table() . " r ON r.id = q.request_id
				WHERE q.fingerprint = %s ORDER BY q.duration DESC LIMIT 1",
				$fingerprint
			),
			ARRAY_A
		);
	}

	public static function recent_requests( $limit, $offset = 0, $order = 'recent' ) {
		global $wpdb;
		$requests = self::requests_table();
		$order_by = 'slowest' === $order ? 'query_time DESC' : 'id DESC';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, created_at, url, method, context, query_count, query_time, slow_count, duplicate_count, page_time, peak_memory
				FROM {$requests} ORDER BY {$order_by} LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		);
	}

	public static function get_request( $id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::requests_table() . ' WHERE id = %d', $id ),
			ARRAY_A
		);
		if ( $row ) {
			$row['patterns'] = json_decode( (string) $row['patterns'], true ) ?: array();
		}
		return $row;
	}

	public static function request_queries( $request_id ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::queries_table() . ' WHERE request_id = %d ORDER BY duration DESC',
				$request_id
			),
			ARRAY_A
		);
	}
}
