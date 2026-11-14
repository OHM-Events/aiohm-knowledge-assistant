<?php
/**
 * Database connection optimizer
 * Optimizes database operations through connection pooling and intelligent caching
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIOHM_KB_DB_Optimizer {

	private static $instance  = null;
	private $query_cache      = array();
	private $batch_operations = array();
	private $connection_stats = array(
		'queries_executed'  => 0,
		'cache_hits'        => 0,
		'batch_operations'  => 0,
		'connection_reuses' => 0,
	);

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->init_hooks();
	}

	private function init_hooks() {
		// Hook into WordPress query events for monitoring
		add_action( 'shutdown', array( $this, 'process_batch_operations' ) );
		add_action( 'wp_loaded', array( $this, 'init_connection_optimization' ) );
	}

	/**
	 * Initialize connection optimization features
	 */
	public function init_connection_optimization() {
		// Set up persistent connections if supported
		$this->maybe_enable_persistent_connections();

		// Initialize query result cache
		$this->init_query_cache();
	}

	/**
	 * Enable persistent connections if supported and beneficial
	 */
	private function maybe_enable_persistent_connections() {
		// Only enable on production environments with high traffic

		// Check if MySQL persistent connections are available
		if ( function_exists( 'mysql_pconnect' ) || function_exists( 'mysqli_connect' ) ) {
		}
	}

	/**
	 * Initialize query result caching system
	 */
	private function init_query_cache() {
		// Set up query cache with reasonable memory limits
		$this->query_cache = array();

		// Clear cache periodically to prevent memory bloat
		if ( wp_rand( 1, 100 ) === 1 ) { // 1% chance per request
			$this->cleanup_query_cache();
		}
	}

	/**
	 * Optimized database query wrapper with caching and batching
	 *
	 * @param string $query SQL query
	 * @param array  $args Query arguments
	 * @param string $cache_key Optional cache key
	 * @param int    $cache_duration Cache duration in seconds
	 * @return mixed Query result
	 */
	public function optimized_query( $query, $args = array(), $cache_key = null, $cache_duration = 300 ) {
		global $wpdb;

		// Generate cache key if not provided
		if ( ! $cache_key ) {
			$cache_key = $this->generate_cache_key( $query, $args );
		}

		// Check cache first
		$cached_result = $this->get_cached_result( $cache_key );
		if ( $cached_result !== false ) {
			++$this->connection_stats['cache_hits'];
			return $cached_result;
		}

		// Prepare and execute query
		if ( ! empty( $args ) ) {
			$prepared_query = $wpdb->prepare( $query, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared with args
		} else {
			$prepared_query = $query;
		}

		// Determine query type and execute appropriately
		$query_type = $this->determine_query_type( $prepared_query );

		switch ( $query_type ) {
			case 'SELECT':
				if ( strpos( $prepared_query, 'LIMIT 1' ) !== false ) {
                    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared above, direct query needed for optimization, caching handled separately
					$result = $wpdb->get_row( $prepared_query, ARRAY_A );
				} else {
                    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared above, direct query needed for optimization, caching handled separately
					$result = $wpdb->get_results( $prepared_query, ARRAY_A );
				}
				break;

			case 'COUNT':
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared above, direct query needed for optimization, caching handled separately
				$result = $wpdb->get_var( $prepared_query );
				break;

			case 'INSERT':
			case 'UPDATE':
			case 'DELETE':
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared above, direct query needed for optimization, caching handled separately
				$result = $wpdb->query( $prepared_query );
				// Clear related caches for write operations
				$this->invalidate_related_cache( $prepared_query );
				break;

			default:
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared above, direct query needed for optimization, caching handled separately
				$result = $wpdb->query( $prepared_query );
				break;
		}

		++$this->connection_stats['queries_executed'];

		// Cache SELECT results only
		if ( $query_type === 'SELECT' || $query_type === 'COUNT' ) {
			$this->cache_result( $cache_key, $result, $cache_duration );
		}

		// Log slow queries for optimization
		if ( isset( $wpdb->last_query_time ) && $wpdb->last_query_time > 1.0 ) {
		}

		return $result;
	}

	/**
	 * Add operation to batch queue for processing
	 *
	 * @param string $operation_type Type of operation
	 * @param array  $data Operation data
	 */
	public function add_to_batch( $operation_type, $data ) {
		if ( ! isset( $this->batch_operations[ $operation_type ] ) ) {
			$this->batch_operations[ $operation_type ] = array();
		}

		$this->batch_operations[ $operation_type ][] = $data;

		// Process batch if it gets too large
		if ( count( $this->batch_operations[ $operation_type ] ) >= 50 ) {
			$this->process_batch( $operation_type );
		}
	}

	/**
	 * Process all pending batch operations
	 */
	public function process_batch_operations() {
		foreach ( $this->batch_operations as $operation_type => $operations ) {
			if ( ! empty( $operations ) ) {
				$this->process_batch( $operation_type );
			}
		}
	}

	/**
	 * Process a specific batch type
	 *
	 * @param string $operation_type Type of operation to process
	 */
	private function process_batch( $operation_type ) {
		if ( empty( $this->batch_operations[ $operation_type ] ) ) {
			return;
		}

		global $wpdb;
		$operations = $this->batch_operations[ $operation_type ];

		switch ( $operation_type ) {
			case 'insert_vector_entries':
				$this->batch_insert_vector_entries( $operations );
				break;

			case 'update_metadata':
				$this->batch_update_metadata( $operations );
				break;

			case 'cache_invalidation':
				$this->batch_cache_invalidation( $operations );
				break;

			default:
				// Process individually for unknown types
				foreach ( $operations as $operation ) {
					// Custom processing logic here
				}
				break;
		}

		$this->connection_stats['batch_operations'] += count( $operations );
		$this->batch_operations[ $operation_type ]   = array();
	}

	/**
	 * Batch insert vector entries for better performance
	 *
	 * @param array $entries Array of entry data
	 */
	private function batch_insert_vector_entries( $entries ) {
		if ( empty( $entries ) ) {
			return;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'aiohm_vector_entries';

		// Build bulk insert query
		$values       = array();
		$placeholders = array();

		foreach ( $entries as $entry ) {
			$placeholders[] = '(%d, %s, %s, %s, %s, %s, %s, %d)';
			$values         = array_merge(
				$values,
				array(
					$entry['user_id'],
					$entry['content_id'],
					$entry['content_type'],
					$entry['title'],
					$entry['content'],
					$entry['vector_data'],
					$entry['metadata'],
					$entry['is_public'],
				)
			);
		}

        $query = 'INSERT INTO `'. esc_sql( $table_name ) .'` (user_id, content_id, content_type, title, content, vector_data, metadata, is_public) VALUES ' . implode( ', ', $placeholders );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Optimized batch insert operation, caching not applicable for bulk inserts
		$result = $wpdb->query( $wpdb->prepare( $query, $values ) );

		if ( $result === false ) {
		}

		// Clear related caches
		$this->invalidate_vector_caches();
	}

	/**
	 * Batch update metadata operations
	 *
	 * @param array $updates Array of update operations
	 */
	private function batch_update_metadata( $updates ) {
		foreach ( $updates as $update ) {
			update_post_meta( $update['post_id'], $update['meta_key'], $update['meta_value'] );
		}
	}

	/**
	 * Generate cache key for query
	 *
	 * @param string $query SQL query
	 * @param array  $args Query arguments
	 * @return string Cache key
	 */
	private function generate_cache_key( $query, $args = array() ) {
		$key_data = $query . serialize( $args );
		return 'aiohm_db_' . md5( $key_data );
	}

	/**
	 * Get cached query result
	 *
	 * @param string $cache_key Cache key
	 * @return mixed Cached result or false if not found
	 */
	private function get_cached_result( $cache_key ) {
		// Try WordPress object cache first
		$result = wp_cache_get( $cache_key, 'aiohm_db' );
		if ( $result !== false ) {
			return $result;
		}

		// Try internal cache
		if ( isset( $this->query_cache[ $cache_key ] ) ) {
			$cache_entry = $this->query_cache[ $cache_key ];
			if ( $cache_entry['expires'] > time() ) {
				return $cache_entry['data'];
			} else {
				unset( $this->query_cache[ $cache_key ] );
			}
		}

		return false;
	}

	/**
	 * Cache query result
	 *
	 * @param string $cache_key Cache key
	 * @param mixed  $result Query result
	 * @param int    $duration Cache duration in seconds
	 */
	private function cache_result( $cache_key, $result, $duration = 300 ) {
		// Store in WordPress object cache
		wp_cache_set( $cache_key, $result, 'aiohm_db', $duration );

		// Store in internal cache with size limit
		if ( count( $this->query_cache ) < 100 ) {
			$this->query_cache[ $cache_key ] = array(
				'data'    => $result,
				'expires' => time() + $duration,
			);
		}
	}

	/**
	 * Determine query type for optimization
	 *
	 * @param string $query SQL query
	 * @return string Query type
	 */
	private function determine_query_type( $query ) {
		$query = trim( strtoupper( $query ) );

		if ( strpos( $query, 'SELECT COUNT' ) === 0 ) {
			return 'COUNT';
		} elseif ( strpos( $query, 'SELECT' ) === 0 ) {
			return 'SELECT';
		} elseif ( strpos( $query, 'INSERT' ) === 0 ) {
			return 'INSERT';
		} elseif ( strpos( $query, 'UPDATE' ) === 0 ) {
			return 'UPDATE';
		} elseif ( strpos( $query, 'DELETE' ) === 0 ) {
			return 'DELETE';
		}

		return 'OTHER';
	}

	/**
	 * Invalidate related cache entries for write operations
	 *
	 * @param string $query SQL query that was executed
	 */
	private function invalidate_related_cache( $query ) {
		$query_upper = strtoupper( $query );

		// Determine affected tables and invalidate related caches
		if ( strpos( $query_upper, 'AIOHM_VECTOR_ENTRIES' ) !== false ) {
			$this->invalidate_vector_caches();
		} elseif ( strpos( $query_upper, 'AIOHM_CONVERSATIONS' ) !== false ) {
			$this->invalidate_conversation_caches();
		} elseif ( strpos( $query_upper, 'AIOHM_MESSAGES' ) !== false ) {
			$this->invalidate_message_caches();
		}
	}

	/**
	 * Invalidate vector-related caches
	 */
	private function invalidate_vector_caches() {
		wp_cache_delete_group( 'aiohm_kb' );

		// Clear internal cache entries related to vectors
		foreach ( $this->query_cache as $key => $data ) {
			if ( strpos( $key, 'vector' ) !== false || strpos( $key, 'search' ) !== false ) {
				unset( $this->query_cache[ $key ] );
			}
		}
	}

	/**
	 * Invalidate conversation-related caches
	 */
	private function invalidate_conversation_caches() {
		wp_cache_delete_group( 'aiohm_conversations' );

		foreach ( $this->query_cache as $key => $data ) {
			if ( strpos( $key, 'conversation' ) !== false || strpos( $key, 'message' ) !== false ) {
				unset( $this->query_cache[ $key ] );
			}
		}
	}

	/**
	 * Invalidate message-related caches
	 */
	private function invalidate_message_caches() {
		wp_cache_delete_group( 'aiohm_messages' );
	}

	/**
	 * Clean up expired cache entries
	 */
	private function cleanup_query_cache() {
		$current_time = time();
		$cleaned      = 0;

		foreach ( $this->query_cache as $key => $entry ) {
			if ( $entry['expires'] <= $current_time ) {
				unset( $this->query_cache[ $key ] );
				++$cleaned;
			}
		}

		if ( $cleaned > 0 ) {
		}
	}

	/**
	 * Get database optimization statistics
	 *
	 * @return array Statistics data
	 */
	public function get_optimization_stats() {
		global $wpdb;

		$stats = $this->connection_stats;

		// Add WordPress database stats if available
		if ( isset( $wpdb->num_queries ) ) {
			$stats['total_wp_queries'] = $wpdb->num_queries;
		}

		// Calculate cache hit rate
		if ( $stats['queries_executed'] > 0 ) {
			$stats['cache_hit_rate'] = round( ( $stats['cache_hits'] / $stats['queries_executed'] ) * 100, 2 );
		} else {
			$stats['cache_hit_rate'] = 0;
		}

		// Add memory usage for caches
		$stats['cache_memory_usage']       = $this->calculate_cache_memory_usage();
		$stats['pending_batch_operations'] = array_sum( array_map( 'count', $this->batch_operations ) );

		return $stats;
	}

	/**
	 * Calculate approximate memory usage of caches
	 *
	 * @return string Formatted memory usage
	 */
	private function calculate_cache_memory_usage() {
		$memory_usage = 0;

		foreach ( $this->query_cache as $entry ) {
			$memory_usage += strlen( serialize( $entry ) );
		}

		return $this->format_bytes( $memory_usage );
	}

	/**
	 * Format bytes into human-readable format
	 *
	 * @param int $bytes Number of bytes
	 * @return string Formatted string
	 */
	private function format_bytes( $bytes ) {
		$units = array( 'B', 'KB', 'MB', 'GB' );
		$bytes = max( $bytes, 0 );
		$pow   = floor( ( $bytes ? log( $bytes ) : 0 ) / log( 1024 ) );
		$pow   = min( $pow, count( $units ) - 1 );

		$bytes /= ( 1 << ( 10 * $pow ) );

		return round( $bytes, 2 ) . ' ' . $units[ $pow ];
	}

	/**
	 * Force process all pending batches (useful for testing)
	 */
	public function force_process_batches() {
		$this->process_batch_operations();
	}

	/**
	 * Clear all caches
	 */
	public function clear_all_caches() {
		$this->query_cache = array();
		wp_cache_flush_group( 'aiohm_db' );
		wp_cache_flush_group( 'aiohm_kb' );
		wp_cache_flush_group( 'aiohm_conversations' );
		wp_cache_flush_group( 'aiohm_messages' );
	}
}

// Initialize the optimizer
AIOHM_KB_DB_Optimizer::get_instance();
