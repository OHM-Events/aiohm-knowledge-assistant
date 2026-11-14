<?php
/**
 * PDF Library Loader for AIOHM Knowledge Assistant
 * Simple implementation for PDF generation using WordPress/PHP built-ins
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIOHM_PDF_Library_Loader {
	private static $instance = null;
	private $pdf_instance    = null;

	/**
	 * Get singleton instance
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Load mPDF library (simplified implementation)
	 */
	public function load_mpdf() {
		// Create our simple PDF instance
		$this->pdf_instance = new AIOHM_Simple_PDF();
		return true;
	}

	/**
	 * Get PDF instance
	 */
	public function get_pdf() {
		return $this->pdf_instance;
	}
}

/**
 * Simple PDF generator class
 */
class AIOHM_Simple_PDF {
	private $content = '';
	private $title   = '';

	/**
	 * Initialize PDF generation with proper WordPress hooks
	 */
	public function __construct() {
		// Enqueue assets using proper WordPress hooks
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
	}

	/**
	 * Enqueue admin assets using WordPress standards
	 */
	public function enqueue_admin_assets( $hook ) {
		// Only load on relevant admin pages
		if ( strpos( $hook, 'aiohm' ) === false ) {
			return;
		}

		wp_enqueue_style(
			'aiohm-pdf-generation',
			AIOHM_KB_PLUGIN_URL . 'assets/css/aiohm-pdf-generation.css',
			array(),
			AIOHM_KB_VERSION,
			'all'
		);

		wp_enqueue_script(
			'aiohm-pdf-generation',
			AIOHM_KB_PLUGIN_URL . 'assets/js/aiohm-pdf-generation.js',
			array(),
			AIOHM_KB_VERSION,
			true
		);
	}

	/**
	 * Enqueue frontend assets using WordPress standards
	 */
	public function enqueue_frontend_assets() {
		// Only enqueue when needed (not globally)
		if ( ! $this->should_load_pdf_assets() ) {
			return;
		}

		wp_enqueue_style(
			'aiohm-pdf-generation',
			AIOHM_KB_PLUGIN_URL . 'assets/css/aiohm-pdf-generation.css',
			array(),
			AIOHM_KB_VERSION,
			'all'
		);

		wp_enqueue_script(
			'aiohm-pdf-generation',
			AIOHM_KB_PLUGIN_URL . 'assets/js/aiohm-pdf-generation.js',
			array(),
			AIOHM_KB_VERSION,
			true
		);
	}

	/**
	 * Check if PDF assets should be loaded
	 */
	private function should_load_pdf_assets() {
		// Load only when PDF functionality is needed
		return is_admin() || has_shortcode( get_post()->post_content ?? '', 'aiohm_chat' );
	}

	/**
	 * Add a page (in our case, just initialize)
	 */
	public function AddPage() {
		// Initialize the HTML content with proper structure
		$this->content = '<html><head><meta charset="UTF-8">';
		$this->content .= '</head><body class="aiohm-pdf-output">';
	}


	/**
	 * Add a chapter title
	 */
	public function ChapterTitle( $title ) {
		$this->content .= '<div class="chapter-title">' . esc_html( $title ) . '</div>';
	}

	/**
	 * Add a message block
	 */
	public function MessageBlock( $type, $message, $timestamp = '' ) {
		if ( $type === 'question' ) {
			$this->content .= '<div class="question">' . esc_html( $message ) . '</div>';
		} elseif ( $type === 'answer' ) {
			$this->content .= '<div class="answer">' . nl2br( esc_html( $message ) ) . '</div>';
		} else {
			$class          = $type === 'user' ? 'message-user' : 'message-assistant';
			$this->content .= '<div class="message-block"><div class="' . $class . '">' . nl2br( esc_html( $message ) ) . '</div></div>';
		}
	}

	/**
	 * Output the PDF
	 */
	public function Output( $filename, $mode = 'D' ) {
		// Close HTML
		$this->content .= '</body></html>';

		// For now, we'll output as HTML that browsers can save as PDF
		// In a production environment, you'd use a proper PDF library like mPDF or TCPDF

		if ( $mode === 'D' ) {
			// Download mode
			header( 'Content-Type: text/html; charset=UTF-8' );
			header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '.html"' );
			header( 'Cache-Control: no-cache, must-revalidate' );
			header( 'Expires: Sat, 26 Jul 1997 05:00:00 GMT' );

			echo wp_kses_post( $this->content );
			exit;
		}

		return $this->content;
	}
}
