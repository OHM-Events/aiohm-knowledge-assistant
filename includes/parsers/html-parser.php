<?php
/**
 * HTML Parser for AIOHM Knowledge Assistant
 * Handles HTML and HTM files with DOM parsing and structure awareness
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIOHM_HTML_Parser extends AIOHM_Abstract_Parser {

	protected function init() {
		$this->supported_extensions = array( 'html', 'htm' );
		$this->has_ai_fallback      = true;
		$this->priority             = 11;
	}

	/**
	 * Parse HTML files
	 *
	 * @param string $file_path Path to the HTML file
	 * @return array Parsed content data
	 * @throws Exception If HTML cannot be parsed
	 */
	public function parse( $file_path ) {

		if ( ! is_readable( $file_path ) ) {
			throw new Exception( 'HTML file is not readable: ' . esc_html( basename( $file_path ) ) );
		}

		$html_content = file_get_contents( $file_path );
		if ( $html_content === false ) {
			throw new Exception( 'Failed to read HTML file contents' );
		}

		if ( empty( trim( $html_content ) ) ) {
			throw new Exception( 'HTML file appears to be empty' );
		}

		// Parse HTML with DOM parser
		$parsed_data = $this->parse_html_content( $html_content );

		if ( empty( trim( $parsed_data['content'] ) ) ) {
			throw new Exception( 'HTML file contains no extractable text content' );
		}

		// Sanitize content while preserving some structure
		$sanitized_content = $this->sanitize_content( $parsed_data['content'], 'html' );

		// Extract title
		$title = $parsed_data['title'] ?: pathinfo( $file_path, PATHINFO_FILENAME );

		// Generate metadata
		$metadata = $this->generate_metadata(
			$file_path,
			'html',
			array(
				'original_title'    => $parsed_data['title'],
				'meta_description'  => $parsed_data['meta_description'],
				'meta_keywords'     => $parsed_data['meta_keywords'],
				'heading_structure' => $parsed_data['headings'],
				'link_count'        => $parsed_data['link_count'],
				'image_count'       => $parsed_data['image_count'],
				'table_count'       => $parsed_data['table_count'],
				'list_count'        => $parsed_data['list_count'],
				'word_count'        => str_word_count( $sanitized_content ),
				'char_count'        => strlen( $sanitized_content ),
				'language'          => $parsed_data['language'],
				'encoding'          => $parsed_data['encoding'],
			)
		);

		return array(
			'content'  => $sanitized_content,
			'title'    => $title,
			'metadata' => $metadata,
		);
	}

	/**
	 * Parse HTML content using DOM parser
	 *
	 * @param string $html_content Raw HTML content
	 * @return array Parsed content and metadata
	 * @throws Exception If HTML parsing fails
	 */
	private function parse_html_content( $html_content ) {
		// Clean up the HTML first
		$html_content = $this->clean_html( $html_content );

		$dom = new DOMDocument();
		libxml_use_internal_errors( true );

		// Load HTML with encoding detection
		$encoding = $this->detect_html_encoding( $html_content );
		if ( $encoding && $encoding !== 'UTF-8' ) {
			$html_content = mb_convert_encoding( $html_content, 'UTF-8', $encoding );
		}

		$success = $dom->loadHTML( $html_content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		if ( ! $success ) {
			throw new Exception( 'Failed to parse HTML content with DOM parser' );
		}

		$xpath = new DOMXPath( $dom );

		// Extract title
		$title = $this->extract_html_title( $xpath );

		// Extract meta information
		$meta_description = $this->extract_meta_content( $xpath, 'description' );
		$meta_keywords    = $this->extract_meta_content( $xpath, 'keywords' );
		$language         = $this->extract_html_language( $xpath );

		// Extract structured content
		$content_data = $this->extract_structured_content( $xpath );

		// Count various elements
		$link_count  = $xpath->query( '//a[@href]' )->length;
		$image_count = $xpath->query( '//img[@src]' )->length;
		$table_count = $xpath->query( '//table' )->length;
		$list_count  = $xpath->query( '//ul | //ol' )->length;

		return array(
			'content'          => $content_data['content'],
			'title'            => $title,
			'meta_description' => $meta_description,
			'meta_keywords'    => $meta_keywords,
			'headings'         => $content_data['headings'],
			'paragraph_count'  => $content_data['paragraph_count'],
			'link_count'       => $link_count,
			'image_count'      => $image_count,
			'table_count'      => $table_count,
			'list_count'       => $list_count,
			'language'         => $language,
			'encoding'         => $encoding,
		);
	}

	/**
	 * Clean HTML content before parsing
	 *
	 * @param string $html_content Raw HTML
	 * @return string Cleaned HTML
	 */
	private function clean_html( $html_content ) {
		// Remove script and style content
		$html_content = preg_replace( '/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi', '', $html_content );
		$html_content = preg_replace( '/<style\b[^<]*(?:(?!<\/style>)<[^<]*)*<\/style>/mi', '', $html_content );

		// Remove HTML comments
		$html_content = preg_replace( '/<!--.*?-->/s', '', $html_content );

		// Fix common HTML issues
		$html_content = str_replace(
			array( '&nbsp;', '&ldquo;', '&rdquo;', '&lsquo;', '&rsquo;' ),
			array( ' ', '"', '"', "'", "'" ),
			$html_content
		);

		return $html_content;
	}

	/**
	 * Detect HTML encoding
	 *
	 * @param string $html_content HTML content
	 * @return string|null Detected encoding
	 */
	private function detect_html_encoding( $html_content ) {
		// Look for charset in meta tags
		if ( preg_match( '/<meta[^>]+charset=[\'"]*([^\'">]+)/i', $html_content, $matches ) ) {
			return trim( $matches[1] );
		}

		// Look for XML encoding declaration
		if ( preg_match( '/encoding=[\'"]*([^\'">]+)/i', $html_content, $matches ) ) {
			return trim( $matches[1] );
		}

		// Use MB string detection
		if ( function_exists( 'mb_detect_encoding' ) ) {
			$encoding = mb_detect_encoding( $html_content, array( 'UTF-8', 'ISO-8859-1', 'Windows-1252' ), true );
			return $encoding ?: 'UTF-8';
		}

		return 'UTF-8';
	}

	/**
	 * Extract HTML title
	 *
	 * @param DOMXPath $xpath XPath object
	 * @return string Title
	 */
	private function extract_html_title( $xpath ) {
		$title_nodes = $xpath->query( '//title' );
		if ( $title_nodes->length > 0 ) {
			return trim( $title_nodes->item( 0 )->textContent );
		}

		// Try h1 as fallback
		$h1_nodes = $xpath->query( '//h1' );
		if ( $h1_nodes->length > 0 ) {
			return trim( $h1_nodes->item( 0 )->textContent );
		}

		return '';
	}

	/**
	 * Extract meta content
	 *
	 * @param DOMXPath $xpath XPath object
	 * @param string   $name Meta name attribute
	 * @return string Meta content
	 */
	private function extract_meta_content( $xpath, $name ) {
		$meta_nodes = $xpath->query( "//meta[@name='$name']/@content" );
		if ( $meta_nodes->length > 0 ) {
			return trim( $meta_nodes->item( 0 )->value );
		}

		return '';
	}

	/**
	 * Extract HTML language
	 *
	 * @param DOMXPath $xpath XPath object
	 * @return string Language code
	 */
	private function extract_html_language( $xpath ) {
		$lang_nodes = $xpath->query( '//html/@lang' );
		if ( $lang_nodes->length > 0 ) {
			return trim( $lang_nodes->item( 0 )->value );
		}

		return '';
	}

	/**
	 * Extract structured content from HTML
	 *
	 * @param DOMXPath $xpath XPath object
	 * @return array Content and structure data
	 */
	private function extract_structured_content( $xpath ) {
		$content         = '';
		$headings        = array();
		$paragraph_count = 0;

		// Define content extraction order
		$content_selectors = array(
			'main',
			'article',
			'.content',
			'.main-content',
			'#content',
			'.post-content',
			'.entry-content',
			'body',
		);

		$content_container = null;
		foreach ( $content_selectors as $selector ) {
			$nodes = null;
			if ( strpos( $selector, '.' ) === 0 ) {
				// Class selector
				$class = substr( $selector, 1 );
				$nodes = $xpath->query( "//*[@class='$class']" );
			} elseif ( strpos( $selector, '#' ) === 0 ) {
				// ID selector
				$id    = substr( $selector, 1 );
				$nodes = $xpath->query( "//*[@id='$id']" );
			} else {
				// Element selector
				$nodes = $xpath->query( "//$selector" );
			}

			if ( $nodes && $nodes->length > 0 ) {
				$content_container = $nodes->item( 0 );
				break;
			}
		}

		// If no specific content container found, use body
		if ( ! $content_container ) {
			$body_nodes        = $xpath->query( '//body' );
			$content_container = $body_nodes->length > 0 ? $body_nodes->item( 0 ) : $xpath->document;
		}

		// Extract headings with hierarchy
		for ( $level = 1; $level <= 6; $level++ ) {
			$heading_nodes = $xpath->query( ".//h$level", $content_container );
			foreach ( $heading_nodes as $heading ) {
				$heading_text = trim( $heading->textContent );
				if ( ! empty( $heading_text ) ) {
					$headings[] = array(
						'level' => $level,
						'text'  => $heading_text,
					);
					$content   .= str_repeat( '#', $level ) . ' ' . $heading_text . "\n\n";
				}
			}
		}

		// Extract paragraphs
		$paragraph_nodes = $xpath->query( './/p', $content_container );
		foreach ( $paragraph_nodes as $paragraph ) {
			$paragraph_text = trim( $paragraph->textContent );
			if ( ! empty( $paragraph_text ) && strlen( $paragraph_text ) > 10 ) {
				$content .= $paragraph_text . "\n\n";
				++$paragraph_count;
			}
		}

		// Extract list content
		$list_nodes = $xpath->query( './/ul | .//ol', $content_container );
		foreach ( $list_nodes as $list ) {
			$list_items = $xpath->query( './/li', $list );
			foreach ( $list_items as $item ) {
				$item_text = trim( $item->textContent );
				if ( ! empty( $item_text ) ) {
					$content .= '- ' . $item_text . "\n";
				}
			}
			$content .= "\n";
		}

		// Extract table content
		$table_nodes = $xpath->query( './/table', $content_container );
		foreach ( $table_nodes as $table ) {
			$content .= "\n[Table]\n";
			$rows     = $xpath->query( './/tr', $table );
			foreach ( $rows as $row ) {
				$cells    = $xpath->query( './/td | .//th', $row );
				$row_data = array();
				foreach ( $cells as $cell ) {
					$cell_text = trim( $cell->textContent );
					if ( ! empty( $cell_text ) ) {
						$row_data[] = $cell_text;
					}
				}
				if ( ! empty( $row_data ) ) {
					$content .= implode( ' | ', $row_data ) . "\n";
				}
			}
			$content .= "\n";
		}

		// Extract blockquote content
		$quote_nodes = $xpath->query( './/blockquote', $content_container );
		foreach ( $quote_nodes as $quote ) {
			$quote_text = trim( $quote->textContent );
			if ( ! empty( $quote_text ) ) {
				$content .= '> ' . $quote_text . "\n\n";
			}
		}

		// Clean up content
		$content = preg_replace( '/\n{3,}/', "\n\n", $content );
		$content = trim( $content );

		return array(
			'content'         => $content,
			'headings'        => $headings,
			'paragraph_count' => $paragraph_count,
		);
	}
}
