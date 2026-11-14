<?php
/**
 * Abstract Parser Interface and Base Class
 * Provides the foundation for all file parsers in AIOHM Knowledge Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface for all file parsers
 */
interface AIOHM_Parser_Interface {
	/**
	 * Check if this parser can handle the given file
	 *
	 * @param string $file_path Path to the file
	 * @param string $extension File extension
	 * @return bool True if parser can handle this file
	 */
	public function can_parse( $file_path, $extension );

	/**
	 * Parse the file and extract content
	 *
	 * @param string $file_path Path to the file
	 * @return array Array with 'content', 'title', 'metadata'
	 * @throws Exception If parsing fails
	 */
	public function parse( $file_path );

	/**
	 * Get list of supported file extensions
	 *
	 * @return array Array of supported extensions (without dots)
	 */
	public function get_supported_extensions();

	/**
	 * Check if this parser has AI fallback capability
	 *
	 * @return bool True if AI fallback is available
	 */
	public function has_ai_fallback();

	/**
	 * Get parser priority (higher = preferred)
	 *
	 * @return int Priority level
	 */
	public function get_priority();
}

/**
 * Abstract base class for all parsers
 */
abstract class AIOHM_Abstract_Parser implements AIOHM_Parser_Interface {

	protected $supported_extensions = array();
	protected $has_ai_fallback      = false;
	protected $priority             = 10;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->init();
	}

	/**
	 * Initialize parser - override in child classes
	 */
	protected function init() {}

	/**
	 * Check if this parser can handle the given file
	 */
	public function can_parse( $file_path, $extension ) {
		return in_array( strtolower( $extension ), $this->supported_extensions, true );
	}

	/**
	 * Get list of supported file extensions
	 */
	public function get_supported_extensions() {
		return $this->supported_extensions;
	}

	/**
	 * Check if this parser has AI fallback capability
	 */
	public function has_ai_fallback() {
		return $this->has_ai_fallback;
	}

	/**
	 * Get parser priority
	 */
	public function get_priority() {
		return $this->priority;
	}

	/**
	 * Sanitize extracted content
	 *
	 * @param string $content Raw content
	 * @param string $extension File extension for context
	 * @return string Sanitized content
	 */
	protected function sanitize_content( $content, $extension ) {
		if ( empty( $content ) ) {
			return '';
		}

		// Remove null bytes and control characters
		$content = str_replace( "\0", '', $content );
		$content = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $content );

		// Normalize line breaks
		$content = str_replace( array( "\r\n", "\r" ), "\n", $content );

		// Extension-specific sanitization
		switch ( strtolower( $extension ) ) {
			case 'csv':
				// Prevent CSV formula injection
				$content = preg_replace( '/^[=+\-@]/', "'$0", $content );
				break;
			case 'html':
			case 'htm':
				// Allow basic HTML but strip potentially dangerous content
				$content = wp_kses(
					$content,
					array(
						'p'          => array(),
						'br'         => array(),
						'strong'     => array(),
						'em'         => array(),
						'b'          => array(),
						'i'          => array(),
						'h1'         => array(),
						'h2'         => array(),
						'h3'         => array(),
						'h4'         => array(),
						'h5'         => array(),
						'h6'         => array(),
						'ul'         => array(),
						'ol'         => array(),
						'li'         => array(),
						'blockquote' => array(),
						'pre'        => array(),
						'code'       => array(),
					)
				);
				break;
			case 'json':
				// Validate JSON structure
				if ( ! json_decode( $content ) ) {
				}
				break;
		}

		return trim( $content );
	}

	/**
	 * Generate metadata for parsed content
	 *
	 * @param string $file_path Original file path
	 * @param string $extension File extension
	 * @param array  $additional_metadata Additional metadata to include
	 * @return array Metadata array
	 */
	protected function generate_metadata( $file_path, $extension, $additional_metadata = array() ) {
		$file_info = pathinfo( $file_path );

		$metadata = array(
			'original_filename' => $file_info['basename'],
			'file_extension'    => $extension,
			'file_size'         => file_exists( $file_path ) ? filesize( $file_path ) : 0,
			'parsed_at'         => current_time( 'mysql' ),
			'parser_used'       => get_class( $this ),
			'mime_type'         => $this->get_mime_type( $file_path ),
		);

		return array_merge( $metadata, $additional_metadata );
	}

	/**
	 * Get MIME type for file
	 *
	 * @param string $file_path File path
	 * @return string MIME type
	 */
	protected function get_mime_type( $file_path ) {
		if ( function_exists( 'mime_content_type' ) ) {
			return mime_content_type( $file_path );
		}

		if ( function_exists( 'finfo_file' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			$mime  = finfo_file( $finfo, $file_path );
			finfo_close( $finfo );
			return $mime;
		}

		// Fallback to extension-based detection
		$extension  = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		$mime_types = array(
			'txt'  => 'text/plain',
			'md'   => 'text/markdown',
			'json' => 'application/json',
			'csv'  => 'text/csv',
			'pdf'  => 'application/pdf',
			'doc'  => 'application/msword',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'html' => 'text/html',
			'htm'  => 'text/html',
			'xls'  => 'application/vnd.ms-excel',
			'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		);

		return $mime_types[ $extension ] ?? 'application/octet-stream';
	}

	/**
	 * Log parser activity
	 *
	 * @param string $message Log message
	 * @param string $level Log level (info, warning, error)
	 */

	/**
	 * Abstract method - must be implemented by child classes
	 *
	 * @param string $file_path Path to the file
	 * @return array Array with 'content', 'title', 'metadata'
	 */
	abstract public function parse( $file_path );
}
