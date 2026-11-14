<?php
/**
 * Data Parser for AIOHM Knowledge Assistant
 * Handles JSON and CSV files
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIOHM_Data_Parser extends AIOHM_Abstract_Parser {

	protected function init() {
		$this->supported_extensions = array( 'json', 'csv' );
		$this->has_ai_fallback      = true;
		$this->priority             = 10;
	}

	/**
	 * Parse data files (JSON, CSV)
	 *
	 * @param string $file_path Path to the file
	 * @return array Parsed content data
	 * @throws Exception If file cannot be parsed
	 */
	public function parse( $file_path ) {
		$extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

		if ( ! is_readable( $file_path ) ) {
			throw new Exception( 'File is not readable: ' . esc_html( basename( $file_path ) ) );
		}

		switch ( $extension ) {
			case 'json':
				return $this->parse_json( $file_path );
			case 'csv':
				return $this->parse_csv( $file_path );
			default:
				throw new Exception( 'Unsupported data format: ' . esc_html( $extension ) );
		}
	}

	/**
	 * Parse JSON file
	 *
	 * @param string $file_path Path to JSON file
	 * @return array Parsed content data
	 * @throws Exception If JSON is invalid
	 */
	private function parse_json( $file_path ) {
		$content = file_get_contents( $file_path );
		if ( $content === false ) {
			throw new Exception( 'Failed to read JSON file' );
		}

		// Try to decode JSON
		$data = json_decode( $content, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			throw new Exception( 'Invalid JSON: ' . esc_html( json_last_error_msg() ) );
		}

		// Convert JSON data to readable text
		$readable_content  = $this->json_to_text( $data );
		$sanitized_content = $this->sanitize_content( $readable_content, 'json' );

		if ( empty( trim( $sanitized_content ) ) ) {
			throw new Exception( 'JSON file produced no readable content' );
		}

		// Extract title from JSON structure
		$title = $this->extract_json_title( $data, pathinfo( $file_path, PATHINFO_FILENAME ) );

		// Generate metadata
		$metadata = $this->generate_metadata(
			$file_path,
			'json',
			array(
				'json_structure'     => $this->analyze_json_structure( $data ),
				'original_size'      => strlen( $content ),
				'processed_size'     => strlen( $sanitized_content ),
				'has_nested_objects' => $this->has_nested_objects( $data ),
				'top_level_keys'     => is_array( $data ) && ! $this->is_sequential_array( $data ) ? array_keys( $data ) : array(),
			)
		);

		return array(
			'content'  => $sanitized_content,
			'title'    => $title,
			'metadata' => $metadata,
		);
	}

	/**
	 * Parse CSV file
	 *
	 * @param string $file_path Path to CSV file
	 * @return array Parsed content data
	 * @throws Exception If CSV cannot be parsed
	 */
	private function parse_csv( $file_path ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- CSV parsing requires direct file operations
		$handle = fopen( $file_path, 'r' );
		if ( $handle === false ) {
			throw new Exception( 'Failed to open CSV file' );
		}

		$csv_data  = array();
		$headers   = null;
		$row_count = 0;

		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			if ( $headers === null ) {
				$headers = $row;
				continue;
			}

			$csv_data[] = array_combine( $headers, $row );
			++$row_count;

			// Limit to prevent memory issues
			if ( $row_count > 10000 ) {
				break;
			}
		}

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- CSV parsing cleanup
		fclose( $handle );

		if ( empty( $csv_data ) ) {
			throw new Exception( 'CSV file appears to be empty or has no data rows' );
		}

		// Convert CSV data to readable text
		$readable_content  = $this->csv_to_text( $csv_data, $headers );
		$sanitized_content = $this->sanitize_content( $readable_content, 'csv' );

		// Extract title
		$title = pathinfo( $file_path, PATHINFO_FILENAME );
		if ( ! empty( $headers ) && count( $headers ) > 0 ) {
			$title .= ' (' . implode( ', ', array_slice( $headers, 0, 3 ) ) .
					( count( $headers ) > 3 ? '...' : '' ) . ')';
		}

		// Generate metadata
		$metadata = $this->generate_metadata(
			$file_path,
			'csv',
			array(
				'row_count'    => $row_count,
				'column_count' => count( $headers ),
				'headers'      => $headers,
				'data_types'   => $this->analyze_csv_data_types( $csv_data ),
				'has_headers'  => ! empty( $headers ),
			)
		);

		return array(
			'content'  => $sanitized_content,
			'title'    => $title,
			'metadata' => $metadata,
		);
	}

	/**
	 * Convert JSON data to readable text
	 *
	 * @param mixed $data JSON data
	 * @param int   $depth Current depth (for recursion)
	 * @return string Readable text representation
	 */
	private function json_to_text( $data, $depth = 0 ) {
		$text   = '';
		$indent = str_repeat( '  ', $depth );

		if ( is_array( $data ) ) {
			if ( $this->is_sequential_array( $data ) ) {
				// Handle sequential arrays (lists)
				foreach ( $data as $item ) {
					if ( is_scalar( $item ) ) {
						$text .= '- ' . $item . "\n";
					} else {
						$text .= '- ' . $this->json_to_text( $item, $depth + 1 ) . "\n";
					}
				}
			} else {
				// Handle associative arrays (objects)
				foreach ( $data as $key => $value ) {
					$text .= $indent . $key . ': ';
					if ( is_scalar( $value ) ) {
						$text .= $value . "\n";
					} else {
						$text .= "\n" . $this->json_to_text( $value, $depth + 1 );
					}
				}
			}
		} elseif ( is_scalar( $data ) ) {
			$text .= $indent . $data . "\n";
		}

		return $text;
	}

	/**
	 * Convert CSV data to readable text
	 *
	 * @param array $csv_data CSV rows
	 * @param array $headers Column headers
	 * @return string Readable text representation
	 */
	private function csv_to_text( $csv_data, $headers ) {
		$text = 'Data Table with ' . count( $csv_data ) . " rows:\n\n";

		// Add column descriptions
		if ( ! empty( $headers ) ) {
			$text .= 'Columns: ' . implode( ', ', $headers ) . "\n\n";
		}

		// Convert each row to readable format
		$sample_size = min( 100, count( $csv_data ) ); // Limit to prevent huge text blocks
		for ( $i = 0; $i < $sample_size; $i++ ) {
			$row   = $csv_data[ $i ];
			$text .= 'Row ' . ( $i + 1 ) . ":\n";

			foreach ( $row as $column => $value ) {
				if ( ! empty( $value ) ) {
					$text .= "  $column: $value\n";
				}
			}

			$text .= "\n";
		}

		if ( count( $csv_data ) > $sample_size ) {
			$text .= '[... ' . ( count( $csv_data ) - $sample_size ) . " more rows]\n";
		}

		return $text;
	}

	/**
	 * Extract title from JSON structure
	 *
	 * @param mixed  $data JSON data
	 * @param string $fallback Fallback title
	 * @return string Extracted title
	 */
	private function extract_json_title( $data, $fallback ) {
		if ( is_array( $data ) && ! $this->is_sequential_array( $data ) ) {
			// Look for common title fields
			$title_fields = array( 'title', 'name', 'label', 'heading', 'subject' );
			foreach ( $title_fields as $field ) {
				if ( isset( $data[ $field ] ) && is_string( $data[ $field ] ) ) {
					return $data[ $field ];
				}
			}
		}

		return $fallback;
	}

	/**
	 * Check if array is sequential (list) vs associative (object)
	 *
	 * @param array $array Array to check
	 * @return bool True if sequential
	 */
	private function is_sequential_array( $array ) {
		if ( ! is_array( $array ) ) {
			return false;
		}
		return array_keys( $array ) === range( 0, count( $array ) - 1 );
	}

	/**
	 * Analyze JSON structure
	 *
	 * @param mixed $data JSON data
	 * @return array Structure analysis
	 */
	private function analyze_json_structure( $data ) {
		return array(
			'type'               => gettype( $data ),
			'is_array'           => is_array( $data ),
			'is_sequential'      => is_array( $data ) ? $this->is_sequential_array( $data ) : false,
			'element_count'      => is_array( $data ) ? count( $data ) : 0,
			'max_depth'          => $this->get_json_depth( $data ),
			'has_nested_objects' => $this->has_nested_objects( $data ),
		);
	}

	/**
	 * Get maximum depth of JSON structure
	 *
	 * @param mixed $data JSON data
	 * @param int   $current_depth Current depth
	 * @return int Maximum depth
	 */
	private function get_json_depth( $data, $current_depth = 0 ) {
		if ( ! is_array( $data ) ) {
			return $current_depth;
		}

		$max_depth = $current_depth;
		foreach ( $data as $value ) {
			if ( is_array( $value ) ) {
				$depth     = $this->get_json_depth( $value, $current_depth + 1 );
				$max_depth = max( $max_depth, $depth );
			}
		}

		return $max_depth;
	}

	/**
	 * Check if JSON has nested objects
	 *
	 * @param mixed $data JSON data
	 * @return bool True if has nested objects
	 */
	private function has_nested_objects( $data ) {
		if ( ! is_array( $data ) ) {
			return false;
		}

		foreach ( $data as $value ) {
			if ( is_array( $value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Analyze data types in CSV columns
	 *
	 * @param array $csv_data CSV data
	 * @return array Data type analysis per column
	 */
	private function analyze_csv_data_types( $csv_data ) {
		if ( empty( $csv_data ) ) {
			return array();
		}

		$sample_size = min( 100, count( $csv_data ) );
		$columns     = array_keys( $csv_data[0] );
		$types       = array();

		foreach ( $columns as $column ) {
			$column_types = array();

			for ( $i = 0; $i < $sample_size; $i++ ) {
				$value = $csv_data[ $i ][ $column ] ?? '';

				if ( empty( $value ) ) {
					$column_types['empty'] = ( $column_types['empty'] ?? 0 ) + 1;
					continue;
				}

				if ( is_numeric( $value ) ) {
					if ( strpos( $value, '.' ) !== false ) {
						$column_types['float'] = ( $column_types['float'] ?? 0 ) + 1;
					} else {
						$column_types['integer'] = ( $column_types['integer'] ?? 0 ) + 1;
					}
				} elseif ( strtotime( $value ) !== false ) {
					$column_types['date'] = ( $column_types['date'] ?? 0 ) + 1;
				} else {
					$column_types['string'] = ( $column_types['string'] ?? 0 ) + 1;
				}
			}

			// Determine predominant type
			arsort( $column_types );
			$types[ $column ] = array(
				'primary_type'      => array_key_first( $column_types ),
				'type_distribution' => $column_types,
			);
		}

		return $types;
	}
}
