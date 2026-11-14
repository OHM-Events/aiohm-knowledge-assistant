<?php
/**
 * Parser Factory for AIOHM Knowledge Assistant
 * Manages and instantiates appropriate parsers for different file types
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIOHM_Parser_Factory {

	private static $registered_parsers = array();
	private static $initialized        = false;

	/**
	 * Initialize the factory and register default parsers
	 */
	public static function init() {
		if ( self::$initialized ) {
			return;
		}

		// Register default parsers
		self::register_default_parsers();

		self::$initialized = true;
	}

	/**
	 * Register default parsers
	 */
	private static function register_default_parsers() {
		// Load abstract parser first
		$abstract_parser_file = __DIR__ . '/abstract-parser.php';
		if ( file_exists( $abstract_parser_file ) ) {
			require_once $abstract_parser_file;
		}

		// Load parser files
		$parser_files = array(
			'text-parser.php',
			'data-parser.php',
			'smalot-pdf-parser.php',          // Smalot PDF parser (highest priority)
			'content-focused-pdf-parser.php', // Content-focused PDF parser (fallback)
			'aggressive-pdf-parser.php',      // Aggressive PDF parser (fallback)
			'clean-pdf-parser.php',           // Clean PDF parser (final fallback)
			'html-parser.php',
			'spreadsheet-parser.php',
		);

		foreach ( $parser_files as $file ) {
			$file_path = __DIR__ . '/' . $file;
			if ( file_exists( $file_path ) ) {
				require_once $file_path;
			}
		}

		// Register parser classes
		$default_parsers = array(
			'AIOHM_Text_Parser',
			'AIOHM_Data_Parser',
			'AIOHM_Smalot_PDF_Parser',          // Smalot PDF parser (highest priority)
			'AIOHM_Content_Focused_PDF_Parser', // Content-focused PDF parser (fallback)
			'AIOHM_Aggressive_PDF_Parser',      // Aggressive PDF parser (fallback)
			'AIOHM_Clean_PDF_Parser',           // Clean PDF parser (final fallback)
			'AIOHM_HTML_Parser',
			'AIOHM_Spreadsheet_Parser',
		);

		foreach ( $default_parsers as $parser_class ) {
			if ( class_exists( $parser_class ) ) {
				self::register_parser( $parser_class );
			}
		}
	}

	/**
	 * Register a parser class
	 *
	 * @param string $parser_class Parser class name
	 * @return bool True if registered successfully
	 */
	public static function register_parser( $parser_class ) {
		if ( ! class_exists( $parser_class ) ) {
			return false;
		}

		$parser_instance = new $parser_class();

		if ( ! $parser_instance instanceof AIOHM_Parser_Interface ) {
			return false;
		}

		// Register parser for each supported extension
		$extensions = $parser_instance->get_supported_extensions();
		foreach ( $extensions as $extension ) {
			$extension = strtolower( $extension );

			if ( ! isset( self::$registered_parsers[ $extension ] ) ) {
				self::$registered_parsers[ $extension ] = array();
			}

			self::$registered_parsers[ $extension ][] = array(
				'class'    => $parser_class,
				'instance' => $parser_instance,
				'priority' => $parser_instance->get_priority(),
			);

			// Sort by priority (highest first)
			usort(
				self::$registered_parsers[ $extension ],
				function ( $a, $b ) {
					return $b['priority'] - $a['priority'];
				}
			);
		}

		return true;
	}

	/**
	 * Get the best parser for a file
	 *
	 * @param string $file_path Path to the file
	 * @param string $extension File extension (optional, will be detected)
	 * @return AIOHM_Parser_Interface|null Parser instance or null if none found
	 */
	public static function get_parser( $file_path, $extension = null ) {
		self::init();

		if ( $extension === null ) {
			$extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		} else {
			$extension = strtolower( $extension );
		}

		if ( ! isset( self::$registered_parsers[ $extension ] ) ) {
			return null;
		}

		// Try parsers in priority order
		foreach ( self::$registered_parsers[ $extension ] as $parser_info ) {
			$parser = $parser_info['instance'];

			if ( $parser->can_parse( $file_path, $extension ) ) {
				return $parser;
			}
		}

		return null;
	}

	/**
	 * Parse a file using the appropriate parser
	 *
	 * @param string $file_path Path to the file
	 * @param string $extension File extension (optional)
	 * @return array|false Parsed content array or false on failure
	 */
	public static function parse_file( $file_path, $extension = null ) {
		if ( ! file_exists( $file_path ) ) {
			return false;
		}

		$parser = self::get_parser( $file_path, $extension );
		if ( ! $parser ) {
			return false;
		}

		$parser_class = get_class( $parser );

		try {
			$result = $parser->parse( $file_path );

			// Validate result structure
			if ( ! is_array( $result ) || ! isset( $result['content'] ) ) {
				throw new Exception( 'Parser returned invalid result structure' );
			}

			$content_length = strlen( $result['content'] ?? '' );

			// Ensure required fields exist
			$result = array_merge(
				array(
					'content'  => '',
					'title'    => basename( $file_path ),
					'metadata' => array(),
				),
				$result
			);

			return $result;

		} catch ( Exception $e ) {

			// Try AI fallback if available
			if ( $parser->has_ai_fallback() ) {
				return self::try_ai_fallback( $file_path, $extension, $e->getMessage() );
			}

			return false;
		}
	}

	/**
	 * Try AI fallback parsing
	 *
	 * @param string $file_path Path to the file
	 * @param string $extension File extension
	 * @param string $error_message Original parsing error
	 * @return array|false Parsed content or false
	 */
	private static function try_ai_fallback( $file_path, $extension, $error_message ) {

		try {
			// Load AI fallback parser if exists
			$fallback_file = __DIR__ . '/ai-fallback-parser.php';
			if ( file_exists( $fallback_file ) ) {
				require_once $fallback_file;

				if ( class_exists( 'AIOHM_AI_Fallback_Parser' ) ) {
					$ai_parser = new AIOHM_AI_Fallback_Parser();
					return $ai_parser->parse( $file_path );
				}
			}

			return false;

		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * Get all registered parsers
	 *
	 * @return array Registered parsers by extension
	 */
	public static function get_registered_parsers() {
		self::init();
		return self::$registered_parsers;
	}

	/**
	 * Get supported file extensions
	 *
	 * @return array Array of supported extensions
	 */
	public static function get_supported_extensions() {
		self::init();
		return array_keys( self::$registered_parsers );
	}

	/**
	 * Check if an extension is supported
	 *
	 * @param string $extension File extension
	 * @return bool True if supported
	 */
	public static function supports_extension( $extension ) {
		self::init();
		return isset( self::$registered_parsers[ strtolower( $extension ) ] );
	}

	/**
	 * Get parser statistics
	 *
	 * @return array Statistics about registered parsers
	 */
	public static function get_stats() {
		self::init();

		$stats = array(
			'total_parsers'        => 0,
			'total_extensions'     => count( self::$registered_parsers ),
			'parsers_by_extension' => array(),
			'extensions_by_parser' => array(),
		);

		foreach ( self::$registered_parsers as $extension => $parsers ) {
			$stats['parsers_by_extension'][ $extension ] = count( $parsers );

			foreach ( $parsers as $parser_info ) {
				$class = $parser_info['class'];
				if ( ! isset( $stats['extensions_by_parser'][ $class ] ) ) {
					$stats['extensions_by_parser'][ $class ] = array();
				}
				$stats['extensions_by_parser'][ $class ][] = $extension;
			}
		}

		$stats['total_parsers'] = count( $stats['extensions_by_parser'] );

		return $stats;
	}
}
