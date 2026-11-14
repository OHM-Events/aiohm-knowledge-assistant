<?php
/**
 * AI Fallback Parser for AIOHM Knowledge Assistant
 * Uses AI to extract content when native parsing fails
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIOHM_AI_Fallback_Parser extends AIOHM_Abstract_Parser {

	private $ai_client;

	protected function init() {
		$this->supported_extensions = array( 'pdf', 'doc', 'docx', 'html', 'htm', 'txt', 'md', 'json', 'csv' );
		$this->has_ai_fallback      = false; // This IS the fallback
		$this->priority             = 1; // Lowest priority - only used as fallback

		// Initialize AI client
		$this->ai_client = new AIOHM_KB_AI_GPT_Client();
	}

	/**
	 * Parse file using AI when other methods fail
	 *
	 * @param string $file_path Path to the file
	 * @return array Parsed content data
	 * @throws Exception If AI parsing fails
	 */
	public function parse( $file_path ) {
		$extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

		if ( ! is_readable( $file_path ) ) {
			throw new Exception( 'File is not readable for AI parsing: ' . esc_html( basename( $file_path ) ) );
		}

		// Check if AI client is configured
		if ( ! $this->is_ai_available() ) {
			throw new Exception( 'AI client not configured for fallback parsing' );
		}

		// Different strategies based on file type
		switch ( $extension ) {
			case 'pdf':
				return $this->parse_pdf_with_ai( $file_path );
			case 'doc':
			case 'docx':
				return $this->parse_document_with_ai( $file_path );
			case 'html':
			case 'htm':
				return $this->parse_html_with_ai( $file_path );
			default:
				return $this->parse_text_with_ai( $file_path );
		}
	}

	/**
	 * Check if AI client is available and configured
	 *
	 * @return bool True if AI is available
	 */
	private function is_ai_available() {
		$settings = AIOHM_KB_Assistant::get_settings();

		// Check if any AI provider is configured
		$providers = array( 'openai_api_key', 'gemini_api_key', 'claude_api_key' );
		foreach ( $providers as $provider ) {
			if ( ! empty( $settings[ $provider ] ) ) {
				return true;
			}
		}

		if ( function_exists( 'aiohm_kb_is_wp_ai_connector_configured' ) ) {
			if ( aiohm_kb_is_wp_ai_connector_configured( 'openai' ) ||
				 aiohm_kb_is_wp_ai_connector_configured( 'gemini' ) ||
				 aiohm_kb_is_wp_ai_connector_configured( 'claude' ) ) {
				return true;
			}
		}

		if ( ! empty( get_option( 'connectors_ai_openai_api_key' ) ) ||
			 ! empty( get_option( 'connectors_ai_google_api_key' ) ) ||
			 ! empty( get_option( 'connectors_ai_gemini_api_key' ) ) ||
			 ! empty( get_option( 'connectors_ai_anthropic_api_key' ) ) ||
			 ! empty( get_option( 'connectors_ai_claude_api_key' ) ) ||
			 ( defined( 'OPENAI_API_KEY' ) && OPENAI_API_KEY ) ||
			 ( defined( 'GOOGLE_API_KEY' ) && GOOGLE_API_KEY ) ||
			 ( defined( 'GEMINI_API_KEY' ) && GEMINI_API_KEY ) ||
			 ( defined( 'ANTHROPIC_API_KEY' ) && ANTHROPIC_API_KEY ) ||
			 ( defined( 'CLAUDE_API_KEY' ) && CLAUDE_API_KEY ) ||
			 getenv( 'OPENAI_API_KEY' ) ||
			 getenv( 'GOOGLE_API_KEY' ) ||
			 getenv( 'GEMINI_API_KEY' ) ||
			 getenv( 'ANTHROPIC_API_KEY' ) ||
			 getenv( 'CLAUDE_API_KEY' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Parse PDF using AI
	 *
	 * @param string $file_path PDF file path
	 * @return array Parsed content data
	 * @throws Exception If AI parsing fails
	 */
	private function parse_pdf_with_ai( $file_path ) {
		// For PDFs, we'll read the binary content and ask AI to extract text
		$file_size = filesize( $file_path );

		if ( $file_size > 5 * 1024 * 1024 ) { // 5MB limit
			throw new Exception( 'PDF file too large for AI parsing (max 5MB)' );
		}

		// Try multiple extraction strategies
		$sample_content   = $this->extract_pdf_sample( $file_path );
		$metadata_content = $this->extract_pdf_metadata( $file_path );

		// Determine PDF type for better AI prompting
		$pdf_type = $this->analyze_pdf_type( $file_path );

		$prompt = $this->build_pdf_ai_prompt( $sample_content, $metadata_content, $pdf_type );

		$ai_response = $this->query_ai( $prompt );

		if ( empty( trim( $ai_response ) ) ) {
			// If AI fails, try to provide helpful information about the PDF
			$fallback_content = $this->generate_pdf_fallback_content( $file_path, $pdf_type );
			if ( ! empty( $fallback_content ) ) {
				return $this->format_ai_response( $file_path, $fallback_content, 'pdf' );
			}
			throw new Exception( 'AI returned empty response for PDF parsing and no fallback content available' );
		}

		return $this->format_ai_response( $file_path, $ai_response, 'pdf' );
	}

	/**
	 * Parse document using AI
	 *
	 * @param string $file_path Document file path
	 * @return array Parsed content data
	 * @throws Exception If AI parsing fails
	 */
	private function parse_document_with_ai( $file_path ) {
		$extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

		// Try to get some readable content from the document
		$sample_content = '';

		if ( $extension === 'docx' ) {
			$sample_content = $this->extract_docx_sample( $file_path );
		} else {
			$sample_content = $this->extract_doc_sample( $file_path );
		}

		if ( empty( trim( $sample_content ) ) ) {
			throw new Exception( 'Could not extract any content for AI processing' );
		}

		$prompt = 'Extract and clean up the text content from this document. ' .
				'The content may be mixed with formatting codes or binary data. ' .
				'Please return only the readable text content, properly formatted with paragraphs. ' .
				"If you can identify headings or structure, please preserve that.\n\n" .
				"Document content:\n" . substr( $sample_content, 0, 3000 );

		$ai_response = $this->query_ai( $prompt );

		if ( empty( trim( $ai_response ) ) ) {
			throw new Exception( 'AI returned empty response for document parsing' );
		}

		return $this->format_ai_response( $file_path, $ai_response, $extension );
	}

	/**
	 * Parse HTML using AI
	 *
	 * @param string $file_path HTML file path
	 * @return array Parsed content data
	 * @throws Exception If AI parsing fails
	 */
	private function parse_html_with_ai( $file_path ) {
		$html_content = file_get_contents( $file_path );
		if ( $html_content === false ) {
			throw new Exception( 'Failed to read HTML file for AI parsing' );
		}

		// Limit content size for AI processing
		if ( strlen( $html_content ) > 10000 ) {
			$html_content = substr( $html_content, 0, 10000 ) . "\n[Content truncated for AI processing]";
		}

		$prompt = 'Extract the main text content from this HTML, removing all HTML tags, scripts, styles, and navigation elements. ' .
				'Focus on the main content like articles, blog posts, or page content. ' .
				'Preserve the structure with headings and paragraphs, but convert to clean text format. ' .
				"If there are lists or tables, convert them to readable text format.\n\n" .
				"HTML content:\n" . $html_content;

		$ai_response = $this->query_ai( $prompt );

		if ( empty( trim( $ai_response ) ) ) {
			throw new Exception( 'AI returned empty response for HTML parsing' );
		}

		return $this->format_ai_response( $file_path, $ai_response, 'html' );
	}

	/**
	 * Parse text file using AI (for complex cases)
	 *
	 * @param string $file_path Text file path
	 * @return array Parsed content data
	 * @throws Exception If AI parsing fails
	 */
	private function parse_text_with_ai( $file_path ) {
		$content = file_get_contents( $file_path );
		if ( $content === false ) {
			throw new Exception( 'Failed to read text file for AI parsing' );
		}

		$extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

		// Only use AI for text files if there's encoding or formatting issues
		$prompt = 'Clean up and format this text content. ' .
				'Fix any encoding issues, remove unnecessary whitespace, and improve readability. ' .
				"If this appears to be structured data (JSON, CSV, etc.), convert it to readable text format.\n\n" .
				"Content:\n" . substr( $content, 0, 5000 );

		$ai_response = $this->query_ai( $prompt );

		if ( empty( trim( $ai_response ) ) ) {
			throw new Exception( 'AI returned empty response for text parsing' );
		}

		return $this->format_ai_response( $file_path, $ai_response, $extension );
	}

	/**
	 * Extract sample content from PDF for AI processing
	 *
	 * @param string $file_path PDF file path
	 * @return string Sample content
	 */
	private function extract_pdf_sample( $file_path ) {
		$binary_content = file_get_contents( $file_path );

		// Extract readable strings from PDF binary with improved filtering
		$readable_strings = array();
		if ( preg_match_all( '/[\x20-\x7E]{5,}/s', $binary_content, $matches ) ) {
			foreach ( $matches[0] as $match ) {
				// Skip pure numbers, hex codes, and technical strings
				if ( ! preg_match( '/^[0-9\x00-\x1F]+$/', $match ) &&
					! preg_match( '/^[A-F0-9]+$/', $match ) &&
					! preg_match( '/^[\s\d\.]+$/', $match ) &&
					strlen( trim( $match ) ) > 3 &&
					preg_match( '/[a-zA-Z]/', $match ) ) {
					$readable_strings[] = trim( $match );
				}
			}
		}

		// Get more content, but prioritize longer strings
		usort(
			$readable_strings,
			function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);

		return implode( ' ', array_slice( $readable_strings, 0, 30 ) );
	}

	/**
	 * Extract PDF metadata for AI processing
	 *
	 * @param string $file_path PDF file path
	 * @return string Metadata content
	 */
	private function extract_pdf_metadata( $file_path ) {
		$binary_content = file_get_contents( $file_path, false, null, 0, 5000 );
		$metadata       = array();

		// Extract common PDF metadata
		if ( preg_match( '/\/Title\s*\(([^)]+)\)/', $binary_content, $matches ) ) {
			$metadata[] = 'Title: ' . $matches[1];
		}
		if ( preg_match( '/\/Subject\s*\(([^)]+)\)/', $binary_content, $matches ) ) {
			$metadata[] = 'Subject: ' . $matches[1];
		}
		if ( preg_match( '/\/Author\s*\(([^)]+)\)/', $binary_content, $matches ) ) {
			$metadata[] = 'Author: ' . $matches[1];
		}
		if ( preg_match( '/\/Creator\s*\(([^)]+)\)/', $binary_content, $matches ) ) {
			$metadata[] = 'Creator: ' . $matches[1];
		}

		return implode( ', ', $metadata );
	}

	/**
	 * Analyze PDF type to provide better AI prompting
	 *
	 * @param string $file_path PDF file path
	 * @return string PDF type description
	 */
	private function analyze_pdf_type( $file_path ) {
		$binary_content = file_get_contents( $file_path, false, null, 0, 10000 );

		// Count different object types
		$image_objects = substr_count( $binary_content, '/Type/XObject' );
		$text_objects  = substr_count( $binary_content, 'BT' );
		$font_objects  = substr_count( $binary_content, '/Type/Font' );

		if ( $image_objects > 5 && $text_objects < 3 ) {
			return 'image-heavy (likely scanned document)';
		} elseif ( strpos( $binary_content, '/Encrypt' ) !== false ) {
			return 'encrypted/protected';
		} elseif ( $font_objects > 10 ) {
			return 'complex formatting with multiple fonts';
		} elseif ( $text_objects > 10 ) {
			return 'text-rich document';
		} else {
			return 'standard PDF';
		}
	}

	/**
	 * Build appropriate AI prompt based on PDF analysis
	 *
	 * @param string $sample_content Sample content
	 * @param string $metadata_content Metadata
	 * @param string $pdf_type PDF type
	 * @return string AI prompt
	 */
	private function build_pdf_ai_prompt( $sample_content, $metadata_content, $pdf_type ) {
		$prompt = "I need help extracting readable content from a PDF that appears to be: $pdf_type.\n\n";

		if ( ! empty( $metadata_content ) ) {
			$prompt .= "PDF Metadata: $metadata_content\n\n";
		}

		if ( strpos( $pdf_type, 'image-heavy' ) !== false ) {
			$prompt .= 'This appears to be a scanned document with mostly images. ';
			$prompt .= 'Please extract any readable text you can find, even if fragmented. ';
			$prompt .= "If no readable text is available, please indicate this is an image-based PDF that would require OCR.\n\n";
		} elseif ( strpos( $pdf_type, 'encrypted' ) !== false ) {
			$prompt .= 'This PDF appears to be encrypted or password-protected. ';
			$prompt .= "Please extract any readable text you can find, or indicate if the content is protected.\n\n";
		} else {
			$prompt .= 'Please extract and clean up the readable text content. ';
			$prompt .= 'Remove any binary artifacts, formatting codes, or technical metadata. ';
			$prompt .= "Return only the actual document text in a clean, readable format.\n\n";
		}

		$prompt .= "Sample content from PDF:\n" . substr( $sample_content, 0, 4000 );

		return $prompt;
	}

	/**
	 * Generate fallback content when AI fails
	 *
	 * @param string $file_path PDF file path
	 * @param string $pdf_type PDF type
	 * @return string Fallback content
	 */
	private function generate_pdf_fallback_content( $file_path ) {
		$filename      = basename( $file_path );
		$file_size     = filesize( $file_path );
		$readable_size = size_format( $file_size );

		$metadata = $this->extract_pdf_metadata( $file_path );
		$pdf_type = $this->analyze_pdf_type( $file_path );

		$content  = "PDF Document: $filename\n";
		$content .= "File Size: $readable_size\n";
		$content .= "Type: $pdf_type\n\n";

		if ( ! empty( $metadata ) ) {
			$content .= "Metadata: $metadata\n\n";
		}

		if ( strpos( $pdf_type, 'image-heavy' ) !== false ) {
			$content .= 'Note: This appears to be a scanned document containing primarily images. ';
			$content .= 'Text extraction would require OCR (Optical Character Recognition) capabilities. ';
			$content .= 'The document may contain valuable information that is not accessible through standard text extraction methods.';
		} elseif ( strpos( $pdf_type, 'encrypted' ) !== false ) {
			$content .= 'Note: This PDF is encrypted or password-protected. ';
			$content .= 'The content cannot be accessed without the appropriate credentials.';
		} else {
			$content .= 'Note: This PDF contains complex formatting or encoding that prevents standard text extraction. ';
			$content .= 'The document may require specialized tools or manual review to access its content.';
		}

		return $content;
	}

	/**
	 * Extract sample content from DOCX for AI processing
	 *
	 * @param string $file_path DOCX file path
	 * @return string Sample content
	 */
	private function extract_docx_sample( $file_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return $this->extract_binary_text_sample( $file_path );
		}

		$zip = new ZipArchive();
		if ( $zip->open( $file_path ) !== true ) {
			return $this->extract_binary_text_sample( $file_path );
		}

		$document_xml = $zip->getFromName( 'word/document.xml' );
		$zip->close();

		if ( $document_xml === false ) {
			return $this->extract_binary_text_sample( $file_path );
		}

		// Strip XML tags to get text content
		return wp_strip_all_tags( $document_xml );
	}

	/**
	 * Extract sample content from DOC for AI processing
	 *
	 * @param string $file_path DOC file path
	 * @return string Sample content
	 */
	private function extract_doc_sample( $file_path ) {
		return $this->extract_binary_text_sample( $file_path );
	}

	/**
	 * Extract readable text from binary file
	 *
	 * @param string $file_path File path
	 * @return string Extracted text
	 */
	private function extract_binary_text_sample( $file_path ) {
		$binary_content = file_get_contents( $file_path );
		$readable_text  = '';

		if ( preg_match_all( '/[\x20-\x7E]{5,}/s', $binary_content, $matches ) ) {
			foreach ( $matches[0] as $match ) {
				if ( strlen( $match ) > 10 && ! preg_match( '/^[0-9A-F]+$/', $match ) ) {
					$readable_text .= $match . ' ';
				}
			}
		}

		return $readable_text;
	}

	/**
	 * Query AI for content extraction
	 *
	 * @param string $prompt AI prompt
	 * @return string AI response
	 * @throws Exception If AI query fails
	 */
	private function query_ai( $prompt ) {
		try {
			$system_message = 'You are an expert document parser. Extract clean, readable text content from the provided document data. Remove any metadata, formatting artifacts, or technical gibberish. Return only the meaningful text content.';

			$response = $this->ai_client->get_chat_completion( $system_message, $prompt, 0.3 );

			if ( empty( $response ) ) {
				throw new Exception( 'AI client returned empty response' );
			}

			return $response;

		} catch ( Exception $e ) {
			throw new Exception( 'AI content extraction failed: ' . esc_html( $e->getMessage() ) );
		}
	}

	/**
	 * Format AI response into standard parser output
	 *
	 * @param string $file_path Original file path
	 * @param string $ai_response AI response content
	 * @param string $extension File extension
	 * @return array Formatted response
	 */
	private function format_ai_response( $file_path, $ai_response, $extension ) {
		// Clean up AI response
		$content = trim( $ai_response );

		// Extract title from response
		$title = $this->extract_title_from_ai_content( $content, pathinfo( $file_path, PATHINFO_FILENAME ) );

		// Generate metadata
		$extraction_quality = $this->assess_extraction_quality( $content );
		$metadata           = $this->generate_metadata(
			$file_path,
			$extension,
			array(
				'parsed_with_ai'      => true,
				'ai_model'            => $this->get_ai_model_info(),
				'word_count'          => str_word_count( $content ),
				'char_count'          => strlen( $content ),
				'extraction_quality'  => $extraction_quality,
				'is_fallback_content' => ( $extraction_quality === 'extraction_failed' ),
			)
		);

		return array(
			'content'  => $content,
			'title'    => $title,
			'metadata' => $metadata,
		);
	}

	/**
	 * Extract title from AI-processed content
	 *
	 * @param string $content AI processed content
	 * @param string $fallback Fallback title
	 * @return string Title
	 */
	private function extract_title_from_ai_content( $content, $fallback ) {
		$lines = explode( "\n", $content );

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( strlen( $line ) > 5 && strlen( $line ) < 100 ) {
				// Remove markdown heading markers
				$line = preg_replace( '/^#+\s*/', '', $line );
				// Check if it looks like a title
				if ( ! ctype_digit( $line ) && ! preg_match( '/^[^a-zA-Z]*$/', $line ) ) {
					return $line;
				}
			}
		}

		return $fallback;
	}

	/**
	 * Get AI model information
	 *
	 * @return array AI model info
	 */
	private function get_ai_model_info() {
		$settings = AIOHM_KB_Assistant::get_settings();

		$aiohm_has_openai = ! empty( $settings['openai_api_key'] )
			|| ( function_exists( 'aiohm_kb_is_wp_ai_connector_configured' ) && aiohm_kb_is_wp_ai_connector_configured( 'openai' ) )
			|| ! empty( get_option( 'connectors_ai_openai_api_key' ) )
			|| ( defined( 'OPENAI_API_KEY' ) && OPENAI_API_KEY )
			|| getenv( 'OPENAI_API_KEY' );

		$aiohm_has_gemini = ! empty( $settings['gemini_api_key'] )
			|| ( function_exists( 'aiohm_kb_is_wp_ai_connector_configured' ) && aiohm_kb_is_wp_ai_connector_configured( 'gemini' ) )
			|| ! empty( get_option( 'connectors_ai_google_api_key' ) )
			|| ! empty( get_option( 'connectors_ai_gemini_api_key' ) )
			|| ( defined( 'GOOGLE_API_KEY' ) && GOOGLE_API_KEY )
			|| ( defined( 'GEMINI_API_KEY' ) && GEMINI_API_KEY )
			|| getenv( 'GOOGLE_API_KEY' )
			|| getenv( 'GEMINI_API_KEY' );

		$aiohm_has_claude = ! empty( $settings['claude_api_key'] )
			|| ( function_exists( 'aiohm_kb_is_wp_ai_connector_configured' ) && aiohm_kb_is_wp_ai_connector_configured( 'claude' ) )
			|| ! empty( get_option( 'connectors_ai_anthropic_api_key' ) )
			|| ! empty( get_option( 'connectors_ai_claude_api_key' ) )
			|| ( defined( 'ANTHROPIC_API_KEY' ) && ANTHROPIC_API_KEY )
			|| ( defined( 'CLAUDE_API_KEY' ) && CLAUDE_API_KEY )
			|| getenv( 'ANTHROPIC_API_KEY' )
			|| getenv( 'CLAUDE_API_KEY' );

		// Determine which AI provider was used
		if ( $aiohm_has_openai ) {
			return array(
				'provider' => 'openai',
				'model'    => $settings['openai_model'] ?? 'gpt-3.5-turbo',
			);
		} elseif ( $aiohm_has_gemini ) {
			return array(
				'provider' => 'gemini',
				'model'    => 'gemini-pro',
			);
		} elseif ( $aiohm_has_claude ) {
			return array(
				'provider' => 'claude',
				'model'    => 'claude-3',
			);
		}

		return array(
			'provider' => 'unknown',
			'model'    => 'unknown',
		);
	}

	/**
	 * Assess quality of extracted content
	 *
	 * @param string $content Extracted content
	 * @return string Quality assessment
	 */
	private function assess_extraction_quality( $content ) {
		$word_count = str_word_count( $content );
		$char_count = strlen( $content );

		// Check for indicators of failed extraction
		if ( strpos( $content, 'image-based PDF' ) !== false ||
			strpos( $content, 'encrypted' ) !== false ||
			strpos( $content, 'password-protected' ) !== false ) {
			return 'extraction_failed';
		}

		if ( $word_count < 10 ) {
			return 'poor';
		} elseif ( $word_count < 50 ) {
			return 'fair';
		} elseif ( $word_count < 200 ) {
			return 'good';
		} else {
			return 'excellent';
		}
	}
}
