<?php
/**
 * Query normalization, EXPLAIN and plain-language hints.
 */

defined( 'ABSPATH' ) || exit;

class SQA_Analyzer {

	/**
	 * Reduce a query to its shape so identical queries with different values
	 * group together: literals become ?, IN lists become (?+).
	 */
	public static function normalize( $sql ) {
		$sql = substr( (string) $sql, 0, 20000 );
		$sql = preg_replace( '#/\*.*?\*/#s', '', $sql );
		$sql = preg_replace( "/'(?:[^'\\\\]++|\\\\.)*+'/s", '?', $sql );
		$sql = preg_replace( '/"(?:[^"\\\\]++|\\\\.)*+"/s', '?', $sql );
		$sql = preg_replace( '/\b0x[0-9a-f]+\b/i', '?', $sql );
		$sql = preg_replace( '/(?<![\w`$.])-?\d+(?:\.\d+)?(?:e[+-]?\d+)?\b/i', '?', $sql );
		$sql = preg_replace( '/\(\s*\?(?:\s*,\s*\?)+\s*\)/', '(?+)', $sql );
		$sql = preg_replace( '/\s+/', ' ', trim( $sql ) );
		return null === $sql ? '' : $sql;
	}

	/**
	 * Last function in wpdb's caller summary, e.g. "WP_Query->get_posts".
	 */
	public static function short_caller( $caller ) {
		$parts = array_map( 'trim', explode( ',', (string) $caller ) );
		$parts = array_filter( $parts, 'strlen' );
		return $parts ? end( $parts ) : '(unknown)';
	}

	public static function is_explainable( $sql ) {
		$sql = preg_replace( '#^\s*(/\*.*?\*/\s*)*#s', '', (string) $sql );
		return (bool) preg_match( '/^\(?\s*SELECT\s/i', $sql );
	}

	/**
	 * Run EXPLAIN on a SELECT. EXPLAIN does not execute the query or change data.
	 *
	 * @return array{rows:array,error:string}
	 */
	public static function explain( $sql ) {
		global $wpdb;

		if ( ! self::is_explainable( $sql ) ) {
			return array(
				'rows'  => array(),
				'error' => __( 'Only SELECT queries can be explained.', 'slow-query-analyzer' ),
			);
		}
		if ( strlen( $sql ) >= SQA_Store::MAX_SQL_LENGTH ) {
			return array(
				'rows'  => array(),
				'error' => __( 'This query was too long to store in full, so it cannot be explained.', 'slow-query-analyzer' ),
			);
		}

		$suppress = $wpdb->suppress_errors( true );
		$rows     = $wpdb->get_results( 'EXPLAIN ' . $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$error    = $wpdb->last_error;
		$wpdb->suppress_errors( $suppress );

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'error' => (string) $error,
		);
	}

	/**
	 * Plain-language findings for a query, from its text and EXPLAIN output.
	 *
	 * @return array<int,array{level:string,text:string}>
	 */
	public static function hints( $sql, array $explain_rows = array() ) {
		$hints = array();

		foreach ( $explain_rows as $row ) {
			$row   = array_change_key_case( $row, CASE_LOWER );
			$table = isset( $row['table'] ) ? $row['table'] : '?';
			$type  = isset( $row['type'] ) ? strtoupper( (string) $row['type'] ) : '';
			$rows  = isset( $row['rows'] ) ? (int) $row['rows'] : 0;
			$extra = isset( $row['extra'] ) ? (string) $row['extra'] : '';
			$key   = isset( $row['key'] ) ? $row['key'] : null;
			$keys  = isset( $row['possible_keys'] ) ? $row['possible_keys'] : null;

			if ( 'ALL' === $type ) {
				if ( $rows >= 1000 ) {
					$hints[] = self::hint(
						'high',
						/* translators: 1: table name, 2: estimated rows */
						sprintf( __( 'Full table scan on %1$s (about %2$s rows read). No index is used — add an index on the columns this query filters or joins on, or narrow the query.', 'slow-query-analyzer' ), "`$table`", number_format_i18n( $rows ) )
					);
				} else {
					$hints[] = self::hint(
						'info',
						/* translators: %s: table name */
						sprintf( __( 'Full scan of the small table %s — usually harmless.', 'slow-query-analyzer' ), "`$table`" )
					);
				}
			} elseif ( 'INDEX' === $type && $rows >= 10000 ) {
				$hints[] = self::hint(
					'medium',
					/* translators: 1: table name, 2: estimated rows */
					sprintf( __( 'Full index scan on %1$s (about %2$s entries). It reads the whole index instead of looking up a range.', 'slow-query-analyzer' ), "`$table`", number_format_i18n( $rows ) )
				);
			}

			if ( null === $key && ! empty( $keys ) ) {
				$hints[] = self::hint(
					'medium',
					/* translators: 1: table name, 2: index names */
					sprintf( __( 'MySQL had candidate indexes on %1$s (%2$s) but decided not to use any — the condition probably matches too many rows to benefit.', 'slow-query-analyzer' ), "`$table`", $keys )
				);
			}
			if ( false !== stripos( $extra, 'Using filesort' ) ) {
				$hints[] = self::hint(
					$rows >= 1000 ? 'medium' : 'info',
					/* translators: %s: table name */
					sprintf( __( 'Results from %s are sorted without an index (Using filesort). An index matching the ORDER BY columns avoids this.', 'slow-query-analyzer' ), "`$table`" )
				);
			}
			if ( false !== stripos( $extra, 'Using temporary' ) ) {
				$hints[] = self::hint(
					$rows >= 1000 ? 'medium' : 'info',
					/* translators: %s: table name */
					sprintf( __( 'A temporary table is built for %s, typically for GROUP BY / DISTINCT on unindexed columns.', 'slow-query-analyzer' ), "`$table`" )
				);
			}
		}

		if ( preg_match( '/\bmeta_value\b/i', $sql ) && preg_match( '/(post|user|term|comment)meta\b/i', $sql ) ) {
			$hints[] = self::hint( 'high', __( 'Filters or sorts on meta_value, which WordPress does not index. Meta queries on large meta tables are a classic slowdown — store the value in a taxonomy or a custom table, or add an index such as (meta_key, meta_value(32)).', 'slow-query-analyzer' ) );
		}
		if ( preg_match( '/ORDER\s+BY\s+RAND\s*\(/i', $sql ) ) {
			$hints[] = self::hint( 'high', __( 'ORDER BY RAND() reads and sorts every matching row. Pick random IDs in PHP or cache the result.', 'slow-query-analyzer' ) );
		}
		if ( preg_match( '/SQL_CALC_FOUND_ROWS/i', $sql ) ) {
			$hints[] = self::hint( 'medium', __( 'SQL_CALC_FOUND_ROWS makes MySQL count every matching row for pagination. If the query doesn\'t need pagination, pass \'no_found_rows\' => true to WP_Query.', 'slow-query-analyzer' ) );
		}
		if ( preg_match( '/\bpost_content\s+LIKE\b/i', $sql ) ) {
			$hints[] = self::hint( 'medium', __( 'This is a WordPress search (LIKE \'%term%\' on post content). It scans every post by design; on large sites use a search plugin with its own index.', 'slow-query-analyzer' ) );
		} elseif ( preg_match( '/\bLIKE\s+\'(%|\{[0-9a-f]{64}\})/i', $sql ) ) {
			$hints[] = self::hint( 'medium', __( 'A LIKE pattern starting with % cannot use an index, so every row is checked.', 'slow-query-analyzer' ) );
		}
		if ( preg_match( '/\boptions\b.*\bautoload\b/is', $sql ) ) {
			$hints[] = self::hint( 'medium', __( 'This loads the autoloaded options on every page. If it is slow, too much data is autoloaded — check the Database health tab.', 'slow-query-analyzer' ) );
		}
		if ( preg_match( '/\bLIMIT\s+(\d+)\s*,\s*\d+/i', $sql, $m ) || preg_match( '/\bOFFSET\s+(\d+)/i', $sql, $m ) ) {
			if ( (int) $m[1] >= 1000 ) {
				$hints[] = self::hint(
					'medium',
					/* translators: %s: row offset */
					sprintf( __( 'Deep pagination: MySQL reads and throws away %s rows before returning results.', 'slow-query-analyzer' ), number_format_i18n( (int) $m[1] ) )
				);
			}
		}

		$order = array(
			'high'   => 0,
			'medium' => 1,
			'info'   => 2,
		);
		usort(
			$hints,
			function ( $a, $b ) use ( $order ) {
				return $order[ $a['level'] ] <=> $order[ $b['level'] ];
			}
		);

		return $hints;
	}

	/**
	 * Advice for a query shape repeated many times in one request.
	 */
	public static function pattern_hint( $count ) {
		if ( $count >= 20 ) {
			return __( 'Very likely an N+1 problem: the same query runs once per item in a loop. Load the data in one query (e.g. prime caches with update_meta_cache() / _prime_post_caches()) or cache the result.', 'slow-query-analyzer' );
		}
		if ( $count >= 5 ) {
			return __( 'Runs repeatedly in one page load — a candidate for caching or batching.', 'slow-query-analyzer' );
		}
		return '';
	}

	private static function hint( $level, $text ) {
		return array(
			'level' => $level,
			'text'  => $text,
		);
	}
}
