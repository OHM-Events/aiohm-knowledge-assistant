<?php
/**
 * Content-Focused PDF Parser for AIOHM Knowledge Assistant
 * Specifically targets document content, ignoring font/metadata streams
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIOHM_Content_Focused_PDF_Parser extends AIOHM_Abstract_Parser {

	protected function init() {
		$this->supported_extensions = array( 'pdf' );
		$this->has_ai_fallback      = false;
		$this->priority             = 40; // Highest priority
	}

	/**
	 * Parse PDF file focusing on actual document content
	 *
	 * @param string $file_path Path to the PDF file
	 * @return array Parsed content data
	 */
	public function parse( $file_path ) {

		if ( ! is_readable( $file_path ) ) {
			throw new Exception( 'PDF file is not readable: ' . esc_html( basename( $file_path ) ) );
		}

		$file_size = filesize( $file_path );

		// Read raw PDF data
		$pdf_data = file_get_contents( $file_path );
		if ( ! $pdf_data ) {
			throw new Exception( 'Could not read PDF file data' );
		}

		$content           = '';
		$extraction_method = 'none';

		// Method 1: Look for actual content streams (not font/metadata)
		$content = $this->extract_content_streams( $pdf_data );
		if ( ! empty( $content ) && strlen( trim( $content ) ) > 20 ) {
			$extraction_method = 'content_streams';
		}

		// Method 2: Look for page content specifically
		if ( empty( $content ) ) {
			$content = $this->extract_page_content( $pdf_data );
			if ( ! empty( $content ) && strlen( trim( $content ) ) > 20 ) {
				$extraction_method = 'page_content';
			}
		}

		// Method 3: Extract from BT/ET blocks (text objects)
		if ( empty( $content ) ) {
			$content = $this->extract_text_objects( $pdf_data );
			if ( ! empty( $content ) && strlen( trim( $content ) ) > 10 ) {
				$extraction_method = 'text_objects';
			}
		}

		// Method 4: Look for readable sentences in raw data
		if ( empty( $content ) ) {
			$content = $this->extract_readable_sentences( $pdf_data );
			if ( ! empty( $content ) && strlen( trim( $content ) ) > 10 ) {
				$extraction_method = 'readable_sentences';
			}
		}

		if ( empty( $content ) ) {
			throw new Exception( 'No readable content found in PDF' );
		}

		// Clean and validate content
		$content = $this->clean_content( $content );

		return array(
			'content'  => $content,
			'metadata' => array(
				'extraction_method' => $extraction_method,
				'file_size'         => $file_size,
				'raw_length'        => strlen( $content ),
			),
		);
	}

	/**
	 * Extract content streams while avoiding font/metadata streams
	 */
	private function extract_content_streams( $pdf_data ) {
		$content = '';

		// Look for stream objects, but filter out obvious font/metadata streams
		if ( preg_match_all( '/(\d+\s+\d+\s+obj.*?stream\s*\n(.*?)\nendstream.*?endobj)/s', $pdf_data, $objects, PREG_SET_ORDER ) ) {
			foreach ( $objects as $object ) {
				$obj_header  = $object[1];
				$stream_data = $object[2];

				// Skip font-related objects
				if ( preg_match( '/\/FontDescriptor|\/FontFile|\/Type\s*\/Font|\/Subtype\s*\/Type1|\/CIDFont/', $obj_header ) ) {
					continue;
				}

				// Skip obvious metadata objects
				if ( preg_match( '/\/Info|\/Metadata|\/Catalog/', $obj_header ) ) {
					continue;
				}

				// Try to decompress and extract text
				$decoded_stream = $this->decompress_stream( $stream_data );
				$stream_text    = $this->extract_meaningful_text( $decoded_stream );

				if ( ! empty( $stream_text ) ) {
					$content .= $stream_text . ' ';
				}
			}
		}

		return trim( $content );
	}

	/**
	 * Extract content from page objects specifically
	 */
	private function extract_page_content( $pdf_data ) {
		$content = '';

		// Look for page objects
		if ( preg_match_all( '/\/Type\s*\/Page.*?stream\s*\n(.*?)\nendstream/s', $pdf_data, $matches ) ) {
			foreach ( $matches[1] as $stream ) {
				$decoded_stream = $this->decompress_stream( $stream );
				$page_text      = $this->extract_meaningful_text( $decoded_stream );

				if ( ! empty( $page_text ) ) {
					$content .= $page_text . ' ';
				}
			}
		}

		return trim( $content );
	}

	/**
	 * Extract text from BT/ET blocks (text objects)
	 */
	private function extract_text_objects( $pdf_data ) {
		$content = '';

		// Decompress all streams first
		$decompressed_data = $pdf_data;
		if ( preg_match_all( '/stream\s*\n(.*?)\nendstream/s', $pdf_data, $streams ) ) {
			foreach ( $streams[1] as $stream ) {
				$decoded = $this->decompress_stream( $stream );
				if ( $decoded !== $stream ) {
					$decompressed_data .= ' ' . $decoded;
				}
			}
		}

		// Look for BT/ET blocks (Begin Text/End Text)
		if ( preg_match_all( '/BT\s+(.*?)\s+ET/s', $decompressed_data, $text_blocks ) ) {
			foreach ( $text_blocks[1] as $block ) {
				$block_text = $this->extract_meaningful_text( $block );
				if ( ! empty( $block_text ) ) {
					$content .= $block_text . ' ';
				}
			}
		}

		return trim( $content );
	}

	/**
	 * Extract readable sentences directly from PDF data
	 */
	private function extract_readable_sentences( $pdf_data ) {
		$content = '';

		// Look for sequences that look like readable sentences
		if ( preg_match_all( '/[A-Z][a-z]+[^A-Z]{10,}[.!?]/', $pdf_data, $sentences ) ) {
			foreach ( $sentences[0] as $sentence ) {
				$sentence = trim( $sentence );
				if ( $this->is_meaningful_content( $sentence ) ) {
					$content .= $sentence . ' ';
				}
			}
		}

		// Also look for longer phrases without punctuation
		if ( preg_match_all( '/[A-Z][a-z]+\s+[a-z]+(?:\s+[a-z]+){3,}/', $pdf_data, $phrases ) ) {
			foreach ( $phrases[0] as $phrase ) {
				$phrase = trim( $phrase );
				if ( $this->is_meaningful_content( $phrase ) && strlen( $phrase ) > 20 ) {
					$content .= $phrase . ' ';
				}
			}
		}

		return trim( $content );
	}

	/**
	 * Extract meaningful text from stream data
	 */
	private function extract_meaningful_text( $stream ) {
		$text = '';

		// Method 1: Text in parentheses
		if ( preg_match_all( '/\(([^)]{3,})\)/', $stream, $matches ) ) {
			foreach ( $matches[1] as $match ) {
				$decoded = $this->decode_pdf_string( $match );
				if ( $this->is_meaningful_content( $decoded ) ) {
					$text .= $decoded . ' ';
				}
			}
		}

		// Method 2: Text after Tj commands
		if ( preg_match_all( '/\(([^)]{3,})\)\s*Tj/', $stream, $matches ) ) {
			foreach ( $matches[1] as $match ) {
				$decoded = $this->decode_pdf_string( $match );
				if ( $this->is_meaningful_content( $decoded ) ) {
					$text .= $decoded . ' ';
				}
			}
		}

		// Method 3: Direct readable text
		if ( preg_match_all( '/[A-Za-z][A-Za-z\s]{5,}/', $stream, $matches ) ) {
			foreach ( $matches[0] as $match ) {
				$match = trim( $match );
				if ( $this->is_meaningful_content( $match ) ) {
					$text .= $match . ' ';
				}
			}
		}

		return trim( $text );
	}

	/**
	 * Check if content is meaningful (not font metadata, etc.)
	 */
	private function is_meaningful_content( $text ) {
		if ( empty( $text ) || strlen( $text ) < 4 ) {
			return false;
		}

		// Filter out common font/metadata patterns
		$exclude_patterns = array(
			'/Monotype|Corporation|Rights Reserved/i',
			'/OpenType|Layout|logic|copyright/i',
			'/MIT License|Permission|hereby granted/i',
			'/Times New Roman|Arial|Helvetica/i',
			'/FontDescriptor|FontFile|CIDFont/i',
			'/Adobe|Microsoft|supplied font/i',
			'/^[A-Z]{2,}\s*$/i', // All caps short words
			'/^\s*[a-z]{1,2}\s*$/i', // Single/double lowercase letters
			'/PQXED|KRXED|KQZX|BYYY/i', // PDF encoding artifacts
		);

		foreach ( $exclude_patterns as $pattern ) {
			if ( preg_match( $pattern, $text ) ) {
				return false;
			}
		}

		// Check for reasonable letter ratio
		$letters = preg_match_all( '/[a-zA-Z]/', $text );
		$total   = strlen( $text );

		if ( $total == 0 ) {
			return false;
		}

		$letter_ratio = $letters / $total;
		return $letter_ratio > 0.3; // Stricter than aggressive parser
	}

	/**
	 * Try to decompress PDF stream data
	 */
	private function decompress_stream( $stream ) {
		// Try gzuncompress (FlateDecode)
		if ( function_exists( 'gzuncompress' ) ) {
			$decoded = @gzuncompress( $stream );
			if ( $decoded !== false ) {
				return $decoded;
			}
		}

		// Try gzinflate
		if ( function_exists( 'gzinflate' ) ) {
			$decoded = @gzinflate( $stream );
			if ( $decoded !== false ) {
				return $decoded;
			}
		}

		// Try with stripped header
		if ( function_exists( 'gzinflate' ) && strlen( $stream ) > 2 ) {
			$stripped = substr( $stream, 2 );
			$decoded  = @gzinflate( $stripped );
			if ( $decoded !== false ) {
				return $decoded;
			}
		}

		return $stream;
	}

	/**
	 * Decode PDF string escape sequences
	 */
	private function decode_pdf_string( $str ) {
		$str = str_replace( array( '\\(', '\\)', '\\\\', '\\n', '\\r', '\\t' ), array( '(', ')', '\\', "\n", "\r", "\t" ), $str );

		// Handle octal escapes
		$str = preg_replace_callback(
			'/\\\\([0-7]{1,3})/',
			function ( $matches ) {
				return chr( octdec( $matches[1] ) );
			},
			$str
		);

		return $str;
	}

	/**
	 * Clean extracted content
	 */
	private function clean_content( $content ) {
		// Remove control characters
		$content = preg_replace( '/[\x00-\x1F\x7F]/', ' ', $content );

		// Remove excessive whitespace
		$content = preg_replace( '/\s+/', ' ', $content );

		// Remove obvious artifacts
		$content = preg_replace( '/[^\w\s\.,!?;:\-\'\"()]{2,}/', ' ', $content );

		return trim( $content );
	}
}
