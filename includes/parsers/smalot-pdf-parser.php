<?php
/**
 * Smalot PDF Parser for AIOHM Knowledge Assistant
 * Uses a reliable PDF parsing library to extract text from PDF files.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIOHM_Smalot_PDF_Parser extends AIOHM_Abstract_Parser {

	protected function init() {
		$this->supported_extensions = array( 'pdf' );
		$this->has_ai_fallback      = false;
		$this->priority             = 60; // Highest priority - better than client-side alternatives
	}

	/**
	 * Parse PDF file using Smalot PDF Parser
	 *
	 * @param string $file_path Path to the PDF file
	 * @return array Parsed content data
	 */
	public function parse( $file_path ) {

		if ( ! is_readable( $file_path ) ) {
			throw new Exception( 'PDF file is not readable: ' . esc_html( basename( $file_path ) ) );
		}

		$file_size = filesize( $file_path );

		// Load the Smalot PDF Parser library
		if ( ! $this->load_smalot_library() ) {
			throw new Exception( 'Smalot PDF Parser library not available' );
		}

		$extracted_text = $this->extract_with_smalot( $file_path );

		if ( empty( $extracted_text ) ) {
			throw new Exception( 'Smalot PDF Parser could not extract readable text from PDF' );
		}

		// Clean and process the extracted text
		$content = $this->process_extracted_text( $extracted_text );

		return array(
			'content'  => $content,
			'metadata' => array(
				'extraction_method' => 'smalot_parser',
				'file_size'         => $file_size,
				'raw_length'        => strlen( $content ),
			),
		);
	}

	/**
	 * Load the Smalot PDF Parser library
	 */
	private function load_smalot_library() {
		// Check if already loaded
		if ( class_exists( 'Smalot\PdfParser\Parser' ) ) {
			return true;
		}

		// Try to load from plugin vendor directory
		$vendor_autoload = AIOHM_KB_PLUGIN_DIR . 'vendor/autoload.php';
		if ( file_exists( $vendor_autoload ) ) {
			require_once $vendor_autoload;
			if ( class_exists( 'Smalot\PdfParser\Parser' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Extract text using Smalot PDF Parser
	 */
	private function extract_with_smalot( $file_path ) {
		try {
			$parser = new \Smalot\PdfParser\Parser();
			$pdf    = $parser->parseFile( $file_path );
			$text   = $pdf->getText();

			// If text is empty or very short, try to extract by pages
			if ( empty( $text ) || strlen( trim( $text ) ) < 10 ) {
				$text = $this->extract_text_by_pages( $pdf );
			}

			return $text;

		} catch ( \Exception $e ) {
			throw new Exception( 'PDF parsing failed: ' . esc_html( $e->getMessage() ) );
		}
	}

	/**
	 * Extract text from PDF by pages
	 */
	private function extract_text_by_pages( $pdf ) {
		$text = '';

		try {
			$pages = $pdf->getPages();
			foreach ( $pages as $page ) {
				$pageText = $page->getText();
				$text    .= $pageText . "\n\n";
			}
		} catch ( \Exception $e ) {
		}

		return $text;
	}

	/**
	 * Process extracted text for clean formatting
	 */
	private function process_extracted_text( $text ) {
		if ( empty( $text ) ) {
			return '';
		}

		// Normalize line endings
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );

		// Try to detect and format question/answer pattern
		if ( preg_match( '/question\s*:/i', $text ) ) {
			$text = $this->format_qa_text( $text );
		}

		// Try to detect and format title/content pattern
		if ( preg_match( '/title\s*:/i', $text ) ) {
			$text = $this->format_title_content_text( $text );
		}

		// Basic cleanup
		$text = $this->clean_extracted_text( $text );

		return $text;
	}

	/**
	 * Format Q&A text for better readability
	 */
	private function format_qa_text( $text ) {
		// Split by "Question:" or "Q:" patterns
		$pattern  = '/(question\s*:|q\s*:)/i';
		$sections = preg_split( $pattern, $text, -1, PREG_SPLIT_NO_EMPTY );

		if ( count( $sections ) <= 1 ) {
			return $text;
		}

		$formatted = '';
		foreach ( $sections as $section ) {
			// Skip empty sections
			if ( empty( trim( $section ) ) ) {
				continue;
			}

			// Split into question and answer
			$parts = preg_split( '/(answer\s*:|a\s*:)/i', $section, 2 );

			if ( count( $parts ) > 1 ) {
				$question   = trim( $parts[0] );
				$answer     = trim( $parts[1] );
				$formatted .= 'Question: ' . $question . "\nAnswer: " . $answer . "\n\n";
			} else {
				// Handle case where there's no explicit "Answer:" label
				$formatted .= 'Question: ' . trim( $section ) . "\n\n";
			}
		}

		return $formatted;
	}

	/**
	 * Format Title/Content text for structured processing
	 */
	private function format_title_content_text( $text ) {
		// Split by "Title:" pattern
		$pattern  = '/(title\s*:)/i';
		$sections = preg_split( $pattern, $text, -1, PREG_SPLIT_NO_EMPTY );

		if ( count( $sections ) <= 1 ) {
			return $text;
		}

		$formatted = '';
		foreach ( $sections as $section ) {
			// Skip empty sections
			if ( empty( trim( $section ) ) ) {
				continue;
			}

			// Split into title and content
			$parts = preg_split( '/(content\s*:)/i', $section, 2 );

			if ( count( $parts ) > 1 ) {
				$title      = trim( $parts[0] );
				$content    = trim( $parts[1] );
				$formatted .= 'Title: ' . $title . "\nContent: " . $content . "\n\n";
			} else {
				// Handle case where there's no explicit "Content:" label
				$formatted .= 'Title: ' . trim( $section ) . "\n\n";
			}
		}

		return $formatted;
	}

	/**
	 * Clean extracted text
	 */
	private function clean_extracted_text( $text ) {
		// Remove control characters but preserve line breaks
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', ' ', $text );

		// Remove excessive whitespace but preserve paragraph breaks
		$text = preg_replace( '/[ \t]+/', ' ', $text );
		$text = preg_replace( '/\n{3,}/', "\n\n", $text );

		// Remove obvious artifacts but be conservative
		$text = preg_replace( '/[^\w\s\.,!?;:\-\'\"()\n\r]{3,}/', ' ', $text );

		return trim( $text );
	}
}
