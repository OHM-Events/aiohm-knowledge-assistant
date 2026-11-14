<?php
/**
 * WordPress admin settings page controller
 * Manages plugin configuration interface and AJAX handlers
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIOHM_KB_Settings_Page {
	private static $instance = null;

	public static function init() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		add_action( 'admin_menu', array( self::$instance, 'register_admin_pages' ) );
		add_action( 'admin_init', array( self::$instance, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( self::$instance, 'enqueue_admin_scripts' ) );

		// Brand Soul AJAX handlers
		add_action( 'wp_ajax_aiohm_save_brand_soul', array( self::$instance, 'ajax_save_brand_soul' ) );
		add_action( 'wp_ajax_aiohm_add_brand_soul_to_kb', array( self::$instance, 'ajax_add_brand_soul_to_kb' ) );

		// AJAX handler for Muse mode settings
		add_action( 'wp_ajax_aiohm_save_setting', array( self::$instance, 'ajax_save_setting' ) );
		add_action( 'wp_ajax_aiohm_save_settings', array( self::$instance, 'ajax_save_settings' ) );

		// Muse mode preview chat handler
		add_action( 'wp_ajax_aiohm_muse_preview_chat', array( self::$instance, 'ajax_muse_preview_chat' ) );

		// Pro Features AJAX handlers
		add_action( 'wp_ajax_aiohm_save_pro_features', array( self::$instance, 'ajax_save_pro_features' ) );
		add_action( 'wp_ajax_aiohm_test_ollama_connection', array( self::$instance, 'ajax_test_ollama_connection' ) );

		// Brand Soul PDF download handler
		// Use admin_post for handling file downloads to avoid interfering with other admin processes.
		add_action( 'admin_post_aiohm_download_brand_soul_pdf', array( self::$instance, 'handle_brand_soul_pdf_download' ) );
		add_action( 'admin_post_aiohm_download_brand_soul_md', array( self::$instance, 'handle_brand_soul_md_download' ) );
	}

	private function include_header() {
		include_once AIOHM_KB_PLUGIN_DIR . 'templates/partials/header.php';
	}

	private function include_footer() {
		include_once AIOHM_KB_PLUGIN_DIR . 'templates/partials/footer.php';
	}

	/**
	 * Get the appropriate menu icon based on admin color scheme
	 *
	 * @return string Base64 encoded SVG data URI
	 */
	private function get_menu_icon() {
		// Detect admin color scheme for dynamic theming
		$admin_color   = get_user_option( 'admin_color' );
		$is_dark_theme = in_array( $admin_color, array( 'midnight', 'blue', 'coffee', 'ectoplasm', 'ocean' ) );

		// Use the correct OHM logo SVG files
		$logo_path = $is_dark_theme
			? AIOHM_KB_PLUGIN_DIR . 'assets/images/OHM_logo-white.svg'
			: AIOHM_KB_PLUGIN_DIR . 'assets/images/OHM_logo-black.svg';

		if ( file_exists( $logo_path ) ) {
			// Load and optimize the OHM logo for menu use using WP_Filesystem
			global $wp_filesystem;
			if ( empty( $wp_filesystem ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				WP_Filesystem();
			}
			$svg_content = $wp_filesystem->get_contents( $logo_path );
			if ( $svg_content !== false ) {
				// Return the raw SVG content directly
				return 'data:image/svg+xml;base64,' . base64_encode( $svg_content );
			}
		}

		// Fallback - simple OHM-inspired icon if logo files are not available
		if ( $is_dark_theme ) {
			// Light icon for dark themes
			$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none">
                <circle cx="10" cy="10" r="7" fill="none" stroke="rgba(255,255,255,0.9)" stroke-width="1.5"/>
                <circle cx="10" cy="10" r="4" fill="none" stroke="rgba(255,255,255,0.9)" stroke-width="1"/>
                <circle cx="10" cy="10" r="1.5" fill="rgba(255,255,255,0.9)"/>
                <path d="M6 6l8 8M14 6l-8 8" stroke="rgba(255,255,255,0.6)" stroke-width="0.5"/>
            </svg>';
		} else {
			// Dark icon for light themes
			$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none">
                <circle cx="10" cy="10" r="7" fill="none" stroke="#2c3e50" stroke-width="1.5"/>
                <circle cx="10" cy="10" r="4" fill="none" stroke="#2c3e50" stroke-width="1"/>
                <circle cx="10" cy="10" r="1.5" fill="#2c3e50"/>
                <path d="M6 6l8 8M14 6l-8 8" stroke="rgba(44,62,80,0.4)" stroke-width="0.5"/>
            </svg>';
		}

		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	/**
	 * Optimize the full OHM logo for use as a 20x20 menu icon
	 *
	 * @param string $svg_content Original SVG content
	 * @param bool   $is_dark_theme Whether we're using dark theme
	 * @return string Optimized SVG
	 */
	private function optimize_logo_for_menu( $svg_content, $is_dark_theme ) {
		// Create a simplified version of the actual OHM mandala logo for the menu
		$fill_color   = $is_dark_theme ? 'rgba(255,255,255,0.85)' : '#000000';
		$stroke_color = $is_dark_theme ? 'rgba(255,255,255,0.85)' : '#000000';

		$optimized_svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none">
            <!-- Central circle -->
            <circle cx="10" cy="10" r="2.5" fill="white" stroke="' . $stroke_color . '" stroke-width="0.6"/>
            
            <!-- 8 simplified petal shapes -->
            <path d="M10 7.5 Q8.5 5 10 3.5 Q11.5 5 10 7.5" fill="' . $fill_color . '" stroke="' . $stroke_color . '" stroke-width="0.3"/>
            <path d="M12.5 10 Q15 8.5 16.5 10 Q15 11.5 12.5 10" fill="' . $fill_color . '" stroke="' . $stroke_color . '" stroke-width="0.3"/>
            <path d="M10 12.5 Q11.5 15 10 16.5 Q8.5 15 10 12.5" fill="' . $fill_color . '" stroke="' . $stroke_color . '" stroke-width="0.3"/>
            <path d="M7.5 10 Q5 11.5 3.5 10 Q5 8.5 7.5 10" fill="' . $fill_color . '" stroke="' . $stroke_color . '" stroke-width="0.3"/>
            <path d="M11.8 8.2 Q14.5 5.5 16.2 7.2 Q14.5 9.9 11.8 8.2" fill="' . $fill_color . '" stroke="' . $stroke_color . '" stroke-width="0.3"/>
            <path d="M11.8 11.8 Q14.5 14.5 12.8 16.2 Q10.1 14.5 11.8 11.8" fill="' . $fill_color . '" stroke="' . $stroke_color . '" stroke-width="0.3"/>
            <path d="M8.2 11.8 Q5.5 14.5 3.8 12.8 Q5.5 10.1 8.2 11.8" fill="' . $fill_color . '" stroke="' . $stroke_color . '" stroke-width="0.3"/>
            <path d="M8.2 8.2 Q5.5 5.5 7.2 3.8 Q9.9 5.5 8.2 8.2" fill="' . $fill_color . '" stroke="' . $stroke_color . '" stroke-width="0.3"/>
            
            <!-- Small dots at the tips -->
            <circle cx="10" cy="3" r="0.6" fill="' . $fill_color . '"/>
            <circle cx="17" cy="10" r="0.6" fill="' . $fill_color . '"/>
            <circle cx="10" cy="17" r="0.6" fill="' . $fill_color . '"/>
            <circle cx="3" cy="10" r="0.6" fill="' . $fill_color . '"/>
            <circle cx="14.5" cy="5.5" r="0.6" fill="' . $fill_color . '"/>
            <circle cx="14.5" cy="14.5" r="0.6" fill="' . $fill_color . '"/>
            <circle cx="5.5" cy="14.5" r="0.6" fill="' . $fill_color . '"/>
            <circle cx="5.5" cy="5.5" r="0.6" fill="' . $fill_color . '"/>
        </svg>';

		return $optimized_svg;
	}

	public function register_admin_pages() {
		// Main Menu Page - using dynamic OHM SVG icon
		add_menu_page( 'AIOHM Assistant', 'AIOHM', 'manage_options', 'aiohm-knowledge-assistant', array( $this, 'render_dashboard_page' ), $this->get_menu_icon(), 2 );

		// Dashboard
		add_submenu_page( 'aiohm-knowledge-assistant', 'Dashboard', 'Dashboard', 'manage_options', 'aiohm-knowledge-assistant', array( $this, 'render_dashboard_page' ) );

		// Settings with submenu
		add_submenu_page( 'aiohm-knowledge-assistant', 'Settings', 'Settings', 'manage_options', 'aiohm-settings', array( $this, 'render_form_settings_page' ) );

		add_submenu_page( 'aiohm-knowledge-assistant', 'Scan Content', 'Scan Content', 'manage_options', 'aiohm-scan-content', array( $this, 'render_scan_page' ) );
		add_submenu_page( 'aiohm-knowledge-assistant', 'Manage Knowledge Base', 'Manage KB', 'manage_options', 'aiohm-manage-kb', array( $this, 'render_manage_kb_page' ) );

		// AI Brand Core
		add_submenu_page( 'aiohm-knowledge-assistant', 'AI Brand Core', 'AI Brand Core', 'manage_options', 'aiohm-brand-soul', array( $this, 'render_brand_soul_page' ) );

		// Mirror Mode and Muse Mode (always available to admins)
		add_submenu_page( 'aiohm-knowledge-assistant', 'Mirror Mode', 'Mirror Mode', 'manage_options', 'aiohm-mirror-mode', array( $this, 'render_mirror_mode_page' ) );
		add_submenu_page( 'aiohm-knowledge-assistant', 'Muse Mode', 'Muse Mode', 'manage_options', 'aiohm-muse-mode', array( $this, 'render_muse_mode_page' ) );

		// Pro Features tab (shows upgrade notice in FREE, full features in PRO)
		add_submenu_page( 'aiohm-knowledge-assistant', 'Pro Features', 'Pro Features', 'manage_options', 'aiohm-pro-features', array( $this, 'render_pro_features_page' ) );

		// Get Help page
		add_submenu_page( 'aiohm-knowledge-assistant', 'Get Help', 'Get Help', 'manage_options', 'aiohm-get-help', array( $this, 'render_help_page' ) );
	}

	public function enqueue_admin_scripts( $hook ) {

		$current_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

		// Load global admin styles on all AIOHM pages
		if ( strpos( $hook, 'aiohm-' ) !== false ) {
			wp_enqueue_style( 'aiohm-admin-global-styles', AIOHM_KB_PLUGIN_URL . 'assets/css/aiohm-chat.css', array(), AIOHM_KB_VERSION );
			wp_enqueue_style( 'aiohm-admin-header-styles', AIOHM_KB_PLUGIN_URL . 'assets/css/aiohm-admin-header.css', array(), AIOHM_KB_VERSION );
		}

		$mirror_mode_hook        = 'aiohm_page_aiohm-mirror-mode';
		$muse_mode_hook          = 'aiohm_page_aiohm-muse-mode';
		$settings_hook           = 'aiohm_page_aiohm-settings';
		$license_hook            = 'aiohm_page_aiohm-license';
		$dashboard_hook          = 'aiohm_page_aiohm-knowledge-assistant';
		$toplevel_dashboard_hook = 'toplevel_page_aiohm-knowledge-assistant';
		$help_hook               = 'aiohm_page_aiohm-get-help';
		$scan_hook               = 'aiohm_page_aiohm-scan-content';
		$manage_kb_hook          = 'aiohm_page_aiohm-manage-kb';
		$brand_soul_hook         = 'aiohm_page_aiohm-brand-soul';
		$pro_features_hook       = 'aiohm_page_aiohm-pro-features';

		// Mirror Mode page - separate CSS and JS files
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Safe $_GET check for admin page identification
		if ( $hook === $mirror_mode_hook || ( isset( $_GET['page'] ) && sanitize_text_field( wp_unslash( $_GET['page'] ) ) === 'aiohm-mirror-mode' ) ) {
			wp_enqueue_style(
				'aiohm-admin-mirror-mode-style',
				AIOHM_KB_PLUGIN_URL . 'assets/css/aiohm-admin-mirror-mode.css',
				array(),
				AIOHM_KB_VERSION
			);
		}

		// Settings page - separate CSS and JS files
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Safe $_GET check for admin page identification  
		if ( $hook === $settings_hook || 'aiohm-settings' === $current_page ) {
			wp_enqueue_style(
				'aiohm-admin-settings-style',
				AIOHM_KB_PLUGIN_URL . 'assets/css/aiohm-admin-settings.css',
				array(),
				filemtime( AIOHM_KB_PLUGIN_DIR . 'assets/css/aiohm-admin-settings.css' )
			);

			wp_enqueue_style(
				'aiohm-provider-icons-style',
				AIOHM_KB_PLUGIN_URL . 'assets/css/aiohm-provider-icons.css',
				array(),
				AIOHM_KB_VERSION
			);

				wp_enqueue_script(
					'aiohm-provider-selection',
					AIOHM_KB_PLUGIN_URL . 'assets/js/aiohm-provider-selection.js',
					array( 'jquery' ),
					filemtime( AIOHM_KB_PLUGIN_DIR . 'assets/js/aiohm-provider-selection.js' ),
					true
				);
		}

		// Shared JavaScript for Mirror Mode and Settings pages
		if ( $hook === $mirror_mode_hook || $hook === $settings_hook ) {

			wp_enqueue_script(
				'aiohm-admin-modes-script',
				AIOHM_KB_PLUGIN_URL . 'assets/js/aiohm-admin-modes.js',
				array( 'jquery' ),
				AIOHM_KB_VERSION,
				true // Load in footer
			);

			// Prepare and localize data to pass from PHP to our JavaScript file
			$localized_data = array(
				'ajax_url'  => admin_url( 'admin-ajax.php' ),
				'pluginUrl' => AIOHM_KB_PLUGIN_URL,
			);

			if ( $hook === $mirror_mode_hook ) {
				wp_enqueue_media(); // Needed for the media uploader
				$localized_data['mode']           = 'mirror';
				$localized_data['formId']         = 'mirror-mode-settings-form';
				$localized_data['saveButtonId']   = 'save-mirror-mode-settings';
				$localized_data['saveAction']     = 'aiohm_save_mirror_mode_settings';
				$localized_data['testChatAction'] = 'aiohm_test_mirror_mode_chat';
				$localized_data['nonceFieldId']   = 'aiohm_mirror_mode_nonce_field';
				$localized_data['defaultPrompt']  = "You are the official AI Knowledge Assistant for \"%site_name%\".\n\nYour core mission is to embody our brand's tagline: \"%site_tagline%\".\n\nYou are to act as a thoughtful and emotionally intelligent guide for all website visitors, reflecting the unique voice of the brand. You should be aware that today is %day_of_week%, %current_date%.\n\nCore Instructions:\n\n1. Primary Directive:\n   Your primary goal is to answer the user's question by grounding your response in the context provided below. This context is your main source of truth.\n\n2. Tone & Personality:\n   • Speak with emotional clarity, not robotic formality\n   • Sound like a thoughtful assistant, not a sales rep\n   • Be concise, but not curt — useful, but never cold\n   • Your purpose is to express with presence, not persuasion\n\n3. Formatting Rules:\n   • Use only basic HTML tags for clarity (like <strong> or <em> if needed). Do not use Markdown\n   • Never end your response with a question like \"Do you need help with anything else?\"\n\n4. Fallback Response (Crucial):\n   If the provided context does not contain enough information to answer the user's question, you MUST respond with this exact phrase: \"Hmm… I don't want to guess here. This might need a human's wisdom. You can connect with the person behind this site on the contact page. They'll know exactly how to help.\"\n\nPrimary Context for Answering the User's Question:\n{context}";
			}

			if ( $hook === $settings_hook ) {
				$localized_data['mode']     = 'settings';
				$localized_data['ajax_url'] = admin_url( 'admin-ajax.php' );
				$localized_data['nonce']    = wp_create_nonce( 'aiohm_admin_nonce' );
			}

			wp_localize_script( 'aiohm-admin-modes-script', 'aiohm_admin_modes_data', $localized_data );
		}

		// Enqueue license page specific styles and scripts
		if ( $hook === $license_hook ) {
			wp_enqueue_style(
				'aiohm-admin-license-style',
				AIOHM_KB_PLUGIN_URL . 'assets/css/aiohm-admin-license.css',
				array(),
				AIOHM_KB_VERSION
			);

			wp_enqueue_script(
				'aiohm-admin-license',
				AIOHM_KB_PLUGIN_URL . 'assets/js/aiohm-admin-license.js',
				array( 'jquery' ),
				AIOHM_KB_VERSION,
				true
			);

			wp_localize_script(
				'aiohm-admin-license',
				'aiohm_license_ajax',
				array(
					'nonce' => wp_create_nonce( 'aiohm_license_verification' ),
				)
			);
		}

		// Enqueue dashboard page specific styles
		if ( $hook === $dashboard_hook || $hook === $toplevel_dashboard_hook ) {
			wp_enqueue_style(
				'aiohm-admin-dashboard-style',
				AIOHM_KB_PLUGIN_URL . 'assets/css/aiohm-admin-dashboard.css',
				array(),
				AIOHM_KB_VERSION
			);
		}

		// Enqueue help page specific styles and scripts
		if ( $hook === $help_hook ) {
			wp_enqueue_style(
				'aiohm-admin-help-style',
				AIOHM_KB_PLUGIN_URL . 'assets/css/aiohm-admin-help.css',
				array(),
				AIOHM_KB_VERSION
			);

			wp_enqueue_script(
				'aiohm-admin-help-script',
				AIOHM_KB_PLUGIN_URL . 'assets/js/aiohm-admin-help.js',
				array( 'jquery' ),
				AIOHM_KB_VERSION,
				true
			);

			// Localize script for AJAX
			wp_localize_script(
				'aiohm-admin-help-script',
				'aiohm_admin_help_ajax',
				array(
					'ajax_url' => admin_url( 'admin-ajax.php' ),
					'nonce'    => wp_create_nonce( 'aiohm_admin_nonce' ),
				)
			);
		}

		// Enqueue muse mode specific styles and scripts (completely separate from admin-modes)
		if ( $hook === $muse_mode_hook ) {
			wp_enqueue_style(
				'aiohm-admin-modes-style',
				AIOHM_KB_PLUGIN_URL . 'assets/css/aiohm-admin-modes.css',
				array(),
				AIOHM_KB_VERSION
			);

			wp_enqueue_script(
				'aiohm-admin-muse-mode',
				AIOHM_KB_PLUGIN_URL . 'assets/js/aiohm-admin-muse-mode.js',
				array( 'jquery' ),
				AIOHM_KB_VERSION,
				true
			);

			wp_localize_script(
				'aiohm-admin-muse-mode',
				'aiohm_muse_mode_ajax',
				array(
					'ajax_url'   => admin_url( 'admin-ajax.php' ),
					'plugin_url' => AIOHM_KB_PLUGIN_URL,
					'nonce'      => wp_create_nonce( 'aiohm_admin_nonce' ),
				)
			);
		}

		// Enqueue scan website page specific styles and scripts
		if ( $hook === $scan_hook ) {
			wp_enqueue_style(
				'aiohm-admin-scan-website-style',
				AIOHM_KB_PLUGIN_URL . 'assets/css/aiohm-admin-scan-website.css',
				array(),
				AIOHM_KB_VERSION
			);

			wp_enqueue_script(
				'aiohm-admin-scan-website',
				AIOHM_KB_PLUGIN_URL . 'assets/js/aiohm-admin-scan-website.js',
				array( 'jquery' ),
				AIOHM_KB_VERSION,
				true
			);

			wp_localize_script(
				'aiohm-admin-scan-website',
				'aiohm_scan_website_ajax',
				array(
					'ajax_url'   => admin_url( 'admin-ajax.php' ),
					'nonce'      => wp_create_nonce( 'aiohm_admin_nonce' ),
					'plugin_url' => AIOHM_KB_PLUGIN_URL,
				)
			);
		}

		// Enqueue manage KB page specific styles and scripts
		if ( $hook === $manage_kb_hook ) {
			wp_enqueue_style(
				'aiohm-admin-manage-kb-style',
				AIOHM_KB_PLUGIN_URL . 'assets/css/aiohm-admin-manage-kb.css',
				array(),
				AIOHM_KB_VERSION
			);

			wp_enqueue_script(
				'aiohm-admin-manage-kb',
				AIOHM_KB_PLUGIN_URL . 'assets/js/aiohm-admin-manage-kb.js',
				array( 'jquery' ),
				AIOHM_KB_VERSION,
				true
			);

			wp_localize_script(
				'aiohm-admin-manage-kb',
				'aiohm_manage_kb_ajax',
				array(
					'ajax_url' => admin_url( 'admin-ajax.php' ),
					'nonce'    => wp_create_nonce( 'aiohm_admin_nonce' ),
				)
			);

			wp_localize_script(
				'aiohm-admin-manage-kb',
				'aiohm_admin',
				array(
					'ajax_url' => admin_url( 'admin-ajax.php' ),
					'nonce'    => wp_create_nonce( 'aiohm_admin_nonce' ),
				)
			);

		}

		// Enqueue brand soul page specific styles and scripts
		if ( $hook === $brand_soul_hook ) {
			wp_enqueue_style(
				'aiohm-admin-brand-soul-style',
				AIOHM_KB_PLUGIN_URL . 'assets/css/aiohm-admin-brand-soul.css',
				array(),
				AIOHM_KB_VERSION
			);

			wp_enqueue_script(
				'aiohm-admin-brand-soul',
				AIOHM_KB_PLUGIN_URL . 'assets/js/aiohm-admin-brand-soul.js',
				array( 'jquery' ),
				AIOHM_KB_VERSION,
				true
			);

            wp_localize_script(
                'aiohm-admin-brand-soul',
                'aiohm_brand_soul_ajax',
                array(
                    'ajax_url'                   => admin_url( 'admin-ajax.php' ),
                    'nonce'                      => wp_create_nonce( 'aiohm_brand_soul_nonce' ),
                    'download_pdf_url'           => add_query_arg(
                        array(
                            'action' => 'aiohm_download_brand_soul_md',
                            'nonce'  => wp_create_nonce( 'aiohm_brand_soul_download' ),
                        ),
                        admin_url( 'admin-post.php' )
                    ),
                    'save_answers_text'          => __( 'Save Answers', 'aiohm-knowledge-assistant' ),
                    'add_to_kb_text'             => __( 'Add to Knowledge Base', 'aiohm-knowledge-assistant' ),
                    'download_pdf_text'          => __( 'Download', 'aiohm-knowledge-assistant' ),
                    'saving_text'                => __( 'Saving...', 'aiohm-knowledge-assistant' ),
                    'adding_text'                => __( 'Adding to KB...', 'aiohm-knowledge-assistant' ),
                    'save_success_text'          => __( 'Brand soul answers saved successfully!', 'aiohm-knowledge-assistant' ),
                    'progress_save_success_text' => __( 'Progress saved!', 'aiohm-knowledge-assistant' ),
                    'add_to_kb_success_text'     => __( 'Added to your private knowledge base.', 'aiohm-knowledge-assistant' ),
                    'add_to_kb_error_text'       => __( 'Failed to add to KB.', 'aiohm-knowledge-assistant' ),
                    'server_error_text'          => __( 'A server error occurred.', 'aiohm-knowledge-assistant' ),
                )
            );
		}

		// Enqueue settings backup page specific styles and scripts
		if ( $hook === 'aiohm_page_aiohm-settings-backup' ) {
			wp_enqueue_style(
				'aiohm-admin-settings-backup-style',
				AIOHM_KB_PLUGIN_URL . 'assets/css/aiohm-admin-settings-backup.css',
				array(),
				AIOHM_KB_VERSION
			);

			wp_enqueue_script(
				'aiohm-admin-settings-backup',
				AIOHM_KB_PLUGIN_URL . 'assets/js/aiohm-admin-settings-backup.js',
				array( 'jquery' ),
				AIOHM_KB_VERSION,
				true
			);
		}

		// Enqueue main settings page specific styles and scripts
		if ( $hook === $settings_hook ) {
			wp_enqueue_style(
				'aiohm-admin-settings-style',
				AIOHM_KB_PLUGIN_URL . 'assets/css/aiohm-admin-settings.css',
				array(),
				filemtime( AIOHM_KB_PLUGIN_DIR . 'assets/css/aiohm-admin-settings.css' )
			);

			wp_enqueue_script(
				'aiohm-admin-settings',
				AIOHM_KB_PLUGIN_URL . 'assets/js/aiohm-admin-settings.js',
				array( 'jquery' ),
				filemtime( AIOHM_KB_PLUGIN_DIR . 'assets/js/aiohm-admin-settings.js' ),
				true
			);

			wp_localize_script(
				'aiohm-admin-settings',
				'aiohm_admin_settings_ajax',
				array(
					'ajax_url' => admin_url( 'admin-ajax.php' ),
					'nonce'    => wp_create_nonce( 'aiohm_admin_nonce' ),
				)
			);
		}

		// Brand Soul page scripts
		if ( $hook == 'aiohm_page_aiohm-brand-soul' ) {
			wp_enqueue_style(
				'aiohm-admin-brand-soul',
				AIOHM_KB_PLUGIN_URL . 'assets/css/aiohm-admin-brand-soul.css',
				array(),
				AIOHM_KB_VERSION
			);

			wp_enqueue_script(
				'aiohm-admin-brand-soul',
				AIOHM_KB_PLUGIN_URL . 'assets/js/aiohm-admin-brand-soul.js',
				array( 'jquery' ),
				AIOHM_KB_VERSION,
				true
			);

            wp_localize_script(
                'aiohm-admin-brand-soul',
                'aiohm_brand_soul_ajax',
                array(
                    'ajax_url'                   => admin_url( 'admin-ajax.php' ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                    'nonce'                      => wp_create_nonce( 'aiohm_brand_soul_nonce' ),
                    'download_pdf_url'           => add_query_arg(
                        array(
                            'action' => 'aiohm_download_brand_soul_md',
                            'nonce'  => wp_create_nonce( 'aiohm_brand_soul_download' ),
                        ),
                        admin_url( 'admin-post.php' )
                    ),
					'save_answers_text'          => esc_html__( 'Save Answers', 'aiohm-knowledge-assistant' ),
					'add_to_kb_text'             => esc_html__( 'Add to Knowledge Base', 'aiohm-knowledge-assistant' ),
					'download_pdf_text'          => esc_html__( 'Download', 'aiohm-knowledge-assistant' ),
					'saving_text'                => esc_html__( 'Saving...', 'aiohm-knowledge-assistant' ),
					'adding_text'                => esc_html__( 'Adding to KB...', 'aiohm-knowledge-assistant' ),
					'save_success_text'          => esc_html__( 'Brand soul answers saved successfully!', 'aiohm-knowledge-assistant' ),
					'progress_save_success_text' => esc_html__( 'Progress saved!', 'aiohm-knowledge-assistant' ),
					'save_error_text'            => esc_html__( 'Failed to save answers. Please try again.', 'aiohm-knowledge-assistant' ),
					'add_to_kb_success_text'     => esc_html__( 'Brand soul successfully added to your private knowledge base!', 'aiohm-knowledge-assistant' ),
					'add_to_kb_error_text'       => esc_html__( 'Failed to add to knowledge base. Please try again.', 'aiohm-knowledge-assistant' ),
					'server_error_text'          => esc_html__( 'Server error. Please try again later.', 'aiohm-knowledge-assistant' ),
				)
			);
		}

		// Pro Features page scripts
		if ( $hook === $pro_features_hook ) {
			wp_enqueue_style(
				'aiohm-admin-settings-style',
				AIOHM_KB_PLUGIN_URL . 'assets/css/aiohm-admin-settings.css',
				array(),
				AIOHM_KB_VERSION
			);
		}
	}

	/**
	 * Check if pro license is active
	 * @return bool True if pro license is active
	 */
	private function is_pro_license_active() {
		return true;
	}

	/**
	 * Check if this is a white label license
	 * @return bool True if white label mode is active
	 */
	private function is_white_label_license() {
		return (bool) apply_filters( 'aiohm_kb_is_white_label', false );
	}

	/**
	 * Render Pro Features page with Ollama and MCP settings
	 * Shows upgrade notice in FREE version, full features in PRO version
	 */
	public function render_pro_features_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'aiohm-knowledge-assistant' ) );
		}
		$this->include_header();
		include AIOHM_KB_PLUGIN_DIR . 'templates/admin-pro-features.php';
		$this->include_footer();
	}


	public function render_dashboard_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'aiohm-knowledge-assistant' ) );
		}
		$this->include_header();
		include AIOHM_KB_PLUGIN_DIR . 'templates/admin-dashboard.php';
		$this->include_footer();
	}

	public function render_form_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'aiohm-knowledge-assistant' ) );
		}

		$this->include_header();
		include AIOHM_KB_PLUGIN_DIR . 'templates/admin-settings.php';
		$this->include_footer();
	}

	public function render_scan_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'aiohm-knowledge-assistant' ) );
		}
		$site_crawler    = new AIOHM_KB_Site_Crawler();
		$uploads_crawler = new AIOHM_KB_Uploads_Crawler();
		$site_stats      = $site_crawler->get_scan_stats();
		$uploads_stats   = $uploads_crawler->get_stats();
		$this->include_header();
		include AIOHM_KB_PLUGIN_DIR . 'templates/admin-scan-website.php';
		$this->include_footer();
	}

	public function render_manage_kb_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'aiohm-knowledge-assistant' ) );
		}
		$this->include_header();
		$manager = new AIOHM_KB_Manager();
		$manager->display_page();
		$this->include_footer();
	}

	public function render_brand_soul_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'aiohm-knowledge-assistant' ) );
		}
		$this->include_header();
		include AIOHM_KB_PLUGIN_DIR . 'templates/admin-brand-soul.php';
		$this->include_footer();
	}

	public function render_mirror_mode_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'aiohm-knowledge-assistant' ) );
		}
		$this->include_header();
		include AIOHM_KB_PLUGIN_DIR . 'templates/admin-mirror-mode.php';
		$this->include_footer();
	}

	public function render_muse_mode_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'aiohm-knowledge-assistant' ) );
		}
		$this->include_header();
		include AIOHM_KB_PLUGIN_DIR . 'templates/admin-muse-mode.php';
		$this->include_footer();
	}

	public function render_help_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'aiohm-knowledge-assistant' ) );
		}
		$this->include_header();
		include AIOHM_KB_PLUGIN_DIR . 'templates/admin-help.php';
		$this->include_footer();
	}


	public function register_settings() {
		register_setting( 'aiohm_kb_settings', 'aiohm_kb_settings', array( $this, 'sanitize_settings' ) );
	}

	/**
	 * Handle AJAX request to save brand soul answers
	 */
	public function ajax_save_brand_soul() {
		// Verify nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'aiohm_brand_soul_nonce' ) ) {
			wp_send_json_error( array( 'message' => 'Security check failed' ) );
			return;
		}

		// Check user permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
			return;
		}

		// Get answers directly from POST and sanitize them
		$answers = isset( $_POST['answers'] ) ? map_deep( wp_unslash( $_POST['answers'] ), 'sanitize_textarea_field' ) : array();

		if ( empty( $answers ) ) {
			wp_send_json_error( array( 'message' => 'No answers provided.' ) );
			return;
		}

		// Save to user meta
		$user_id = get_current_user_id();
		$saved   = update_user_meta( $user_id, 'aiohm_brand_soul_answers', $answers );

		if ( $saved !== false ) {
			wp_send_json_success( array( 'message' => __( 'Brand soul answers saved successfully!', 'aiohm-knowledge-assistant' ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to save answers. Please try again.', 'aiohm-knowledge-assistant' ) ) );
		}
	}

	/**
	 * Handle AJAX request to add brand soul to knowledge base
	 */
	public function ajax_add_brand_soul_to_kb() {
		// Verify nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'aiohm_brand_soul_nonce' ) ) {
			wp_send_json_error( array( 'message' => 'Security check failed' ) );
			return;
		}

		// Check user permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
			return;
		}

		try {
			// Get answers directly from POST and sanitize them
			$answers = isset( $_POST['answers'] ) ? map_deep( wp_unslash( $_POST['answers'] ), 'sanitize_textarea_field' ) : array();

			if ( empty( $answers ) ) {
				wp_send_json_error( array( 'message' => 'No answers to add to knowledge base.' ) );
				return;
			}

			// Format answers for knowledge base
			$content  = "# Brand Soul Questionnaire Answers\n\n";
			$content .= 'Generated on: ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n\n";

			// Define question categories
			$questions = array(
				'✨ Foundation' => array(
					'foundation_1' => "What's the deeper purpose behind your brand — beyond profit?",
					'foundation_2' => 'What life experiences shaped this work you now do?',
					'foundation_3' => 'Who were you before this calling emerged?',
					'foundation_4' => 'If your brand had a soul story, how would you tell it?',
					'foundation_5' => "What's one transformation you've witnessed that reminds you why you do this?",
				),
				'🌀 Energy'     => array(
					'energy_1' => 'What 3 words describe the emotional tone of your brand voice?',
					'energy_2' => 'How do you want your audience to feel after encountering your message?',
					'energy_3' => 'What do you not want to sound like?',
					'energy_4' => 'Do you prefer poetic, punchy, playful, or professional language?',
					'energy_5' => 'Share a quote, phrase, or piece of content that feels like you.',
				),
				'🎨 Expression' => array(
					'expression_1' => "What are your brand's primary colors (and any specific hex codes)?",
					'expression_2' => 'What font(s) do you use — or wish to use — for headers and body text?',
					'expression_3' => 'Is there a visual theme (earthy, cosmic, minimalist, ornate) that matches your brand essence?',
					'expression_4' => 'Are there any logos, patterns, or symbols that hold meaning for your brand?',
					'expression_5' => 'What offerings are you currently sharing with the world — and how are they priced or exchanged?',
				),
				'🚀 Direction'  => array(
					'direction_1' => "What's your current main offer or project you want support with?",
					'direction_2' => 'Who is your dream client? Describe them with emotion and detail.',
					'direction_3' => 'What are 3 key goals you have for the next 6 months?',
					'direction_4' => 'Where do you feel stuck, overwhelmed, or unsure — and where would you love AI support?',
					'direction_5' => 'If this AI assistant could speak your soul fluently, what would you want it to never forget?',
				),
			);

			foreach ( $questions as $section_title => $section_questions ) {
				$content .= '## ' . $section_title . "\n\n";
				foreach ( $section_questions as $key => $question ) {
					if ( ! empty( $answers[ $key ] ) ) {
						$content .= '### ' . $question . "\n\n";
						$content .= $answers[ $key ] . "\n\n";
					}
				}
			}

			// Add to knowledge base using RAG engine
			$rag_engine = new AIOHM_KB_RAG_Engine();
			$user_id    = get_current_user_id();

			$result = $rag_engine->add_entry(
				$content,
				'brand_soul',
				'Brand Soul Questionnaire',
				array(
					'source_type'  => 'brand_soul',
					'user_id'      => $user_id,
					'created_date' => gmdate( 'Y-m-d H:i:s' ),
				),
				$user_id,
				0
			);

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => 'Failed to add to knowledge base: ' . $result->get_error_message() ) );
			} else {
				wp_send_json_success( array( 'message' => __( 'Brand soul successfully added to your private knowledge base!', 'aiohm-knowledge-assistant' ) ) );
			}
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => 'Error: ' . $e->getMessage() ) );
		}
	}

	/**
	 * Generate and download brand soul PDF
	 */
	public function download_brand_soul_pdf() {
		// Check user permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions' );
		}

		// Verify nonce
		if ( ! isset( $_GET['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['nonce'] ) ), 'aiohm_brand_soul_pdf' ) ) {
			wp_die( 'Security check failed' );
		}

		try {
			$user_id = get_current_user_id();
			$answers = get_user_meta( $user_id, 'aiohm_brand_soul_answers', true );

			if ( empty( $answers ) ) {
				wp_die( 'No brand soul answers found. Please complete the questionnaire first.' );
			}

			// Initialize PDF loader
			$pdf_loader = AIOHM_PDF_Library_Loader::get_instance();
			if ( ! $pdf_loader->load_mpdf() ) {
				wp_die( esc_html__( 'PDF generation is not available.', 'aiohm-knowledge-assistant' ) );
			}

			// Create PDF instance
			$pdf = $pdf_loader->get_pdf();

			// Add title
			$pdf->ChapterTitle( 'Brand Soul Questionnaire' );

			// Define questions for PDF
			$questions = array(
				'✨ Foundation' => array(
					'foundation_1' => "What's the deeper purpose behind your brand — beyond profit?",
					'foundation_2' => 'What life experiences shaped this work you now do?',
					'foundation_3' => 'Who were you before this calling emerged?',
					'foundation_4' => 'If your brand had a soul story, how would you tell it?',
					'foundation_5' => "What's one transformation you've witnessed that reminds you why you do this?",
				),
				'🌀 Energy'     => array(
					'energy_1' => 'What 3 words describe the emotional tone of your brand voice?',
					'energy_2' => 'How do you want your audience to feel after encountering your message?',
					'energy_3' => 'What do you not want to sound like?',
					'energy_4' => 'Do you prefer poetic, punchy, playful, or professional language?',
					'energy_5' => 'Share a quote, phrase, or piece of content that feels like you.',
				),
				'🎨 Expression' => array(
					'expression_1' => "What are your brand's primary colors (and any specific hex codes)?",
					'expression_2' => 'What font(s) do you use — or wish to use — for headers and body text?',
					'expression_3' => 'Is there a visual theme (earthy, cosmic, minimalist, ornate) that matches your brand essence?',
					'expression_4' => 'Are there any logos, patterns, or symbols that hold meaning for your brand?',
					'expression_5' => 'What offerings are you currently sharing with the world — and how are they priced or exchanged?',
				),
				'🚀 Direction'  => array(
					'direction_1' => "What's your current main offer or project you want support with?",
					'direction_2' => 'Who is your dream client? Describe them with emotion and detail.',
					'direction_3' => 'What are 3 key goals you have for the next 6 months?',
					'direction_4' => 'Where do you feel stuck, overwhelmed, or unsure — and where would you love AI support?',
					'direction_5' => 'If this AI assistant could speak your soul fluently, what would you want it to never forget?',
				),
			);

			// Add content to PDF
			foreach ( $questions as $section_title => $section_questions ) {
				$pdf->ChapterTitle( $section_title );
				foreach ( $section_questions as $key => $question ) {
					if ( ! empty( $answers[ $key ] ) ) {
						$pdf->MessageBlock( 'question', $question, gmdate( 'Y-m-d H:i:s' ) );
						$pdf->MessageBlock( 'answer', $answers[ $key ], gmdate( 'Y-m-d H:i:s' ) );
					}
				}
			}

			// Output PDF
			$filename = 'brand-soul-questionnaire-' . gmdate( 'Y-m-d' ) . '.pdf';
			$pdf->Output( $filename, 'D' );

		} catch ( Exception $e ) {
			wp_die( 'Error generating PDF: ' . esc_html( $e->getMessage() ) );
		}
	}

	public function download_brand_soul_md() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions' );
		}
		if ( ! isset( $_GET['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['nonce'] ) ), 'aiohm_brand_soul_download' ) ) {
			wp_die( 'Security check failed' );
		}
		try {
			$user_id = get_current_user_id();
			$answers = get_user_meta( $user_id, 'aiohm_brand_soul_answers', true );
			if ( empty( $answers ) ) {
				wp_die( 'No brand soul answers found. Please complete the questionnaire first.' );
			}
			$content  = "# Brand Soul Questionnaire Answers\n\n";
			$content .= 'Generated on: ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n\n";
			$questions = array(
				'✨ Foundation' => array(
					'foundation_1' => "What's the deeper purpose behind your brand — beyond profit?",
					'foundation_2' => 'What life experiences shaped this work you now do?',
					'foundation_3' => 'Who were you before this calling emerged?',
					'foundation_4' => 'If your brand had a soul story, how would you tell it?',
					'foundation_5' => "What's one transformation you've witnessed that reminds you why you do this?",
				),
				'🌀 Energy'     => array(
					'energy_1' => 'What 3 words describe the emotional tone of your brand voice?',
					'energy_2' => 'How do you want your audience to feel after encountering your message?',
					'energy_3' => 'What do you not want to sound like?',
					'energy_4' => 'Do you prefer poetic, punchy, playful, or professional language?',
					'energy_5' => 'Share a quote, phrase, or piece of content that feels like you.',
				),
				'🎨 Expression' => array(
					'expression_1' => "What are your brand's primary colors (and any specific hex codes)?",
					'expression_2' => 'What font(s) do you use — or wish to use — for headers and body text?',
					'expression_3' => 'Is there a visual theme (earthy, cosmic, minimalist, ornate) that matches your brand essence?',
					'expression_4' => 'Are there any logos, patterns, or symbols that hold meaning for your brand?',
					'expression_5' => 'What offerings are you currently sharing with the world — and how are they priced or exchanged?',
				),
				'🚀 Direction'  => array(
					'direction_1' => "What's your current main offer or project you want support with?",
					'direction_2' => 'Who is your dream client? Describe them with emotion and detail.',
					'direction_3' => 'What are 3 key goals you have for the next 6 months?',
					'direction_4' => 'Where do you feel stuck, overwhelmed, or unsure — and where would you love AI support?',
					'direction_5' => 'If this AI assistant could speak your soul fluently, what would you want it to never forget?',
				),
			);
			foreach ( $questions as $section_title => $section_questions ) {
				$content .= '## ' . $section_title . "\n\n";
				foreach ( $section_questions as $key => $question ) {
					if ( ! empty( $answers[ $key ] ) ) {
						$content .= '### ' . $question . "\n\n";
						$content .= $answers[ $key ] . "\n\n";
					}
				}
			}
			$filename = 'brand-soul-questionnaire-' . gmdate( 'Y-m-d' ) . '.md';
            header( 'Content-Type: text/markdown; charset=utf-8' );
            header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
            echo esc_html( $content );
            exit;
		} catch ( Exception $e ) {
			wp_die( 'Error generating file: ' . esc_html( $e->getMessage() ) );
		}
	}

	/**
	 * AJAX handler for saving individual settings
	 */
	public function ajax_save_setting() {
		// Verify nonce
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'aiohm_admin_nonce' ) ) {
			wp_send_json_error( 'Security check failed' );
			return;
		}

		// Check user permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
			return;
		}

		$setting_key   = sanitize_key( wp_unslash( $_POST['setting_key'] ?? '' ) );
		$setting_value = sanitize_text_field( wp_unslash( $_POST['setting_value'] ?? '' ) );

		if ( empty( $setting_key ) ) {
			wp_send_json_error( 'Setting key is required' );
			return;
		}

		if ( 'default_ai_provider' === $setting_key ) {
			$setting_value = function_exists( 'aiohm_kb_normalize_wp_ai_provider_id' )
				? aiohm_kb_normalize_wp_ai_provider_id( $setting_value )
				: sanitize_key( $setting_value );

			if ( ! in_array( $setting_value, array( 'shareai', 'openai', 'claude', 'gemini', 'groq', 'ollama' ), true ) ) {
				wp_send_json_error( 'Invalid AI provider selected' );
				return;
			}
		}

		// Get current settings
		$current_settings = AIOHM_KB_Assistant::get_settings();

		// Update the specific setting
		$current_settings[ $setting_key ] = $setting_value;

		// Save the updated settings
		$result = update_option( 'aiohm_kb_settings', $current_settings );
		$saved_settings = get_option( 'aiohm_kb_settings', array() );
		$setting_saved  = is_array( $saved_settings )
			&& array_key_exists( $setting_key, $saved_settings )
			&& (string) $saved_settings[ $setting_key ] === (string) $setting_value;

		// WordPress returns false when the option value was unchanged.
		if ( false !== $result || $setting_saved ) {
			wp_send_json_success( 'Setting saved successfully' );
		} else {
			wp_send_json_error( 'Failed to save setting' );
		}
	}

	/**
	 * AJAX handler for saving multiple settings (from Muse mode form).
	 */
	public function ajax_save_settings() {
		// Verify nonce for Muse Mode
		if ( ! isset( $_POST['aiohm_muse_mode_nonce_field'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['aiohm_muse_mode_nonce_field'] ) ), 'aiohm_muse_mode_nonce' ) ) {
			wp_send_json_error( 'Security check failed. Nonce field present: ' . (isset( $_POST['aiohm_muse_mode_nonce_field'] ) ? 'yes' : 'no') );
			return;
		}

		// Check user permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}

		// Get current settings
		$current_settings = get_option( 'aiohm_kb_settings', array() );

		$raw_post = wp_unslash( $_POST );
		if ( isset( $raw_post['aiohm_kb_settings'] ) && is_array( $raw_post['aiohm_kb_settings'] ) ) {
			$form_settings = $this->sanitize_settings_array( $raw_post['aiohm_kb_settings'] );

			// Recursively sanitize and merge the settings
			$sanitized_settings = $this->sanitize_settings_array( $form_settings );
			$current_settings   = array_merge( $current_settings, $sanitized_settings );

			// Save the updated settings
			$update_result = update_option( 'aiohm_kb_settings', $current_settings );
			if ( $update_result ) {
				wp_send_json_success( 'Settings saved successfully' );
			} else {
				// Check if the settings are actually the same (no change needed)
				$saved_settings = get_option( 'aiohm_kb_settings', array() );
				if ( $saved_settings === $current_settings ) {
					wp_send_json_success( 'Settings saved successfully' );
				} else {
					wp_send_json_error( 'Failed to save settings' );
				}
			}
		} else {
			wp_send_json_error( 'No settings data received' );
		}
	}

	/**
	 * Recursively sanitize settings array
	 */
	private function sanitize_settings_array( $settings ) {
		$sanitized = array();

		foreach ( $settings as $key => $value ) {
			$sanitized_key = sanitize_key( $key );

			if ( is_array( $value ) ) {
				$sanitized[ $sanitized_key ] = $this->sanitize_settings_array( $value );
			} else {
				// Handle different types of values
				if ( is_bool( $value ) || $value === '1' || $value === '0' ) {
					$sanitized[ $sanitized_key ] = (bool) $value;
				} elseif ( is_numeric( $value ) ) {
					$sanitized[ $sanitized_key ] = floatval( $value );
				} else {
					// For text fields, use textarea sanitization to preserve line breaks
					$sanitized[ $sanitized_key ] = sanitize_textarea_field( $value );
				}
			}
		}

		return $sanitized;
	}

	public function sanitize_settings( $input ) {
		$old_settings = get_option( 'aiohm_kb_settings', array() );
		$sanitized    = is_array( $old_settings ) ? $old_settings : array();

		// Sanitize API keys and encrypt them for storage
		$api_keys = array( 'openai_api_key', 'gemini_api_key', 'claude_api_key' );
		foreach ( $api_keys as $field ) {
			if ( isset( $input[ $field ] ) ) {
				$sanitized_key = sanitize_text_field( trim( $input[ $field ] ) );
				// Only encrypt if not empty and not already encrypted
				if ( ! empty( $sanitized_key ) ) {
					if ( base64_decode( $sanitized_key, true ) === false || strlen( base64_decode( $sanitized_key ) ) < 17 ) {
						$sanitized[ $field ] = AIOHM_KB_Assistant::encrypt_api_key( $sanitized_key );
					} else {
						$sanitized[ $field ] = $sanitized_key; // Assume it's already encrypted from AJAX save
					}
				} else {
					$sanitized[ $field ] = '';
				}
			}
		}

		// ShareAI API key and model are handled via AJAX, but sanitize if submitted with main form
		if ( isset( $input['shareai_api_key'] ) ) {
			$sanitized['shareai_api_key'] = sanitize_text_field( trim( $input['shareai_api_key'] ) );
		}

		// Sanitize other text fields
		$text_fields = array( 'private_llm_server_url', 'private_llm_model' );
		foreach ( $text_fields as $field ) {
			if ( isset( $input[ $field ] ) ) {
				$sanitized[ $field ] = sanitize_text_field( trim( $input[ $field ] ) );
			}
		}

		// Sanitize select fields
		if ( isset( $input['default_ai_provider'] ) ) {
			$provider = function_exists( 'aiohm_kb_normalize_wp_ai_provider_id' )
				? aiohm_kb_normalize_wp_ai_provider_id( $input['default_ai_provider'] )
				: sanitize_key( $input['default_ai_provider'] );
			if ( in_array( $provider, array( 'shareai', 'openai', 'claude', 'gemini', 'groq', 'ollama' ), true ) ) {
				$sanitized['default_ai_provider'] = $provider;
			}
		}

		// Sanitize checkboxes
		$checkboxes = array( 'chat_enabled', 'show_floating_chat', 'enable_private_assistant', 'enable_search_shortcode', 'enable_qa_shortcode', 'delete_data_on_uninstall', 'external_api_consent' );
		foreach ( $checkboxes as $checkbox ) {
			$sanitized[ $checkbox ] = ! empty( $input[ $checkbox ] );
		}

		// Handle nested Mirror Mode settings
		if ( isset( $input['mirror_mode'] ) && is_array( $input['mirror_mode'] ) ) {
			$mirror_mode              = $input['mirror_mode'];
			$sanitized['mirror_mode'] = $sanitized['mirror_mode'] ?? array();

			$mirror_text_fields = array( 'business_name', 'ai_model' );
			foreach ( $mirror_text_fields as $field ) {
				if ( isset( $mirror_mode[ $field ] ) ) {
					$sanitized['mirror_mode'][ $field ] = sanitize_text_field( trim( $mirror_mode[ $field ] ) );
				}
			}

			if ( isset( $mirror_mode['qa_system_message'] ) ) {
				$sanitized['mirror_mode']['qa_system_message'] = sanitize_textarea_field( $mirror_mode['qa_system_message'] );
			}
			if ( isset( $mirror_mode['welcome_message'] ) ) {
				$sanitized['mirror_mode']['welcome_message'] = sanitize_textarea_field( $mirror_mode['welcome_message'] );
			}
			if ( isset( $mirror_mode['qa_temperature'] ) ) {
				$sanitized['mirror_mode']['qa_temperature'] = floatval( $mirror_mode['qa_temperature'] );
			}
			if ( isset( $mirror_mode['primary_color'] ) ) {
				$sanitized['mirror_mode']['primary_color'] = sanitize_hex_color( $mirror_mode['primary_color'] );
			}
			if ( isset( $mirror_mode['background_color'] ) ) {
				$sanitized['mirror_mode']['background_color'] = sanitize_hex_color( $mirror_mode['background_color'] );
			}
			if ( isset( $mirror_mode['text_color'] ) ) {
				$sanitized['mirror_mode']['text_color'] = sanitize_hex_color( $mirror_mode['text_color'] );
			}
			if ( isset( $mirror_mode['ai_avatar'] ) ) {
				$sanitized['mirror_mode']['ai_avatar'] = esc_url_raw( trim( $mirror_mode['ai_avatar'] ) );
			}
			if ( isset( $mirror_mode['meeting_button_url'] ) ) {
				$sanitized['mirror_mode']['meeting_button_url'] = esc_url_raw( trim( $mirror_mode['meeting_button_url'] ) );
			}
		}

		return $sanitized;
	}

	/**
	 * Handle muse mode preview chat AJAX request
	 */
	public function ajax_muse_preview_chat() {
		if ( ! check_ajax_referer( 'aiohm_admin_nonce', 'nonce', false ) || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'aiohm-knowledge-assistant' ) ) );
		}

		try {
			$user_message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
			
			if ( empty( $user_message ) ) {
				wp_send_json_error( array( 'message' => __( 'Message cannot be empty.', 'aiohm-knowledge-assistant' ) ) );
			}

			// Get muse mode settings
			$all_settings = AIOHM_KB_Assistant::get_settings();
			$muse_settings = $all_settings['muse_mode'] ?? array();
			
			$system_prompt = isset( $muse_settings['system_prompt'] ) ? wp_kses_post( $muse_settings['system_prompt'] ) : 'You are a helpful creative assistant.';
			$temperature = floatval( $muse_settings['temperature'] ?? 0.7 );
			$assistant_name = $muse_settings['assistant_name'] ?? 'Muse';
			$ai_model = $muse_settings['ai_model'] ?? 'gpt-3.5-turbo';
			
			// Initialize AI client
			$ai_client = new AIOHM_KB_AI_GPT_Client( $all_settings );
			
			// Check if any AI provider is configured
			$providers = array( 'openai', 'gemini', 'claude', 'groq', 'shareai' );
			$has_api_key = false;
			foreach ( $providers as $provider ) {
				if ( $ai_client->is_api_key_configured( $provider ) ) {
					$has_api_key = true;
					break;
				}
			}
			
			if ( ! $has_api_key ) {
				wp_send_json_error( array( 'message' => __( 'No AI API keys are configured. Please configure at least one AI provider in the settings.', 'aiohm-knowledge-assistant' ) ) );
			}
			$rag_engine = new AIOHM_KB_RAG_Engine();
			
			// Get relevant context from knowledge base
			$context_data = array();
			try {
				$context_data = $rag_engine->find_relevant_context( $user_message, 5 );
			} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// If context retrieval fails (e.g., no API keys for embeddings), continue with empty context.
				$context_data = array();
			}
			$context_string = '';
			
			foreach ( $context_data as $data ) {
				$context_string .= 'Source: ' . $data['entry']['title'] . "\nContent: " . $data['entry']['content'] . "\n\n";
			}
			
			if ( empty( $context_string ) ) {
				$context_string = 'No relevant context found.';
			}

			// Replace placeholders in system prompt
			$replacements = array(
				'{context}'        => $context_string,
				'%site_name%'      => get_bloginfo( 'name' ),
				'%assistant_name%' => $assistant_name,
				'%day_of_week%'    => wp_date( 'l' ),
				'%current_date%'   => wp_date( get_option( 'date_format' ) ),
				'%current_time%'   => wp_date( get_option( 'time_format' ) ),
			);
			
			$final_system_prompt = str_replace( array_keys( $replacements ), array_values( $replacements ), $system_prompt );

			// Send request to AI
			$ai_response = $ai_client->get_chat_completion(
				$final_system_prompt,
				$user_message,
				$temperature,
				$ai_model
			);

			if ( $ai_response ) {
				wp_send_json_success( $ai_response );
			} else {
				wp_send_json_error( array( 'message' => __( 'Failed to get AI response.', 'aiohm-knowledge-assistant' ) ) );
			}

		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => __( 'An error occurred while processing your request.', 'aiohm-knowledge-assistant' ) ) );
		}
	}

	/**
	 * AJAX handler for saving pro features settings
	 */
	public function ajax_save_pro_features() {
		// Ensure user has permissions first
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed - insufficient permissions.', 'aiohm-knowledge-assistant' ) ) );
		}

		// Get current settings directly from database
		$current_settings = get_option( 'aiohm_kb_settings', array() );
		$pro_settings = isset($current_settings['pro_features']) ? $current_settings['pro_features'] : array();

		// Get posted data - simplified approach
		$posted_pro_features = isset($_POST['aiohm_kb_settings']['pro_features']) ? $_POST['aiohm_kb_settings']['pro_features'] : array();

		// Update checkbox settings
		$checkboxes = array( 'ollama_enabled', 'mcp_enabled', 'mcp_require_https', 'private_hub_enabled' );
		foreach ( $checkboxes as $checkbox ) {
			$pro_settings[ $checkbox ] = isset( $posted_pro_features[ $checkbox ] ) && $posted_pro_features[ $checkbox ] === '1';
		}

		// Update text field settings
		if ( isset( $posted_pro_features['ollama_server_url'] ) ) {
			$pro_settings['ollama_server_url'] = sanitize_url( $posted_pro_features['ollama_server_url'] );
		}
		if ( isset( $posted_pro_features['ollama_model'] ) ) {
			$pro_settings['ollama_model'] = sanitize_text_field( $posted_pro_features['ollama_model'] );
		}
		if ( isset( $posted_pro_features['mcp_rate_limit'] ) ) {
			$pro_settings['mcp_rate_limit'] = max( 100, min( 10000, intval( $posted_pro_features['mcp_rate_limit'] ) ) );
		}
		if ( isset( $posted_pro_features['private_hub_name'] ) ) {
			$pro_settings['private_hub_name'] = sanitize_text_field( $posted_pro_features['private_hub_name'] );
		}

		// Update the main settings array
		$current_settings['pro_features'] = $pro_settings;

		// Save the settings
		$result = update_option( 'aiohm_kb_settings', $current_settings );

		// Always return success for now
		wp_send_json_success( array( 'message' => __( 'Pro features settings saved successfully.', 'aiohm-knowledge-assistant' ) ) );
	}

	/**
	 * AJAX handler for testing Ollama connection
	 */
	public function ajax_test_ollama_connection() {
		// Verify nonce and permissions
		if ( ! check_ajax_referer( 'aiohm_admin_nonce', 'nonce', false ) || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Security check failed.', 'aiohm-knowledge-assistant' ) );
		}

		// Check if pro license is active
		if ( ! $this->is_pro_license_active() ) {
			wp_send_json_error( __( 'Pro license required.', 'aiohm-knowledge-assistant' ) );
		}

		$server_url = sanitize_url( wp_unslash( $_POST['server_url'] ?? '' ) );
		if ( empty( $server_url ) ) {
			wp_send_json_error( __( 'Server URL is required.', 'aiohm-knowledge-assistant' ) );
		}

		// Test connection to Ollama server
		$test_url = rtrim( $server_url, '/' ) . '/api/tags';

		$response = wp_remote_get( $test_url, array(
			'timeout' => 10,
			'headers' => array(
				'Content-Type' => 'application/json',
			),
		) );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( $response->get_error_message() );
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( $response_code === 200 ) {
			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			if ( isset( $data['models'] ) ) {
				$model_count = count( $data['models'] );
				/* translators: %d: number of models found */
				wp_send_json_success( sprintf( __( 'Connected successfully! Found %d models.', 'aiohm-knowledge-assistant' ), $model_count ) );
			} else {
				wp_send_json_success( __( 'Connected successfully!', 'aiohm-knowledge-assistant' ) );
			}
		} else {
			/* translators: %d: HTTP status code */
			wp_send_json_error( sprintf( __( 'Connection failed with status code: %d', 'aiohm-knowledge-assistant' ), $response_code ) );
		}
	}

	/**
	 * Handle brand soul PDF download request
	 */
	public function handle_brand_soul_pdf_download() {
		$this->download_brand_soul_pdf();
	}

	public function handle_brand_soul_md_download() {
		$this->download_brand_soul_md();
	}
}
