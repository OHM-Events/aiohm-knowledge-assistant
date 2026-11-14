<?php
/**
 * Aggressive PDF Parser for AIOHM Knowledge Assistant
 * Uses multiple extraction methods to extract text from PDFs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIOHM_Aggressive_PDF_Parser extends AIOHM_Abstract_Parser {

	protected function init() {
		$this->supported_extensions = array( 'pdf' );
		$this->has_ai_fallback      = false;
		$this->priority             = 35; // Higher priority than clean parser
	}

	/**
	 * Parse PDF file with multiple extraction methods
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

		// Method 1: Basic text stream extraction
		$content = $this->extract_text_streams( $pdf_data );
		if ( ! empty( $content ) && strlen( trim( $content ) ) > 10 ) {
			$extraction_method = 'text_streams';
		}

		// Method 2: Regex-based text extraction
		if ( empty( $content ) ) {
			$content = $this->extract_with_regex( $pdf_data );
			if ( ! empty( $content ) && strlen( trim( $content ) ) > 10 ) {
				$extraction_method = 'regex';
			}
		}

		// Method 3: Simple string extraction
		if ( empty( $content ) ) {
			$content = $this->extract_simple_strings( $pdf_data );
			if ( ! empty( $content ) && strlen( trim( $content ) ) > 10 ) {
				$extraction_method = 'simple_strings';
			}
		}

		// Method 4: Brute force any readable text
		if ( empty( $content ) ) {
			$content = $this->extract_any_text( $pdf_data );
			if ( ! empty( $content ) && strlen( trim( $content ) ) > 5 ) {
				$extraction_method = 'brute_force';
			}
		}

		if ( empty( $content ) ) {
			throw new Exception( 'No readable text found in PDF using any extraction method' );
		}

		// Basic cleanup - remove excessive whitespace but keep structure
		$content = $this->clean_extracted_text( $content );

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
	 * Extract text from PDF text streams
	 */
	private function extract_text_streams( $pdf_data ) {
		$text = '';

		// Look for stream objects with text
		if ( preg_match_all( '/stream\s*\n(.*?)\nendstream/s', $pdf_data, $streams ) ) {
			foreach ( $streams[1] as $stream ) {
				// Try different decompression methods
				$decoded_stream = $this->decompress_stream( $stream );

				// Extract text from stream
				$stream_text = $this->extract_text_from_stream( $decoded_stream );
				if ( ! empty( $stream_text ) ) {
					$text .= $stream_text . ' ';
				}
			}
		}

		return trim( $text );
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

		// Try gzinflate (alternative FlateDecode)
		if ( function_exists( 'gzinflate' ) ) {
			$decoded = @gzinflate( $stream );
			if ( $decoded !== false ) {
				return $decoded;
			}
		}

		// Try with different headers
		if ( function_exists( 'gzinflate' ) ) {
			// Remove possible zlib header
			$stripped = substr( $stream, 2 );
			$decoded  = @gzinflate( $stripped );
			if ( $decoded !== false ) {
				return $decoded;
			}
		}

		// Return original if decompression fails
		return $stream;
	}

	/**
	 * Extract text from a single stream
	 */
	private function extract_text_from_stream( $stream ) {
		$text = '';

		// Method 1: Look for text in parentheses (most common)
		if ( preg_match_all( '/\(([^)]+)\)/', $stream, $matches ) ) {
			foreach ( $matches[1] as $match ) {
				$decoded = $this->decode_pdf_string( $match );
				if ( $this->is_readable_text( $decoded ) ) {
					$text .= $decoded . ' ';
				}
			}
		}

		// Method 2: Look for text after Tj commands
		if ( preg_match_all( '/\(([^)]+)\)\s*Tj/', $stream, $matches ) ) {
			foreach ( $matches[1] as $match ) {
				$decoded = $this->decode_pdf_string( $match );
				if ( $this->is_readable_text( $decoded ) ) {
					$text .= $decoded . ' ';
				}
			}
		}

		// Method 3: Look for text in square brackets (array format)
		if ( preg_match_all( '/\[([^\]]+)\]/', $stream, $matches ) ) {
			foreach ( $matches[1] as $match ) {
				// Extract strings from array format
				if ( preg_match_all( '/\(([^)]+)\)/', $match, $submatches ) ) {
					foreach ( $submatches[1] as $submatch ) {
						$decoded = $this->decode_pdf_string( $submatch );
						if ( $this->is_readable_text( $decoded ) ) {
							$text .= $decoded . ' ';
						}
					}
				}
			}
		}

		// Method 4: Look for hex strings
		if ( preg_match_all( '/<([0-9a-fA-F]+)>/', $stream, $matches ) ) {
			foreach ( $matches[1] as $hex ) {
				$decoded = $this->decode_hex_string( $hex );
				if ( $this->is_readable_text( $decoded ) ) {
					$text .= $decoded . ' ';
				}
			}
		}

		// Method 5: Look for direct readable text in stream
		if ( preg_match_all( '/[a-zA-Z][a-zA-Z\s]{3,}/', $stream, $matches ) ) {
			foreach ( $matches[0] as $match ) {
				$match = trim( $match );
				if ( $this->is_readable_text( $match ) && strlen( $match ) > 3 ) {
					$text .= $match . ' ';
				}
			}
		}

		return trim( $text );
	}

	/**
	 * Decode hex string to text
	 */
	private function decode_hex_string( $hex ) {
		$text = '';
		for ( $i = 0; $i < strlen( $hex ); $i += 2 ) {
			$char = hexdec( substr( $hex, $i, 2 ) );
			if ( $char >= 32 && $char <= 126 ) { // Printable ASCII
				$text .= chr( $char );
			}
		}
		return $text;
	}

	/**
	 * Extract text using regex patterns
	 */
	private function extract_with_regex( $pdf_data ) {
		$text = '';

		// Pattern 1: Text in parentheses
		if ( preg_match_all( '/\(([^)]{2,})\)/', $pdf_data, $matches ) ) {
			foreach ( $matches[1] as $match ) {
				$decoded = $this->decode_pdf_string( $match );
				if ( $this->is_readable_text( $decoded ) ) {
					$text .= $decoded . ' ';
				}
			}
		}

		return trim( $text );
	}

	/**
	 * Extract simple readable strings
	 */
	private function extract_simple_strings( $pdf_data ) {
		$text = '';

		// Find all sequences of printable characters
		if ( preg_match_all( '/[a-zA-Z][a-zA-Z0-9\s\.,!?;:\-\'"]{10,}/', $pdf_data, $matches ) ) {
			foreach ( $matches[0] as $match ) {
				$match = trim( $match );
				if ( $this->is_readable_text( $match ) && strlen( $match ) > 10 ) {
					$text .= $match . ' ';
				}
			}
		}

		return trim( $text );
	}

	/**
	 * Brute force extract any readable text
	 */
	private function extract_any_text( $pdf_data ) {
		$text = '';

		// Remove binary data and keep only printable characters
		$clean_data = preg_replace( '/[^\x20-\x7E\n\r\t]/', ' ', $pdf_data );

		// Find sequences of words
		if ( preg_match_all( '/[a-zA-Z]+(?:\s+[a-zA-Z]+){2,}/', $clean_data, $matches ) ) {
			foreach ( $matches[0] as $match ) {
				$match = trim( $match );
				if ( strlen( $match ) > 10 && $this->is_readable_text( $match ) ) {
					$text .= $match . ' ';
				}
			}
		}

		return trim( $text );
	}

	/**
	 * Decode PDF string escape sequences
	 */
	private function decode_pdf_string( $str ) {
		// Handle basic PDF string escapes
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
	 * Check if text appears to be readable
	 */
	private function is_readable_text( $text ) {
		if ( empty( $text ) || strlen( $text ) < 2 ) {
			return false;
		}

		// Count letters vs total characters
		$letters = preg_match_all( '/[a-zA-Z]/', $text );
		$total   = strlen( $text );

		if ( $total == 0 ) {
			return false;
		}

		$letter_ratio = $letters / $total;

		// Extremely lenient - accept almost anything with letters
		return $letter_ratio > 0.05;
	}

	/**
	 * Clean extracted text
	 */
	private function clean_extracted_text( $text ) {
		// Remove control characters but keep basic punctuation
		$text = preg_replace( '/[\x00-\x1F\x7F]/', ' ', $text );

		// Remove excessive whitespace
		$text = preg_replace( '/\s+/', ' ', $text );

		// Try to extract readable sentences/phrases
		$sentences = array();
		if ( preg_match_all( '/[A-Z][a-zA-Z\s]{5,}[.!?]?/', $text, $matches ) ) {
			foreach ( $matches[0] as $sentence ) {
				$sentence = trim( $sentence );
				if ( strlen( $sentence ) > 5 && $this->is_readable_text( $sentence ) ) {
					$sentences[] = $sentence;
				}
			}
		}

		// If we found good sentences, use those
		if ( ! empty( $sentences ) ) {
			return implode( ' ', $sentences );
		}

		// Otherwise try to extract readable words
		$words = array();
		if ( preg_match_all( '/[a-zA-Z]{3,}/', $text, $matches ) ) {
			foreach ( $matches[0] as $word ) {
				if ( strlen( $word ) >= 3 ) {
					$words[] = $word;
				}
			}
		}

		if ( ! empty( $words ) ) {
			return implode( ' ', array_unique( $words ) );
		}

		// Last resort - return cleaned original
		return trim( $text );
	}
}
