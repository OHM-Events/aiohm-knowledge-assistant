<?php
/**
 * Text Parser for AIOHM Knowledge Assistant
 * Handles TXT and MD (Markdown) files
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIOHM_Text_Parser extends AIOHM_Abstract_Parser {

	protected function init() {
		$this->supported_extensions = array( 'txt', 'md' );
		$this->has_ai_fallback      = false;
		$this->priority             = 10;
	}

	/**
	 * Parse text-based files
	 *
	 * @param string $file_path Path to the file
	 * @return array Parsed content data
	 * @throws Exception If file cannot be read
	 */
	public function parse( $file_path ) {

		if ( ! is_readable( $file_path ) ) {
			throw new Exception( 'File is not readable: ' . esc_html( basename( $file_path ) ) );
		}

		$content = file_get_contents( $file_path );
		if ( $content === false ) {
			throw new Exception( 'Failed to read file contents: ' . esc_html( basename( $file_path ) ) );
		}

		$extension         = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		$sanitized_content = $this->sanitize_content( $content, $extension );

		if ( empty( trim( $sanitized_content ) ) ) {
			throw new Exception( 'File appears to be empty after sanitization' );
		}

		// Extract title - for markdown, try to get first heading
		$title = $this->extract_title( $sanitized_content, $extension );
		if ( empty( $title ) ) {
			$title = pathinfo( $file_path, PATHINFO_FILENAME );
		}

		// Generate metadata
		$metadata = $this->generate_metadata(
			$file_path,
			$extension,
			array(
				'encoding'   => $this->detect_encoding( $content ),
				'line_count' => substr_count( $content, "\n" ) + 1,
				'word_count' => str_word_count( $sanitized_content ),
				'char_count' => strlen( $sanitized_content ),
			)
		);

		// Additional processing for markdown
		if ( $extension === 'md' ) {
			$metadata['markdown_features'] = $this->analyze_markdown_features( $sanitized_content );
		}

		return array(
			'content'  => $sanitized_content,
			'title'    => $title,
			'metadata' => $metadata,
		);
	}

	/**
	 * Extract title from content
	 *
	 * @param string $content File content
	 * @param string $extension File extension
	 * @return string Extracted title or empty string
	 */
	private function extract_title( $content, $extension ) {
		if ( $extension === 'md' ) {
			// Look for markdown heading
			if ( preg_match( '/^#\s+(.+)$/m', $content, $matches ) ) {
				return trim( $matches[1] );
			}
		}

		// For any text file, try to use first non-empty line as title
		$lines = explode( "\n", $content );
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( ! empty( $line ) && strlen( $line ) < 100 ) {
				// Remove markdown heading markers if present
				$line = preg_replace( '/^#+\s*/', '', $line );
				return $line;
			}
		}

		return '';
	}

	/**
	 * Detect text encoding
	 *
	 * @param string $content File content
	 * @return string Detected encoding
	 */
	private function detect_encoding( $content ) {
		if ( function_exists( 'mb_detect_encoding' ) ) {
			$encoding = mb_detect_encoding( $content, array( 'UTF-8', 'ISO-8859-1', 'Windows-1252' ), true );
			return $encoding ?: 'UTF-8';
		}

		// Simple UTF-8 detection
		return mb_check_encoding( $content, 'UTF-8' ) ? 'UTF-8' : 'ISO-8859-1';
	}

	/**
	 * Analyze markdown features in content
	 *
	 * @param string $content Markdown content
	 * @return array Array of detected markdown features
	 */
	private function analyze_markdown_features( $content ) {
		$features = array();

		// Count headings by level
		for ( $i = 1; $i <= 6; $i++ ) {
			$pattern                   = '/^' . str_repeat( '#', $i ) . '\s+.+$/m';
			$features[ "h{$i}_count" ] = preg_match_all( $pattern, $content );
		}

		// Count other markdown features
		$features['links']          = preg_match_all( '/\[([^\]]+)\]\(([^)]+)\)/', $content );
		$features['images']         = preg_match_all( '/!\[([^\]]*)\]\(([^)]+)\)/', $content );
		$features['bold']           = preg_match_all( '/\*\*([^*]+)\*\*/', $content );
		$features['italic']         = preg_match_all( '/\*([^*]+)\*/', $content );
		$features['code_blocks']    = preg_match_all( '/```[\s\S]*?```/', $content );
		$features['inline_code']    = preg_match_all( '/`([^`]+)`/', $content );
		$features['lists']          = preg_match_all( '/^[\s]*[-*+]\s+/m', $content );
		$features['numbered_lists'] = preg_match_all( '/^[\s]*\d+\.\s+/m', $content );
		$features['blockquotes']    = preg_match_all( '/^>/m', $content );

		return $features;
	}
}
