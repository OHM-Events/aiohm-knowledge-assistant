<?php
/**
 * Spreadsheet Parser for AIOHM Knowledge Assistant
 * Handles XLS and XLSX files with data-to-text conversion
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIOHM_Spreadsheet_Parser extends AIOHM_Abstract_Parser {

	protected function init() {
		$this->supported_extensions = array( 'xlsx', 'xls' );
		$this->has_ai_fallback      = true;
		$this->priority             = 13;
	}

	/**
	 * Parse spreadsheet files (XLS, XLSX)
	 *
	 * @param string $file_path Path to the spreadsheet file
	 * @return array Parsed content data
	 * @throws Exception If spreadsheet cannot be parsed
	 */
	public function parse( $file_path ) {
		$extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

		if ( ! is_readable( $file_path ) ) {
			throw new Exception( 'Spreadsheet file is not readable: ' . esc_html( basename( $file_path ) ) );
		}

		switch ( $extension ) {
			case 'xlsx':
				return $this->parse_xlsx( $file_path );
			case 'xls':
				return $this->parse_xls( $file_path );
			default:
				throw new Exception( 'Unsupported spreadsheet format: ' . esc_html( $extension ) );
		}
	}

	/**
	 * Parse XLSX file using ZIP extraction
	 *
	 * @param string $file_path Path to XLSX file
	 * @return array Parsed content data
	 * @throws Exception If XLSX cannot be parsed
	 */
	private function parse_xlsx( $file_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new Exception( 'ZipArchive class not available for XLSX parsing' );
		}

		$zip    = new ZipArchive();
		$result = $zip->open( $file_path );

		if ( $result !== true ) {
			throw new Exception( 'Failed to open XLSX file as ZIP archive (Error code: ' . esc_html( $result ) . ')' );
		}

		try {
			// Extract shared strings
			$shared_strings = $this->extract_shared_strings( $zip );

			// Extract worksheet data
			$worksheets_data = $this->extract_worksheets( $zip, $shared_strings );

			// Extract workbook properties
			$properties = $this->extract_xlsx_properties( $zip );

			$zip->close();

			if ( empty( $worksheets_data ) ) {
				throw new Exception( 'XLSX file contains no readable worksheet data' );
			}

			// Convert spreadsheet data to readable text
			$content_data      = $this->convert_xlsx_to_text( $worksheets_data );
			$sanitized_content = $this->sanitize_content( $content_data['content'], 'xlsx' );

			// Extract title
			$title = $properties['title'] ?? pathinfo( $file_path, PATHINFO_FILENAME );
			if ( empty( $title ) && ! empty( $worksheets_data ) ) {
				$first_sheet = array_key_first( $worksheets_data );
				$title       = $first_sheet;
			}

			// Generate metadata
			$metadata = $this->generate_metadata(
				$file_path,
				'xlsx',
				array_merge(
					$properties,
					array(
						'worksheet_count' => count( $worksheets_data ),
						'total_rows'      => $content_data['total_rows'],
						'total_columns'   => $content_data['total_columns'],
						'worksheets'      => array_keys( $worksheets_data ),
						'has_formulas'    => $content_data['has_formulas'],
						'data_types'      => $content_data['data_types'],
						'word_count'      => str_word_count( $sanitized_content ),
						'char_count'      => strlen( $sanitized_content ),
					)
				)
			);

			return array(
				'content'  => $sanitized_content,
				'title'    => $title,
				'metadata' => $metadata,
			);

		} catch ( Exception $e ) {
			$zip->close();
			throw $e;
		}
	}

	/**
	 * Parse XLS file (legacy format)
	 *
	 * @param string $file_path Path to XLS file
	 * @return array Parsed content data
	 * @throws Exception If XLS cannot be parsed
	 */
	private function parse_xls( $file_path ) {
		// XLS files are in binary format and much more complex to parse
		// We'll use a simplified approach to extract readable text

		$content = $this->extract_xls_text( $file_path );

		if ( empty( trim( $content ) ) ) {
			throw new Exception( 'XLS file appears to be empty or unreadable' );
		}

		$sanitized_content = $this->sanitize_content( $content, 'xls' );

		// Extract title
		$title = pathinfo( $file_path, PATHINFO_FILENAME );

		// Generate metadata
		$metadata = $this->generate_metadata(
			$file_path,
			'xls',
			array(
				'extraction_method' => 'binary_text_extraction',
				'word_count'        => str_word_count( $sanitized_content ),
				'char_count'        => strlen( $sanitized_content ),
				'note'              => 'XLS parsing is limited - consider converting to XLSX for better results',
			)
		);

		return array(
			'content'  => $sanitized_content,
			'title'    => $title,
			'metadata' => $metadata,
		);
	}

	/**
	 * Extract shared strings from XLSX
	 *
	 * @param ZipArchive $zip ZIP archive
	 * @return array Shared strings
	 */
	private function extract_shared_strings( $zip ) {
		$shared_strings_xml = $zip->getFromName( 'xl/sharedStrings.xml' );
		if ( $shared_strings_xml === false ) {
			return array();
		}

		$shared_strings = array();
		$doc            = new DOMDocument();

		if ( $doc->loadXML( $shared_strings_xml ) ) {
			$xpath    = new DOMXPath( $doc );
			$si_nodes = $xpath->query( '//si' );

			foreach ( $si_nodes as $si_node ) {
				$text_nodes = $xpath->query( './/t', $si_node );
				$text       = '';

				foreach ( $text_nodes as $text_node ) {
					$text .= $text_node->textContent;
				}

				$shared_strings[] = $text;
			}
		}

		return $shared_strings;
	}

	/**
	 * Extract worksheets from XLSX
	 *
	 * @param ZipArchive $zip ZIP archive
	 * @param array      $shared_strings Shared strings array
	 * @return array Worksheets data
	 */
	private function extract_worksheets( $zip, $shared_strings ) {
		$worksheets = array();

		// Get workbook.xml to find worksheet names
		$workbook_xml = $zip->getFromName( 'xl/workbook.xml' );
		$sheet_names  = array();

		if ( $workbook_xml !== false ) {
			$doc = new DOMDocument();
			if ( $doc->loadXML( $workbook_xml ) ) {
				$xpath       = new DOMXPath( $doc );
				$sheet_nodes = $xpath->query( '//sheet' );

				foreach ( $sheet_nodes as $sheet_node ) {
					$sheet_id                 = $sheet_node->getAttribute( 'sheetId' );
					$sheet_name               = $sheet_node->getAttribute( 'name' );
					$sheet_names[ $sheet_id ] = $sheet_name;
				}
			}
		}

		// Extract worksheet data
		for ( $i = 1; $i <= 10; $i++ ) { // Limit to first 10 worksheets
			$worksheet_xml = $zip->getFromName( "xl/worksheets/sheet{$i}.xml" );
			if ( $worksheet_xml === false ) {
				continue;
			}

			$sheet_name     = $sheet_names[ $i ] ?? "Sheet{$i}";
			$worksheet_data = $this->parse_worksheet_xml( $worksheet_xml, $shared_strings );

			if ( ! empty( $worksheet_data ) ) {
				$worksheets[ $sheet_name ] = $worksheet_data;
			}
		}

		return $worksheets;
	}

	/**
	 * Parse individual worksheet XML
	 *
	 * @param string $worksheet_xml Worksheet XML content
	 * @param array  $shared_strings Shared strings
	 * @return array Worksheet data
	 */
	private function parse_worksheet_xml( $worksheet_xml, $shared_strings ) {
		$doc = new DOMDocument();
		if ( ! $doc->loadXML( $worksheet_xml ) ) {
			return array();
		}

		$xpath = new DOMXPath( $doc );
		$xpath->registerNamespace( 'x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main' );

		$cells          = $xpath->query( '//x:c' );
		$worksheet_data = array();

		foreach ( $cells as $cell ) {
			$cell_ref  = $cell->getAttribute( 'r' ); // e.g., "A1", "B2"
			$cell_type = $cell->getAttribute( 't' );

			$value_node = $xpath->query( './/x:v', $cell )->item( 0 );
			if ( ! $value_node ) {
				continue;
			}

			$value = $value_node->textContent;

			// Process cell value based on type
			if ( $cell_type === 's' && isset( $shared_strings[ $value ] ) ) {
				// Shared string
				$value = $shared_strings[ $value ];
			} elseif ( $cell_type === 'b' ) {
				// Boolean
				$value = $value ? 'TRUE' : 'FALSE';
			} elseif ( is_numeric( $value ) ) {
				// Number - check if it might be a date
				if ( $value > 25569 && $value < 50000 ) { // Approximate Excel date range
					$unix_date  = ( $value - 25569 ) * 86400;
					$date_value = gmdate( 'Y-m-d', $unix_date );
					if ( $date_value !== '1970-01-01' ) {
						$value = $date_value;
					}
				}
			}

			if ( ! empty( trim( $value ) ) ) {
				$coords = $this->parse_cell_reference( $cell_ref );
				$worksheet_data[ $coords['row'] ][ $coords['col'] ] = $value;
			}
		}

		return $worksheet_data;
	}

	/**
	 * Parse cell reference (e.g., "A1" -> row 1, col 1)
	 *
	 * @param string $cell_ref Cell reference
	 * @return array Row and column numbers
	 */
	private function parse_cell_reference( $cell_ref ) {
		if ( preg_match( '/([A-Z]+)(\d+)/', $cell_ref, $matches ) ) {
			$col_letters = $matches[1];
			$row         = (int) $matches[2];

			// Convert column letters to number
			$col = 0;
			for ( $i = 0; $i < strlen( $col_letters ); $i++ ) {
				$col = $col * 26 + ( ord( $col_letters[ $i ] ) - ord( 'A' ) + 1 );
			}

			return array(
				'row' => $row,
				'col' => $col,
			);
		}

		return array(
			'row' => 1,
			'col' => 1,
		);
	}

	/**
	 * Extract XLSX properties
	 *
	 * @param ZipArchive $zip ZIP archive
	 * @return array Properties
	 */
	private function extract_xlsx_properties( $zip ) {
		$properties = array();

		// Core properties
		$core_props = $zip->getFromName( 'docProps/core.xml' );
		if ( $core_props !== false ) {
			$doc = new DOMDocument();
			if ( $doc->loadXML( $core_props ) ) {
				$xpath = new DOMXPath( $doc );
				$xpath->registerNamespace( 'dc', 'http://purl.org/dc/elements/1.1/' );

				$title_node = $xpath->query( '//dc:title' )->item( 0 );
				if ( $title_node ) {
					$properties['title'] = $title_node->textContent;
				}

				$creator_node = $xpath->query( '//dc:creator' )->item( 0 );
				if ( $creator_node ) {
					$properties['author'] = $creator_node->textContent;
				}
			}
		}

		return $properties;
	}

	/**
	 * Convert XLSX data to readable text
	 *
	 * @param array $worksheets_data Worksheets data
	 * @return array Content data with statistics
	 */
	private function convert_xlsx_to_text( $worksheets_data ) {
		$content       = "Spreadsheet Data Summary:\n\n";
		$total_rows    = 0;
		$total_columns = 0;
		$has_formulas  = false;
		$data_types    = array();

		foreach ( $worksheets_data as $sheet_name => $sheet_data ) {
			$content .= "=== $sheet_name ===\n\n";

			if ( empty( $sheet_data ) ) {
				$content .= "No data in this worksheet.\n\n";
				continue;
			}

			// Sort rows by row number
			ksort( $sheet_data );

			$sheet_rows  = count( $sheet_data );
			$total_rows += $sheet_rows;

			// Determine column count
			$max_cols = 0;
			foreach ( $sheet_data as $row_data ) {
				$max_cols = max( $max_cols, count( $row_data ) );
			}
			$total_columns = max( $total_columns, $max_cols );

			// Convert first few rows to readable format
			$row_count = 0;
			foreach ( $sheet_data as $row_num => $row_data ) {
				if ( $row_count >= 100 ) { // Limit to prevent huge text blocks
					$remaining_rows = $sheet_rows - $row_count;
					$content       .= "[... $remaining_rows more rows]\n\n";
					break;
				}

				ksort( $row_data ); // Sort by column

				$content   .= "Row $row_num: ";
				$row_values = array();

				foreach ( $row_data as $col_num => $value ) {
					$row_values[] = $value;

					// Analyze data types
					if ( is_numeric( $value ) ) {
						$data_types['numeric'] = ( $data_types['numeric'] ?? 0 ) + 1;
					} elseif ( strtotime( $value ) ) {
						$data_types['date'] = ( $data_types['date'] ?? 0 ) + 1;
					} elseif ( strpos( $value, '=' ) === 0 ) {
						$has_formulas          = true;
						$data_types['formula'] = ( $data_types['formula'] ?? 0 ) + 1;
					} else {
						$data_types['text'] = ( $data_types['text'] ?? 0 ) + 1;
					}
				}

				$content .= implode( ' | ', $row_values ) . "\n";
				++$row_count;
			}

			$content .= "\n";
		}

		return array(
			'content'       => $content,
			'total_rows'    => $total_rows,
			'total_columns' => $total_columns,
			'has_formulas'  => $has_formulas,
			'data_types'    => $data_types,
		);
	}

	/**
	 * Extract text from XLS file (binary format)
	 *
	 * @param string $file_path XLS file path
	 * @return string Extracted text
	 */
	private function extract_xls_text( $file_path ) {
		$binary_content = file_get_contents( $file_path );
		if ( $binary_content === false ) {
			throw new Exception( 'Failed to read XLS file' );
		}

		$text_content = '';

		// Extract strings from XLS binary content
		// XLS files store strings in various formats, this is a simplified extraction
		if ( preg_match_all( '/[\x20-\x7E]{3,}/s', $binary_content, $matches ) ) {
			foreach ( $matches[0] as $match ) {
				// Filter out obvious binary artifacts and system strings
				if ( strlen( $match ) > 2 &&
					! preg_match( '/^[0-9\x00-\x1F]+$/', $match ) &&
					! preg_match( '/^[A-F0-9]+$/', $match ) &&
					! preg_match( '/^(Microsoft|Excel|Workbook|Sheet)/i', $match ) &&
					! preg_match( '/\.(dll|exe|sys)$/i', $match ) ) {

					$text_content .= $match . "\n";
				}
			}
		}

		// Clean up extracted text
		$text_content = preg_replace( '/\s+/', ' ', $text_content );
		$text_content = preg_replace( '/[^\x20-\x7E\n\r\t]/', '', $text_content );

		// Convert to structured format
		$lines              = explode( "\n", $text_content );
		$structured_content = "XLS Data (Basic Extraction):\n\n";

		$line_count = 0;
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( ! empty( $line ) && strlen( $line ) > 3 && $line_count < 200 ) {
				$structured_content .= "- $line\n";
				++$line_count;
			}
		}

		return $structured_content;
	}
}
