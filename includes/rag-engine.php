<?php
/**
 * RAG (Retrieval-Augmented Generation) Engine
 * Handles vector search, content processing, and AI-powered knowledge retrieval
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIOHM_KB_RAG_Engine {

	private $table_name;
	private $allowed_tables = array();

	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'aiohm_vector_entries';

		$this->allowed_tables = array(
			$wpdb->prefix . 'aiohm_vector_entries',
			$wpdb->prefix . 'aiohm_conversations',
			$wpdb->prefix . 'aiohm_messages',
			$wpdb->prefix . 'aiohm_projects',
		);
	}

	public function get_table_name() {
		return $this->table_name;
	}

	/**
	 * Validate table name against allowed tables to prevent SQL injection
	 *
	 * @param string $table_name Table name to validate
	 * @return bool True if valid, false otherwise
	 */
	private function validate_table_name( $table_name ) {
		return in_array( $table_name, $this->allowed_tables, true );
	}

	/**
	 * Safely get table reference with validation
	 *
	 * @param string $table_name Table name to validate
	 * @return string Validated table name
	 * @throws Exception If table name is invalid
	 */
	private function get_safe_table_name( $table_name ) {
		if ( ! $this->validate_table_name( $table_name ) ) {
			throw new Exception( 'Invalid table name for security reasons.' );
		}
		return $table_name;
	}

	public function add_entry( $content, $content_type, $title, $metadata = array(), $user_id = 0, $is_public = 0, $attachment_id = 0 ) {
		$settings      = AIOHM_KB_Assistant::get_settings();
		$chunk_size    = $settings['chunk_size'] ?? 1000;
		$chunk_overlap = $settings['chunk_overlap'] ?? 200;

		$chunks = $this->chunk_content( $content, $chunk_size, $chunk_overlap );

		if ( empty( $chunks ) ) {
			return 0;
		}

		$transient_dir    = $this->get_transient_directory();
		$chunks_file_path = $transient_dir . '/' . $attachment_id . '_chunks.json';
		file_put_contents( $chunks_file_path, json_encode( $chunks ) );

		$content_id = $this->generate_entry_id( $title, $content );
		// Store content_id in attachment meta to retrieve it later
		update_post_meta( $attachment_id, '_aiohm_content_id', $content_id );

		return count( $chunks );
	}

	/**
	 * Add content entry directly to database (for site content scanning)
	 *
	 * @param string $content Content to add
	 * @param string $content_type Content type
	 * @param string $title Content title
	 * @param array  $metadata Additional metadata
	 * @param int    $user_id User ID
	 * @param int    $is_public Whether content is public
	 * @return bool|WP_Error True on success, WP_Error on failure
	 */
	public function add_content_entry( $content, $content_type, $title, $metadata = array(), $user_id = 0, $is_public = 0 ) {
		$settings      = AIOHM_KB_Assistant::get_settings();
		$chunk_size    = $settings['chunk_size'] ?? 1000;
		$chunk_overlap = $settings['chunk_overlap'] ?? 200;

		$chunks = $this->chunk_content( $content, $chunk_size, $chunk_overlap );

		if ( empty( $chunks ) ) {
			return new WP_Error( 'no_chunks', 'No content chunks to process.' );
		}

		$content_id = $this->generate_entry_id( $title, $content );
		$ai_client  = new AIOHM_KB_AI_GPT_Client();

		global $wpdb;

		$successful_inserts = 0;
		foreach ( $chunks as $chunk_index => $chunk ) {
			$embedding = $ai_client->generate_embeddings( $chunk );

			if ( ! $embedding || empty( $embedding ) ) {
				continue; // Skip chunks that fail embedding generation
			}

			$chunk_metadata = array_merge( $metadata, array(
				'chunk_index' => $chunk_index,
				'total_chunks' => count( $chunks ),
			) );

			$insert_data = array(
				'user_id'      => $user_id,
				'content_id'   => $content_id,
				'content_type' => $content_type,
				'title'        => $title,
				'content'      => $chunk,
				'vector_data'  => json_encode( $embedding ),
				'metadata'     => json_encode( $chunk_metadata ),
				'is_public'    => $is_public,
			);

			// Direct database query required for vector embeddings table
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$result = $wpdb->insert(
				$this->table_name,
				$insert_data,
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
			);

			if ( $result === false ) {
				return new WP_Error( 'db_insert_failed', 'Failed to insert content chunk: ' . $wpdb->last_error );
			}

			$successful_inserts++;
		}

		// If no chunks were successfully inserted (all embedding generations failed), return error
		if ( $successful_inserts === 0 ) {
			return new WP_Error( 'no_successful_inserts', 'All content chunks failed to be processed. This may be due to AI service issues.' );
		}

		// Clear search caches after successful insertion
		$this->clear_search_caches();

		return true;
	}

	/**
	 * Prepare content for chunked processing (public helper)
	 *
	 * @param string $content Content to chunk
	 * @param string $title Content title
	 * @param int    $attachment_id Attachment ID for metadata
	 * @return array Array with chunks and content_id
	 */
	public function prepare_content_for_chunking( $content, $title, $attachment_id = 0 ) {
		$settings      = AIOHM_KB_Assistant::get_settings();
		$chunk_size    = $settings['chunk_size'] ?? 1000;
		$chunk_overlap = $settings['chunk_overlap'] ?? 200;

		$chunks     = $this->chunk_content( $content, $chunk_size, $chunk_overlap );
		$content_id = $this->generate_entry_id( $title, $content );

		return array(
			'chunks'      => $chunks,
			'content_id'  => $content_id,
			'chunk_count' => count( $chunks ),
		);
	}

	public function add_entry_chunk( $attachment_id, $chunk_index ) {

		$transient_dir    = $this->get_transient_directory();
		$chunks_file_path = $transient_dir . '/' . $attachment_id . '_chunks.json';

		if ( ! file_exists( $chunks_file_path ) ) {
			throw new Exception( 'Chunks file not found.' );
		}

		$chunks = json_decode( file_get_contents( $chunks_file_path ), true );
		if ( ! $chunks || ! isset( $chunks[ $chunk_index ] ) ) {
			throw new Exception( 'Invalid chunk data.' );
		}

		$chunk = $chunks[ $chunk_index ];

		$attachment = get_post( $attachment_id );
		$title      = $attachment->post_title;
		$content_id = get_post_meta( $attachment_id, '_aiohm_content_id', true );
		$user_id    = $attachment->post_author;

		$ai_client = new AIOHM_KB_AI_GPT_Client();
		$embedding = $ai_client->generate_embeddings( $chunk );

		if ( ! $embedding || empty( $embedding ) ) {
			throw new Exception( 'Failed to generate embeddings.' );
		}

		$metadata = array(
			'chunk_index'   => $chunk_index,
			'attachment_id' => $attachment_id,
		);

		global $wpdb;

		$insert_data = array(
			'user_id'      => $user_id,
			'content_id'   => $content_id,
			'content_type' => get_post_mime_type( $attachment_id ),
			'title'        => $title,
			'content'      => $chunk,
			'vector_data'  => json_encode( $embedding ),
			'metadata'     => json_encode( $metadata ),
			'is_public'    => 0,
		);

		// Direct database query required for vector embeddings table - no WordPress equivalent exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$this->table_name,
			$insert_data,
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		if ( $result ) {
		} else {
			throw new Exception( 'Failed to insert chunk into database: ' . esc_html( $wpdb->last_error ) );
		}
	}

	private function get_transient_directory() {
		$upload_dir    = wp_upload_dir();
		$transient_dir = $upload_dir['basedir'] . '/aiohm-transients';
		if ( ! file_exists( $transient_dir ) ) {
			wp_mkdir_p( $transient_dir );
		}
		return $transient_dir;
	}

	public function query( $query_text, $scope = 'site', $user_id = 0 ) {
		try {
			$research_prefix = 'Please research the following URL and provide a summary of its key points:';

			if ( strpos( $query_text, $research_prefix ) === 0 ) {
				preg_match( '/(https?:\/\/[^\s]+)/', $query_text, $matches );
				$url = $matches[0] ?? null;

				if ( ! $url ) {
					return "I couldn't find a valid URL in your request. Please try again with the full URL (e.g., https://example.com).";
				}

				$result = $this->research_and_add_url( $url, $user_id );

				if ( is_wp_error( $result ) ) {
					return 'I encountered an error trying to research that URL: ' . $result->get_error_message();
				}

				return $this->summarize_new_context( $url, $user_id );
			}

			if ( $scope === 'private' ) {
				$context_entries = $this->find_context_for_user( $query_text, $user_id );
			} else {
				$context_entries = $this->find_relevant_context( $query_text );
			}

			$context = '';
			foreach ( $context_entries as $entry ) {
				$context .= 'Title: ' . $entry['entry']['title'] . "\n";
				$context .= 'Content: ' . $entry['entry']['content'] . "\n\n";
			}

			$settings    = AIOHM_KB_Assistant::get_settings();
			$ai_settings = ( $scope === 'private' ) ? $settings['muse_mode'] : $settings['mirror_mode'];

			$system_message_key = ( $scope === 'private' ) ? 'system_prompt' : 'qa_system_message';
			$system_message     = $ai_settings[ $system_message_key ] ?? 'You are a helpful assistant.';
			$model_name         = $ai_settings['ai_model'] ?? 'gpt-3.5-turbo';
			$temperature        = $ai_settings['temperature'] ?? 0.7;

			$enriched_user_message = "Here is some context to help you answer:\n\n---\n\n{$context}\n\n---\n\nBased on that context, please answer the following question:\n\n{$query_text}";

			$ai_client = new AIOHM_KB_AI_GPT_Client();
			return $ai_client->get_chat_completion(
				$system_message,
				$enriched_user_message,
				$temperature,
				$model_name
			);

		} catch ( Exception $e ) {
			return 'AI Error: ' . $e->getMessage();
		}
	}

	public function research_and_add_url( $url, $user_id ) {
		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return new WP_Error( 'invalid_url', 'The provided URL is not valid.' );
		}

		// Enhanced request with proper headers
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 30,
				'headers' => array(
					'User-Agent'                => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
					'Accept'                    => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
					'Accept-Language'           => 'en-US,en;q=0.5',
					'Accept-Encoding'           => 'gzip, deflate',
					'Connection'                => 'keep-alive',
					'Upgrade-Insecure-Requests' => '1',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'fetch_failed', 'Could not retrieve content: ' . $response->get_error_message() );
		}

		$content_type = wp_remote_retrieve_header( $response, 'content-type' );
		if ( strpos( $content_type, 'text/html' ) === false ) {
			return new WP_Error( 'invalid_content_type', 'The URL does not appear to be an HTML page.' );
		}

		$html_content = wp_remote_retrieve_body( $response );

		// Enhanced content extraction
		$extracted_data = $this->extract_enhanced_content( $html_content, $url );

		if ( empty( trim( $extracted_data['content'] ) ) ) {
			return new WP_Error( 'no_content', 'Could not extract any readable text from the URL.' );
		}

		$metadata = array(
			'source_url'      => $url,
			'title'           => $extracted_data['title'],
			'description'     => $extracted_data['description'],
			'author'          => $extracted_data['author'],
			'published_date'  => $extracted_data['published_date'],
			'keywords'        => $extracted_data['keywords'],
			'extraction_date' => current_time( 'mysql' ),
		);

		return $this->add_entry( $extracted_data['content'], 'external_url', $extracted_data['title'], $metadata, $user_id, 0 );
	}

	private function extract_enhanced_content( $html_content, $url ) {
		$title          = 'Web Research: ' . $url;
		$description    = '';
		$author         = '';
		$published_date = '';
		$keywords       = '';

		// Create DOMDocument for better parsing
		$dom = new DOMDocument();
		@$dom->loadHTML( '<?xml encoding="UTF-8">' . $html_content );
		$xpath = new DOMXPath( $dom );

		// Extract title - try multiple methods
		if ( preg_match( '/<title>(.*?)<\/title>/i', $html_content, $matches ) ) {
			$title = trim( html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' ) );
		}

		if ( preg_match( '/<meta\s+name=["\']description["\']\s+content=["\'](.*?)["\']/i', $html_content, $matches ) ) {
			$description = trim( html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' ) );
		}

		if ( preg_match( '/<meta\s+property=["\']og:title["\']\s+content=["\'](.*?)["\']/i', $html_content, $matches ) ) {
			$og_title = trim( html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' ) );
			if ( ! empty( $og_title ) && strlen( $og_title ) > strlen( $title ) ) {
				$title = $og_title;
			}
		}

		if ( preg_match( '/<meta\s+property=["\']og:description["\']\s+content=["\'](.*?)["\']/i', $html_content, $matches ) ) {
			$og_description = trim( html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' ) );
			if ( ! empty( $og_description ) && empty( $description ) ) {
				$description = $og_description;
			}
		}

		if ( preg_match( '/<meta\s+name=["\']author["\']\s+content=["\'](.*?)["\']/i', $html_content, $matches ) ) {
			$author = trim( html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' ) );
		}

		if ( preg_match( '/<meta\s+name=["\']keywords["\']\s+content=["\'](.*?)["\']/i', $html_content, $matches ) ) {
			$keywords = trim( html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' ) );
		}

		if ( preg_match( '/<meta\s+property=["\']article:published_time["\']\s+content=["\'](.*?)["\']/i', $html_content, $matches ) ) {
			$published_date = trim( $matches[1] );
		}

		$content_selectors = array(
			'article',
			'main',
			'[role="main"]',
			'.content',
			'.post-content',
			'.entry-content',
			'.article-content',
			'#content',
			'.main-content',
		);

		$main_content = '';
		foreach ( $content_selectors as $selector ) {
			$elements = $xpath->query( "//*[contains(@class, '" . str_replace( array( '[', ']', '.', '#' ), '', $selector ) . "')]" );
			if ( $elements->length > 0 ) {
				foreach ( $elements as $element ) {
					$main_content .= $element->textContent . ' ';
				}
				break;
			}
		}

		// If no main content found, extract from body but exclude common noise
		if ( empty( trim( $main_content ) ) ) {
			$plain_text = wp_strip_all_tags( $html_content );
			// Remove common noise patterns
			$plain_text = preg_replace( '/\b(cookie|privacy|policy|terms|conditions|javascript|advertisement|ads)\b/i', '', $plain_text );
		} else {
			$plain_text = $main_content;
		}

		// Clean up whitespace
		$plain_text = preg_replace( '/\s+/', ' ', trim( $plain_text ) );

		// Limit content length to prevent massive entries
		if ( strlen( $plain_text ) > 15000 ) {
			$plain_text = substr( $plain_text, 0, 15000 ) . '... [Content truncated]';
		}

		return array(
			'title'          => $title,
			'content'        => $plain_text,
			'description'    => $description,
			'author'         => $author,
			'published_date' => $published_date,
			'keywords'       => $keywords,
		);
	}

	private function summarize_new_context( $url, $user_id ) {
		$summary_prompt = "You have just successfully read the content from the URL: {$url}. Now, provide a concise summary of its key points based on the context you've just learned.";
		return $this->query( $summary_prompt, 'private', $user_id );
	}

	public function find_relevant_context( $query_text, $limit = 5 ) {
		global $wpdb;

		// Separate public results from caches populated before visibility enforcement.
		$cache_key     = 'aiohm_public_v2_context_' . md5( $this->table_name . ':' . $query_text . ':' . $limit );
		$cached_result = wp_cache_get( $cache_key, 'aiohm_kb' );

		if ( false !== $cached_result ) {
			return $this->filter_public_context_entries( $cached_result, true );
		}

		$ai_client       = new AIOHM_KB_AI_GPT_Client();
		$query_embedding = $ai_client->generate_embeddings( $query_text );

		$keywords = preg_replace( '/[^a-z0-9\s]/i', '', strtolower( $query_text ) );

		$table_name = $this->table_name;
		$safe_table = $this->get_safe_table_name( $wpdb->prefix . 'aiohm_vector_entries' );

		if ( ! empty( trim( $keywords ) ) ) {
			$search_query         = trim( $keywords );
			$cache_key_search     = 'aiohm_public_v2_search_' . md5( $this->table_name . ':' . $search_query );
			$pre_filtered_entries = wp_cache_get( $cache_key_search, 'aiohm_kb' );

			if ( false === $pre_filtered_entries ) {
				$pre_filtered_entries = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT id, title, content, content_type, metadata, vector_data FROM `' . esc_sql( $safe_table ) . '` WHERE user_id = 0 AND is_public = 1 AND MATCH(content) AGAINST(%s IN NATURAL LANGUAGE MODE) LIMIT 50',
						$search_query
					),
					ARRAY_A
				);

				// If natural language search returned few results, also include recent catalog entries for vector similarity
				if ( empty( $pre_filtered_entries ) || count( $pre_filtered_entries ) < 10 ) {
					$existing_ids = ! empty( $pre_filtered_entries ) ? wp_list_pluck( $pre_filtered_entries, 'id' ) : array();
					$supplemental = $wpdb->get_results(
						'SELECT id, title, content, content_type, metadata, vector_data FROM `' . esc_sql( $safe_table ) . '` WHERE user_id = 0 AND is_public = 1 ORDER BY id DESC LIMIT 100',
						ARRAY_A
					);
					if ( ! empty( $supplemental ) ) {
						foreach ( $supplemental as $s_entry ) {
							if ( ! in_array( $s_entry['id'], $existing_ids, true ) ) {
								$pre_filtered_entries[] = $s_entry;
							}
						}
					}
				}

				wp_cache_set( $cache_key_search, $pre_filtered_entries, 'aiohm_kb', 300 );
			}
		} else {
			$cache_key_all        = 'aiohm_public_v2_all_' . md5( $this->table_name );
			$pre_filtered_entries = wp_cache_get( $cache_key_all, 'aiohm_kb' );

			if ( false === $pre_filtered_entries ) {
				$pre_filtered_entries = $wpdb->get_results(
					'SELECT id, title, content, content_type, metadata, vector_data FROM `' . esc_sql( $safe_table ) . '` WHERE user_id = 0 AND is_public = 1 ORDER BY id DESC LIMIT 150',
					ARRAY_A
				);

				wp_cache_set( $cache_key_all, $pre_filtered_entries, 'aiohm_kb', 600 );
			}
		}

		if ( empty( $pre_filtered_entries ) ) {
			$cache_key_random     = 'aiohm_public_v2_random_' . md5( $this->table_name );
			$pre_filtered_entries = wp_cache_get( $cache_key_random, 'aiohm_kb' );

			if ( false === $pre_filtered_entries ) {
                // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				// Random sampling operations require direct database access for efficient offset-based selection with caching

				// Validate table name for security
				$safe_table = $this->get_safe_table_name( $wpdb->prefix . 'aiohm_vector_entries' );

				// Efficient random sampling using offset-based selection instead of ORDER BY RAND()
				// First get the total count for calculating random offsets
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe table name is validated via get_safe_table_name method
                $total_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `'. esc_sql( $safe_table ) .'` WHERE user_id = %d AND is_public = 1', 0 ) );

				$random_entries = array();
				if ( $total_count > 0 ) {
					$sample_size = min( 100, $total_count );
					$offsets     = array();

					// Generate unique random offsets
					while ( count( $offsets ) < $sample_size ) {
						$offset = wp_rand( 0, $total_count - 1 );
						if ( ! in_array( $offset, $offsets ) ) {
							$offsets[] = $offset;
						}
					}

					// Fetch entries at random offsets
					foreach ( $offsets as $offset ) {
						$entry = $wpdb->get_row(
							$wpdb->prepare(
								'SELECT id, title, content, content_type, metadata, vector_data FROM `' . esc_sql( $safe_table ) . '` WHERE user_id = 0 AND is_public = 1 LIMIT 1 OFFSET %d',
								$offset
							),
							ARRAY_A
						);

						if ( $entry ) {
							$random_entries[] = $entry;
						}
					}

                // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				}

				$pre_filtered_entries = $random_entries;
				wp_cache_set( $cache_key_random, $pre_filtered_entries, 'aiohm_kb', 180 ); // 3 minute cache for random results
			}
		}

		// Recheck persisted visibility even for cached candidates, so revocation is immediate.
		$pre_filtered_entries = $this->filter_public_context_entries( $pre_filtered_entries );
		$similarities = array();
		foreach ( $pre_filtered_entries as $entry ) {
			$vector = json_decode( $entry['vector_data'], true );
			if ( is_array( $vector ) ) {
				$dot_product = array_sum( array_map( fn( $a, $b ) => $a * $b, $query_embedding, $vector ) );
				$mag_a       = sqrt( array_sum( array_map( fn( $a ) => $a * $a, $query_embedding ) ) );
				$mag_b       = sqrt( array_sum( array_map( fn( $b ) => $b * $b, $vector ) ) );
				if ( $mag_a > 0 && $mag_b > 0 ) {
					$similarities[] = array(
						'score' => $dot_product / ( $mag_a * $mag_b ),
						'entry' => $entry,
					);
				}
			}
		}
		usort( $similarities, fn( $a, $b ) => $b['score'] <=> $a['score'] );
		$result = array_slice( $similarities, 0, $limit );

		// Cache the final result
		wp_cache_set( $cache_key, $result, 'aiohm_kb', 300 );

		return $result;
	}

	/** Keep public retrieval scoped after visibility changes, including cache hits. */
	private function filter_public_context_entries( $entries, $wrapped = false ) {
		global $wpdb;

		if ( ! is_array( $entries ) || empty( $entries ) ) {
			return array();
		}

		$ids = array();
		foreach ( $entries as $item ) {
			$entry = $wrapped ? ( $item['entry'] ?? array() ) : $item;
			$id = (int) ( $entry['id'] ?? 0 );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		$ids = array_values( array_unique( $ids ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$safe_table = $this->get_safe_table_name( $this->table_name );
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Revalidate access on every cache hit; identifiers are allowlisted and IDs prepared.
		$public_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM `' . esc_sql( $safe_table ) . '` WHERE user_id = 0 AND is_public = 1 AND id IN (' . $placeholders . ')', $ids ) );
		$allowed = array_fill_keys( array_map( 'intval', (array) $public_ids ), true );

		return array_values( array_filter( $entries, static function ( $item ) use ( $wrapped, $allowed ) {
			$entry = $wrapped ? ( $item['entry'] ?? array() ) : $item;
			return isset( $allowed[ (int) ( $entry['id'] ?? 0 ) ] );
		} ) );
	}

	public function get_all_entries_paginated( $per_page = 20, $page_number = 1 ) {
		global $wpdb;

		$cache_key     = 'aiohm_entries_page_' . $page_number . '_' . $per_page;
		$cached_result = wp_cache_get( $cache_key, 'aiohm_kb' );

		if ( false !== $cached_result ) {
			return $cached_result;
		}

		$offset = ( $page_number - 1 ) * $per_page;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// Export query with caching implementation for paginated data retrieval

		// Validate table name for security
		$safe_table = $this->get_safe_table_name( $wpdb->prefix . 'aiohm_vector_entries' );

		$result = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, title, content_type, user_id, content_id, metadata, created_at FROM `' . esc_sql( $safe_table ) . '` GROUP BY content_id ORDER BY id DESC LIMIT %d OFFSET %d',
				$per_page,
				$offset
			),
			ARRAY_A
		);
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		wp_cache_set( $cache_key, $result, 'aiohm_kb', 600 );

		return $result;
	}

	public function get_total_entries_count() {
		global $wpdb;

		$cache_key    = 'aiohm_total_entries_count';
		$cached_count = wp_cache_get( $cache_key, 'aiohm_kb' );

		if ( false !== $cached_count ) {
			return (int) $cached_count;
		}

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// Count query with caching implementation for total entries tracking
        $table_name = $wpdb->prefix . 'aiohm_vector_entries';
        $count = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT content_id) FROM `" . esc_sql( $table_name ) . "`" );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		wp_cache_set( $cache_key, $count, 'aiohm_kb', 3600 ); // Cache for 1 hour

		return $count;
	}

	public function delete_entry_by_content_id( $content_id ) {
		global $wpdb;
		$table_name = $this->table_name;

		// Get metadata before deletion for cache invalidation
		$cache_key_meta = 'aiohm_meta_' . md5( $content_id );
		$metadata_json  = wp_cache_get( $cache_key_meta, 'aiohm_kb' );

		if ( false === $metadata_json ) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			// Metadata lookup and delete operations require direct database access with proper cache invalidation

			// Validate table name for security
			$safe_table = $this->get_safe_table_name( $wpdb->prefix . 'aiohm_vector_entries' );

			$metadata_json = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT metadata FROM `' . esc_sql( $safe_table ) . '` WHERE content_id = %s LIMIT 1',
					$content_id
				)
			);
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

			wp_cache_set( $cache_key_meta, $metadata_json, 'aiohm_kb', 300 );
		}

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// Delete operation with cache invalidation requires direct database access
		$deleted = $wpdb->delete( $this->table_name, array( 'content_id' => $content_id ), array( '%s' ) );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery

		if ( $deleted > 0 ) {
			// Invalidate relevant caches
			$this->clear_search_caches();
			wp_cache_delete( $cache_key_meta, 'aiohm_kb' );
			wp_cache_delete( 'aiohm_total_entries_count', 'aiohm_kb' );

			if ( ! empty( $metadata_json ) ) {
				$metadata         = json_decode( $metadata_json, true );
				$original_item_id = null;
				if ( isset( $metadata['post_id'] ) ) {
					$original_item_id = (int) $metadata['post_id'];
				} elseif ( isset( $metadata['attachment_id'] ) ) {
					$original_item_id = (int) $metadata['attachment_id'];
				}
				if ( $original_item_id ) {
					delete_post_meta( $original_item_id, '_aiohm_indexed' );
					clean_post_cache( $original_item_id );
				}
			}
		}
		return $deleted;
	}

	private function clear_search_caches() {
		// Clear all search-related caches
		wp_cache_flush_group( 'aiohm_kb' );
	}

	private function generate_entry_id( $title, $content ) {
		// Include timestamp and user ID to make content_id more unique
		$user_id   = get_current_user_id();
		$timestamp = current_time( 'timestamp' );
		return md5( $title . $content . $user_id . $timestamp );
	}

	private function chunk_content( $content, $chunk_size, $chunk_overlap ) {
		$content = trim( $content );
		if ( empty( $content ) ) {
			return array();
		}
		if ( strlen( $content ) <= $chunk_size ) {
			return array( $content );
		}

		// Use intelligent chunking that respects sentence boundaries
		return $this->intelligent_chunk_content( $content, $chunk_size, $chunk_overlap );
	}

	/**
	 * Intelligent content chunking that respects sentence and paragraph boundaries
	 *
	 * @param string $content Content to chunk
	 * @param int    $chunk_size Target chunk size
	 * @param int    $chunk_overlap Overlap between chunks
	 * @return array Array of content chunks
	 */
	private function intelligent_chunk_content( $content, $chunk_size, $chunk_overlap ) {
		$chunks = array();

		$paragraphs    = preg_split( '/\n\s*\n/', $content );
		$current_chunk = '';

		foreach ( $paragraphs as $paragraph ) {
			$paragraph = trim( $paragraph );
			if ( empty( $paragraph ) ) {
				continue;
			}

			if ( ! empty( $current_chunk ) && ( strlen( $current_chunk ) + strlen( $paragraph ) + 2 ) > $chunk_size ) {
				$processed_chunks = $this->process_chunk_with_sentences( $current_chunk, $chunk_size, $chunk_overlap );
				$chunks           = array_merge( $chunks, $processed_chunks );

				$current_chunk = $this->get_chunk_overlap( $chunks, $chunk_overlap ) . $paragraph;
			} else {
				$current_chunk .= ( empty( $current_chunk ) ? '' : "\n\n" ) . $paragraph;
			}
		}

		if ( ! empty( $current_chunk ) ) {
			$processed_chunks = $this->process_chunk_with_sentences( $current_chunk, $chunk_size, $chunk_overlap );
			$chunks           = array_merge( $chunks, $processed_chunks );
		}

		if ( empty( $chunks ) ) {
			return $this->basic_chunk_content( $content, $chunk_size, $chunk_overlap );
		}

		return array_filter(
			$chunks,
			function ( $chunk ) {
				return ! empty( trim( $chunk ) );
			}
		);
	}

	/**
	 * Process a chunk with sentence-boundary awareness
	 *
	 * @param string $content Content to process
	 * @param int    $chunk_size Target chunk size
	 * @param int    $chunk_overlap Overlap size
	 * @return array Array of processed chunks
	 */
	private function process_chunk_with_sentences( $content, $chunk_size, $chunk_overlap ) {
		if ( strlen( $content ) <= $chunk_size ) {
			return array( $content );
		}

		$chunks = array();

		$sentences = preg_split( '/(?<=[.!?])\s+(?=[A-Z])|(?<=[.!?])\n+/', $content, -1, PREG_SPLIT_NO_EMPTY );

		if ( empty( $sentences ) ) {
			return $this->basic_chunk_content( $content, $chunk_size, $chunk_overlap );
		}

		$current_chunk   = '';
		$sentence_buffer = array();

		foreach ( $sentences as $sentence ) {
			$sentence = trim( $sentence );
			if ( empty( $sentence ) ) {
				continue;
			}

			$potential_chunk = $current_chunk . ( empty( $current_chunk ) ? '' : ' ' ) . $sentence;

			if ( strlen( $potential_chunk ) <= $chunk_size ) {
				$current_chunk     = $potential_chunk;
				$sentence_buffer[] = $sentence;
			} else {
				if ( ! empty( $current_chunk ) ) {
					$chunks[] = $current_chunk;
				}

				if ( strlen( $sentence ) > $chunk_size ) {
					$long_sentence_chunks = $this->split_long_sentence( $sentence, $chunk_size );
					$chunks               = array_merge( $chunks, $long_sentence_chunks );
					$current_chunk        = '';
					$sentence_buffer      = array();
				} else {
					$overlap_text    = $this->get_sentence_overlap( $sentence_buffer, $chunk_overlap );
					$current_chunk   = $overlap_text . ( empty( $overlap_text ) ? '' : ' ' ) . $sentence;
					$sentence_buffer = $overlap_text ? array( end( $sentence_buffer ), $sentence ) : array( $sentence );
				}
			}
		}

		if ( ! empty( $current_chunk ) ) {
			$chunks[] = $current_chunk;
		}

		return $chunks;
	}

	/**
	 * Split a very long sentence that exceeds chunk size
	 *
	 * @param string $sentence Long sentence to split
	 * @param int    $chunk_size Maximum chunk size
	 * @return array Array of sentence fragments
	 */
	private function split_long_sentence( $sentence, $chunk_size ) {
		$chunks = array();

		$parts = preg_split( '/([,;:])\s*/', $sentence, -1, PREG_SPLIT_DELIM_CAPTURE );

		$current_chunk = '';
		for ( $i = 0; $i < count( $parts ); $i += 2 ) {
			$part      = $parts[ $i ];
			$delimiter = isset( $parts[ $i + 1 ] ) ? $parts[ $i + 1 ] : '';

			$potential_chunk = $current_chunk . $part . $delimiter;

			if ( strlen( $potential_chunk ) <= $chunk_size ) {
				$current_chunk = $potential_chunk;
			} else {
				if ( ! empty( $current_chunk ) ) {
					$chunks[] = trim( $current_chunk );
				}

				if ( strlen( $part . $delimiter ) > $chunk_size ) {
					$char_chunks   = $this->basic_chunk_content( $part . $delimiter, $chunk_size, 50 );
					$chunks        = array_merge( $chunks, $char_chunks );
					$current_chunk = '';
				} else {
					$current_chunk = $part . $delimiter;
				}
			}
		}

		if ( ! empty( $current_chunk ) ) {
			$chunks[] = trim( $current_chunk );
		}

		return array_filter(
			$chunks,
			function ( $chunk ) {
				return ! empty( trim( $chunk ) );
			}
		);
	}

	/**
	 * Get overlap text from previous chunks
	 *
	 * @param array $chunks Previous chunks
	 * @param int   $overlap_size Target overlap size
	 * @return string Overlap text
	 */
	private function get_chunk_overlap( $chunks, $overlap_size ) {
		if ( empty( $chunks ) || $overlap_size <= 0 ) {
			return '';
		}

		$last_chunk = end( $chunks );
		if ( strlen( $last_chunk ) <= $overlap_size ) {
			return $last_chunk;
		}

		$overlap_text   = substr( $last_chunk, -$overlap_size );
		$sentence_start = strpos( $overlap_text, '. ' );

		if ( $sentence_start !== false && $sentence_start < $overlap_size / 2 ) {
			return substr( $overlap_text, $sentence_start + 2 );
		}

		return $overlap_text;
	}

	/**
	 * Get sentence-based overlap from sentence buffer
	 *
	 * @param array $sentence_buffer Buffer of recent sentences
	 * @param int   $overlap_size Target overlap size
	 * @return string Overlap text
	 */
	private function get_sentence_overlap( $sentence_buffer, $overlap_size ) {
		if ( empty( $sentence_buffer ) || $overlap_size <= 0 ) {
			return '';
		}

		$overlap = '';
		for ( $i = count( $sentence_buffer ) - 1; $i >= 0; $i-- ) {
			$potential_overlap = $sentence_buffer[ $i ] . ( empty( $overlap ) ? '' : ' ' . $overlap );
			if ( strlen( $potential_overlap ) <= $overlap_size ) {
				$overlap = $potential_overlap;
			} else {
				break;
			}
		}

		return $overlap;
	}

	/**
	 * Basic character-based chunking as fallback
	 *
	 * @param string $content Content to chunk
	 * @param int    $chunk_size Chunk size
	 * @param int    $chunk_overlap Overlap size
	 * @return array Array of chunks
	 */
	private function basic_chunk_content( $content, $chunk_size, $chunk_overlap ) {
		$chunks         = array();
		$content_length = strlen( $content );
		$start          = 0;

		while ( $start < $content_length ) {
			$chunk    = substr( $content, $start, $chunk_size );
			$chunks[] = $chunk;
			$start   += ( $chunk_size - $chunk_overlap );
		}

		return $chunks;
	}

	public function export_knowledge_base( $user_id = 0 ) {
		global $wpdb;

		try {

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			// Knowledge base export operations require direct database access with table validation for data integrity

			// Check if table exists
			$table_name  = $wpdb->prefix . 'aiohm_vector_entries';
			$table_check = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

			if ( ! $table_check ) {
				throw new Exception( 'Vector entries table does not exist' );
			}

			// Validate table name for security
			$safe_table = $this->get_safe_table_name( $wpdb->prefix . 'aiohm_vector_entries' );

			// Get data with specific columns to avoid memory issues
			// Filter by both user_id and is_public to ensure correct scope separation
			if ( $user_id === 0 ) {
				// Public export: user_id = 0 AND is_public = 1
				$data = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT id, user_id, content_id, content_type, title, content, metadata, created_at FROM `' . esc_sql( $safe_table ) . '` WHERE user_id = %d AND is_public = 1',
						$user_id
					),
					ARRAY_A
				);
			} else {
				// Private export: user_id = current_user AND is_public = 0
				$data = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT id, user_id, content_id, content_type, title, content, metadata, created_at FROM `' . esc_sql( $safe_table ) . '` WHERE user_id = %d AND is_public = 0',
						$user_id
					),
					ARRAY_A
				);
			}
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

			if ( $wpdb->last_error ) {
				// Return generic error to user, log detailed error internally
				throw new Exception( 'Failed to export knowledge base. Please try again.' );
			}

			// Return empty array as JSON if no data found
			if ( empty( $data ) ) {
				return json_encode( array(), JSON_PRETTY_PRINT );
			}

			// Try encoding without vector_data first for public exports
			if ( $user_id === 0 ) {
				// Remove large vector_data for public exports to avoid memory issues
				foreach ( $data as &$entry ) {
					unset( $entry['vector_data'] );
				}
			}

			$json_result = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

			if ( json_last_error() !== JSON_ERROR_NONE ) {
				throw new Exception( 'JSON encoding error: ' . json_last_error_msg() );
			}

			return $json_result;

		} catch ( Exception $e ) {
			throw $e;
		}
	}

	public function import_knowledge_base( $json_data ) {
		global $wpdb;
		$data = json_decode( $json_data, true );
		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
			throw new Exception( 'Invalid JSON data provided.' );
		}
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// Bulk import operations require direct database access with transaction management for data integrity

		$wpdb->query( 'START TRANSACTION' );
		try {
			// Bulk delete with cache clearing
			$wpdb->delete( $this->table_name, array( 'user_id' => 0 ), array( '%d' ) );

			foreach ( $data as $row ) {
				if ( isset( $row['content_id'], $row['content_type'], $row['title'], $row['content'] ) ) {
					$wpdb->insert(
						$this->table_name,
						array(
							'user_id'      => 0,
							'content_id'   => $row['content_id'],
							'content_type' => $row['content_type'],
							'title'        => $row['title'],
							'content'      => $row['content'],
							'vector_data'  => $row['vector_data'] ?? '[]',
							'metadata'     => $row['metadata'] ?? '[]',
						),
						array(
							'%d',
							'%s',
							'%s',
							'%s',
							'%s',
							'%s',
							'%s',
						)
					);
				}
			}

			// Transaction commit with cache clearing
			$wpdb->query( 'COMMIT' );

			// Clear all caches after successful import
			$this->clear_search_caches();

		} catch ( Exception $e ) {
			// Transaction rollback required
			$wpdb->query( 'ROLLBACK' );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			throw $e;
		}
		return count( $data );
	}

	public function find_context_for_user( $query_text, $user_id, $limit = 5 ) {
		global $wpdb;
		$ai_client       = new AIOHM_KB_AI_GPT_Client();
		$query_embedding = $ai_client->generate_embeddings( $query_text );
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// User context search query requires direct database access for vector similarity operations

		// Validate table name for security
		$safe_table = $this->get_safe_table_name( $wpdb->prefix . 'aiohm_vector_entries' );

		$all_entries = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, title, content, content_type, metadata, vector_data FROM `' . esc_sql( $safe_table ) . '` WHERE user_id = 0 OR user_id = %d',
				$user_id
			),
			ARRAY_A
		);
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$similarities = array();
		foreach ( $all_entries as $entry ) {
			$vector = ! empty( $entry['vector_data'] ) ? json_decode( $entry['vector_data'], true ) : null;
			if ( is_array( $vector ) ) {
				$dot_product = array_sum( array_map( fn( $a, $b ) => $a * $b, $query_embedding, $vector ) );
				$mag_a       = sqrt( array_sum( array_map( fn( $a ) => $a * $a, $query_embedding ) ) );
				$mag_b       = sqrt( array_sum( array_map( fn( $b ) => $b * $b, $vector ) ) );
				if ( $mag_a > 0 && $mag_b > 0 ) {
					$similarities[] = array(
						'score' => $dot_product / ( $mag_a * $mag_b ),
						'entry' => $entry,
					);
				}
			}
		}
		usort( $similarities, fn( $a, $b ) => $b['score'] <=> $a['score'] );
		return array_slice( $similarities, 0, $limit );
	}

	public function update_entry_scope_by_content_id( $content_id, $new_user_id ) {
		global $wpdb;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// Direct update with cache clearing required for scope management operations
		$result = $wpdb->update( $this->table_name, array( 'user_id' => $new_user_id ), array( 'content_id' => $content_id ), array( '%d' ), array( '%s' ) );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( $result ) {
			// Clear relevant caches after update
			$this->clear_search_caches();
		}

		return $result;
	}

	public function update_entry_visibility_by_content_id( $content_id, $is_public ) {
		global $wpdb;

		$update_data    = array( 'is_public' => $is_public );
		$update_formats = array( '%d' );

		if ( $is_public ) {
			// Public: user_id = 0, is_public = 1
			$update_data['user_id'] = 0;
			$update_formats[]       = '%d';
		} else {
			// Private: user_id = current_user_id, is_public = 0
			$update_data['user_id'] = get_current_user_id();
			$update_formats[]       = '%d';
		}

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct update with cache clearing
		$result = $wpdb->update( $this->table_name, $update_data, array( 'content_id' => $content_id ), $update_formats, array( '%s' ) );

		if ( $result ) {
			// Clear relevant caches after update
			$this->clear_search_caches();
		}

		return $result;
	}

	public function get_random_chunk() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'aiohm_vector_entries';

		// Use efficient random sampling instead of ORDER BY RAND()
		// First get the total count
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Count query for random sampling
		$total_count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'aiohm_vector_entries WHERE is_public = %d', 1 ) );

		if ( $total_count > 0 ) {
			$random_offset = wp_rand( 0, $total_count - 1 );

			// Get random entry using offset
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Optimized random content query
			$random_entry = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT content FROM ' . $wpdb->prefix . 'aiohm_vector_entries WHERE is_public = %d LIMIT 1 OFFSET %d',
					1,
					$random_offset
				)
			);

			return $random_entry;
		}

		return null;
	}
}
