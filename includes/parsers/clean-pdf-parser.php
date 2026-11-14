<?php
/**
 * Clean PDF Parser for AIOHM Knowledge Assistant
 * Extracts readable text from PDF files
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIOHM_Clean_PDF_Parser extends AIOHM_Abstract_Parser {

	protected function init() {
		$this->supported_extensions = array( 'pdf' );
		$this->has_ai_fallback      = false;
		$this->priority             = 30;
	}

	/**
	 * Parse PDF file with focus on clean text extraction
	 *
	 * @param string $file_path Path to the PDF file
	 * @return array Parsed content data
	 * @throws Exception If PDF cannot be parsed
	 */
	public function parse( $file_path ) {

		if ( ! is_readable( $file_path ) ) {
			throw new Exception( 'PDF file is not readable: ' . esc_html( basename( $file_path ) ) );
		}

		$pdf_info = $this->analyze_pdf( $file_path );

		if ( $pdf_info['is_encrypted'] ) {
			throw new Exception( 'PDF is password-protected. Please remove the password and try again.' );
		}

		if ( $pdf_info['is_scanned'] ) {
			throw new Exception( 'This appears to be a scanned PDF (image-only). Please use OCR software to convert it to text-searchable PDF first.' );
		}

		$content           = '';
		$extraction_method = 'none';

		try {
			$content = $this->extract_simple_text( $file_path );
			if ( strlen( $content ) > 50 ) {
				$extraction_method = 'simple_text';
			} else {
				$content = '';
			}
		} catch ( Exception $e ) {
		}

		// Method 2: Stream-based extraction (if simple fails)
		if ( empty( $content ) ) {
			try {
				$content = $this->extract_from_streams( $file_path );
				if ( strlen( $content ) > 50 ) { // More lenient check
					$extraction_method = 'stream_based';
				} else {
					$content = '';
				}
			} catch ( Exception $e ) {
			}
		}

		// Method 3: Enhanced text stream parsing (better for modern PDFs)
		if ( empty( $content ) ) {
			try {
				$content = $this->extract_enhanced_text_streams( $file_path );
				if ( strlen( $content ) > 50 ) { // More lenient check
					$extraction_method = 'enhanced_streams';
				} else {
					$content = '';
				}
			} catch ( Exception $e ) {
			}
		}

		// Method 4: Binary search (last resort)
		if ( empty( $content ) ) {
			try {
				$content = $this->extract_from_binary( $file_path );
				if ( strlen( $content ) > 50 ) { // More lenient check
					$extraction_method = 'binary_search';
				} else {
					$content = '';
				}
			} catch ( Exception $e ) {
			}
		}

		// Method 5: Basic readable text extraction (second to last resort)
		if ( empty( $content ) ) {
			try {
				$content = $this->extract_basic_readable_text( $file_path );
				if ( ! empty( $content ) && strlen( $content ) > 10 ) {
					$extraction_method = 'basic_readable';
				} else {
					$content = '';
				}
			} catch ( Exception $e ) {
			}
		}

		// Method 6: Ultra-aggressive extraction (last resort)
		if ( empty( $content ) ) {
			try {
				$content = $this->extract_ultra_aggressive_text( $file_path );
				if ( ! empty( $content ) && strlen( $content ) > 20 ) {
					$extraction_method = 'ultra_aggressive';
				} else {
					$content = '';
				}
			} catch ( Exception $e ) {
			}
		}

		// Method 7: No-validation brute force (absolute last resort)
		if ( empty( $content ) ) {
			try {
				$content = $this->extract_no_validation_brute_force( $file_path );
				if ( ! empty( $content ) ) {
					$extraction_method = 'brute_force';
				}
			} catch ( Exception $e ) {
			}
		}

		if ( empty( $content ) ) {
			throw new Exception( 'Could not extract readable text from PDF. This may be a scanned document, encrypted PDF, or use complex formatting. Please try:\n\n1. Converting to a text-searchable PDF using OCR\n2. Copying and pasting the text manually\n3. Saving as a different format (Word, TXT)' );
		}

		// Clean and validate the extracted content
		$cleaned_content = $this->clean_extracted_text( $content );

		if ( empty( $cleaned_content ) ) {
			throw new Exception( 'No readable text could be extracted from PDF.' );
		}

		// Skip all validation - just accept whatever we extracted

		// Calculate quality score for logging only - don't reject anything
		$quality_score = $this->calculate_quality_score( $cleaned_content );

		// Extract title
		$title = $this->extract_title( $file_path, $cleaned_content );

		// Generate metadata
		$metadata = $this->generate_metadata(
			$file_path,
			'pdf',
			array(
				'extraction_method' => $extraction_method,
				'word_count'        => str_word_count( $cleaned_content ),
				'char_count'        => strlen( $cleaned_content ),
				'quality_score'     => $this->calculate_quality_score( $cleaned_content ),
			)
		);

		return array(
			'content'  => $cleaned_content,
			'title'    => $title,
			'metadata' => $metadata,
		);
	}

	/**
	 * Analyze PDF structure to detect common issues
	 */
	private function analyze_pdf( $file_path ) {
		$info = array(
			'is_encrypted' => false,
			'is_scanned'   => false,
			'has_text'     => false,
		);

		// Read first 8KB for analysis
		$header = file_get_contents( $file_path, false, null, 0, 8192 );

		// Check for encryption
		if ( strpos( $header, '/Encrypt' ) !== false ) {
			$info['is_encrypted'] = true;
		}

		// Check for text content vs images
		$text_objects  = substr_count( $header, 'BT' );
		$image_objects = substr_count( $header, '/XObject' );

		if ( $text_objects > 0 ) {
			$info['has_text'] = true;
		}

		if ( $image_objects > $text_objects * 3 && $text_objects < 2 ) {
			$info['is_scanned'] = true;
		}

		return $info;
	}

	/**
	 * Simple text extraction - most reliable method
	 */
	private function extract_simple_text( $file_path ) {
		$content = file_get_contents( $file_path );
		if ( ! $content ) {
			throw new Exception( 'Cannot read PDF file' );
		}

		$text = '';

		// Look for text in BT/ET blocks
		if ( preg_match_all( '/BT\s+(.*?)\s+ET/s', $content, $matches ) ) {
			foreach ( $matches[1] as $block ) {
				// Extract parenthesized text
				if ( preg_match_all( '/\(([^)]+)\)\s*Tj/', $block, $text_matches ) ) {
					foreach ( $text_matches[1] as $text_content ) {
						$decoded = $this->decode_pdf_text( $text_content );
						if ( $this->is_valid_text_segment( $decoded ) ) {
							$text .= $decoded . ' ';
						}
					}
				}
			}
		}

		return trim( $text );
	}

	/**
	 * Extract text from PDF streams
	 */
	private function extract_from_streams( $file_path ) {
		$content = file_get_contents( $file_path );
		if ( ! $content ) {
			throw new Exception( 'Cannot read PDF file' );
		}

		$text = '';

		// Look for stream objects
		if ( preg_match_all( '/stream\s+(.*?)\s+endstream/s', $content, $matches ) ) {
			foreach ( $matches[1] as $stream ) {
				// Try to decompress if needed
				$decompressed = @gzinflate( trim( $stream ) );
				if ( $decompressed !== false ) {
					$stream = $decompressed;
				}

				// Extract text from stream
				if ( preg_match_all( '/\(([^)]+)\)/', $stream, $text_matches ) ) {
					foreach ( $text_matches[1] as $text_content ) {
						$decoded = $this->decode_pdf_text( $text_content );
						if ( $this->is_valid_text_segment( $decoded ) ) {
							$text .= $decoded . ' ';
						}
					}
				}
			}
		}

		return trim( $text );
	}

	/**
	 * Extract readable text from binary data (last resort)
	 */
	private function extract_from_binary( $file_path ) {
		$content = file_get_contents( $file_path );
		if ( ! $content ) {
			throw new Exception( 'Cannot read PDF file' );
		}

		$text         = '';
		$current_text = '';

		// Scan for readable ASCII sequences
		for ( $i = 0; $i < strlen( $content ); $i++ ) {
			$char  = $content[ $i ];
			$ascii = ord( $char );

			// Collect printable characters
			if ( ( $ascii >= 32 && $ascii <= 126 ) || in_array( $ascii, array( 9, 10, 13 ) ) ) {
				$current_text .= $char;
			} else {
				// End of text sequence
				if ( strlen( $current_text ) > 10 ) {
					$cleaned = trim( $current_text );
					if ( $this->is_valid_text_segment( $cleaned ) ) {
						$text .= $cleaned . ' ';
					}
				}
				$current_text = '';
			}
		}

		// Add final text
		if ( strlen( $current_text ) > 10 ) {
			$cleaned = trim( $current_text );
			if ( $this->is_valid_text_segment( $cleaned ) ) {
				$text .= $cleaned . ' ';
			}
		}

		return trim( $text );
	}

	/**
	 * Enhanced text stream extraction for modern PDFs
	 */
	private function extract_enhanced_text_streams( $file_path ) {
		$data = file_get_contents( $file_path );
		if ( ! $data ) {
			throw new Exception( 'Could not read PDF file' );
		}

		$text = '';

		// Look for PDF page content objects first (like Smalot parser does)
		if ( preg_match_all( '/obj\s*<<.*?\/Contents\s+(\d+)\s+(\d+)\s+R.*?>>.*?endobj/s', $data, $page_matches ) ) {
			foreach ( $page_matches[1] as $i => $content_obj_id ) {
				$content_gen = $page_matches[2][ $i ];
				// Find the referenced content object
				if ( preg_match( '/' . $content_obj_id . '\s+' . $content_gen . '\s+obj\s*(.*?)\s*endobj/s', $data, $content_match ) ) {
					$content_data = $content_match[1];
					// Extract text from this content stream
					$stream_text = $this->extract_text_from_content_stream( $content_data );
					if ( ! empty( $stream_text ) ) {
						$text .= $stream_text . ' ';
					}
				}
			}
		}

		// Fallback: Look for text objects with better patterns
		$patterns = array(
			// Standard text streams
			'/BT\s*(.*?)\s*ET/s',
			// Text with positioning
			'/Tj\s*\[(.*?)\]/s',
			// Simple text strings
			'/\(([^)]*)\)\s*Tj/s',
			// Array text strings
			'/\[([^\]]*)\]\s*TJ/s',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match_all( $pattern, $data, $matches ) ) {
				foreach ( $matches[1] as $match ) {
					// Clean and decode the text
					$decoded = $this->decode_pdf_text_content( $match );
					if ( ! empty( $decoded ) && strlen( $decoded ) > 3 ) {
						$text .= $decoded . ' ';
					}
				}
			}
		}

		// Also try to extract from content streams more intelligently
		if ( preg_match_all( '/stream\s*(.*?)\s*endstream/s', $data, $stream_matches ) ) {
			foreach ( $stream_matches[1] as $stream ) {
				// Skip binary streams and font definitions
				if ( strpos( $stream, "\x00" ) !== false ) {
					continue;
				}
				if ( strpos( $stream, '/FontDescriptor' ) !== false ) {
					continue;
				}
				if ( strpos( $stream, '/FontFile' ) !== false ) {
					continue;
				}
				if ( strpos( $stream, 'Identity' ) !== false ) {
					continue;
				}

				// Only process streams that look like text content
				$text_density = strlen( preg_replace( '/[^a-zA-Z\s]/', '', $stream ) ) / max( 1, strlen( $stream ) );
				if ( $text_density < 0.1 ) {
					continue; // Skip streams with very low text density
				}

				// Look for readable text patterns
				if ( preg_match_all( '/\(([^)]{5,})\)/', $stream, $text_matches ) ) {
					foreach ( $text_matches[1] as $text_match ) {
						$decoded = $this->decode_pdf_text_content( $text_match );
						if ( ! empty( $decoded ) && $this->is_valid_text_segment( $decoded ) ) {
							$text .= $decoded . ' ';
						}
					}
				}
			}
		}

		return trim( $text );
	}

	/**
	 * Decode PDF text content
	 */
	private function decode_pdf_text_content( $text ) {
		// Handle common PDF text encodings
		$text = str_replace(
			array( '\\(', '\\)', '\\\\', '\\n', '\\r', '\\t' ),
			array( '(', ')', '\\', "\n", "\r", "\t" ),
			$text
		);

		// Remove obvious font/formatting commands
		$text = preg_replace( '/\/[A-Z][a-zA-Z0-9]*\s+[0-9.]+\s+Tf/', '', $text );
		$text = preg_replace( '/[0-9.]+\s+[0-9.]+\s+Td/', '', $text );
		$text = preg_replace( '/[0-9.]+\s+TL/', '', $text );

		// Clean up whitespace
		$text = preg_replace( '/\s+/', ' ', $text );
		$text = trim( $text );

		// Filter out obvious non-text content
		if ( preg_match( '/^[0-9.\s\-+]+$/', $text ) ) {
			return '';
		}
		if ( strlen( $text ) < 3 ) {
			return '';
		}
		if ( preg_match( '/[^\x20-\x7E\s]/', $text ) && strlen( $text ) < 10 ) {
			return '';
		}

		return $text;
	}

	/**
	 * Extract text from PDF content stream (improved method)
	 */
	private function extract_text_from_content_stream( $content_data ) {
		$text = '';

		// Handle compressed streams (FlateDecode)
		if ( strpos( $content_data, '/FlateDecode' ) !== false ) {
			// Look for the actual stream data after "stream"
			if ( preg_match( '/stream\s*(.*?)\s*endstream/s', $content_data, $stream_match ) ) {
				$compressed_data = $stream_match[1];
				// Try to decompress if possible
				$decompressed = @gzuncompress( $compressed_data );
				if ( $decompressed !== false ) {
					$content_data = $decompressed;
				}
			}
		}

		// Extract text using BT...ET blocks (Begin Text...End Text)
		if ( preg_match_all( '/BT\s*(.*?)\s*ET/s', $content_data, $text_blocks ) ) {
			foreach ( $text_blocks[1] as $block ) {
				// Look for text strings in parentheses
				if ( preg_match_all( '/\(([^)]*)\)\s*Tj/i', $block, $text_matches ) ) {
					foreach ( $text_matches[1] as $text_match ) {
						$decoded = $this->decode_pdf_text_content( $text_match );
						if ( ! empty( $decoded ) ) {
							$text .= $decoded . ' ';
						}
					}
				}

				// Look for text arrays with TJ
				if ( preg_match_all( '/\[([^\]]*)\]\s*TJ/i', $block, $array_matches ) ) {
					foreach ( $array_matches[1] as $array_match ) {
						// Extract strings from the array
						if ( preg_match_all( '/\(([^)]*)\)/', $array_match, $string_matches ) ) {
							foreach ( $string_matches[1] as $string_match ) {
								$decoded = $this->decode_pdf_text_content( $string_match );
								if ( ! empty( $decoded ) ) {
									$text .= $decoded . ' ';
								}
							}
						}
					}
				}
			}
		}

		return trim( $text );
	}

	/**
	 * Decode PDF text strings
	 */
	private function decode_pdf_text( $text ) {
		// Handle common PDF escapes
		$text = str_replace(
			array( '\n', '\r', '\t', '\\', '\(', '\)' ),
			array( "\n", "\r", "\t", '\\', '(', ')' ),
			$text
		);

		// Handle octal escapes
		$text = preg_replace_callback(
			'/\\\\(\d{3})/',
			function ( $matches ) {
				return chr( octdec( $matches[1] ) );
			},
			$text
		);

		// Ensure UTF-8 encoding
		if ( ! empty( $text ) && ! mb_check_encoding( $text, 'UTF-8' ) ) {
			$text = mb_convert_encoding( $text, 'UTF-8', 'auto' );
		}

		return $text;
	}

	/**
	 * Check if a text segment is valid content (not metadata/junk)
	 */
	private function is_valid_text_segment( $text ) {
		$text = trim( $text ?? '' );

		// Must have minimum length
		if ( strlen( $text ) < 2 ) {
			return false;
		}

		// Must contain letters
		if ( ! preg_match( '/[a-zA-ZÀ-ÿ]/', $text ) ) {
			return false;
		}

		// Skip obvious PDF metadata
		$junk_patterns = array(
			'/^%PDF-/',
			'/^\d+\s+\d+\s+obj/',
			'/^endobj$/',
			'/^stream$/',
			'/^endstream$/',
			'/^xref$/',
			'/^startxref$/',
			'/^trailer$/',
			'/^Adobe/',
			'/^Identity/',
			'/^D:\d{8}/',
			'/^[A-Z]{4,}$/',
			'/^[a-f0-9]{8,}$/i',
			'/^\d+(\.\d+)*\s+\d+(\.\d+)*$/',
			'/FontDescriptor/',
			'/FontFile/',
			'/FontName/',
			'/Type\/Font/',
			'/Subtype\//',
			'/CIDSystemInfo/',
			'/Ascent\s+\d+/',
			'/Descent\s+\-\d+/',
			'/CapHeight\s+\d+/',
			'/StemV\s+\d+/',
			'/ItalicAngle\s+\-?\d+/',
			'/FontBBox\s+\[/',
			'/Flags\s+\d+/',
			'/^[üÜÿ´ý\*\s]+$/',
			'/^endstream\s+endobj/',
			'/^\s*\d+\s+0\s+obj\s*$/',
		);

		foreach ( $junk_patterns as $pattern ) {
			if ( preg_match( $pattern, $text ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check if extracted text is clean and readable
	 */
	private function is_clean_text( $text ) {
		if ( empty( trim( $text ) ) ) {
			return false;
		}

		// Must have reasonable length
		if ( strlen( $text ) < 10 ) { // More lenient: was 20
			return false;
		}

		// Must have reasonable word count
		if ( str_word_count( $text ) < 3 ) { // More lenient: was 5
			return false;
		}

		// Check character distribution
		$total_chars  = strlen( $text );
		$letter_count = preg_match_all( '/[a-zA-ZÀ-ÿ]/', $text );
		$letter_ratio = $letter_count / $total_chars;

		// Very lenient letter ratio check: was 30%, now 15%
		if ( $letter_ratio < 0.15 ) {
			return false;
		}

		// Check for excessive special characters (indicates corruption)
		$special_count = preg_match_all( '/[^a-zA-Z0-9\s\pP\pS]/u', $text );
		$special_ratio = $special_count / $total_chars;

		// More lenient special character check: was 10%, now 20%
		if ( $special_ratio > 0.2 ) {
			return false;
		}

		// Check for gibberish patterns
		$gibberish_patterns = array(
			'/[\x80-\xFF]{5,}/', // Extended ASCII sequences
			'/[^\x20-\x7E]{3,}/', // Non-printable character sequences
			'/[ÀÁÂÃÄÅÆÇÈÉÊËÌÍÎÏÐÑÒÓÔÕÖØÙÚÛÜÝÞßàáâãäåæçèéêëìíîïðñòóôõöøùúûüýþÿ]{5,}/', // Extended Latin
		);

		foreach ( $gibberish_patterns as $pattern ) {
			if ( preg_match( $pattern, $text ) ) {
				return false;
			}
		}

		// Check for readable words
		$readable_words = preg_match_all( '/\b[a-zA-Z]{2,}\b/', $text );
		$word_density   = $readable_words / max( 1, str_word_count( $text ) );

		// Must have at least 70% readable words
		if ( $word_density < 0.7 ) {
			return false;
		}

		return true;
	}

	/**
	 * Clean extracted text
	 */
	private function clean_extracted_text( $text ) {
		// Remove control characters and extended ASCII
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\xFF]/', '', $text );

		// Remove obvious gibberish patterns
		$text = preg_replace( '/[^\x20-\x7E\s]+/', ' ', $text );

		// Normalize whitespace
		$text = preg_replace( '/\s+/', ' ', $text );
		$text = preg_replace( '/\n\s*\n\s*\n+/', "\n\n", $text );

		// Remove obvious junk lines
		$lines       = explode( "\n", $text );
		$clean_lines = array();

		foreach ( $lines as $line ) {
			$line = trim( $line );

			// Skip lines that are too short or too long
			if ( strlen( $line ) < 3 || strlen( $line ) > 1000 ) {
				continue;
			}

			// Skip lines with too many special characters
			$special_ratio = $this->get_special_char_ratio( $line );
			if ( $special_ratio > 0.3 ) {
				continue;
			}

			// Skip lines with no readable words
			if ( ! preg_match( '/\b[a-zA-Z]{2,}\b/', $line ) ) {
				continue;
			}

			if ( $this->is_valid_text_segment( $line ) ) {
				$clean_lines[] = $line;
			}
		}

		return trim( implode( "\n", $clean_lines ) );
	}

	/**
	 * Calculate ratio of special characters in text
	 */
	private function get_special_char_ratio( $text ) {
		$total_chars = strlen( $text );
		if ( $total_chars === 0 ) {
			return 0;
		}

		$special_chars = preg_match_all( '/[^a-zA-Z0-9\s.,;:!?\-()\[\]{}"\'\/\\@#$%^&*+=<>|~`]/', $text );
		return $special_chars / $total_chars;
	}

	/**
	 * Extract title from PDF
	 */
	private function extract_title( $file_path, $content ) {
		// Try to get title from PDF metadata
		$raw_content = file_get_contents( $file_path, false, null, 0, 4096 );
		if ( preg_match( '/\/Title\s*\(([^)]+)\)/', $raw_content, $matches ) ) {
			$title = $this->decode_pdf_text( $matches[1] );
			if ( ! empty( trim( $title ) ) && $this->is_valid_text_segment( $title ) ) {
				return trim( $title );
			}
		}

		// Try first meaningful line from content
		$lines = explode( "\n", $content );
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( strlen( $line ) > 5 && strlen( $line ) < 200 && str_word_count( $line ) >= 2 ) {
				return $line;
			}
		}

		// Fallback to filename
		return pathinfo( $file_path, PATHINFO_FILENAME );
	}

	/**
	 * Calculate quality score for extracted text
	 */
	private function calculate_quality_score( $text ) {
		$score  = 0;
		$length = strlen( $text );

		if ( $length === 0 ) {
			return 0;
		}

		// Length bonus (up to 20 points)
		$score += min( $length / 200, 20 );

		// Word count bonus (up to 25 points)
		$word_count = str_word_count( $text );
		$score     += min( $word_count / 20, 25 );

		// Character distribution bonus (up to 30 points)
		$letter_count = preg_match_all( '/[a-zA-Z]/', $text );
		$letter_ratio = $letter_count / $length;
		$score       += $letter_ratio * 30;

		// Penalty for gibberish characters (up to -30 points)
		$gibberish_count = preg_match_all( '/[\x80-\xFF]/', $text );
		$gibberish_ratio = $gibberish_count / $length;
		$score          -= $gibberish_ratio * 30;

		// Penalty for repeated patterns (up to -20 points)
		if ( preg_match( '/(.{3,})\1{3,}/', $text ) ) {
			$score -= 20;
		}

		// Bonus for common English words (up to 15 points)
		$common_words = array( 'the', 'and', 'of', 'to', 'in', 'is', 'you', 'that', 'it', 'for', 'with', 'as', 'have', 'be', 'at' );
		$text_lower   = strtolower( $text );
		$common_count = 0;
		foreach ( $common_words as $word ) {
			if ( preg_match( '/\b' . preg_quote( $word ) . '\b/', $text_lower ) ) {
				++$common_count;
			}
		}
		$score += min( $common_count, 15 );

		// Bonus for sentence structure (up to 10 points)
		$sentence_count = preg_match_all( '/[.!?]+/', $text );
		if ( $sentence_count > 0 ) {
			$score += min( $sentence_count / 2, 10 );
		}

		return max( 0, min( 100, $score ) );
	}

	/**
	 * Very basic text extraction - last resort method
	 * Just looks for any readable ASCII text in the PDF
	 */
	private function extract_basic_readable_text( $file_path ) {

		$data = file_get_contents( $file_path );
		if ( ! $data ) {
			throw new Exception( 'Cannot read PDF file' );
		}

		$text            = '';
		$min_word_length = 3;
		$max_gap         = 5; // Maximum non-text characters between words
		$current_word    = '';
		$gap_count       = 0;

		// Scan through file looking for readable text
		for ( $i = 0; $i < strlen( $data ); $i++ ) {
			$char  = $data[ $i ];
			$ascii = ord( $char );

			// Check if character is readable (letters, numbers, basic punctuation)
			if ( ( $ascii >= 65 && $ascii <= 90 ) || // A-Z
				( $ascii >= 97 && $ascii <= 122 ) || // a-z
				( $ascii >= 48 && $ascii <= 57 ) || // 0-9
				in_array( $ascii, array( 32, 33, 44, 46, 58, 59, 63, 39, 34 ) ) ) { // space, !, comma, period, :, ;, ?, ', "

				$current_word .= $char;
				$gap_count     = 0;

			} else {
				++$gap_count;

				// If we hit a gap, check if we have a good word
				if ( $gap_count >= $max_gap ) {
					if ( strlen( $current_word ) >= $min_word_length ) {
						// Basic validation - must contain at least one letter
						if ( preg_match( '/[a-zA-Z]/', $current_word ) ) {
							$text .= trim( $current_word ) . ' ';
						}
					}
					$current_word = '';
					$gap_count    = 0;
				}
			}
		}

		// Add final word if valid
		if ( strlen( $current_word ) >= $min_word_length && preg_match( '/[a-zA-Z]/', $current_word ) ) {
			$text .= trim( $current_word ) . ' ';
		}

		// Clean up the extracted text
		$text = trim( $text );
		$text = preg_replace( '/\s+/', ' ', $text ); // Normalize spaces

		// Basic filtering of obvious junk
		$words       = explode( ' ', $text );
		$clean_words = array();

		foreach ( $words as $word ) {
			$word = trim( $word );
			// Skip words that are too short or too long
			if ( strlen( $word ) < 2 || strlen( $word ) > 50 ) {
				continue;
			}
			// Skip words that are mostly numbers or punctuation
			if ( preg_match( '/^[0-9.,;:!?"\'-]+$/', $word ) ) {
				continue;
			}
			// Must contain at least one letter
			if ( ! preg_match( '/[a-zA-Z]/', $word ) ) {
				continue;
			}

			$clean_words[] = $word;
		}

		$result = implode( ' ', $clean_words );

		return $result;
	}

	/**
	 * Ultra-aggressive text extraction - scans entire PDF byte by byte
	 * This method tries to extract any readable text from any PDF structure
	 */
	private function extract_ultra_aggressive_text( $file_path ) {

		$data = file_get_contents( $file_path );
		if ( ! $data ) {
			throw new Exception( 'Cannot read PDF file' );
		}

		$text_segments        = array();
		$current_segment      = '';
		$in_readable_section  = false;
		$consecutive_readable = 0;

		// Scan every byte looking for readable text patterns
		for ( $i = 0; $i < strlen( $data ); $i++ ) {
			$char  = $data[ $i ];
			$ascii = ord( $char );

			// Check if character is potentially readable
			$is_readable = (
				( $ascii >= 32 && $ascii <= 126 ) || // Standard ASCII printable
				$ascii == 9 || $ascii == 10 || $ascii == 13 // Tab, LF, CR
			);

			if ( $is_readable ) {
				$current_segment .= $char;
				++$consecutive_readable;

				// If we have enough consecutive readable characters, we're in a text section
				if ( $consecutive_readable >= 3 ) {
					$in_readable_section = true;
				}
			} else {
				// Hit a non-readable character
				$consecutive_readable = 0;

				if ( $in_readable_section && strlen( $current_segment ) >= 5 ) {
					// We have a potentially valid text segment
					$cleaned_segment = trim( $current_segment );
					if ( $this->is_potentially_meaningful_text( $cleaned_segment ) ) {
						$text_segments[] = $cleaned_segment;
					}
				}

				$current_segment     = '';
				$in_readable_section = false;
			}
		}

		// Add final segment if valid
		if ( $in_readable_section && strlen( $current_segment ) >= 5 ) {
			$cleaned_segment = trim( $current_segment );
			if ( $this->is_potentially_meaningful_text( $cleaned_segment ) ) {
				$text_segments[] = $cleaned_segment;
			}
		}

		// Combine and clean segments
		$combined_text = implode( ' ', $text_segments );

		// Post-process the extracted text
		$combined_text = $this->post_process_extracted_text( $combined_text );

		return $combined_text;
	}

	/**
	 * Check if text segment is potentially meaningful
	 */
	private function is_potentially_meaningful_text( $text ) {
		$text = trim( $text );

		// Must have minimum length
		if ( strlen( $text ) < 5 ) {
			return false;
		}

		// Must contain some letters
		if ( ! preg_match( '/[a-zA-ZÀ-ÿ]/', $text ) ) {
			return false;
		}

		// Must not be all numbers or symbols
		if ( preg_match( '/^[0-9\W\s]+$/', $text ) ) {
			return false;
		}

		// Skip obvious PDF junk
		$junk_patterns = array(
			'/^obj\s*$/',
			'/^endobj\s*$/',
			'/^stream\s*$/',
			'/^endstream\s*$/',
			'/^xref\s*$/',
			'/^trailer\s*$/',
			'/^startxref\s*$/',
			'/^\d+\s+\d+\s+obj/',
			'/^%PDF/',
			'/^%%EOF/',
			'/^\/[A-Z][a-zA-Z0-9]*/',  // PDF object names
			'/^[0-9\s\.\-\+]+$/',       // Just numbers and punctuation
			'/^[A-Z]{4,}$/',            // All caps strings (likely metadata)
			'/^Identity[A-Z]/',         // Identity strings
			'/^Adobe/',                 // Adobe identifiers
			'/FontDescriptor/',         // Font descriptors
			'/FontFile/',               // Font files
			'/CIDSystemInfo/',          // Font system info
		);

		foreach ( $junk_patterns as $pattern ) {
			if ( preg_match( $pattern, $text ) ) {
				return false;
			}
		}

		// Calculate text quality score
		$letter_count = preg_match_all( '/[a-zA-ZÀ-ÿ]/', $text );
		$letter_ratio = $letter_count / strlen( $text );

		// Must have at least 30% letters
		if ( $letter_ratio < 0.3 ) {
			return false;
		}

		// Check for word-like patterns
		$word_count = preg_match_all( '/\b[a-zA-ZÀ-ÿ]{2,}\b/', $text );
		if ( $word_count < 1 ) {
			return false;
		}

		return true;
	}

	/**
	 * Post-process extracted text to improve readability
	 */
	private function post_process_extracted_text( $text ) {
		// Remove control characters
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text );

		// Normalize whitespace
		$text = preg_replace( '/\s+/', ' ', $text );

		// Remove isolated single characters (except meaningful ones)
		$text = preg_replace( '/\b[b-df-hj-np-tv-zB-DF-HJ-NP-TV-Z]\b/', '', $text );

		// Remove repetitive patterns
		$text = preg_replace( '/(.{1,10})\1{3,}/', '$1', $text );

		// Clean up punctuation
		$text = preg_replace( '/\s+([,.;:!?])/', '$1', $text );
		$text = preg_replace( '/([,.;:!?])\s*\1+/', '$1', $text );

		// Remove lines that are mostly numbers or symbols
		$lines       = explode( "\n", $text );
		$clean_lines = array();

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( strlen( $line ) < 3 ) {
				continue;
			}

			$letter_count = preg_match_all( '/[a-zA-ZÀ-ÿ]/', $line );
			$letter_ratio = $letter_count / strlen( $line );

			if ( $letter_ratio >= 0.4 ) { // At least 40% letters
				$clean_lines[] = $line;
			}
		}

		$result = implode( ' ', $clean_lines );

		// Final cleanup
		$result = trim( $result );
		$result = preg_replace( '/\s+/', ' ', $result );

		return $result;
	}

	/**
	 * Brute force extraction with NO validation - just grab everything
	 */
	private function extract_no_validation_brute_force( $file_path ) {

		$data = file_get_contents( $file_path );
		if ( ! $data ) {
			throw new Exception( 'Cannot read PDF file' );
		}

		$all_text = '';

		// Method 1: Just grab all parenthesized content
		if ( preg_match_all( '/\(([^)]{3,})\)/', $data, $matches ) ) {
			foreach ( $matches[1] as $match ) {
				$decoded = str_replace( array( '\\(', '\\)', '\\\\', '\\n', '\\r' ), array( '(', ')', '\\', "\n", "\r" ), $match );
				if ( preg_match( '/[a-zA-Z]/', $decoded ) ) {
					$all_text .= $decoded . ' ';
				}
			}
		}

		// Method 2: Look for BT...ET blocks and grab everything
		if ( preg_match_all( '/BT\s+(.*?)\s+ET/s', $data, $bt_matches ) ) {
			foreach ( $bt_matches[1] as $bt_block ) {
				if ( preg_match_all( '/\(([^)]+)\)/', $bt_block, $text_matches ) ) {
					foreach ( $text_matches[1] as $text_match ) {
						$decoded = str_replace( array( '\\(', '\\)', '\\\\', '\\n', '\\r' ), array( '(', ')', '\\', "\n", "\r" ), $text_match );
						if ( preg_match( '/[a-zA-Z]/', $decoded ) ) {
							$all_text .= $decoded . ' ';
						}
					}
				}
			}
		}

		// Method 3: Scan for any readable ASCII sequences (very permissive)
		$readable_text = '';
		for ( $i = 0; $i < strlen( $data ); $i++ ) {
			$char  = $data[ $i ];
			$ascii = ord( $char );

			if ( ( $ascii >= 32 && $ascii <= 126 ) || in_array( $ascii, array( 9, 10, 13 ) ) ) {
				$readable_text .= $char;
			} else {
				if ( strlen( $readable_text ) > 10 && preg_match( '/[a-zA-Z]{3,}/', $readable_text ) ) {
					$all_text .= trim( $readable_text ) . ' ';
				}
				$readable_text = '';
			}
		}

		// Add final chunk
		if ( strlen( $readable_text ) > 10 && preg_match( '/[a-zA-Z]{3,}/', $readable_text ) ) {
			$all_text .= trim( $readable_text ) . ' ';
		}

		// Basic cleanup only - no validation
		$all_text = preg_replace( '/\s+/', ' ', $all_text );
		$all_text = trim( $all_text );

		return $all_text;
	}
}
