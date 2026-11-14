<?php
/**
 * Core initialization and configuration handler for AIOHM Knowledge Assistant
 * Manages database operations, AJAX endpoints, and security validation
 *
 * @package AIOHM_Knowledge_Assistant
 * @since 1.0.0
 */
if (!defined('ABSPATH')) exit;

if (!class_exists('AIOHM_KB_Core_Init')) {

/**
 * Core plugin initialization class
 * 
 * Handles security validation, database operations, and AJAX request processing
 * for the AIOHM Knowledge Assistant plugin.
 *
 * @since 1.0.0
 * @package AIOHM_Knowledge_Assistant
 */
class AIOHM_KB_Core_Init {
    
    /**
     * Allowed database tables for security validation
     * @var array
     */
    private static $allowed_tables = [];
    
    /**
     * Initialize allowed tables list for security validation
     *
     * @since 1.0.0
     * @return void
     */
    private static function init_allowed_tables() {
        if (empty(self::$allowed_tables)) {
            global $wpdb;
            self::$allowed_tables = [
                $wpdb->prefix . 'aiohm_vector_entries',
                $wpdb->prefix . 'aiohm_conversations', 
                $wpdb->prefix . 'aiohm_messages',
                $wpdb->prefix . 'aiohm_projects'
            ];
        }
    }
    
    /**
     * Validate table name against allowed tables to prevent SQL injection
     *
     * @since 1.0.0
     * @param string $table_name Table name to validate
     * @return bool True if valid, false otherwise
     */
    private static function validate_table_name($table_name) {
        self::init_allowed_tables();
        return in_array($table_name, self::$allowed_tables, true);
    }
    
    /**
     * Safely get table reference with validation
     *
     * @since 1.0.0
     * @param string $table_name Table name to validate
     * @return string Validated table name
     * @throws Exception If table name is invalid
     */
    private static function get_safe_table_name($table_name) {
        if (!self::validate_table_name($table_name)) {
            throw new Exception('Invalid table name for security reasons.');
        }
        return $table_name;
    }

    /**
     * Get safe error message for frontend display
     *
     * Sanitizes error messages to prevent information disclosure while providing
     * helpful feedback to administrators.
     *
     * @since 1.0.0
     * @param Exception $e The exception
     * @param string $context Context for logging
     * @return string Safe error message
     */
    private static function get_safe_error_message($e, $context = 'general') {
        
        // For specific admin operations, allow more detailed error messages
        if ($context === 'Scan' && current_user_can('manage_options')) {
            $error_message = $e->getMessage();
            
            // Allow consent-related errors to be shown to admins
            if (strpos($error_message, 'External API calls require user consent') !== false) {
                return $error_message;
            }
            
            // Allow other specific admin-friendly error messages
            if (strpos($error_message, 'API key') !== false || 
                strpos($error_message, 'knowledge base') !== false ||
                strpos($error_message, 'File') !== false) {
                return $error_message;
            }
        }
        
        // Return generic message for security for other contexts
        return __('Sorry, something went wrong. Please try again later.', 'aiohm-knowledge-assistant');
    }

    /**
     * Enhanced input validation for AJAX requests
     *
     * Validates and sanitizes input data according to specified rules to prevent
     * security vulnerabilities and ensure data integrity.
     *
     * @since 1.0.0
     * @param array $input Input data to validate
     * @param array $rules Validation rules array with field requirements
     * @return array|WP_Error Sanitized data or WP_Error on validation failure
     */
    private static function validate_ajax_input($input, $rules) {
        $sanitized = [];
        $errors = [];

        foreach ($rules as $field => $rule) {
            $value = isset($input[$field]) ? $input[$field] : null;
            
            if (isset($rule['required']) && $rule['required'] && (empty($value) && $value !== '0')) {
                // translators: %s is the field name
                $errors[] = sprintf(__('Field %s is required.', 'aiohm-knowledge-assistant'), $field);
                continue;
            }
            
            if (empty($value) && $value !== '0') {
                $sanitized[$field] = '';
                continue;
            }
            
            if (isset($rule['max_length']) && strlen($value) > $rule['max_length']) {
                // translators: %1$s is the field name, %2$d is the maximum length
                $errors[] = sprintf(__('Field %1$s exceeds maximum length of %2$d characters.', 'aiohm-knowledge-assistant'), $field, $rule['max_length']);
                continue;
            }
            
            switch ($rule['type']) {
                case 'text':
                    $sanitized[$field] = sanitize_text_field($value);
                    break;
                    
                case 'textarea':
                    $sanitized[$field] = sanitize_textarea_field($value);
                    break;
                    
                case 'html':
                    $sanitized[$field] = wp_kses_post($value);
                    break;
                    
                case 'email':
                    $sanitized[$field] = sanitize_email($value);
                    if (!is_email($sanitized[$field])) {
                        // translators: %s is the field name
                        $errors[] = sprintf(__('Field %s must be a valid email address.', 'aiohm-knowledge-assistant'), $field);
                    }
                    break;
                    
                case 'url':
                    $sanitized[$field] = esc_url_raw($value);
                    if (!filter_var($sanitized[$field], FILTER_VALIDATE_URL)) {
                        // translators: %s is the field name
                        $errors[] = sprintf(__('Field %s must be a valid URL.', 'aiohm-knowledge-assistant'), $field);
                    }
                    break;
                    
                case 'int':
                    $sanitized[$field] = intval($value);
                    if (isset($rule['min']) && $sanitized[$field] < $rule['min']) {
                        // translators: %1$s is the field name, %2$d is the minimum value
                        $errors[] = sprintf(__('Field %1$s must be at least %2$d.', 'aiohm-knowledge-assistant'), $field, $rule['min']);
                    }
                    if (isset($rule['max']) && $sanitized[$field] > $rule['max']) {
                        // translators: %1$s is the field name, %2$d is the maximum value
                        $errors[] = sprintf(__('Field %1$s must be no more than %2$d.', 'aiohm-knowledge-assistant'), $field, $rule['max']);
                    }
                    break;
                    
                case 'float':
                    $sanitized[$field] = floatval($value);
                    break;
                    
                case 'array':
                    if (!is_array($value)) {
                        // translators: %s is the field name
                        $errors[] = sprintf(__('Field %s must be an array.', 'aiohm-knowledge-assistant'), $field);
                    } else {
                        $sanitized[$field] = array_map('sanitize_text_field', $value);
                    }
                    break;
                    
                case 'json':
                    $decoded = json_decode($value, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        // translators: %s is the field name
                        $errors[] = sprintf(__('Field %s must be valid JSON.', 'aiohm-knowledge-assistant'), $field);
                    } else {
                        $sanitized[$field] = $value;
                    }
                    break;
                    
                default:
                    $sanitized[$field] = sanitize_text_field($value);
            }
        }
        
        if (!empty($errors)) {
            return new WP_Error('validation_failed', implode(' ', $errors));
        }
        
        return $sanitized;
    }

    // ================== START: CONSOLIDATED FIX ==================
    // By placing these functions directly inside the class, we guarantee they are
    // always available when needed, preventing the fatal errors that were causing the crashes.
    
    private static function create_conversation_internal($user_id, $project_id, $title) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'aiohm_conversations';
        
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
        // Internal conversation creation requires direct database access for atomic operations and proper cache invalidation
        $result = $wpdb->insert($table_name, ['user_id' => $user_id, 'project_id' => $project_id, 'title' => $title], ['%d', '%d', '%s']);
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
        
        if ($result) {
            wp_cache_delete('aiohm_user_conversations_' . $user_id, 'aiohm_core');
            wp_cache_delete('aiohm_project_conversations_' . $project_id, 'aiohm_core');
            return $wpdb->insert_id;
        }
        
        return false;
    }

    private static function add_message_to_conversation_internal($conversation_id, $sender, $content) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'aiohm_messages';
        
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
        // Message creation and conversation updates require direct database access for atomic operations
        $result = $wpdb->insert($table_name, ['conversation_id' => $conversation_id, 'sender' => $sender, 'content' => $content], ['%d', '%s', '%s']);
        if ($result) {
            $wpdb->update($wpdb->prefix . 'aiohm_conversations', ['updated_at' => current_time('mysql', 1)], ['id' => $conversation_id]);
            
            wp_cache_delete('aiohm_conversation_messages_' . $conversation_id, 'aiohm_core');
            wp_cache_delete('aiohm_conversation_' . $conversation_id, 'aiohm_core');
        }
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
        
        return $result !== false;
    }
    // =================== END: CONSOLIDATED FIX ===================

    /**
     * Generate AI-powered conversation title
     */
    private static function generate_conversation_title($user_message, $ai_client, $model) {
        try {
            $title_prompt = "Create a short title (3-5 words) for this conversation. Remove quotes. Examples: Website Design Project, Marketing Strategy Planning, Brand Development Discussion. Message: " . substr($user_message, 0, 150);
            
            $title = $ai_client->get_chat_completion(
                "Create concise conversation titles without quotes or special characters.",
                $title_prompt,
                0.2, // Low temperature for consistency
                $model
            );
            
            $title = trim(wp_strip_all_tags($title));
            $title = str_replace(['"', "'", '`', '«', '»'], '', $title);
            $title = preg_replace('/[^\w\s-]/', '', $title);
            $title = preg_replace('/\s+/', ' ', $title);
            $title = trim($title);
            
            if (strlen($title) > 50) {
                $title = mb_strimwidth($title, 0, 47, '...');
            }
            
            if (strlen($title) < 3 || empty($title) || $title === '...' || strtolower($title) === 'new chat') {
                $title = self::create_fallback_title($user_message);
            }
            
            return $title;
            
        } catch (Exception $e) {
            return self::create_fallback_title($user_message);
        }
    }

    /**
     * Create a smart fallback title from user message
     */
    private static function create_fallback_title($user_message) {
        $message = strtolower(trim($user_message));
        
        // Common patterns for smart titles
        if (strpos($message, 'help') !== false && strpos($message, 'with') !== false) {
            return __('Help Request', 'aiohm-knowledge-assistant');
        } elseif (strpos($message, 'create') !== false || strpos($message, 'make') !== false) {
            return __('Creation Project', 'aiohm-knowledge-assistant');
        } elseif (strpos($message, 'plan') !== false || strpos($message, 'strategy') !== false) {
            return __('Planning Session', 'aiohm-knowledge-assistant');
        } elseif (strpos($message, 'write') !== false || strpos($message, 'content') !== false) {
            return __('Content Writing', 'aiohm-knowledge-assistant');
        } elseif (strpos($message, 'design') !== false) {
            return __('Design Discussion', 'aiohm-knowledge-assistant');
        } elseif (strpos($message, 'market') !== false) {
            return __('Marketing Discussion', 'aiohm-knowledge-assistant');
        } elseif (strpos($message, 'brand') !== false) {
            return __('Brand Development', 'aiohm-knowledge-assistant');
        } elseif (strpos($message, 'website') !== false || strpos($message, 'web') !== false) {
            return __('Web Development', 'aiohm-knowledge-assistant');
        } elseif (strpos($message, 'question') !== false) {
            return __('General Questions', 'aiohm-knowledge-assistant');
        } else {
            $words = explode(' ', $message);
            $title_words = array_slice($words, 0, 3);
            $title = ucwords(implode(' ', $title_words));
            return strlen($title) > 3 ? $title : 'General Discussion';
        }
    }

    /**
     * Build the Content Security Policy string from settings.
     * @param bool $is_admin Whether to build for admin or frontend.
     * @return string The full CSP header value.
     */
    private static function build_csp_string($is_admin = false) {
        $settings = AIOHM_KB_Assistant::get_settings();
        
        // Base policies
        $policies = [
            'default-src' => ["'self'"],
            'script-src'  => ["'self'", "'unsafe-inline'"],
            'style-src'   => ["'self'", "'unsafe-inline'"],
            'img-src'     => ["'self'", 'data:', 'https:'],
            'font-src'    => ["'self'", 'data:'],
            'connect-src' => ["'self'", 'https:'],
            'frame-src'   => ["'self'"],
        ];

        if ($is_admin) {
            // Admin needs 'unsafe-eval' for some core scripts and inline styles.
            $policies['script-src'][] = "'unsafe-eval'";
        }

        // Add user-defined sources
        $directives = [
            'script-src'  => 'csp_script_src',
            'style-src'   => 'csp_style_src',
            'font-src'    => 'csp_font_src',
            'frame-src'   => 'csp_frame_src',
            'connect-src' => 'csp_connect_src',
            'img-src'     => 'csp_img_src',
        ];

        foreach ($directives as $directive => $setting_key) {
            if (!empty($settings[$setting_key])) {
                $sources = preg_split('/\s+/', trim($settings[$setting_key]));
                foreach ($sources as $source) {
                    // Basic validation for URLs or keywords
                    if (filter_var($source, FILTER_VALIDATE_URL) || preg_match("/^'[\w-]+'$/", $source) || $source === 'data:' || $source === 'https:') {
                        if (!in_array($source, $policies[$directive])) {
                            $policies[$directive][] = $source;
                        }
                    }
                }
            }
        }

        // Build the header string
        $csp_string = '';
        foreach ($policies as $directive => $sources) {
            $csp_string .= $directive . ' ' . implode(' ', array_unique($sources)) . '; ';
        }

        return trim($csp_string);
    }

    public static function init() {
        // Add security headers and session security
        add_action('admin_init', array(__CLASS__, 'add_admin_security_headers'));
        
        // WordPress.org compliance: Add privacy policy content
        add_action('admin_init', array(__CLASS__, 'add_privacy_policy_content'));
        add_action('send_headers', array(__CLASS__, 'add_frontend_security_headers'));
        add_action('wp_login', array(__CLASS__, 'secure_user_session'), 10, 2);
        add_action('user_register', array(__CLASS__, 'secure_new_user_session'));
        
        // --- All original action hooks are preserved ---
        add_action('wp_ajax_aiohm_progressive_scan', array(__CLASS__, 'handle_progressive_scan_ajax'));
        add_action('wp_ajax_aiohm_check_api_key', array(__CLASS__, 'handle_check_api_key_ajax'));
        add_action('wp_ajax_aiohm_save_individual_api_key', array(__CLASS__, 'handle_save_individual_api_key_ajax'));
        add_action('wp_ajax_aiohm_export_kb', array(__CLASS__, 'handle_export_kb_ajax'));
        add_action('wp_ajax_aiohm_reset_kb', array(__CLASS__, 'handle_reset_kb_ajax'));
        add_action('wp_ajax_aiohm_toggle_kb_scope', array(__CLASS__, 'handle_toggle_kb_scope_ajax'));
        add_action('wp_ajax_aiohm_restore_kb', array(__CLASS__, 'handle_restore_kb_ajax'));
        add_action('wp_ajax_aiohm_delete_kb_entry', array(__CLASS__, 'handle_delete_kb_entry_ajax'));
        add_action('wp_ajax_aiohm_bulk_delete_kb', array(__CLASS__, 'handle_bulk_delete_kb_ajax'));
        add_action('wp_ajax_aiohm_bulk_toggle_kb_scope', array(__CLASS__, 'handle_bulk_toggle_kb_scope_ajax'));
        add_action('wp_ajax_aiohm_save_brand_soul', array(__CLASS__, 'handle_save_brand_soul_ajax'));
        add_action('wp_ajax_aiohm_add_brand_soul_to_kb', array(__CLASS__, 'handle_add_brand_soul_to_kb_ajax'));
        add_action('wp_ajax_aiohm_add_note_to_kb', array(__CLASS__, 'handle_add_note_to_kb_ajax'));
        add_action('admin_init', array(__CLASS__, 'handle_pdf_download'));
        
        // Initialize AJAX handlers
        self::init_ajax_hooks();
    }
    
    /**
     * Add privacy policy content for WordPress.org compliance
     * Required for plugins that use external services
     */
    public static function add_privacy_policy_content() {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }
        
        $content = '<h2>' . esc_html__('AIOHM Knowledge Assistant', 'aiohm-knowledge-assistant') . '</h2>' .
                   '<p>' . esc_html__('This plugin can optionally connect to external AI services to process your content:', 'aiohm-knowledge-assistant') . '</p>' .
                   '<h3>' . esc_html__('ShareAI Service (Optional)', 'aiohm-knowledge-assistant') . '</h3>' .
                   '<p>' . esc_html__('When enabled by an administrator with user consent:', 'aiohm-knowledge-assistant') . '</p>' .
                   '<ul>' .
                   '<li>' . esc_html__('AI prompts and related settings are sent to ShareAI servers', 'aiohm-knowledge-assistant') . '</li>' .
                   '<li>' . esc_html__('Your site\'s WordPress version and plugin version (for debugging)', 'aiohm-knowledge-assistant') . '</li>' .
                   '<li>' . esc_html__('API key (stored locally in your WordPress database)', 'aiohm-knowledge-assistant') . '</li>' .
                   '</ul>' .
                   '<p>' . sprintf(
                       // translators: %1$s is the ShareAI Terms URL link, %2$s is the Privacy Policy URL link
                       esc_html__('ShareAI Terms: %1$s | Privacy Policy: %2$s', 'aiohm-knowledge-assistant'),
                       '<a href="https://shareai.now/terms" target="_blank">https://shareai.now/terms</a>',
                       '<a href="https://shareai.now/privacy" target="_blank">https://shareai.now/privacy</a>'
                   ) . '</p>' .
                   '<h3>' . esc_html__('User Control', 'aiohm-knowledge-assistant') . '</h3>' .
                   '<p>' . esc_html__('All external connections are disabled by default and require explicit administrator consent. You can disable external services at any time in the plugin settings.', 'aiohm-knowledge-assistant') . '</p>';
        
        wp_add_privacy_policy_content('AIOHM Knowledge Assistant', wp_kses_post($content));
    }
    
    /**
     * Initialize AJAX handlers and monitoring hooks
     */
    private static function init_ajax_hooks() {
        add_action('wp_ajax_aiohm_save_mirror_mode_settings', array(__CLASS__, 'handle_save_mirror_mode_settings_ajax'));
        add_action('wp_ajax_aiohm_save_muse_mode_settings', array(__CLASS__, 'handle_save_muse_mode_settings_ajax'));
        // SECURITY: Removed nopriv hook - settings should only be accessible to authenticated users
        
        // Add hook to monitor settings changes
        add_action('update_option_aiohm_kb_settings', array(__CLASS__, 'monitor_settings_changes'), 10, 2);
        add_action('delete_option_aiohm_kb_settings', array(__CLASS__, 'monitor_settings_deletion'), 10, 1);
        add_action('wp_ajax_aiohm_generate_mirror_mode_qa', array(__CLASS__, 'handle_generate_mirror_mode_qa_ajax'));
        add_action('wp_ajax_aiohm_test_mirror_mode_chat', array(__CLASS__, 'handle_test_mirror_mode_chat_ajax'));
        add_action('wp_ajax_aiohm_save_muse_mode_settings', array(__CLASS__, 'handle_save_muse_mode_settings_ajax'));
        add_action('wp_ajax_aiohm_private_assistant_chat', array(__CLASS__, 'handle_private_assistant_chat_ajax'));
        add_action('wp_ajax_aiohm_private_chat', array(__CLASS__, 'handle_private_assistant_chat_ajax'));
        add_action('wp_ajax_aiohm_test_muse_mode_chat', array(__CLASS__, 'handle_test_muse_mode_chat_ajax'));
        add_action('wp_ajax_nopriv_aiohm_frontend_chat', array(__CLASS__, 'handle_frontend_chat_ajax'));
        add_action('wp_ajax_aiohm_frontend_chat', array(__CLASS__, 'handle_frontend_chat_ajax'));
        add_action('wp_ajax_nopriv_aiohm_search_knowledge', array(__CLASS__, 'handle_search_knowledge_ajax'));
        add_action('wp_ajax_aiohm_search_knowledge', array(__CLASS__, 'handle_search_knowledge_ajax'));
        add_action('wp_ajax_aiohm_admin_search_knowledge', array(__CLASS__, 'handle_admin_search_knowledge_ajax'));

        // --- Existing FIX for project and conversations ---
        add_action('wp_ajax_aiohm_get_project_conversations', array(__CLASS__, 'handle_get_project_conversations_ajax'));
        add_action('wp_ajax_aiohm_create_project', array(__CLASS__, 'handle_create_project_ajax'));
        
        // --- NEW FIX: Add the missing action handler for loading all projects and conversations ---
        add_action('wp_ajax_aiohm_load_history', array(__CLASS__, 'handle_load_history_ajax'));
        add_action('wp_ajax_aiohm_load_conversation', array(__CLASS__, 'handle_load_conversation_ajax'));
        add_action('wp_ajax_aiohm_save_project_notes', array(__CLASS__, 'handle_save_project_notes_ajax'));
        add_action('wp_ajax_aiohm_load_project_notes', array(__CLASS__, 'handle_load_project_notes_ajax'));
        add_action('wp_ajax_aiohm_delete_project', array(__CLASS__, 'handle_delete_project_ajax'));
        add_action('wp_ajax_aiohm_delete_conversation', array(__CLASS__, 'handle_delete_conversation_ajax'));
        add_action('wp_ajax_aiohm_create_conversation', array(__CLASS__, 'handle_create_conversation_ajax'));
        add_action('wp_ajax_aiohm_upload_project_files', array(__CLASS__, 'handle_upload_project_files_ajax'));
        add_action('wp_ajax_aiohm_get_brand_soul_content', array(__CLASS__, 'handle_get_brand_soul_content_ajax'));
        add_action('wp_ajax_aiohm_get_content_for_view', array(__CLASS__, 'handle_get_content_for_view_ajax'));
        add_action('wp_ajax_aiohm_get_usage_stats', array(__CLASS__, 'handle_get_usage_stats_ajax'));
        add_action('wp_ajax_aiohm_download_conversation_pdf', array(__CLASS__, 'handle_download_conversation_pdf_ajax'));
        add_action('wp_ajax_aiohm_add_conversation_to_kb', array(__CLASS__, 'handle_add_conversation_to_kb_ajax'));
        add_action('wp_ajax_aiohm_research_online', array(__CLASS__, 'handle_research_online_ajax'));
        add_action('wp_ajax_aiohm_get_pdf_url', array(__CLASS__, 'handle_get_pdf_url_ajax'));
        
        // File upload to KB handler
        add_action('wp_ajax_aiohm_kb_file_upload', array(__CLASS__, 'handle_kb_file_upload_ajax'));
        add_action('wp_ajax_aiohm_update_json_content', array(__CLASS__, 'handle_update_json_content_ajax'));
        add_action('wp_ajax_aiohm_update_text_content', array(__CLASS__, 'handle_update_text_content_ajax'));
        add_action('wp_ajax_aiohm_reprocess_pdf_content', array(__CLASS__, 'handle_reprocess_pdf_content_ajax'));
        add_action('wp_ajax_aiohm_reprocess_html_content', array(__CLASS__, 'handle_reprocess_html_content_ajax'));
        
        // Help Page AJAX handlers
        add_action('wp_ajax_aiohm_get_debug_info', array(__CLASS__, 'handle_get_debug_info_ajax'));
        add_action('wp_ajax_aiohm_test_all_api_connections', array(__CLASS__, 'handle_test_all_api_connections_ajax'));
        add_action('wp_ajax_aiohm_check_database_health', array(__CLASS__, 'handle_check_database_health_ajax'));
        add_action('wp_ajax_aiohm_submit_support_request', array(__CLASS__, 'handle_submit_support_request_ajax'));
        add_action('wp_ajax_aiohm_submit_feature_request', array(__CLASS__, 'handle_submit_feature_request_ajax'));
        
        
        
        // Allow JSON file uploads for KB functionality
        add_filter('upload_mimes', array(__CLASS__, 'allow_json_uploads'));
        add_filter('wp_check_filetype_and_ext', array(__CLASS__, 'allow_json_file_upload'), 10, 4);
    }
    
    /**
     * Handles the AJAX request to load all projects and recent conversations for the current user.
     * This directly supports the `loadHistory()` function in JavaScript.
     */
    /**
     * Check if an AI provider is configured with valid credentials
     */
    private static function is_provider_configured($settings, $provider) {
        switch ($provider) {
            case 'shareai':
                return !empty($settings['shareai_api_key']);
            case 'openai':
                return !empty($settings['openai_api_key'])
                    || (function_exists('aiohm_kb_is_wp_ai_connector_configured') && aiohm_kb_is_wp_ai_connector_configured('openai'));
            case 'gemini':
                return !empty($settings['gemini_api_key'])
                    || (function_exists('aiohm_kb_is_wp_ai_connector_configured') && aiohm_kb_is_wp_ai_connector_configured('gemini'));
            case 'claude':
                return !empty($settings['claude_api_key'])
                    || (function_exists('aiohm_kb_is_wp_ai_connector_configured') && aiohm_kb_is_wp_ai_connector_configured('claude'));
            default:
                return false;
        }
    }

    public static function handle_load_history_ajax() {
        check_ajax_referer('aiohm_private_chat_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission Denied', 'aiohm-knowledge-assistant')]);
            wp_die();
        }

        global $wpdb;
        $user_id = get_current_user_id();

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        // User data queries with caching for dashboard performance
        
        // Fetch projects with caching
        $projects_cache_key = 'aiohm_user_projects_' . $user_id;
        $projects = wp_cache_get($projects_cache_key, 'aiohm_core');
        
        if (false === $projects) {
            // Validate table name for security
            $safe_table = self::get_safe_table_name($wpdb->prefix . 'aiohm_projects');
            
            $projects = $wpdb->get_results($wpdb->prepare(
                "SELECT id, project_name as name FROM `" . esc_sql($safe_table) . "` WHERE user_id = %d ORDER BY creation_date DESC",
                $user_id
            ), ARRAY_A);
            wp_cache_set($projects_cache_key, $projects, 'aiohm_core', 300); // 5 minute cache
        }

        // Fetch recent conversations with caching
        $conversations_cache_key = 'aiohm_user_conversations_' . $user_id;
        $conversations = wp_cache_get($conversations_cache_key, 'aiohm_core');
        
        if (false === $conversations) {
            $conversations = $wpdb->get_results($wpdb->prepare(
                "SELECT id, title, project_id FROM " . $wpdb->prefix . "aiohm_conversations WHERE user_id = %d ORDER BY updated_at DESC LIMIT 50",
                $user_id
            ), ARRAY_A);
            wp_cache_set($conversations_cache_key, $conversations, 'aiohm_core', 180); // 3 minute cache
        }
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

        wp_send_json_success(['projects' => $projects, 'conversations' => $conversations]);
        wp_die();
    }

    /**
     * Handles the AJAX request to load a specific conversation's messages.
     */
    public static function handle_load_conversation_ajax() {
        check_ajax_referer('aiohm_private_chat_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission Denied', 'aiohm-knowledge-assistant')]);
            wp_die();
        }
    
        $conversation_id = isset($_POST['conversation_id']) ? intval($_POST['conversation_id']) : 0;
        if (empty($conversation_id)) {
            wp_send_json_error(['message' => __('Invalid Conversation ID.', 'aiohm-knowledge-assistant')]);
            wp_die();
        }
    
        global $wpdb;
        $user_id = get_current_user_id();
        $messages_table = $wpdb->prefix . 'aiohm_messages';
        $conversations_table = $wpdb->prefix . 'aiohm_conversations';
        $projects_table = $wpdb->prefix . 'aiohm_projects';
    
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        // Conversation lookup with caching for message retrieval
        
        // Verify conversation belongs to the user and get its project ID with caching
        $conversation_cache_key = 'aiohm_conversation_' . $conversation_id . '_' . $user_id;
        $conversation_info = wp_cache_get($conversation_cache_key, 'aiohm_core');
        
        if (false === $conversation_info) {
            $conversation_info = $wpdb->get_row($wpdb->prepare(
                "SELECT c.id, c.title, c.project_id, p.project_name FROM " . $wpdb->prefix . "aiohm_conversations c JOIN " . $wpdb->prefix . "aiohm_projects p ON c.project_id = p.id WHERE c.id = %d AND c.user_id = %d",
                $conversation_id,
                $user_id
            ), ARRAY_A);
            
            if ($conversation_info) {
                wp_cache_set($conversation_cache_key, $conversation_info, 'aiohm_core', 600); // 10 minute cache
            }
        }
    
        if (!$conversation_info) {
            wp_send_json_error(['message' => __('Conversation not found or not accessible.', 'aiohm-knowledge-assistant')]);
            wp_die();
        }
    
        // Fetch messages for the conversation with caching
        $messages_cache_key = 'aiohm_conversation_messages_' . $conversation_id;
        $messages = wp_cache_get($messages_cache_key, 'aiohm_core');
        
        if (false === $messages) {
            $messages = $wpdb->get_results($wpdb->prepare(
                "SELECT sender, content as message_content FROM " . $wpdb->prefix . "aiohm_messages WHERE conversation_id = %d ORDER BY created_at ASC",
                $conversation_id
            ), ARRAY_A);
            wp_cache_set($messages_cache_key, $messages, 'aiohm_core', 300); // 5 minute cache
        }
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    
        wp_send_json_success([
            'messages' => $messages,
            'project_id' => $conversation_info['project_id'],
            'project_name' => $conversation_info['project_name'],
            'conversation_title' => $conversation_info['title']
        ]);
        wp_die();
    }

    /**
     * Handles AJAX request to save project notes.
     */
    public static function handle_save_project_notes_ajax() {
        check_ajax_referer('aiohm_private_chat_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission Denied', 'aiohm-knowledge-assistant')]);
            wp_die();
        }
    
        $project_id = isset($_POST['project_id']) ? intval($_POST['project_id']) : 0;
        $note_content = isset($_POST['note_content']) ? sanitize_textarea_field(wp_unslash($_POST['note_content'])) : '';
    
        if (empty($project_id)) {
            wp_send_json_error(['message' => __('Invalid Project ID.', 'aiohm-knowledge-assistant')]);
            wp_die();
        }
    
        global $wpdb;
        $table_name = $wpdb->prefix . 'aiohm_projects';
        $user_id = get_current_user_id();
    
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        // Project ownership verification and notes update require direct database access with proper table name interpolation
        
        // Ensure the project belongs to the current user
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(id) FROM " . $wpdb->prefix . "aiohm_projects WHERE id = %d AND user_id = %d",
            $project_id,
            $user_id
        ));
    
        if (!$exists) {
            wp_send_json_error(['message' => __('Project not found or not owned by user.', 'aiohm-knowledge-assistant')]);
            wp_die();
        }
    
        // Update project notes with cache clearing
        $updated = $wpdb->update(
            $table_name,
            ['notes' => $note_content],
            ['id' => $project_id],
            ['%s'],
            ['%d']
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    
        if ($updated !== false) {
            wp_send_json_success(['message' => __('Notes saved.', 'aiohm-knowledge-assistant')]);
        } else {
            wp_send_json_error(['message' => __('Failed to save notes.', 'aiohm-knowledge-assistant')]);
        }
        wp_die();
    }

    /**
     * Handles AJAX request to load project notes.
     */
    public static function handle_load_project_notes_ajax() {
        check_ajax_referer('aiohm_private_chat_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission Denied', 'aiohm-knowledge-assistant')]);
            wp_die();
        }
    
        $project_id = isset($_POST['project_id']) ? intval($_POST['project_id']) : 0;
    
        if (empty($project_id)) {
            wp_send_json_error(['message' => __('Invalid Project ID.', 'aiohm-knowledge-assistant')]);
            wp_die();
        }
    
        global $wpdb;
        $table_name = $wpdb->prefix . 'aiohm_projects';
        $user_id = get_current_user_id();
    
        // Get notes with caching
        $notes_cache_key = 'aiohm_project_notes_' . $project_id . '_' . $user_id;
        $note_content = wp_cache_get($notes_cache_key, 'aiohm_core');
        
        if (false === $note_content) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            // Project notes query with caching implementation requires direct database access with table name interpolation
            $note_content = $wpdb->get_var($wpdb->prepare(
                "SELECT notes FROM " . $wpdb->prefix . "aiohm_projects WHERE id = %d AND user_id = %d",
                $project_id,
                $user_id
            ));
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            
            wp_cache_set($notes_cache_key, $note_content, 'aiohm_core', 600); // 10 minute cache
        }
    
        if ($note_content !== null) {
            wp_send_json_success(['note_content' => $note_content]);
        } else {
            wp_send_json_error(['message' => __('Project notes not found or not accessible.', 'aiohm-knowledge-assistant')]);
        }
        wp_die();
    }

    /**
     * Handles AJAX request to delete a project.
     */
    public static function handle_delete_project_ajax() {
        check_ajax_referer('aiohm_private_chat_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission Denied', 'aiohm-knowledge-assistant')]);
            wp_die();
        }
    
        $project_id = isset($_POST['project_id']) ? intval($_POST['project_id']) : 0;
    
        if (empty($project_id)) {
            wp_send_json_error(['message' => __('Invalid Project ID.', 'aiohm-knowledge-assistant')]);
            wp_die();
        }
    
        global $wpdb;
        $user_id = get_current_user_id();
    
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        // Project deletion operations require direct database access for atomic deletion with proper cache clearing
        
        // Delete associated conversations first
        $conversations_table = $wpdb->prefix . 'aiohm_conversations';
        $wpdb->delete($conversations_table, ['project_id' => $project_id, 'user_id' => $user_id], ['%d', '%d']);
    
        // Delete the project
        $projects_table = $wpdb->prefix . 'aiohm_projects';
        $deleted = $wpdb->delete($projects_table, ['id' => $project_id, 'user_id' => $user_id], ['%d', '%d']);
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    
        if ($deleted) {
            // Clear all caches related to this project and user
            wp_cache_delete('aiohm_user_projects_' . $user_id, 'aiohm_core');
            wp_cache_delete('aiohm_user_conversations_' . $user_id, 'aiohm_core');
            wp_cache_delete('aiohm_project_notes_' . $project_id . '_' . $user_id, 'aiohm_core');
            wp_cache_delete('aiohm_project_conversations_' . $project_id, 'aiohm_core');
            
            wp_send_json_success(['message' => __('Project and its conversations deleted.', 'aiohm-knowledge-assistant')]);
        } else {
            wp_send_json_error(['message' => __('Failed to delete project or project not found.', 'aiohm-knowledge-assistant')]);
        }
        wp_die();
    }
    
    /**
     * Handles AJAX request to delete a conversation.
     */
    public static function handle_delete_conversation_ajax() {
        check_ajax_referer('aiohm_private_chat_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission Denied', 'aiohm-knowledge-assistant')]);
            wp_die();
        }
    
        $conversation_id = isset($_POST['conversation_id']) ? intval($_POST['conversation_id']) : 0;
    
        if (empty($conversation_id)) {
            wp_send_json_error(['message' => __('Invalid Conversation ID.', 'aiohm-knowledge-assistant')]);
            wp_die();
        }
    
        global $wpdb;
        $user_id = get_current_user_id();
    
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        // Conversation deletion operations require direct database access for atomic deletion with proper cache clearing
        
        // Delete associated messages first
        $messages_table = $wpdb->prefix . 'aiohm_messages';
        $wpdb->delete($messages_table, ['conversation_id' => $conversation_id], ['%d']);
    
        // Delete the conversation
        $conversations_table = $wpdb->prefix . 'aiohm_conversations';
        $deleted = $wpdb->delete($conversations_table, ['id' => $conversation_id, 'user_id' => $user_id], ['%d', '%d']);
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    
        if ($deleted) {
            wp_send_json_success(['message' => __('Conversation and its messages deleted.', 'aiohm-knowledge-assistant')]);
        } else {
            wp_send_json_error(['message' => __('Failed to delete conversation or conversation not found.', 'aiohm-knowledge-assistant')]);
        }
        wp_die();
    }

    /**
     * Handles AJAX request to create a new conversation.
     */
    public static function handle_create_conversation_ajax() {
        check_ajax_referer('aiohm_private_chat_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission Denied', 'aiohm-knowledge-assistant')]);
            wp_die();
        }
    
        $project_id = isset($_POST['project_id']) ? intval($_POST['project_id']) : 0;
        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : 'New Chat';
    
        if (empty($project_id)) {
            wp_send_json_error(['message' => __('Invalid Project ID.', 'aiohm-knowledge-assistant')]);
            wp_die();
        }
    
        global $wpdb;
        $user_id = get_current_user_id();
        $projects_table = $wpdb->prefix . 'aiohm_projects';
    
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        // Project verification query requires direct database access with table name interpolation
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(id) FROM " . $wpdb->prefix . "aiohm_projects WHERE id = %d AND user_id = %d",
            $project_id,
            $user_id
        ));
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    
        if (!$exists) {
            wp_send_json_error(['message' => __('Project not found or not owned by user.', 'aiohm-knowledge-assistant')]);
            wp_die();
        }
    
        $conversation_id = self::create_conversation_internal($user_id, $project_id, $title);
    
        if ($conversation_id) {
            wp_send_json_success(['conversation_id' => $conversation_id, 'title' => $title]);
        } else {
            wp_send_json_error(['message' => __('Failed to create conversation.', 'aiohm-knowledge-assistant')]);
        }
        wp_die();
    }


    // ================== START: NEW FUNCTION TO FIX PROJECTS ==================
    /**
     * Handles the AJAX request to get all conversations for a specific project.
     * This function was missing, causing projects not to load.
     */
    public static function handle_get_project_conversations_ajax() {
        check_ajax_referer('aiohm_private_chat_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission Denied', 'aiohm-knowledge-assistant')]);
            wp_die();
        }

        $project_id = isset($_POST['project_id']) ? intval($_POST['project_id']) : 0;
        if (empty($project_id)) {
            wp_send_json_error(['message' => __('Invalid Project ID.', 'aiohm-knowledge-assistant')]);
            wp_die();
        }

        global $wpdb;
        $user_id = get_current_user_id();
        $table_name = $wpdb->prefix . 'aiohm_conversations';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        // User conversations query requires direct database access with table name interpolation
        $conversations = $wpdb->get_results($wpdb->prepare(
            "SELECT id, title FROM " . $wpdb->prefix . "aiohm_conversations WHERE user_id = %d AND project_id = %d ORDER BY updated_at DESC",
            $user_id,
            $project_id
        ));
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        wp_send_json_success(['conversations' => $conversations]);
        wp_die();
    }

    public static function handle_create_project_ajax() {
        check_ajax_referer('aiohm_private_chat_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(['message' => 'Permission denied.']); wp_die(); }
        
        $project_name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        if (empty($project_name)) { wp_send_json_error(['message' => __('Project name cannot be empty.', 'aiohm-knowledge-assistant')]); wp_die(); }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'aiohm_projects';
        $user_id = get_current_user_id();
        
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
        // Project creation requires direct database access with cache invalidation handled elsewhere
        $result = $wpdb->insert($table_name, ['user_id' => $user_id, 'project_name' => $project_name], ['%d', '%s']);
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
        
        if ($result === false) {
             wp_send_json_error(['message' => __('Could not save the project to the database.', 'aiohm-knowledge-assistant')]);
        } else {
            $project_id = $wpdb->insert_id;
            wp_send_json_success(['new_project_id' => $project_id, 'name' => $project_name]);
        }
        wp_die();
    }

    public static function handle_private_assistant_chat_ajax() {
        // Enhanced rate limiting for private chat
        if (!AIOHM_KB_Assistant::check_enhanced_rate_limit('private_chat', get_current_user_id(), 30)) {
            wp_send_json_error(['message' => __('Private chat rate limit exceeded. Please wait before sending more messages.', 'aiohm-knowledge-assistant')]);
        }

        if (!check_ajax_referer('aiohm_private_chat_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed.', 'aiohm-knowledge-assistant')]);
        }
    
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to use this feature.', 'aiohm-knowledge-assistant')]);
        }
    
        try {
            $user_message = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';
            
            // Enhanced message validation
            if (empty(trim($user_message))) {
                wp_send_json_error(['message' => __('Message cannot be empty.', 'aiohm-knowledge-assistant')]);
            }
            
            if (strlen($user_message) > 3000) {
                wp_send_json_error(['message' => __('Message is too long. Please limit to 3000 characters.', 'aiohm-knowledge-assistant')]);
            }
            
            // Check for potentially malicious content
            if (preg_match('/<script|<iframe|javascript:|vbscript:|onload=|onerror=/i', $user_message)) {
                wp_send_json_error(['message' => __('Message contains invalid content.', 'aiohm-knowledge-assistant')]);
            }
            $user_id = get_current_user_id();
            
            $project_id = isset($_POST['project_id']) ? intval($_POST['project_id']) : 0;
            $conversation_id = isset($_POST['conversation_id']) ? intval($_POST['conversation_id']) : null;

            if (empty($project_id)) {
                throw new Exception('A project must be selected to start a conversation.');
            }

            $settings = AIOHM_KB_Assistant::get_settings();
            $muse_settings = $settings['muse_mode'] ?? [];
    
            // Initialize AI client with current settings to support all providers
            $ai_client = new AIOHM_KB_AI_GPT_Client($settings);
            $rag_engine = new AIOHM_KB_RAG_Engine();
            
            $context_data = $rag_engine->find_context_for_user($user_message, $user_id, 10);
            $context_string = "";
            if (!empty($context_data)) {
                foreach ($context_data as $data) {
                    $context_string .= "Source: " . $data['entry']['title'] . "\nContent: " . $data['entry']['content'] . "\n\n";
                }
            }
    
            $system_prompt = $muse_settings['system_prompt'] ?? 'You are a helpful brand assistant.';
            $temperature = floatval($muse_settings['temperature'] ?? 0.7);
            
            // Use the user's selected default AI provider with smart fallback
            $default_provider = $settings['default_ai_provider'] ?? null;
            if (function_exists('aiohm_kb_normalize_wp_ai_provider_id')) {
                $default_provider = aiohm_kb_normalize_wp_ai_provider_id($default_provider);
            }
            $provider_priority = ['shareai', 'openai', 'gemini', 'claude'];
            
            // If no default set, find first available provider
            if (!$default_provider) {
                foreach ($provider_priority as $provider) {
                    if (self::is_provider_configured($settings, $provider)) {
                        $default_provider = $provider;
                        break;
                    }
                }
            }
            
            // If default provider not configured, fallback to next available
            if (!self::is_provider_configured($settings, $default_provider)) {
                foreach ($provider_priority as $provider) {
                    if (self::is_provider_configured($settings, $provider)) {
                        $default_provider = $provider;
                        break;
                    }
                }
            }
            
            // Set appropriate model based on provider
            switch ($default_provider) {
                case 'shareai':
                    $model = $settings['shareai_model'] ?? 'llama4:17b-scout-16e-instruct-fp16';
                    break;
                case 'gemini':
                    $model = 'gemini-pro';
                    break;
                case 'claude':
                    $model = 'claude-3-sonnet-20240229';
                    break;
                case 'openai':
                    $model = 'gpt-4';
                    break;
                default:
                    $model = 'gpt-4'; // Final fallback
                    break;
            }
            
            // Enhanced formatting instructions for better readability
            $formatting_instructions = "\n\n--- FORMATTING INSTRUCTIONS ---\n" .
                "Format your responses with clear structure using:\n" .
                "- **Bold headings** for main topics\n" .
                "- Bullet points for lists\n" .
                "- Numbered lists for step-by-step instructions\n" .
                "- Tables when presenting comparative data\n" .
                "- Use line breaks for better readability\n" .
                "- Keep paragraphs concise and focused\n\n";
            
            // Load conversation history for context if conversation exists
            $conversation_history = '';
            if ($conversation_id && !empty($conversation_id)) {
                global $wpdb;
                // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                // Conversation history query for AI context requires direct database access with table name interpolation
                $messages = $wpdb->get_results($wpdb->prepare(
                    "SELECT sender, content FROM " . $wpdb->prefix . "aiohm_messages WHERE conversation_id = %d ORDER BY created_at ASC",
                    $conversation_id
                ), ARRAY_A);
                // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                
                if (!empty($messages)) {
                    $conversation_history = "\n--- CONVERSATION HISTORY ---\n";
                    foreach ($messages as $message) {
                        $role = ($message['sender'] === 'user') ? 'User' : 'Assistant';
                        $conversation_history .= "{$role}: {$message['content']}\n\n";
                    }
                }
            }
            
            $final_system_message = $system_prompt . $formatting_instructions . "--- CONTEXT ---\n" . $context_string . $conversation_history;
    
            $answer = $ai_client->get_chat_completion($final_system_message, $user_message, $temperature, $model);

            if (is_null($conversation_id) || empty($conversation_id)) {
                // Generate AI-powered conversation title
                $conversation_title = self::generate_conversation_title($user_message, $ai_client, $model);
                $conversation_id = self::create_conversation_internal($user_id, $project_id, $conversation_title);
                if (!$conversation_id) {
                }
            }
            
            if ($conversation_id) {
                self::add_message_to_conversation_internal($conversation_id, 'user', $user_message);
                self::add_message_to_conversation_internal($conversation_id, 'ai', $answer);
            }
    
            // This was already fixed in the last step to use 'reply'
            wp_send_json_success(['reply' => $answer, 'conversation_id' => $conversation_id]);
    
        } catch (Exception $e) {
            $safe_message = self::get_safe_error_message($e, 'Private Assistant');
            wp_send_json_error(['message' => $safe_message]);
        }
    }

    public static function handle_frontend_chat_ajax() {
        // Enhanced rate limiting for chat requests - stricter for public endpoints
        $user_id = get_current_user_id() ?: 0;
        $rate_limit = $user_id > 0 ? 50 : 20; // Lower limit for non-authenticated users
        
        if (!AIOHM_KB_Assistant::check_enhanced_rate_limit('frontend_chat', $user_id, $rate_limit)) {
            wp_send_json_error(['reply' => __('Chat rate limit exceeded. Please wait before sending more messages.', 'aiohm-knowledge-assistant')]);
        }

        if (!check_ajax_referer('aiohm_chat_nonce', 'nonce', false)) {
            wp_send_json_error(['reply' => __('Security check failed.', 'aiohm-knowledge-assistant')]);
        }
        
        // Additional validation for public endpoints
        if (!isset($_POST['message']) || !is_string($_POST['message'])) {
            wp_send_json_error(['reply' => __('Invalid message format.', 'aiohm-knowledge-assistant')]);
        }

        try {
            $user_message = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';
            
            // Enhanced message validation
            if (empty(trim($user_message))) {
                wp_send_json_error(['reply' => __('Message cannot be empty.', 'aiohm-knowledge-assistant')]);
            }
            
            if (strlen($user_message) > 2000) {
                wp_send_json_error(['reply' => __('Message is too long. Please limit to 2000 characters.', 'aiohm-knowledge-assistant')]);
            }
            
            // Check for potentially malicious content
            if (preg_match('/<script|<iframe|javascript:|vbscript:|onload=|onerror=/i', $user_message)) {
                wp_send_json_error(['reply' => __('Message contains invalid content.', 'aiohm-knowledge-assistant')]);
            }
            
            $settings = AIOHM_KB_Assistant::get_settings();
            $mirror_settings = $settings['mirror_mode'] ?? [];

            $ai_client = new AIOHM_KB_AI_GPT_Client();
            $rag_engine = new AIOHM_KB_RAG_Engine();
            
            $context_data = $rag_engine->find_relevant_context($user_message, 5);
            $context_string = "";
            $has_context = false;
            
            if (!empty($context_data)) {
                foreach ($context_data as $data) {
                    $context_string .= "Source: " . $data['entry']['title'] . "\nContent: " . $data['entry']['content'] . "\n\n";
                }
                $has_context = true;
            }
            
            // Check if this is a greeting or basic interaction that doesn't need knowledge base context
            $greeting_patterns = ['hello', 'hi', 'hey', 'good morning', 'good afternoon', 'good evening', 'greetings', 'how are you', 'what\'s up', 'sup', 'heloo', 'helo', 'hllo'];
            $is_greeting = false;
            
            // Also check for very short messages (likely greetings)
            $is_short_message = strlen(trim($user_message)) <= 10;
            
            foreach ($greeting_patterns as $pattern) {
                if (stripos($user_message, $pattern) !== false) {
                    $is_greeting = true;
                    break;
                }
            }
            
            // Consider it a greeting if it's a short message or matches greeting patterns
            $is_greeting = $is_greeting || $is_short_message;
            
            if (!$has_context && !$is_greeting) {
                $context_string = "No relevant context found in the knowledge base.";
            } elseif (!$has_context && $is_greeting) {
                $context_string = "GREETING DETECTED: This is a friendly greeting or casual message. You should respond warmly and naturally as the brand representative. Introduce yourself, welcome them, and offer to help. You DO NOT need knowledge base context for basic greetings and social interactions. Be conversational and friendly.";
            }

            $system_message = $mirror_settings['qa_system_message'] ?? 'You are a helpful assistant.';
            $temperature = floatval($mirror_settings['qa_temperature'] ?? 0.8);
            
            // Use the user's selected default AI provider with smart fallback
            $default_provider = $settings['default_ai_provider'] ?? null;
            if (function_exists('aiohm_kb_normalize_wp_ai_provider_id')) {
                $default_provider = aiohm_kb_normalize_wp_ai_provider_id($default_provider);
            }
            $provider_priority = ['shareai', 'openai', 'gemini', 'claude'];
            
            // If no default set, find first available provider
            if (!$default_provider) {
                foreach ($provider_priority as $provider) {
                    if (self::is_provider_configured($settings, $provider)) {
                        $default_provider = $provider;
                        break;
                    }
                }
            }
            
            // If default provider not configured, fallback to next available
            if (!self::is_provider_configured($settings, $default_provider)) {
                foreach ($provider_priority as $provider) {
                    if (self::is_provider_configured($settings, $provider)) {
                        $default_provider = $provider;
                        break;
                    }
                }
            }
            
            // Set appropriate model based on provider (Mirror Mode)
            switch ($default_provider) {
                case 'shareai':
                    $model = $settings['shareai_model'] ?? 'llama4:17b-scout-16e-instruct-fp16';
                    break;
                case 'gemini':
                    $model = 'gemini-pro';
                    break;
                case 'claude':
                    $model = 'claude-3-sonnet-20240229';
                    break;
                case 'openai':
                    $model = 'gpt-3.5-turbo';
                    break;
                default:
                    $model = 'gpt-3.5-turbo'; // Final fallback
                    break;
            }
            
            // Apply variable replacements to system message
            $replacements = [
                '{context}'        => $context_string,
                '%site_name%'      => get_bloginfo('name'),
                '%site_tagline%'   => get_bloginfo('description'),
                '%business_name%'  => $mirror_settings['business_name'] ?? get_bloginfo('name'),
                '%day_of_week%'    => wp_date('l'),
                '%current_date%'   => wp_date(get_option('date_format')),
                '%current_time%'   => wp_date(get_option('time_format')),
            ];
            $final_system_message = str_replace(array_keys($replacements), array_values($replacements), $system_message);

            $answer = $ai_client->get_chat_completion($final_system_message, $user_message, $temperature, $model);
            
            // Apply variable replacements to the AI response as well in case it contains placeholders
            // Use strtr instead of str_replace to avoid partial matches and conflicts
            $final_answer = strtr($answer, $replacements);

            wp_send_json_success(['reply' => $final_answer]);

        } catch (Exception $e) {
            wp_send_json_error(['reply' => __('An error occurred while processing your request. Please try again.', 'aiohm-knowledge-assistant')]);
        }
    }
    
    public static function handle_search_knowledge_ajax() {
        // Enhanced rate limiting for search requests - stricter for public endpoints
        $user_id = get_current_user_id() ?: 0;
        $rate_limit = $user_id > 0 ? 30 : 15; // Lower limit for non-authenticated users
        
        if (!AIOHM_KB_Assistant::check_enhanced_rate_limit('search_knowledge', $user_id, $rate_limit)) {
            wp_send_json_error(['message' => __('Search rate limit exceeded. Please wait before searching again.', 'aiohm-knowledge-assistant')]);
        }

        if (!check_ajax_referer('aiohm_search_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed', 'aiohm-knowledge-assistant')]);
        }
        
        // Additional input validation for public endpoints
        if (!isset($_POST['query']) || !is_string($_POST['query'])) {
            wp_send_json_error(['message' => __('Invalid search query format.', 'aiohm-knowledge-assistant')]);
        }
        
        $query = sanitize_text_field(wp_unslash($_POST['query']));
        $content_type_filter = isset($_POST['content_type_filter']) ? sanitize_text_field(wp_unslash($_POST['content_type_filter'])) : '';
        $max_results = isset($_POST['max_results']) ? intval($_POST['max_results']) : 10;
        $excerpt_length = isset($_POST['excerpt_length']) ? intval($_POST['excerpt_length']) : 25;
        
        // Validate and limit search parameters
        $max_results = min($max_results, 20); // Cap at 20 results to prevent abuse
        $excerpt_length = min($excerpt_length, 100); // Cap excerpt length
        
        if (empty(trim($query)) || strlen($query) < 2) {
            wp_send_json_error(['message' => __('Search query must be at least 2 characters long.', 'aiohm-knowledge-assistant')]);
        }
        
        if (strlen($query) > 200) {
            wp_send_json_error(['message' => __('Search query is too long.', 'aiohm-knowledge-assistant')]);
        }
        
        try {
            $rag_engine = new AIOHM_KB_RAG_Engine();
            $results = $rag_engine->find_relevant_context($query, $max_results);
            
            $filtered_results = [];
            if (!empty($content_type_filter)) {
                 foreach ($results as $result) {
                    if ($result['entry']['content_type'] === $content_type_filter) {
                        $filtered_results[] = $result;
                    }
                }
            } else {
                $filtered_results = $results;
            }
            
            $formatted_results = array();
            foreach ($filtered_results as $result) {
                $entry = $result['entry'];
                $excerpt = wp_trim_words($entry['content'], $excerpt_length, '...');
                $metadata = is_string($entry['metadata']) ? json_decode($entry['metadata'], true) : $entry['metadata'];

                $formatted_results[] = array(
                    'title' => $entry['title'],
                    'excerpt' => $excerpt,
                    'content_type' => $entry['content_type'],
                    'similarity' => round($result['score'] * 100, 1),
                    'url' => $metadata['url'] ?? get_permalink($metadata['post_id'] ?? 0) ?? '#',
                );
            }
            
            wp_send_json_success([
                'results' => $formatted_results,
                'total_count' => count($formatted_results),
            ]);
            
        } catch (Exception $e) {
            $safe_message = self::get_safe_error_message($e, 'Admin Search');
            wp_send_json_error(['message' => $safe_message]);
        }
    }
    
    public static function handle_admin_search_knowledge_ajax() {
        if (!check_ajax_referer('aiohm_mirror_mode_nonce', 'nonce', false) || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Security check failed or insufficient permissions.']);
        }
        
        $query = isset($_POST['query']) ? sanitize_text_field(wp_unslash($_POST['query'])) : '';
        $content_type_filter = isset($_POST['content_type_filter']) ? sanitize_text_field(wp_unslash($_POST['content_type_filter'])) : '';
        $max_results = 5;
        $excerpt_length = 20;

        if (empty($query)) {
            wp_send_json_error(['message' => __('Search query is required.', 'aiohm-knowledge-assistant')]);
        }
        
        try {
            $rag_engine = new AIOHM_KB_RAG_Engine();
            // Use find_relevant_context to ensure only public content (user_id = 0) is shown in test
            $results = $rag_engine->find_relevant_context($query, $max_results);
            
            $filtered_results = [];
            if (!empty($content_type_filter)) {
                 foreach ($results as $result) {
                    if ($result['entry']['content_type'] === $content_type_filter) {
                        $filtered_results[] = $result;
                    }
                }
            } else {
                $filtered_results = $results;
            }
            
            $formatted_results = array();
            foreach ($filtered_results as $result) {
                $entry = $result['entry'];
                $excerpt = wp_trim_words($entry['content'], $excerpt_length, '...');
                $metadata = is_string($entry['metadata']) ? json_decode($entry['metadata'], true) : $entry['metadata'];

                $formatted_results[] = array(
                    'title' => $entry['title'],
                    'excerpt' => $excerpt,
                    'content_type' => $entry['content_type'],
                    'similarity' => round($result['score'] * 100, 1),
                    'url' => $metadata['url'] ?? get_permalink($metadata['post_id'] ?? 0) ?? '#',
                );
            }
            
            wp_send_json_success([
                'results' => $formatted_results,
            ]);
            
        } catch (Exception $e) {
            $safe_message = self::get_safe_error_message($e, 'Admin Search');
            wp_send_json_error(['message' => $safe_message]);
        }
    }

    public static function handle_test_mirror_mode_chat_ajax() {
        if (!check_ajax_referer('aiohm_mirror_mode_nonce', 'aiohm_mirror_mode_nonce_field', false) || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Security check failed.', 'aiohm-knowledge-assistant')]);
        }

        try {
            $user_message = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';
            $posted_settings = isset($_POST['settings']) && is_array($_POST['settings']) ? map_deep(wp_unslash($_POST['settings']), 'sanitize_text_field') : [];

            $system_message = isset($posted_settings['qa_system_message']) ? wp_kses_post($posted_settings['qa_system_message']) : 'You are a helpful assistant.';
            
            $temperature = floatval($posted_settings['qa_temperature'] ?? 0.7);
            
            // Initialize AI client with current settings
            $current_settings = AIOHM_KB_Assistant::get_settings();
            $ai_client = new AIOHM_KB_AI_GPT_Client($current_settings);
            $rag_engine = new AIOHM_KB_RAG_Engine();
            
            
            // Test: Try a broader search first
            $context_data = $rag_engine->find_relevant_context($user_message, 5);
            
            // If no results, try a simple keyword search as fallback
            if (empty($context_data)) {
                $context_data = $rag_engine->find_relevant_context('company', 10);
                if (empty($context_data)) {
                    $context_data = $rag_engine->find_relevant_context('about', 10);
                }
            }
            $context_string = "";
            foreach ($context_data as $data) {
                $context_string .= "Source: " . $data['entry']['title'] . "\nContent: " . $data['entry']['content'] . "\n\n";
            }
            if (empty($context_string)) {
                $context_string = "No relevant context found.";
            }
            

            $replacements = [
                '{context}'        => $context_string,
                '%site_name%'      => $posted_settings['business_name'] ?? get_bloginfo('name'),
                '%business_name%'  => $posted_settings['business_name'] ?? get_bloginfo('name'),
                '%day_of_week%'    => wp_date('l'),
                '%current_date%'   => wp_date(get_option('date_format')),
                '%current_time%'   => wp_date(get_option('time_format')),
            ];
            $final_system_message = str_replace(array_keys($replacements), array_values($replacements), $system_message);
            

            // Use the user's selected default AI provider with smart fallback
            $default_provider = $current_settings['default_ai_provider'] ?? null;
            if (function_exists('aiohm_kb_normalize_wp_ai_provider_id')) {
                $default_provider = aiohm_kb_normalize_wp_ai_provider_id($default_provider);
            }
            $provider_priority = ['shareai', 'openai', 'gemini', 'claude'];
            
            // If no default set, find first available provider
            if (!$default_provider) {
                foreach ($provider_priority as $provider) {
                    if (self::is_provider_configured($current_settings, $provider)) {
                        $default_provider = $provider;
                        break;
                    }
                }
            }
            
            // If default provider not configured, fallback to next available
            if (!self::is_provider_configured($current_settings, $default_provider)) {
                foreach ($provider_priority as $provider) {
                    if (self::is_provider_configured($current_settings, $provider)) {
                        $default_provider = $provider;
                        break;
                    }
                }
            }
            
            // Set appropriate model based on provider (Mirror Mode Test)
            switch ($default_provider) {
                case 'shareai':
                    $model = $current_settings['shareai_model'] ?? 'llama4:17b-scout-16e-instruct-fp16';
                    break;
                case 'gemini':
                    $model = 'gemini-pro';
                    break;
                case 'claude':
                    $model = 'claude-3-sonnet-20240229';
                    break;
                case 'openai':
                    $model = 'gpt-3.5-turbo';
                    break;
                default:
                    $model = 'gpt-3.5-turbo'; // Final fallback
                    break;
            }
            
            $answer = $ai_client->get_chat_completion($final_system_message, $user_message, $temperature, $model);

            // Ensure answer is a string for JSON response
            if (!is_string($answer)) {
                $answer = is_array($answer) || is_object($answer) ? json_encode($answer) : (string)$answer;
            }

            wp_send_json_success(['answer' => $answer]);

        } catch (Exception $e) {
            $safe_message = self::get_safe_error_message($e, 'AI Request');
            wp_send_json_error(['message' => $safe_message]);
        }
    }
    
    public static function handle_test_muse_mode_chat_ajax() {
        if (!check_ajax_referer('aiohm_muse_mode_nonce', 'aiohm_muse_mode_nonce_field', false) || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Security check failed.', 'aiohm-knowledge-assistant')]);
        }
    
        try {
            $user_message = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';
            $posted_settings = isset($_POST['settings']) && is_array($_POST['settings']) ? map_deep(wp_unslash($_POST['settings']), 'sanitize_text_field') : [];
            $user_id = get_current_user_id();
    
            $system_prompt = wp_kses_post($posted_settings['system_prompt'] ?? 'You are a helpful brand assistant.');
            $temperature = floatval($posted_settings['temperature'] ?? 0.7);
            $model = sanitize_text_field($posted_settings['ai_model'] ?? 'gpt-4');
    
            // Initialize AI client with current settings
            $current_settings = AIOHM_KB_Assistant::get_settings();
            $ai_client = new AIOHM_KB_AI_GPT_Client($current_settings);
            $rag_engine = new AIOHM_KB_RAG_Engine();
            
            $context_data = $rag_engine->find_context_for_user($user_message, $user_id, 10);
            $context_string = "";
            if (!empty($context_data)) {
                foreach ($context_data as $data) {
                    $context_string .= "Source: " . $data['entry']['title'] . "\nContent: " . $data['entry']['content'] . "\n\n";
                }
            }
            
            $final_system_message = $system_prompt . "\n\n--- CONTEXT ---\n" . $context_string;
    
            $answer = $ai_client->get_chat_completion($final_system_message, $user_message, $temperature, $model);
    
            wp_send_json_success(['answer' => $answer]);
    
        } catch (Exception $e) {
            $safe_message = self::get_safe_error_message($e, 'AI Request');
            wp_send_json_error(['message' => $safe_message]);
        }
    }

    public static function handle_progressive_scan_ajax() {
        check_ajax_referer('aiohm_admin_nonce', 'nonce');
        try {
            $scan_type = isset($_POST['scan_type']) ? sanitize_text_field(wp_unslash($_POST['scan_type'])) : '';
            switch ($scan_type) {
                case 'website_find':
                    $crawler = new AIOHM_KB_Site_Crawler();
                    $all_items = $crawler->find_all_content();
                    wp_send_json_success(['items' => $all_items]);
                    break;
                case 'website_add':
                    $raw_item_ids = isset($_POST['item_ids']) ? map_deep(wp_unslash($_POST['item_ids']), 'sanitize_text_field') : [];
                    if (!is_array($raw_item_ids)) {
                        throw new Exception('Invalid item IDs format.');
                    }
                    // Use sanitized item IDs
                    $item_ids = $raw_item_ids;
                    $item_ids = array_map('intval', $item_ids);
                    // Filter out invalid IDs (0 or negative values)
                    $item_ids = array_filter($item_ids, function($id) { return $id > 0; });
                    if (empty($item_ids)) throw new Exception('No valid item IDs provided.');
                    
                    // Check prerequisites - validate API key for selected provider
                    $settings = AIOHM_KB_Assistant::get_settings();
                    $default_provider = $settings['default_ai_provider'] ?? 'openai';
                    if (function_exists('aiohm_kb_normalize_wp_ai_provider_id')) {
                        $default_provider = aiohm_kb_normalize_wp_ai_provider_id($default_provider);
                    }
                    
                    // Check if the selected default provider has proper configuration
                    $api_key_exists = false;
                    $provider_names = [
                        'openai' => 'OpenAI',
                        'gemini' => 'Gemini',
                        'claude' => 'Claude',
                        'shareai' => 'ShareAI',
                        'groq' => 'Groq',
                        'ollama' => 'Ollama'
                    ];
                    
                    switch ($default_provider) {
                        case 'openai':
                            $api_key_exists = !empty($settings['openai_api_key']);
                            break;
                        case 'gemini':
                            $api_key_exists = !empty($settings['gemini_api_key']);
                            break;
                        case 'claude':
                            $api_key_exists = !empty($settings['claude_api_key']);
                            break;
                        case 'shareai':
                            $api_key_exists = !empty($settings['shareai_api_key']);
                            break;
                        case 'groq':
                            $api_key_exists = !empty($settings['groq_api_key']);
                            break;
                        case 'ollama':
                            $ollama_enabled = $settings['pro_features']['ollama_enabled'] ?? false;
                            $ollama_url = $settings['pro_features']['ollama_server_url'] ?? ($settings['private_llm_server_url'] ?? '');
                            $api_key_exists = $ollama_enabled && !empty($ollama_url);
                            break;
                        default:
                            $api_key_exists = false;
                    }
                    
                    if (!$api_key_exists) {
                        $current_provider_name = $provider_names[$default_provider] ?? 'Unknown Provider';
                        if ($default_provider === 'ollama') {
                            throw new Exception(__('Ollama server URL is not configured. Set the server URL in Pro Features.', 'aiohm-knowledge-assistant'));
                        }
                        throw new Exception(
                            sprintf(
                                // translators: %s: provider name
                                __('%s API key is not configured. Please add your key in settings.', 'aiohm-knowledge-assistant'),
                                esc_html($current_provider_name)
                            )
                        );
                    }
                    
                    $crawler = new AIOHM_KB_Site_Crawler();
                    $results = $crawler->add_items_to_kb($item_ids);

                    // Clear KB manager cache after successful scanning to ensure new entries appear immediately
                    if (class_exists('AIOHM_KB_Manager')) {
                        $kb_manager = new AIOHM_KB_Manager();
                        $kb_manager->clear_kb_manager_cache();
                    } else {
                        wp_cache_flush_group('aiohm_kb_manager');
                    }

                    // Categorize results
                    $errors = array_filter($results, function($item) { return $item['status'] === 'error'; });
                    $successes = array_filter($results, function($item) { return $item['status'] === 'success'; });
                    $skipped = array_filter($results, function($item) { return $item['status'] === 'skipped'; });
                    
                    if (!empty($errors) && empty($successes)) {
                        // All items failed
                        $error_messages = array_column($errors, 'error_message');
                        throw new Exception(sprintf(
                            /* translators: %s: error messages */
                            __('All items failed to add: %s', 'aiohm-knowledge-assistant'),
                            esc_html(implode(', ', array_map('sanitize_text_field', $error_messages)))
                        ));
                    } else if (!empty($errors)) {
                        // Some items failed - return partial success with error details
                        $all_items = $crawler->find_all_content();
                        wp_send_json(['success' => false, 'data' => [
                            'message' => 'Some items failed to add to knowledge base',
                            'processed_items' => $results, 
                            'all_items' => $all_items,
                            'errors' => $errors,
                            'successes' => $successes
                        ]]);
                    } else if (!empty($skipped) && empty($successes)) {
                        // All items were skipped
                        $skip_reasons = array_column($skipped, 'reason');
                        $message = 'All items were skipped: ' . implode(', ', $skip_reasons);
                        $all_items = $crawler->find_all_content();
                        wp_send_json(['success' => false, 'data' => [
                            'message' => $message,
                            'processed_items' => $results, 
                            'all_items' => $all_items,
                            'skipped' => $skipped
                        ]]);
                    } else if (!empty($skipped)) {
                        // Some items were skipped
                        $all_items = $crawler->find_all_content();
                        $skip_count = count($skipped);
                        $success_count = count($successes);
                        wp_send_json(['success' => true, 'data' => [
                            'message' => "Processing complete: {$success_count} added, {$skip_count} skipped",
                            'processed_items' => $results, 
                            'all_items' => $all_items,
                            'skipped' => $skipped,
                            'successes' => $successes
                        ]]);
                    } else {
                        // All items succeeded
                        $all_items = $crawler->find_all_content();
                        wp_send_json_success(['processed_items' => $results, 'all_items' => $all_items]);
                    }
                    break;
                case 'uploads_find':
                    $crawler = new AIOHM_KB_Uploads_Crawler();
                    $all_supported_files = $crawler->find_all_supported_attachments();
                    wp_send_json_success(['items' => $all_supported_files]);
                    break;
                case 'uploads_add':
                    $raw_ids = isset($_POST['item_ids']) ? map_deep(wp_unslash($_POST['item_ids']), 'sanitize_text_field') : [];
                    if (!is_array($raw_ids)) {
                        throw new Exception('Invalid item IDs format.');
                    }
                    // Use sanitized item IDs
                    $item_ids = array_map('sanitize_text_field', $raw_ids);
                    $item_ids = array_map('intval', $item_ids);
                    // Filter out invalid IDs (0 or negative values)
                    $item_ids = array_filter($item_ids, function($id) { return $id > 0; });
                    if (empty($item_ids)) throw new Exception('No valid item IDs provided.');
                    $crawler = new AIOHM_KB_Uploads_Crawler();
                    $result = $crawler->add_attachments_to_kb($item_ids);
                    
                    // Handle the result - uploads crawler returns a single result, not an array of items
                    if ($result['status'] === 'success') {
                        // All items queued successfully
                        $updated_files_list = $crawler->find_all_supported_attachments();
                        wp_send_json_success([
                            'message' => $result['message'],
                            'items' => $updated_files_list
                        ]);
                    } else {
                        // Handle failure case
                        throw new Exception($result['message'] ?? 'Failed to queue files for processing.');
                    }
                    break;
                default:
                    throw new Exception('Invalid scan type specified.');
            }
        } catch (Exception $e) {
            $safe_message = self::get_safe_error_message($e, 'Scan');
            wp_send_json_error(['message' => $safe_message]);
        } catch (Error $e) {
            // translators: %s is the fatal error message
            wp_send_json_error(['message' => sprintf(__('Fatal error: %s', 'aiohm-knowledge-assistant'), $e->getMessage())]);
        }
    }

    public static function handle_check_api_key_ajax() {
        check_ajax_referer('aiohm_admin_nonce', 'nonce');
        
        $key_type = isset($_POST['key_type']) ? sanitize_key($_POST['key_type']) : '';
        
        // Get current settings to use stored API keys instead of relying on POST data
        $settings = AIOHM_KB_Assistant::get_settings();
        
        // Get the appropriate API key from stored settings
        $api_key = '';
        switch ($key_type) {
            case 'openai':
                $api_key = $settings['openai_api_key'] ?? '';
                if ( empty( $api_key ) && function_exists( 'aiohm_kb_get_wp_connector_api_key' ) ) {
                    $api_key = aiohm_kb_get_wp_connector_api_key( 'openai' );
                }
                break;
            case 'gemini':
                $api_key = $settings['gemini_api_key'] ?? '';
                if ( empty( $api_key ) && function_exists( 'aiohm_kb_get_wp_connector_api_key' ) ) {
                    $api_key = aiohm_kb_get_wp_connector_api_key( 'gemini' );
                }
                break;
            case 'claude':
                $api_key = $settings['claude_api_key'] ?? '';
                if ( empty( $api_key ) && function_exists( 'aiohm_kb_get_wp_connector_api_key' ) ) {
                    $api_key = aiohm_kb_get_wp_connector_api_key( 'claude' );
                }
                break;
            case 'shareai':
                $api_key = $settings['shareai_api_key'] ?? '';
                break;
            case 'groq':
                $api_key = $settings['groq_api_key'] ?? '';
                break;
        }
        
        if (empty($api_key)) {
            wp_send_json_error(['message' => __('API Key is not configured. Please save your settings first.', 'aiohm-knowledge-assistant')]);
        }
        try {
            switch ($key_type) {
                case 'openai':
                    $ai_client = new AIOHM_KB_AI_GPT_Client($settings);
                    $result = $ai_client->test_api_connection();
                    if ($result['success']) {
                        wp_send_json_success(['message' => __('OpenAI connection successful!', 'aiohm-knowledge-assistant')]);
                    } else {
                        // translators: %s is the OpenAI API error message
                        wp_send_json_error(['message' => sprintf(__('OpenAI connection failed: %s', 'aiohm-knowledge-assistant'), ($result['error'] ?? __('Unknown error.', 'aiohm-knowledge-assistant')))]);
                    }
                    break;
                case 'gemini':
                    $ai_client = new AIOHM_KB_AI_GPT_Client($settings);
                    $result = $ai_client->test_gemini_api_connection();
                    if ($result['success']) {
                        wp_send_json_success(['message' => __('Gemini connection successful!', 'aiohm-knowledge-assistant')]);
                    } else {
                        // translators: %s is the Gemini API error message
                        wp_send_json_error(['message' => sprintf(__('Gemini connection failed: %s', 'aiohm-knowledge-assistant'), ($result['error'] ?? __('Unknown error.', 'aiohm-knowledge-assistant')))]);
                    }
                    break;
                case 'claude':
                    $ai_client = new AIOHM_KB_AI_GPT_Client($settings);
                    $result = $ai_client->test_claude_api_connection();
                    if ($result['success']) {
                        wp_send_json_success(['message' => __('Claude connection successful!', 'aiohm-knowledge-assistant')]);
                    } else {
                        // translators: %s is the Claude API error message
                        wp_send_json_error(['message' => sprintf(__('Claude connection failed: %s', 'aiohm-knowledge-assistant'), ($result['error'] ?? __('Unknown error.', 'aiohm-knowledge-assistant')))]);
                    }
                    break;
                case 'shareai':
                    $ai_client = new AIOHM_KB_AI_GPT_Client($settings);
                    $result = $ai_client->test_shareai_api_connection();
                    if ($result['success']) {
                        wp_send_json_success(['message' => __('ShareAI connection successful!', 'aiohm-knowledge-assistant')]);
                    } else {
                        // translators: %s is the ShareAI API error message
                        wp_send_json_error(['message' => sprintf(__('ShareAI connection failed: %s', 'aiohm-knowledge-assistant'), ($result['error'] ?? __('Unknown error.', 'aiohm-knowledge-assistant')))]);
                    }
                    break;
                case 'groq':
                    $ai_client = new AIOHM_KB_AI_GPT_Client($settings);
                    $result = $ai_client->test_groq_api_connection();
                    if ($result['success']) {
                        wp_send_json_success(['message' => __('Groq connection successful!', 'aiohm-knowledge-assistant')]);
                    } else {
                        // translators: %s is the Groq API error message
                        wp_send_json_error(['message' => sprintf(__('Groq connection failed: %s', 'aiohm-knowledge-assistant'), ($result['error'] ?? __('Unknown error.', 'aiohm-knowledge-assistant')))]);
                    }
                    break;
                default:
                    wp_send_json_error(['message' => __('Invalid key type specified.', 'aiohm-knowledge-assistant')]);
            }
        } catch (Exception $e) {
            // translators: %s is the unexpected error message
            wp_send_json_error(['message' => sprintf(__('An unexpected error occurred: %s', 'aiohm-knowledge-assistant'), $e->getMessage())]);
        }
    }

    /**
     * AJAX handler for saving individual API keys
     */
    public static function handle_save_individual_api_key_ajax() {
        // Security checks
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'aiohm-knowledge-assistant')]);
        }

        check_ajax_referer('aiohm_admin_nonce', 'nonce');

        // Get and validate inputs
        $api_type = isset($_POST['api_type']) ? sanitize_key($_POST['api_type']) : '';
        $setting_name = isset($_POST['setting_name']) ? sanitize_key($_POST['setting_name']) : '';
        $api_value = isset($_POST['api_value']) ? sanitize_text_field(wp_unslash($_POST['api_value'])) : '';

        if (empty($api_type) || empty($setting_name) || empty($api_value)) {
            wp_send_json_error(['message' => __('Missing required parameters.', 'aiohm-knowledge-assistant')]);
        }

        try {
            // Clear WordPress object cache to ensure fresh data
            wp_cache_delete('aiohm_kb_settings', 'options');
            wp_cache_flush();
            
            // Get fresh current settings to avoid conflicts - bypass cache completely
            global $wpdb;
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            // Fresh data retrieval bypassing cache for API save conflicts requires direct database access
            $fresh_data = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM " . $wpdb->options . " WHERE option_name = %s", 'aiohm_kb_settings'));
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            if ($fresh_data === null) {
                $current_settings = [];
            } else {
                $current_settings = maybe_unserialize($fresh_data);
            }
            
            // Enhanced API key validation and encryption
            if (in_array($setting_name, ['openai_api_key', 'gemini_api_key', 'claude_api_key', 'groq_api_key'])) {
                // Validate API key format using new validation function
                $provider = str_replace('_api_key', '', $setting_name);
                $validated_key = AIOHM_KB_Assistant::validate_and_sanitize_api_key($api_value, $provider);

                if (is_wp_error($validated_key)) {
                    wp_send_json_error(['message' => $validated_key->get_error_message()]);
                }

                // Encrypt the validated API key
                $current_settings[$setting_name] = AIOHM_KB_Assistant::encrypt_api_key($validated_key);
            } elseif ($setting_name === 'shareai_api_key') {
                // Validate ShareAI key
                $validated_key = AIOHM_KB_Assistant::validate_and_sanitize_api_key($api_value, 'shareai');
                
                if (is_wp_error($validated_key)) {
                    wp_send_json_error(['message' => $validated_key->get_error_message()]);
                }
                
                $current_settings[$setting_name] = $validated_key;
            } else {
                // For non-API keys (like models), save as plain text with additional validation
                $sanitized_value = sanitize_text_field($api_value);
                
                // Additional validation for specific settings
                if ($setting_name === 'private_llm_server_url') {
                    if (!filter_var($sanitized_value, FILTER_VALIDATE_URL)) {
                        wp_send_json_error(['message' => __('Invalid server URL format.', 'aiohm-knowledge-assistant')]);
                    }
                }
                
                $current_settings[$setting_name] = $sanitized_value;
            }
            
            // Save the updated settings
            // Set a transient to prevent conflicts with settings page
            set_transient('aiohm_ajax_saving_' . $setting_name, true, 30);
            
            $result = update_option('aiohm_kb_settings', $current_settings);
            
            // If update_option returns false, verify if the value is actually correct
            if (!$result) {
                $verify_settings = get_option('aiohm_kb_settings', []);
                
                if (isset($verify_settings[$setting_name]) && $verify_settings[$setting_name] === $current_settings[$setting_name]) {
                    $result = true;
                } else {
                    // Try a direct update
                    $direct_update = update_option('aiohm_kb_settings', $current_settings);
                    if ($direct_update) {
                        $result = true;
                    } else {
                        // Last resort: direct database update
                        global $wpdb;
                        $serialized_data = maybe_serialize($current_settings);
                        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
                        // Direct update needed when update_option fails - fallback mechanism for critical settings
                        $db_result = $wpdb->update(
                            $wpdb->options,
                            ['option_value' => $serialized_data],
                            ['option_name' => 'aiohm_kb_settings'],
                            ['%s'],
                            ['%s']
                        );
                        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
                        if ($db_result !== false) {
                            // Clear cache after direct update
                            wp_cache_delete('aiohm_kb_settings', 'options');
                            $result = true;
                        }
                    }
                }
            }
            
            delete_transient('aiohm_ajax_saving_' . $setting_name);
            
            if ($result !== false) {
                // Different message for model selection vs API key
                if ($api_type === 'shareai_model') {
                    wp_send_json_success(['message' => __('ShareAI model saved successfully!', 'aiohm-knowledge-assistant')]);
                } else {
                    // translators: %s is the API provider name (e.g., OpenAI, Claude)
                    wp_send_json_success(['message' => sprintf(__('%s API key saved successfully!', 'aiohm-knowledge-assistant'), ucfirst($api_type))]);
                }
            } else {
                wp_send_json_error(['message' => __('Failed to save API key. Please try again.', 'aiohm-knowledge-assistant')]);
            }
        } catch (Exception $e) {
            // translators: %s is the error message
            wp_send_json_error(['message' => sprintf(__('An error occurred: %s', 'aiohm-knowledge-assistant'), $e->getMessage())]);
        }
    }

    public static function handle_export_kb_ajax() {
        check_ajax_referer('aiohm_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(['message' => 'Permission denied.']); }

        try {
            $scope = isset($_POST['scope']) ? sanitize_text_field(wp_unslash($_POST['scope'])) : 'public';
            $rag_engine = new AIOHM_KB_RAG_Engine();
            
            if ($scope === 'private') {
                $user_id = get_current_user_id();
                $json_data = $rag_engine->export_knowledge_base($user_id);
                $filename = 'aiohm-kb-private-' . gmdate('Y-m-d') . '.json';
            } else {
                // Clear cache first for public export
                wp_cache_delete('aiohm_export_data_0', 'aiohm_kb');
                $json_data = $rag_engine->export_knowledge_base(0);
                $filename = 'aiohm-kb-public-' . gmdate('Y-m-d') . '.json';
            }
            
            if (empty($json_data)) {
                // translators: %s is the export scope (public/private)
                wp_send_json_error(['message' => sprintf(__('No data found to export for %s entries.', 'aiohm-knowledge-assistant'), $scope)]);
            }
            
            wp_send_json_success(['filename' => sanitize_file_name($filename), 'data' => $json_data]);
        } catch (Exception $e) {
            // translators: %s is the export error message
            wp_send_json_error(['message' => sprintf(__('Export failed: %s', 'aiohm-knowledge-assistant'), $e->getMessage())]);
        }
    }

    public static function handle_reset_kb_ajax() {
        check_ajax_referer('aiohm_admin_nonce', 'nonce');
        global $wpdb;
        
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        // KB reset operation requires direct database access with cache clearing for complete knowledge base removal
        $wpdb->query($wpdb->prepare("DELETE FROM " . $wpdb->postmeta . " WHERE meta_key = %s", '_aiohm_indexed'));
        
        // Use DELETE instead of TRUNCATE for better security
        $safe_table_name = $wpdb->prefix . 'aiohm_vector_entries';
        $wpdb->query("DELETE FROM `" . esc_sql($safe_table_name) . "`");
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        wp_send_json_success(['message' => __('The knowledge base has been successfully reset.', 'aiohm-knowledge-assistant')]);
    }

    public static function handle_toggle_kb_scope_ajax() {
        // Enhanced security checks with rate limiting
        if (!AIOHM_KB_Assistant::check_enhanced_rate_limit('kb_scope_toggle', get_current_user_id(), 100)) {
            wp_send_json_error(['message' => __('Rate limit exceeded. Please wait before making more changes.', 'aiohm-knowledge-assistant')]);
        }

        check_ajax_referer('aiohm_admin_nonce', 'nonce');
        
        $content_id = isset($_POST['content_id']) ? sanitize_text_field(wp_unslash($_POST['content_id'])) : '';
        $new_scope = isset($_POST['new_scope']) ? sanitize_text_field(wp_unslash($_POST['new_scope'])) : '';
        
        // Validate content ID using new validation function
        $content_id_validation = AIOHM_KB_Assistant::validate_content_id($content_id);
        if (is_wp_error($content_id_validation)) {
            wp_send_json_error(['message' => $content_id_validation->get_error_message()]);
        }
        
        // Validate scope parameter
        if (!in_array($new_scope, ['public', 'private'], true)) {
            wp_send_json_error(['message' => __('Invalid scope parameter.', 'aiohm-knowledge-assistant')]);
        }
        
        $is_public = ($new_scope === 'public') ? 1 : 0;
        
        $rag_engine = new AIOHM_KB_RAG_Engine();
        $result = $rag_engine->update_entry_visibility_by_content_id($content_id, $is_public);
        if ($result !== false) {
            $new_visibility_text = ($is_public === 1) ? 'Public' : 'Private';
            wp_send_json_success(['message' => __('Entry visibility updated successfully.', 'aiohm-knowledge-assistant'), 'new_visibility_text' => esc_html($new_visibility_text)]);
        } else {
            wp_send_json_error(['message' => __('Failed to update entry visibility in the database.', 'aiohm-knowledge-assistant')]);
        }
    }

    public static function handle_restore_kb_ajax() {
        check_ajax_referer('aiohm_admin_nonce', 'nonce');
        if (!isset($_POST['json_data']) || empty($_POST['json_data'])) {
            wp_send_json_error(['message' => __('No data provided for restore.', 'aiohm-knowledge-assistant')]);
        }
        $json_data = sanitize_textarea_field(wp_unslash($_POST['json_data']));
        
        // Validate JSON data
        json_decode($json_data);
        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(['message' => __('Invalid JSON data provided.', 'aiohm-knowledge-assistant')]);
        }
        
        try {
            $rag_engine = new AIOHM_KB_RAG_Engine();
            $count = $rag_engine->import_knowledge_base($json_data);
            wp_send_json_success(['message' => sprintf(
                /* translators: %d: number of entries */
                __('%d entries have been successfully restored. The page will now reload.', 'aiohm-knowledge-assistant'),
                intval($count)
            )]);
        }
        catch (Exception $e) {
            // translators: %s is the restore error message
            wp_send_json_error(['message' => sprintf(__('Restore failed: %s', 'aiohm-knowledge-assistant'), $e->getMessage())]);
        }
    }

    public static function handle_delete_kb_entry_ajax() {
        // Enhanced security checks with rate limiting
        if (!AIOHM_KB_Assistant::check_enhanced_rate_limit('kb_delete', get_current_user_id(), 50)) {
            wp_send_json_error(['message' => __('Delete rate limit exceeded. Please wait before deleting more entries.', 'aiohm-knowledge-assistant')]);
        }

        check_ajax_referer('aiohm_admin_nonce', 'nonce');
        
        if (!isset($_POST['content_id']) || empty($_POST['content_id'])) {
            wp_send_json_error(['message' => __('Content ID is missing for deletion.', 'aiohm-knowledge-assistant')]);
        }
        
        $content_id = sanitize_text_field(wp_unslash($_POST['content_id']));
        
        // Validate content ID using new validation function
        $content_id_validation = AIOHM_KB_Assistant::validate_content_id($content_id);
        if (is_wp_error($content_id_validation)) {
            wp_send_json_error(['message' => $content_id_validation->get_error_message()]);
        }
        
        $rag_engine = new AIOHM_KB_RAG_Engine();
        if ($rag_engine->delete_entry_by_content_id($content_id)) {
            wp_send_json_success(['message' => __('Entry successfully deleted.', 'aiohm-knowledge-assistant')]);
        } else {
            wp_send_json_error(['message' => __('Failed to delete entry.', 'aiohm-knowledge-assistant')]);
        }
    }

    public static function handle_bulk_delete_kb_ajax() {
        check_ajax_referer('aiohm_admin_nonce', 'nonce');
        
        if (!isset($_POST['content_ids']) || !is_array($_POST['content_ids']) || empty($_POST['content_ids'])) {
            wp_send_json_error(['message' => __('No entries selected for deletion.', 'aiohm-knowledge-assistant')]);
        }
        
        $content_ids = array_map('sanitize_text_field', wp_unslash($_POST['content_ids']));
        $rag_engine = new AIOHM_KB_RAG_Engine();
        $deleted_count = 0;
        
        foreach ($content_ids as $content_id) {
            if ($rag_engine->delete_entry_by_content_id($content_id)) {
                $deleted_count++;
            }
        }
        
        if ($deleted_count > 0) {
            // translators: %d is the number of deleted entries
            wp_send_json_success(['message' => sprintf(__('%d entries successfully deleted.', 'aiohm-knowledge-assistant'), $deleted_count)]);
        } else {
            wp_send_json_error(['message' => __('Failed to delete any entries.', 'aiohm-knowledge-assistant')]);
        }
    }

    public static function handle_bulk_toggle_kb_scope_ajax() {
        check_ajax_referer('aiohm_admin_nonce', 'nonce');
        
        if (!isset($_POST['content_ids']) || !is_array($_POST['content_ids']) || empty($_POST['content_ids'])) {
            wp_send_json_error(['message' => __('No entries selected for scope change.', 'aiohm-knowledge-assistant')]);
        }
        
        if (!isset($_POST['new_scope']) || !in_array($_POST['new_scope'], ['public', 'private'])) {
            wp_send_json_error(['message' => __('Invalid scope specified.', 'aiohm-knowledge-assistant')]);
        }
        
        $content_ids = array_map('sanitize_text_field', wp_unslash($_POST['content_ids']));
        $new_scope = sanitize_text_field(wp_unslash($_POST['new_scope']));
        $is_public = ($new_scope === 'public') ? 1 : 0;
        $rag_engine = new AIOHM_KB_RAG_Engine();
        $updated_count = 0;
        
        foreach ($content_ids as $content_id) {
            if ($rag_engine->update_entry_visibility_by_content_id($content_id, $is_public)) {
                $updated_count++;
            }
        }
        
        $scope_text = ucfirst($new_scope);
        if ($updated_count > 0) {
            // translators: %1$d is the number of updated entries, %2$s is the scope (Public/Private)
            wp_send_json_success(['message' => sprintf(__('%1$d entries successfully updated to %2$s.', 'aiohm-knowledge-assistant'), $updated_count, $scope_text)]);
        } else {
            wp_send_json_error(['message' => __('Failed to update any entries.', 'aiohm-knowledge-assistant')]);
        }
    }

    public static function handle_save_brand_soul_ajax() {
        // Accept nonce from either the localized JS param (`nonce`) or the hidden form field (`aiohm_brand_soul_nonce_field`)
        $nonce_ok = check_ajax_referer('aiohm_brand_soul_nonce', 'nonce', false)
            || check_ajax_referer('aiohm_brand_soul_nonce', 'aiohm_brand_soul_nonce_field', false);
        if (!$nonce_ok) {
            wp_send_json_error(['message' => __('Security check failed.', 'aiohm-knowledge-assistant')]);
        }

        // Directly use the answers from POST
        $form_data = isset($_POST['answers']) ? map_deep(wp_unslash($_POST['answers']), 'sanitize_textarea_field') : [];

        // Validate answers array
        $validation_rules = [
            'answers' => [
                'type' => 'array',
                'required' => true
            ]
        ];

        $validated = self::validate_ajax_input(['answers' => $form_data], $validation_rules);
        if (is_wp_error($validated)) {
            wp_send_json_error(['message' => $validated->get_error_message()]);
        }
        
        // Additional validation for answers content
        $answers = $validated['answers'];
        $sanitized_answers = [];
        
        foreach ($answers as $key => $answer) {
            // Limit individual answer length
            if (strlen($answer) > 2000) {
                wp_send_json_error(['message' => __('Individual answers must be less than 2000 characters.', 'aiohm-knowledge-assistant')]);
            }
            $sanitized_answers[sanitize_key($key)] = sanitize_textarea_field($answer);
        }
        
        update_user_meta(get_current_user_id(), 'aiohm_brand_soul_answers', $sanitized_answers);
        wp_send_json_success();
    }

    public static function handle_add_brand_soul_to_kb_ajax() {
        // Accept nonce from either the localized JS param (`nonce`) or the hidden form field (`aiohm_brand_soul_nonce_field`)
        $nonce_ok = check_ajax_referer('aiohm_brand_soul_nonce', 'nonce', false)
            || check_ajax_referer('aiohm_brand_soul_nonce', 'aiohm_brand_soul_nonce_field', false);
        if (!$nonce_ok) {
            wp_send_json_error(['message' => __('Security check failed.', 'aiohm-knowledge-assistant')]);
        }

        // Directly use the answers from POST
        $form_data = isset($_POST['answers']) ? map_deep(wp_unslash($_POST['answers']), 'sanitize_textarea_field') : [];

        // Validate answers array
        $validation_rules = [
            'answers' => [
                'type' => 'array',
                'required' => true
            ]
        ];
        
        $validated = self::validate_ajax_input(['answers' => $form_data], $validation_rules);
        if (is_wp_error($validated)) {
            wp_send_json_error(['message' => $validated->get_error_message()]);
        }
        
        $answers = $validated['answers'];
        if (empty($answers)) {
            wp_send_json_error(['message' => __('No answers to add.', 'aiohm-knowledge-assistant')]);
        }
        
        try {
            $rag_engine = new AIOHM_KB_RAG_Engine();
            $user_id = get_current_user_id();
            $content = json_encode($answers, JSON_PRETTY_PRINT);
            $result = $rag_engine->add_content_entry($content, 'brand_soul', 'My Brand Soul', ['source_type' => 'brand_soul'], $user_id, 0);
            if (is_wp_error($result)) {
                // translators: %s is the knowledge base addition error message
                wp_send_json_error(['message' => sprintf(__('Error adding to KB: %s', 'aiohm-knowledge-assistant'), $result->get_error_message())]);
            } else {
                wp_send_json_success();
            }
        } catch (Exception $e) {
            // translators: %s is the knowledge base addition error message
            wp_send_json_error(['message' => sprintf(__('Error adding to KB: %s', 'aiohm-knowledge-assistant'), $e->getMessage())]);
        }
    }

    public static function handle_add_note_to_kb_ajax() {
        // Check nonce and permissions
        if (!check_ajax_referer('aiohm_private_chat_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed.', 'aiohm-knowledge-assistant')]);
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions.']);
        }

        // Enhanced input validation with size limits
        $validation_rules = [
            'project_id' => [
                'type' => 'int',
                'required' => true,
                'min' => 1
            ],
            'note_content' => [
                'type' => 'textarea',
                'required' => true,
                'max_length' => 10000 // 10KB limit for notes
            ]
        ];
        
        $input_data = [
            'project_id' => isset($_POST['project_id']) ? intval(wp_unslash($_POST['project_id'])) : '',
            'note_content' => isset($_POST['note_content']) ? sanitize_textarea_field(wp_unslash($_POST['note_content'])) : ''
        ];
        
        $validated = self::validate_ajax_input($input_data, $validation_rules);
        if (is_wp_error($validated)) {
            wp_send_json_error(['message' => $validated->get_error_message()]);
        }
        
        $project_id = $validated['project_id'];
        $note_content = $validated['note_content'];

        try {
            $rag_engine = new AIOHM_KB_RAG_Engine();
            $user_id = get_current_user_id();

            // Create a title for the note
            $note_title = 'Project Note: ' . wp_trim_words($note_content, 8, '...');

            // Add the note to the knowledge base
            $rag_engine->add_entry(
                $note_content,
                'project_note',
                $note_title,
                ['project_id' => $project_id],
                $user_id,
                0
            );

            wp_send_json_success(['message' => __('Note added to Knowledge Base successfully!', 'aiohm-knowledge-assistant')]);

        } catch (Exception $e) {
            // translators: %s is the error message when adding a note to the knowledge base
            wp_send_json_error(['message' => sprintf(__('Error adding note to KB: %s', 'aiohm-knowledge-assistant'), $e->getMessage())]);
        }
    }

    public static function handle_pdf_download() {
        if (isset($_GET['action'], $_GET['nonce']) && $_GET['action'] === 'download_brand_soul_pdf' && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['nonce'])), 'download_brand_soul_pdf')) {
            $user_id = get_current_user_id();
            $user_info = get_userdata($user_id);
            $answers = get_user_meta($user_id, 'aiohm_brand_soul_answers', true);
            if (!is_array($answers)) {
                $answers = [];
            }
            $brand_soul_questions = [
                '✨ Foundation' => [
                    'foundation_1' => "What’s the deeper purpose behind your brand — beyond profit?",
                    'foundation_2' => "What life experiences shaped this work you now do?",
                    'foundation_3' => "Who were you before this calling emerged?",
                    'foundation_4' => "If your brand had a soul story, how would you tell it?",
                    'foundation_5' => "What’s one transformation you’ve witnessed that reminds you why you do this?",
                ],
                '🌀 Energy' => [
                    'energy_1' => "What 3 words describe the emotional tone of your brand voice?",
                    'energy_2' => "How do you want your audience to feel after encountering your message?",
                    'energy_3' => "What do you not want to sound like?",
                    'energy_4' => "Do you prefer poetic, punchy, playful, or professional language?",
                    'energy_5' => "Share a quote, phrase, or piece of content that feels like you.",
                ],
                '🎨 Expression' => [
                    'expression_1' => "What are your brand’s primary colors (and any specific hex codes)?",
                    'expression_2' => "What font(s) do you use — or wish to use — for headers and body text?",
                    'expression_3' => "Is there a visual theme (earthy, cosmic, minimalist, ornate) that matches your brand essence?",
                    'expression_4' => "Are there any logos, patterns, or symbols that hold meaning for your brand?",
                    'expression_5' => "What offerings are you currently sharing with the world — and how are they priced or exchanged?",
                ],
                '🚀 Direction' => [
                    'direction_1' => "What’s your current main offer or project you want support with?",
                    'direction_2' => "Who is your dream client? Describe them with emotion and detail.",
                    'direction_3' => "What are 3 key goals you have for the next 6 months?",
                    'direction_4' => "Where do you feel stuck, overwhelmed, or unsure — and where would you love AI support?",
                    'direction_5' => "If this AI assistant could speak your soul fluently, what would you want it to never forget?",
                ],
            ];
            $pdf_loader = AIOHM_PDF_Library_Loader::get_instance();
            if (!$pdf_loader->load_mpdf()) {
                wp_die(esc_html__('PDF generation is not available.', 'aiohm-knowledge-assistant'));
            }
            
            // Create PDF instance
            $pdf = $pdf_loader->get_pdf();
            
            $pdf->AddPage();
            
            // Add header information
            $pdf->ChapterTitle('Your Brand Core Questionnaire');
            
            foreach ($brand_soul_questions as $section_title => $questions) {
                $pdf->ChapterTitle($section_title);
                foreach ($questions as $key => $question_text) {
                    $answer = isset($answers[$key]) ? $answers[$key] : 'No answer provided.';
                    $pdf->MessageBlock('question', $question_text, gmdate('Y-m-d H:i:s'));
                    $pdf->MessageBlock('answer', $answer, gmdate('Y-m-d H:i:s'));
                }
            }
            $brand_name = sanitize_title($user_info->display_name);
            $filename = $brand_name . '-AI-brand-core.pdf';
            $pdf->Output($filename, 'D');
            exit;
        }
    }

    public static function handle_save_mirror_mode_settings_ajax() {
        check_ajax_referer('aiohm_mirror_mode_nonce', 'aiohm_mirror_mode_nonce_field');
        
        // Get settings from POST data directly, avoiding parse_str
        $settings_input = isset($_POST['aiohm_kb_settings']['mirror_mode']) ? map_deep(wp_unslash($_POST['aiohm_kb_settings']['mirror_mode']), 'sanitize_text_field') : [];
        
        if (empty($settings_input)) {
            wp_send_json_error(['message' => __('No settings data received.', 'aiohm-knowledge-assistant')]);
        }
        
        // Get current settings from database (not the merged defaults)
        $settings = get_option('aiohm_kb_settings', []);
        
        // Ensure mirror_mode structure exists
        if (!isset($settings['mirror_mode'])) {
            $settings['mirror_mode'] = [];
        }
        
        $settings['mirror_mode']['business_name'] = sanitize_text_field(trim($settings_input['business_name'] ?? ''));
        // Handle system message with proper formatting preservation
        $qa_system_message = trim($settings_input['qa_system_message'] ?? '');
        
        // Default template 
        $clean_default = "You are the official AI Knowledge Assistant for \"%site_name%\".\n\nYour core mission is to embody our brand's tagline: \"%site_tagline%\".\n\nYou are to act as a thoughtful and emotionally intelligent guide for all website visitors, reflecting the unique voice of the brand. You should be aware that today is %day_of_week%, %current_date%.\n\nCore Instructions:\n\n1. Primary Directive: Your primary goal is to answer the user's question by grounding your response in the context provided below. This context is your main source of truth.\n\n2. Tone & Personality:\n   - Speak with emotional clarity, not robotic formality.\n   - Sound like a thoughtful assistant, not a sales rep.\n   - Be concise, but not curt — useful, but never cold.\n   - Your purpose is to express with presence, not persuasion.\n\n3. Formatting Rules:\n   - Use only basic HTML tags for clarity (like <strong> or <em> if needed). Do not use Markdown.\n   - Never end your response with a question like \"Do you need help with anything else?\"\n\n4. Fallback Response (Crucial):\n   - If the provided context does not contain enough information to answer the user's question, you MUST respond with this exact phrase: \"Hmm… I don't want to guess here. This might need a human's wisdom. You can connect with the person behind this site on the contact page. They'll know exactly how to help.\"\n\nPrimary Context for Answering the User's Question:\n{context}";
        
        // Check for any signs of corruption and restore clean version
        if (empty($qa_system_message) || 
            // Original corruption patterns
            strpos($qa_system_message, 'y_of_week%') !== false || 
            strpos($qa_system_message, 'Core Instructions: 1.') !== false ||
            // New corruption patterns (missing spaces)
            strpos($qa_system_message, '"%site_name%".Your') !== false ||
            strpos($qa_system_message, 'tagline:"%site_tagline%".You') !== false ||
            strpos($qa_system_message, '"%site_name%".You') !== false ||
            // General corruption check - if it contains variables but no spaces around punctuation
            (strpos($qa_system_message, '%site_name%') !== false && 
             (strpos($qa_system_message, '".You') !== false || strpos($qa_system_message, '".Your') !== false))) {
            
            $qa_system_message = $clean_default;
        } else {
            // Decode HTML entities and preserve formatting
            $qa_system_message = html_entity_decode($qa_system_message, ENT_QUOTES, 'UTF-8');
        }
        
        $settings['mirror_mode']['qa_system_message'] = $qa_system_message;
        $settings['mirror_mode']['qa_temperature'] = floatval($settings_input['qa_temperature'] ?? 0.7);
        $settings['mirror_mode']['primary_color'] = sanitize_hex_color($settings_input['primary_color'] ?? '#1f5014');
        $settings['mirror_mode']['background_color'] = sanitize_hex_color($settings_input['background_color'] ?? '#f0f4f8');
        $settings['mirror_mode']['text_color'] = sanitize_hex_color($settings_input['text_color'] ?? '#ffffff');
        $settings['mirror_mode']['ai_avatar'] = esc_url_raw($settings_input['ai_avatar'] ?? '');
        $settings['mirror_mode']['welcome_message'] = wp_kses_post(trim($settings_input['welcome_message'] ?? ''));
        $settings['mirror_mode']['ai_model'] = sanitize_text_field($settings_input['ai_model'] ?? 'gpt-3.5-turbo');
        
        // Handle URL sanitization with proper validation
        $meeting_url = trim($settings_input['meeting_button_url'] ?? '');
        if (!empty($meeting_url)) {
            // Fix common URL issues
            $meeting_url = str_replace('httpsohm.com', 'https://ohm.com', $meeting_url);
            $meeting_url = str_replace('httpohm.com', 'http://ohm.com', $meeting_url);
            
            // Add protocol if missing
            if (!preg_match('/^https?:\/\//', $meeting_url)) {
                $meeting_url = 'https://' . $meeting_url;
            }
            $settings['mirror_mode']['meeting_button_url'] = esc_url_raw($meeting_url);
        } else {
            $settings['mirror_mode']['meeting_button_url'] = '';
        }

        // Save the settings
        $result = update_option('aiohm_kb_settings', $settings, true);
        
        // If update_option returns false, try direct database update
        if (!$result) {
            global $wpdb;
            $option_name = 'aiohm_kb_settings';
            $option_value = serialize($settings);
            $autoload = 'yes';
            
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
            // Settings update fallback when update_option fails - critical for plugin configuration persistence
            $result = $wpdb->replace(
                $wpdb->options,
                array(
                    'option_name' => $option_name,
                    'option_value' => $option_value,
                    'autoload' => $autoload
                ),
                array('%s', '%s', '%s')
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
            
        }
        
        // Force clear all caches
        wp_cache_delete('aiohm_kb_settings', 'options');
        wp_cache_flush();
        
        wp_send_json_success(['message' => __('Mirror Mode settings saved successfully.', 'aiohm-knowledge-assistant')]);
    }
    
    public static function monitor_settings_changes($old_value, $new_value) {
        // Monitor for unintended setting removals
        if (isset($old_value['mirror_mode']) && !isset($new_value['mirror_mode'])) {
        }
        
        if (isset($old_value['muse_mode']) && !isset($new_value['muse_mode'])) {
        }
    }
    
    public static function monitor_settings_deletion($option_name) {
        if ($option_name === 'aiohm_kb_settings') {
        }
    }

    public static function handle_save_muse_mode_settings_ajax() {
        check_ajax_referer('aiohm_muse_mode_nonce', 'aiohm_muse_mode_nonce_field');
        
        // Get settings from POST data directly, avoiding parse_str
        $muse_input = isset($_POST['aiohm_kb_settings']['muse_mode']) ? map_deep(wp_unslash($_POST['aiohm_kb_settings']['muse_mode']), 'sanitize_text_field') : [];

        if (empty($muse_input)) {
            wp_send_json_error(['message' => __('No settings data received.', 'aiohm-knowledge-assistant')]);
        }

        // Get current settings from database (not the merged defaults)
        $settings = get_option('aiohm_kb_settings', []);
        
        // Ensure muse_mode structure exists
        if (!isset($settings['muse_mode'])) {
            $settings['muse_mode'] = [];
        }
        
        $settings['muse_mode']['assistant_name'] = sanitize_text_field($muse_input['assistant_name'] ?? 'Muse');
        
        // Handle system prompt with special care to preserve formatting
        $raw_system_prompt = $muse_input['system_prompt'] ?? '';
        if (!empty($raw_system_prompt)) {
            // Decode any HTML entities that might have been encoded
            $decoded_prompt = html_entity_decode($raw_system_prompt, ENT_QUOTES, 'UTF-8');
            // Use wp_kses_post which preserves formatting but sanitizes dangerous content
            $settings['muse_mode']['system_prompt'] = $decoded_prompt;
        } else {
            $settings['muse_mode']['system_prompt'] = '';
        }
        
        $settings['muse_mode']['ai_model'] = sanitize_text_field($muse_input['ai_model'] ?? 'gpt-4');
        $settings['muse_mode']['temperature'] = floatval($muse_input['temperature'] ?? 0.7);
        $settings['muse_mode']['start_fullscreen'] = isset($muse_input['start_fullscreen']) ? 1 : 0;
        $settings['muse_mode']['brand_archetype'] = sanitize_text_field($muse_input['brand_archetype'] ?? '');

        // Save the settings
        $result = update_option('aiohm_kb_settings', $settings, true);
        
        // If update_option returns false, try direct database update
        if (!$result) {
            global $wpdb;
            $option_name = 'aiohm_kb_settings';
            $option_value = serialize($settings);
            $autoload = 'yes';
            
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
            // Settings update fallback when update_option fails - critical for Muse mode configuration persistence
            $result = $wpdb->replace(
                $wpdb->options,
                array(
                    'option_name' => $option_name,
                    'option_value' => $option_value,
                    'autoload' => $autoload
                ),
                array('%s', '%s', '%s')
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
        }
        
        // Force clear all caches
        wp_cache_delete('aiohm_kb_settings', 'options');
        wp_cache_flush();
        
        wp_send_json_success(['message' => __('Muse Mode settings saved successfully.', 'aiohm-knowledge-assistant')]);
    }

    public static function handle_generate_mirror_mode_qa_ajax() {
        check_ajax_referer('aiohm_mirror_mode_nonce', 'nonce');
        try {
            $rag_engine = new AIOHM_KB_RAG_Engine();
            $ai_client = new AIOHM_KB_AI_GPT_Client();
            $settings = AIOHM_KB_Assistant::get_settings();
            
            $random_chunk = $rag_engine->get_random_chunk();
            if (!$random_chunk) {
                throw new Exception("Your knowledge base is empty. Please scan some content first.");
            }
            
            // Use the AI model selected in Mirror Mode, fallback to default provider
            $mirror_model = $settings['mirror_mode']['ai_model'] ?? '';
            if (!empty($mirror_model)) {
                $model = $mirror_model;
            } else {
                // Fallback to default provider if no Mirror Mode model is set
                $default_provider = $settings['default_ai_provider'] ?? 'openai';
                if (function_exists('aiohm_kb_normalize_wp_ai_provider_id')) {
                    $default_provider = aiohm_kb_normalize_wp_ai_provider_id($default_provider);
                }
                switch ($default_provider) {
                    case 'gemini':
                        $model = 'gemini-pro';
                        break;
                    case 'claude':
                        $model = 'claude-3-sonnet';
                        break;
                    case 'shareai':
                        $model = 'shareai-llama4:17b-scout-16e-instruct-fp16';
                        break;
                    case 'openai':
                    default:
                        $model = 'gpt-3.5-turbo';
                        break;
                }
            }
            
            $question_prompt = "Based on the following text, what is a likely user question? Only return the question itself, without any preamble.\n\nCONTEXT:\n" . $random_chunk;
            $question = $ai_client->get_chat_completion($question_prompt, "", 0.7, $model);
            $answer_prompt = "You are a helpful assistant. Answer the following question based on the provided context.\n\nCONTEXT:\n{$random_chunk}\n\nQUESTION:\n{$question}";
            $answer = $ai_client->get_chat_completion($answer_prompt, "", 0.2, $model);
            
            // Format the answer with proper HTML formatting
            $formatted_answer = self::format_qa_answer(trim($answer));
            
            wp_send_json_success(['qa_pair' => ['question' => trim(str_replace('"', '', $question)), 'answer' => $formatted_answer]]);
        } catch (Exception $e) {
            // translators: %s is the Q&A generation error message
            wp_send_json_error(['message' => sprintf(__('Failed to generate Q&A pair: %s', 'aiohm-knowledge-assistant'), $e->getMessage())]);
        }
    }

    /**
     * Format Q&A answer text with proper HTML formatting
     */
    public static function format_qa_answer($answer) {
        // Escape HTML first for security
        $answer = wp_kses_post($answer);
        
        // Convert markdown-style formatting to HTML
        $answer = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $answer); // Bold **text**
        $answer = preg_replace('/\*(.*?)\*/', '<em>$1</em>', $answer); // Italic *text*
        $answer = preg_replace('/^### (.*?)$/m', '<h3>$1</h3>', $answer); // H3 headers
        $answer = preg_replace('/^## (.*?)$/m', '<h2>$1</h2>', $answer); // H2 headers
        $answer = preg_replace('/^# (.*?)$/m', '<h1>$1</h1>', $answer); // H1 headers
        
        // Convert line breaks to paragraphs
        $paragraphs = array_filter(explode("\n\n", $answer)); // Split by double line breaks
        $formatted_paragraphs = array_map(function($p) {
            $p = trim($p);
            if (empty($p)) return '';
            
            // Don't wrap headers in paragraphs
            if (preg_match('/^<h[1-6]>/', $p)) {
                return $p;
            }
            
            // Convert single line breaks to <br> within paragraphs
            $p = str_replace("\n", '<br>', $p);
            return '<p>' . $p . '</p>';
        }, $paragraphs);
        
        return implode("\n", array_filter($formatted_paragraphs));
    }

    /**
     * Handle file uploads for projects
     */
    public static function handle_upload_project_files_ajax() {
        // Security checks
        if (!check_ajax_referer('aiohm_private_chat_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed.', 'aiohm-knowledge-assistant')]);
        }

        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'aiohm-knowledge-assistant')]);
        }

        // Check if files were uploaded
        if (empty($_FILES['files'])) {
            wp_send_json_error(['message' => __('No files uploaded.', 'aiohm-knowledge-assistant')]);
        }

        // Get project ID
        $project_id = intval($_POST['project_id'] ?? 0);
        if (!$project_id) {
            wp_send_json_error(['message' => __('Invalid project ID.', 'aiohm-knowledge-assistant')]);
        }

        // Verify user owns the project
        global $wpdb;
        $user_id = get_current_user_id();
        $project_table = $wpdb->prefix . 'aiohm_projects';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Project data query
        $project = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . $wpdb->prefix . "aiohm_projects WHERE id = %d AND user_id = %d",
            $project_id, $user_id
        ));

        if (!$project) {
            wp_send_json_error(['message' => __('Project not found or access denied.', 'aiohm-knowledge-assistant')]);
        }

        // Define upload directory
        $upload_base_dir = wp_upload_dir();
        $project_upload_dir = $upload_base_dir['basedir'] . '/aiohm_project_files/project_' . $project_id;
        $project_upload_url = $upload_base_dir['baseurl'] . '/aiohm_project_files/project_' . $project_id;

        // Create directory if it doesn't exist
        if (!file_exists($project_upload_dir)) {
            wp_mkdir_p($project_upload_dir);
            
            // Create .htaccess to prevent PHP execution
            $htaccess_content = "# AIOHM Knowledge Assistant Security\n";
            $htaccess_content .= "# Prevent execution of uploaded files\n";
            $htaccess_content .= "php_flag engine off\n";
            $htaccess_content .= "<Files *.php>\n";
            $htaccess_content .= "    Order allow,deny\n";
            $htaccess_content .= "    Deny from all\n";
            $htaccess_content .= "</Files>\n";
            $htaccess_content .= "<FilesMatch \"\\.(php|php3|php4|php5|phtml|pl|py|jsp|asp|sh|cgi)$\">\n";
            $htaccess_content .= "    Order allow,deny\n";
            $htaccess_content .= "    Deny from all\n";
            $htaccess_content .= "</FilesMatch>\n";
            
            // Initialize WP_Filesystem if needed
            global $wp_filesystem;
            if (empty($wp_filesystem)) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                WP_Filesystem();
            }
            
            // Write security files using WP_Filesystem
            $wp_filesystem->put_contents($project_upload_dir . '/.htaccess', $htaccess_content, FS_CHMOD_FILE);
            
            // Create index.php to prevent directory listing
            $wp_filesystem->put_contents($project_upload_dir . '/index.php', '<?php // Silence is golden', FS_CHMOD_FILE);
        }

        // Define allowed file types - SECURITY: Reduced to essential document types only
        $allowed_types = [
            'txt' => 'text/plain',
            'pdf' => 'application/pdf',
            'csv' => 'text/csv',
            'json' => 'application/json'
        ];
        
        // Add allowed MIME types to WordPress upload filters
        add_filter('upload_mimes', function($mimes) {
            $mimes['json'] = 'application/json';
            return $mimes;
        });

        $uploaded_files = [];
        $errors = [];

        // Handle multiple files with proper validation
        $files = [];
        if (isset($_FILES['files']) && is_array($_FILES['files'])) {
            // Validate the $_FILES structure before processing
            $required_keys = ['name', 'type', 'tmp_name', 'error', 'size'];
            $files_valid = true;
            foreach ($required_keys as $key) {
                if (!isset($_FILES['files'][$key])) {
                    $files_valid = false;
                    break;
                }
            }
            if ($files_valid) {
                // Sanitize $_FILES data
                $files = [
                    'name' => isset($_FILES['files']['name']) ? array_map('sanitize_file_name', (array) $_FILES['files']['name']) : [],
                    'type' => isset($_FILES['files']['type']) ? array_map('sanitize_mime_type', (array) $_FILES['files']['type']) : [],
                    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tmp_name is system-generated and validated by WordPress core
                    'tmp_name' => isset($_FILES['files']['tmp_name']) ? (array) $_FILES['files']['tmp_name'] : [], // tmp_name doesn't need sanitization
                    'error' => isset($_FILES['files']['error']) ? array_map('intval', (array) $_FILES['files']['error']) : [],
                    'size' => isset($_FILES['files']['size']) ? array_map('intval', (array) $_FILES['files']['size']) : []
                ];
            }
        }
        $file_count = count($files['name']);

        for ($i = 0; $i < $file_count; $i++) {
            $file = [
                'name' => sanitize_file_name($files['name'][$i]),
                'type' => sanitize_mime_type($files['type'][$i]),
                'tmp_name' => $files['tmp_name'][$i], // tmp_name is validated by WordPress core
                'error' => intval($files['error'][$i]),
                'size' => intval($files['size'][$i])
            ];

            // Check for upload errors
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $errors[] = sprintf(
                    /* translators: %1$s: filename, %2$d: error code */
                    __('Error uploading %1$s: Upload error code %2$d', 'aiohm-knowledge-assistant'),
                    esc_html($file['name']),
                    intval($file['error'])
                );
                continue;
            }
            
            // Validate file size (10MB limit)
            if ($file['size'] > 10 * 1024 * 1024) {
                $errors[] = sprintf(
                    /* translators: %s: filename */
                    __('File %s exceeds 10MB size limit.', 'aiohm-knowledge-assistant'),
                    esc_html($file['name'])
                );
                continue;
            }
            
            // Validate file type using finfo for security
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime_type = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);
                
                $allowed_mimes = [
                    'application/pdf',
                    'text/plain',
                    'text/csv',
                    'application/json',
                    'audio/mpeg',
                    'audio/wav',
                    'audio/mp4',
                    'audio/ogg',
                    'image/jpeg',
                    'image/png',
                    'image/gif'
                ];
                
                if (!in_array($mime_type, $allowed_mimes, true)) {
                    $errors[] = sprintf(
                        /* translators: %s: filename */
                        __('File %s has invalid file type.', 'aiohm-knowledge-assistant'),
                        esc_html($file['name'])
                    );
                    continue;
                }
            }
            
            // Sanitize filename to prevent path traversal
            $file['name'] = sanitize_file_name(basename($file['name']));
            $file['name'] = preg_replace('/[^a-zA-Z0-9._-]/', '', $file['name']);

            // Additional security check - already done above but keeping as backup
            if ($file['size'] > 10 * 1024 * 1024) {
                $errors[] = sprintf(
                    /* translators: %s: filename */
                    __('File %s is too large (max 10MB)', 'aiohm-knowledge-assistant'),
                    esc_html($file['name'])
                );
                continue;
            }

            // Check file type
            $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!array_key_exists($file_ext, $allowed_types)) {
                $errors[] = "File type not allowed for {$file['name']}";
                continue;
            }

            // Enhanced MIME type and content validation
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mime_type, $allowed_types)) {
                $errors[] = "Invalid file type for {$file['name']} (MIME: {$mime_type})";
                continue;
            }
            
            // Additional file content validation
            if (!self::validate_file_content($file['tmp_name'], $file_ext, $mime_type)) {
                $errors[] = "File content validation failed for {$file['name']}";
                continue;
            }

            // Use WordPress secure file upload handling
            $upload_overrides = array(
                'test_form' => false,
                'mimes' => $allowed_types,
                'upload_path' => $project_upload_dir
            );
            
            // Prepare file array for wp_handle_upload
            $uploaded_file = array(
                'name' => $file['name'],
                'type' => $mime_type,
                'tmp_name' => $file['tmp_name'],
                'error' => $file['error'],
                'size' => $file['size']
            );
            
            // Use WordPress secure file upload
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            $upload_result = wp_handle_upload($uploaded_file, $upload_overrides);
            
            if (isset($upload_result['error'])) {
                $errors[] = "Upload failed for {$file['name']}: " . $upload_result['error'];
                continue;
            }
            
            // Move to project directory if not already there
            $final_path = $project_upload_dir . '/' . basename($upload_result['file']);
            if ($upload_result['file'] !== $final_path) {
                global $wp_filesystem;
                if (empty($wp_filesystem)) {
                    require_once ABSPATH . '/wp-admin/includes/file.php';
                    WP_Filesystem();
                }
                if (!$wp_filesystem->move($upload_result['file'], $final_path)) {
                    $errors[] = "Failed to move {$file['name']} to project directory";
                    continue;
                }
            } else {
                $final_path = $upload_result['file'];
            }
            
            $uploaded_files[] = [
                'name' => basename($final_path),
                'original_name' => $file['name'],
                'path' => $final_path,
                'url' => $project_upload_url . '/' . basename($final_path),
                'type' => $file_ext,
                'size' => $file['size'],
                'mime_type' => $mime_type
            ];

            // Add to knowledge base
            self::add_file_to_knowledge_base($final_path, $file['name'], $project_id, $user_id, $file_ext, $mime_type);
        }

        // Return response
        if (!empty($uploaded_files)) {
            wp_send_json_success([
                'message' => count($uploaded_files) . ' file(s) uploaded successfully',
                'files' => $uploaded_files,
                'errors' => $errors
            ]);
        } else {
            wp_send_json_error([
                'message' => 'No files were uploaded successfully',
                'errors' => $errors
            ]);
        }
    }

    /**
     * Add uploaded file to knowledge base
     */
    private static function add_file_to_knowledge_base($file_path, $original_name, $project_id, $user_id, $file_ext, $mime_type) {
        global $wpdb;
        
        try {
            $content = '';
            $content_type = 'file';
            
            // Extract content based on file type
            if ($file_ext === 'txt') {
                // Initialize WP_Filesystem if needed
                global $wp_filesystem;
                if (empty($wp_filesystem)) {
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                    WP_Filesystem();
                }
                $content = $wp_filesystem->get_contents($file_path);
                if (false === $content) {
                    $content = '';
                }
                $content_type = 'text';
            } elseif ($file_ext === 'pdf') {
                $content = self::extract_pdf_content($file_path, $original_name);
                $content_type = 'pdf';
            } elseif (in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif'])) {
                $content = "Image file uploaded: {$original_name}. This image has been added to the project and can be referenced in conversations. The user can ask questions about this image or request analysis of its contents.";
                $content_type = 'image';
            } elseif (in_array($file_ext, ['mp3', 'wav', 'm4a', 'ogg'])) {
                $content = "Audio file uploaded: {$original_name}. This audio file has been added to the project and can be referenced in conversations. The user may ask for transcription or analysis of the audio content.";
                $content_type = 'audio';
            } elseif (in_array($file_ext, ['doc', 'docx'])) {
                $content = self::extract_document_content($file_path, $original_name, $file_ext);
                $content_type = 'document';
            }

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
            // File content insertion with cache invalidation handled elsewhere - direct insert required for file processing
            $table_name = $wpdb->prefix . 'aiohm_vector_entries';
            $result = $wpdb->insert(
                $table_name,
                [
                    'user_id' => $user_id,
                    'content_id' => 'project_file_' . $project_id . '_' . time(),
                    'content_type' => $content_type,
                    'title' => $original_name,
                    'content' => $content,
                    'metadata' => json_encode([
                        'project_id' => $project_id,
                        'file_path' => $file_path,
                        'file_type' => $file_ext,
                        'mime_type' => $mime_type,
                        'upload_date' => current_time('mysql')
                    ]),
                    'is_public' => 0
                ],
                [
                    '%d', '%s', '%s', '%s', '%s', '%s', '%d'
                ]
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery

            if ($result === false) {
                // Failed to add file to knowledge base
            }

        } catch (Exception $e) {
            // Error adding file to knowledge base
        }
    }

    /**
     * Extract text content from PDF files
     */
    private static function extract_pdf_content($file_path, $original_name) {
        try {
            // Attempt extraction using parser factory first
            $factory_result = self::try_parser_factory_extraction($file_path, 'pdf', $original_name);
            
            if ($factory_result !== null) {
                $clean_content = self::sanitize_pdf_content($factory_result['content']);
                return $clean_content;
            }
            
            // Fallback to basic PDF text extraction
            $raw_content = self::read_file_safely($file_path, 'PDF');
            $extracted_text = self::extract_basic_pdf_text($raw_content);
            
            if (!empty($extracted_text) && strlen($extracted_text) > 50) {
                $clean_content = self::sanitize_pdf_content($extracted_text);
                return $clean_content;
            }
            
            return self::create_pdf_extraction_failure_message($original_name);
            
        } catch (Exception $e) {
            return self::create_error_message($original_name, 'PDF', $e->getMessage());
        }
    }
    
    /**
     * Extract basic PDF text using regex patterns
     */
    private static function extract_basic_pdf_text($content) {
        $text = '';
        
        if (preg_match_all('/\((.*?)\)/s', $content, $matches)) {
            foreach ($matches[1] as $match) {
                $decoded = self::decode_pdf_string_simple($match);
                if (self::is_readable_text($decoded)) {
                    $text .= $decoded . ' ';
                }
            }
        }
        
        return preg_replace('/\s+/', ' ', trim($text));
    }
    
    /**
     * Create PDF extraction failure message with helpful information
     */
    private static function create_pdf_extraction_failure_message($original_name) {
        return "PDF Document: {$original_name}\n\nThis PDF file was uploaded but text extraction failed. This could be due to:\n• Image-only PDF (scanned document)\n• Password-protected PDF\n• Complex formatting or encoding\n\nYou can edit this entry to manually add the PDF content, or try re-uploading after converting the PDF to a text-friendly format.";
    }
    
    /**
     * Simple PDF string decoder
     */
    private static function decode_pdf_string_simple($str) {
        // Handle basic PDF string encodings
        $str = preg_replace_callback('/\\\\(\d{3})/', function($matches) {
            return chr(octdec($matches[1]));
        }, $str);
        
        return str_replace(['\\n', '\\r', '\\t', '\\\\', '\\(', '\\)'], 
                          ["\n", "\r", "\t", "\\", "(", ")"], $str);
    }
    
    /**
     * Check if extracted text is readable
     */
    private static function is_readable_text($text) {
        if (empty($text) || strlen($text) < 3) {
            return false;
        }
        
        // Skip obvious PDF metadata patterns
        $metadata_patterns = [
            '/^Identity/',
            '/^Adobe/',
            '/^D:\d{8}/',
            '/^FontDescriptor/',
            '/^FontFile/',
            '/^[A-Z]{4,}$/',
            '/^[a-f0-9]{8,}$/i',
            '/^[üÜÿ´ý\*\s]+$/',
            '/^\d+\s+\d+\s+obj/',
            '/^endobj$/',
            '/^stream$/',
            '/^endstream$/',
        ];
        
        foreach ($metadata_patterns as $pattern) {
            if (preg_match($pattern, trim($text))) {
                return false;
            }
        }
        
        // Check if text contains mostly readable characters (more lenient)
        $readable = preg_match_all('/[a-zA-ZÀ-ÿ0-9 .,!?\-\'"()[\]:;]/', $text);
        $total = strlen($text);
        
        // Must have reasonable amount of letters
        $letters = preg_match_all('/[a-zA-ZÀ-ÿ]/', $text);
        $letter_ratio = $letters / $total;
        
        return $total > 0 && ($readable / $total) > 0.6 && $letter_ratio > 0.3;
    }
    
    /**
     * Sanitize PDF content to prevent database issues
     */
    private static function sanitize_pdf_content($content) {
        // Remove null bytes and other problematic characters
        $content = str_replace("\0", '', $content);
        
        // Remove control characters except newlines, tabs, and carriage returns
        $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $content);
        
        // Remove Adobe font metadata and PDF gibberish
        $gibberish_patterns = [
            '/Identity\s+Adobe\s+Identity\s+Adobe/i',
            '/üÜÿ´ý\*+/i',
            '/endstream\s+endobj/i',
            '/\d+\s+0\s+obj\s+<<.*?>>/i',
            '/FontDescriptor.*?endobj/is',
            '/FontFile.*?endobj/is',
            '/CIDSystemInfo.*?endobj/is',
            '/Type\/Font.*?endobj/is',
        ];
        
        foreach ($gibberish_patterns as $pattern) {
            $content = preg_replace($pattern, ' ', $content);
        }
        
        // Split into lines and filter out obvious junk lines
        $lines = explode("\n", $content);
        $clean_lines = [];
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // Skip empty lines
            if (empty($line)) continue;
            
            // Skip lines that are obviously PDF metadata
            if (preg_match('/^(Identity|Adobe|FontName|FontDescriptor|endobj|obj|stream|endstream|xref|startxref|trailer|\d+\s+\d+\s+R)$/i', $line)) {
                continue;
            }
            
            // Skip lines with too many special characters
            $special_ratio = strlen(preg_replace('/[a-zA-Z0-9\s.,!?\-\'"]/', '', $line)) / max(1, strlen($line));
            if ($special_ratio > 0.5) continue;
            
            // Keep lines that have reasonable letter content
            $letter_count = preg_match_all('/[a-zA-Z]/', $line);
            if ($letter_count >= 3 || strlen($line) > 20) {
                $clean_lines[] = $line;
            }
        }
        
        $content = implode("\n", $clean_lines);
        
        // Ensure UTF-8 encoding
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', ['UTF-8', 'ISO-8859-1', 'ASCII']);
        }
        
        // Clean up excessive whitespace
        $content = preg_replace('/[ \t]+/', ' ', $content);
        $content = preg_replace('/\n\s*\n\s*\n+/', "\n\n", $content);
        
        return trim($content);
    }
    
    /**
     * Extract content from HTML files
     */
    private static function extract_html_content($file_path, $original_name) {
        try {
            // Attempt extraction using parser factory first
            $factory_result = self::try_parser_factory_extraction($file_path, 'html', $original_name);
            
            if ($factory_result !== null) {
                $clean_content = self::sanitize_html_content($factory_result['content']);
                $title = isset($factory_result['title']) ? $factory_result['title'] : 
                         self::extract_html_title($file_path, $clean_content, $original_name);
                
                return [
                    'content' => $clean_content,
                    'title' => $title
                ];
            }
            
            // Fallback extraction methods
            $raw_content = self::read_file_safely($file_path, 'HTML');
            
            // Try enhanced HTML processing first
            $enhanced_content = self::process_html_content($raw_content);
            if (!empty(trim($enhanced_content))) {
                return self::create_html_result($enhanced_content, $file_path, $original_name, 'enhanced');
            }
            
            // Basic extraction as final fallback
            $basic_content = self::extract_basic_html_content($raw_content);
            if (!empty(trim($basic_content))) {
                return self::create_html_result($basic_content, $file_path, $original_name, 'basic');
            }
            
            return self::create_fallback_message($original_name, 'HTML');
            
        } catch (Exception $e) {
            return self::create_error_message($original_name, 'HTML', $e->getMessage());
        }
    }
    
    /**
     * Process HTML content to preserve structure while extracting text
     */
    private static function process_html_content($html) {
        // Remove script and style elements completely
        $html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);
        $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
        
        // Convert common HTML elements to readable text with structure
        $replacements = [
            // Headers - add newlines and emphasis
            '/<h[1-6][^>]*>(.*?)<\/h[1-6]>/is' => "\n\n**$1**\n\n",
            
            // Paragraphs - add line breaks
            '/<p[^>]*>(.*?)<\/p>/is' => "$1\n\n",
            
            // Line breaks
            '/<br[^>]*>/i' => "\n",
            
            // Lists - preserve structure
            '/<ul[^>]*>(.*?)<\/ul>/is' => "\n$1\n",
            '/<ol[^>]*>(.*?)<\/ol>/is' => "\n$1\n",
            '/<li[^>]*>(.*?)<\/li>/is' => "• $1\n",
            
            // Tables - preserve structure
            '/<table[^>]*>(.*?)<\/table>/is' => "\n\n[TABLE]\n$1\n[/TABLE]\n\n",
            '/<tr[^>]*>(.*?)<\/tr>/is' => "$1\n",
            '/<t[hd][^>]*>(.*?)<\/t[hd]>/is' => "$1\t",
            
            // Emphasis
            '/<strong[^>]*>(.*?)<\/strong>/is' => "**$1**",
            '/<b[^>]*>(.*?)<\/b>/is' => "**$1**",
            '/<em[^>]*>(.*?)<\/em>/is' => "*$1*",
            '/<i[^>]*>(.*?)<\/i>/is' => "*$1*",
            
            // Links - preserve link text and URL
            '/<a[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/is' => "$2 [$1]",
            
            // Divs and spans - just remove tags
            '/<div[^>]*>(.*?)<\/div>/is' => "$1\n",
            '/<span[^>]*>(.*?)<\/span>/is' => "$1",
        ];
        
        foreach ($replacements as $pattern => $replacement) {
            $html = preg_replace($pattern, $replacement, $html);
        }
        
        // Remove remaining HTML tags
        $text = wp_strip_all_tags($html);
        
        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        
        // Clean up whitespace while preserving structure
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n\s*\n\s*\n+/', "\n\n", $text);
        
        return trim($text);
    }
    
    /**
     * Try to extract content using the parser factory
     */
    private static function try_parser_factory_extraction($file_path, $extension, $original_name) {
        $parser_factory_file = dirname(__FILE__) . '/parsers/parser-factory.php';
        
        if (!file_exists($parser_factory_file)) {
            return null;
        }
        
        require_once($parser_factory_file);
        
        if (!class_exists('AIOHM_Parser_Factory')) {
            return null;
        }
        
        $parsed_data = AIOHM_Parser_Factory::parse_file($file_path, $extension);
        
        if ($parsed_data && !empty(trim($parsed_data['content']))) {
            return $parsed_data;
        }
        
        return null;
    }
    
    /**
     * Safely read file contents with error handling
     */
    private static function read_file_safely($file_path, $file_type) {
        $content = file_get_contents($file_path);
        if ($content === false) {
            throw new Exception('Cannot read ' . esc_html($file_type) . ' file');
        }
        return $content;
    }
    
    /**
     * Extract basic HTML content by stripping tags
     */
    private static function extract_basic_html_content($raw_content) {
        $content = wp_strip_all_tags($raw_content);
        return html_entity_decode($content, ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Create HTML extraction result array
     */
    private static function create_html_result($content, $file_path, $original_name, $method) {
        $clean_content = self::sanitize_html_content($content);
        $title = self::extract_html_title($file_path, $clean_content, $original_name);
        
        
        return [
            'content' => $clean_content,
            'title' => $title
        ];
    }
    
    /**
     * Create fallback message for failed extractions
     */
    private static function create_fallback_message($original_name, $file_type) {
        return "{$file_type} Document: {$original_name}\n\nThis {$file_type} file was uploaded but content extraction failed.\n\nYou can edit this entry to manually add the {$file_type} content.";
    }
    
    /**
     * Create error message for extraction failures
     */
    private static function create_error_message($original_name, $file_type, $error_message) {
        return "{$file_type} Document: {$original_name} - Content extraction failed: {$error_message}\n\nYou can edit this entry to manually add the {$file_type} content.";
    }
    
    /**
     * Sanitize HTML content to prevent database issues
     */
    private static function sanitize_html_content($content) {
        // Remove null bytes and other problematic characters
        $content = str_replace("\0", '', $content);
        
        // Remove control characters except newlines, tabs, and carriage returns
        $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $content);
        
        // Ensure UTF-8 encoding
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', ['UTF-8', 'ISO-8859-1', 'ASCII']);
        }
        
        // Clean up excessive whitespace while preserving intentional formatting
        $content = preg_replace('/[ \t]+/', ' ', $content);
        $content = preg_replace('/\n\s*\n\s*\n+/', "\n\n", $content);
        
        return trim($content);
    }
    
    /**
     * Extract title from HTML file
     */
    private static function extract_html_title($file_path, $content, $original_name) {
        try {
            $html_content = file_get_contents($file_path);
            if ($html_content === false) {
                return self::extract_title_from_content($content, $original_name);
            }
            
            // Try multiple HTML-specific extraction methods
            $title_sources = [
                'title_tag' => '/<title[^>]*>(.*?)<\/title>/is',
                'h1_tag' => '/<h1[^>]*>(.*?)<\/h1>/is',
                'meta_title' => '/<meta[^>]*(?:name=["\']title["\']|property=["\']og:title["\'])[^>]*content=["\']([^"\']*)["\'][^>]*>/is'
            ];
            
            foreach ($title_sources as $source => $pattern) {
                $title = self::extract_title_with_pattern($html_content, $pattern);
                if ($title) {
                    return $title;
                }
            }
            
            // Extract from processed content
            return self::extract_title_from_content($content, $original_name);
            
        } catch (Exception $e) {
            return pathinfo($original_name, PATHINFO_FILENAME);
        }
    }
    
    /**
     * Extract title using regex pattern and validate
     */
    private static function extract_title_with_pattern($html_content, $pattern) {
        if (preg_match($pattern, $html_content, $matches)) {
            $title = trim(wp_strip_all_tags($matches[1]));
            $title = html_entity_decode($title, ENT_QUOTES, 'UTF-8');
            
            if (self::is_valid_title($title)) {
                return $title;
            }
        }
        return null;
    }
    
    /**
     * Extract title from processed content
     */
    private static function extract_title_from_content($content, $original_name) {
        $lines = explode("\n", $content);
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // Check for markdown-style headers
            if (preg_match('/^\*\*(.*?)\*\*$/', $line, $matches)) {
                $title = trim($matches[1]);
                if (self::is_valid_title($title)) {
                    return $title;
                }
            }
            
            // Check for meaningful content lines
            if (self::is_meaningful_content_line($line)) {
                return $line;
            }
        }
        
        return pathinfo($original_name, PATHINFO_FILENAME);
    }
    
    /**
     * Validate if string is suitable as a title
     */
    private static function is_valid_title($title) {
        return !empty($title) && strlen($title) > 3 && strlen($title) < 200;
    }
    
    /**
     * Check if line contains meaningful content for title
     */
    private static function is_meaningful_content_line($line) {
        if (strlen($line) <= 5 || strlen($line) >= 200 || str_word_count($line) < 2) {
            return false;
        }
        
        // Avoid lines that look like navigation or boilerplate
        $boilerplate_patterns = [
            '/^(home|menu|nav|skip|click|login|search|©|copyright)/i'
        ];
        
        foreach ($boilerplate_patterns as $pattern) {
            if (preg_match($pattern, $line)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Validate AJAX security and get content entry
     */
    private static function validate_reprocess_ajax_request($content_id, $allowed_content_types) {
        // Security check
        if (!check_ajax_referer('aiohm_admin_nonce', 'nonce', false) || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Security check failed.', 'aiohm-knowledge-assistant')]);
            return null;
        }
        
        // Content ID validation
        if (empty($content_id)) {
            wp_send_json_error(['message' => __('Content ID is required.', 'aiohm-knowledge-assistant')]);
            return null;
        }
        
        global $wpdb;
        
        // Get the original entry with metadata with caching
        $cache_key = 'aiohm_entry_' . $content_id;
        $entry = wp_cache_get($cache_key, 'aiohm_kb');
        
        if (false === $entry) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Entry validation query with caching implemented
            $entry = $wpdb->get_row($wpdb->prepare(
                "SELECT content, title, content_type, metadata FROM " . $wpdb->prefix . "aiohm_vector_entries WHERE content_id = %s LIMIT 1",
                $content_id
            ), ARRAY_A);
            
            if ($entry) {
                wp_cache_set($cache_key, $entry, 'aiohm_kb', 300); // 5 minute cache
            }
        }
        
        if (!$entry) {
            wp_send_json_error(['message' => __('Content not found.', 'aiohm-knowledge-assistant')]);
            return null;
        }
        
        // Content type validation
        if (!in_array($entry['content_type'], $allowed_content_types, true)) {
            $allowed_types_str = implode(', ', $allowed_content_types);
            // translators: %s is a comma-separated list of allowed content types
            wp_send_json_error(['message' => sprintf(__('This function is only for %s content.', 'aiohm-knowledge-assistant'), $allowed_types_str)]);
            return null;
        }
        
        return $entry;
    }
    
    /**
     * Get file path from entry metadata
     */
    private static function get_file_path_from_metadata($metadata_json) {
        $metadata = json_decode($metadata_json, true);
        
        if (!is_array($metadata) || !isset($metadata['attachment_id'])) {
            return null;
        }
        
        $file_path = get_attached_file($metadata['attachment_id']);
        
        if (!$file_path || !file_exists($file_path)) {
            return null;
        }
        
        return $file_path;
    }
    
    /**
     * Update database entry content
     */
    private static function update_entry_content($content_id, $new_content) {
        global $wpdb;
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Content update operation
        $updated = $wpdb->update(
            $wpdb->prefix . 'aiohm_vector_entries',
            ['content' => $new_content],
            ['content_id' => $content_id],
            ['%s'],
            ['%s']
        );
        
        // Clear cache for updated entry
        $cache_key = 'aiohm_entry_' . $content_id;
        wp_cache_delete($cache_key, 'aiohm_kb');
        
        return $updated !== false;
    }
    
    /**
     * Create processing details for AJAX response
     */
    private static function create_processing_details($content_length, $file_type) {
        return [
            'extraction_quality' => $content_length > 1000 ? 'high' : ($content_length > 200 ? 'medium' : 'low'),
            'processing_log' => [
                ['success', "{$file_type} file located successfully"],
                ['success', 'Content re-extracted using improved methods'],
                ['success', 'Database updated with new content'],
                ['info', "Extracted {$content_length} characters"]
            ]
        ];
    }

    /**
     * Extract text content from Word documents
     */
    private static function extract_document_content($file_path, $original_name, $file_ext) {
        try {
            // SECURITY: Removed exec() calls to prevent command injection vulnerabilities
            // Command line document processing tools are disabled for security reasons

            // Method 3: Try ZIP extraction for .docx (it's essentially a ZIP file)
            if ($file_ext === 'docx' && class_exists('ZipArchive')) {
                $zip = new ZipArchive();
                if ($zip->open($file_path) === TRUE) {
                    $xml_content = $zip->getFromName('word/document.xml');
                    $zip->close();
                    
                    if ($xml_content) {
                        // Parse XML and extract text
                        $dom = new DOMDocument();
                        if (@$dom->loadXML($xml_content)) {
                            $text_nodes = $dom->getElementsByTagName('t');
                            $content = '';
                            foreach ($text_nodes as $node) {
                                $content .= $node->nodeValue . ' ';
                            }
                            
                            if (strlen(trim($content)) > 50) {
                                return "Word Document: {$original_name}\n\nContent:\n" . trim($content);
                            }
                        }
                    }
                }
            }

            // Fallback - create a descriptive entry
            return "Document uploaded: {$original_name}. This is a Word document that has been uploaded to the project. The user has indicated they want to analyze or discuss the contents of this document. While I cannot directly read Word documents in this conversation, I can help the user by asking them to paste relevant text excerpts from the document that they'd like to discuss, or I can provide guidance on how to extract and work with document content.";

        } catch (Exception $e) {
            return "Document: {$original_name} - Content extraction failed, but file is available for reference.";
        }
    }

    /**
     * Handle AJAX request to get Brand Soul content for viewing
     */
    public static function handle_get_brand_soul_content_ajax() {
        if (!check_ajax_referer('aiohm_admin_nonce', 'nonce', false) || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Security check failed.', 'aiohm-knowledge-assistant')]);
            return;
        }

        $content_id = isset($_POST['content_id']) ? sanitize_text_field(wp_unslash($_POST['content_id'])) : '';
        if (empty($content_id)) {
            wp_send_json_error(['message' => __('Content ID is required.', 'aiohm-knowledge-assistant')]);
            return;
        }

        try {
            global $wpdb;
            $rag_engine = new AIOHM_KB_RAG_Engine();
            $table_name = $wpdb->prefix . 'aiohm_vector_entries';

            // Get the brand soul content by content_id with caching
            $cache_key = 'aiohm_brand_soul_' . $content_id;
            $entry = wp_cache_get($cache_key, 'aiohm_kb');
            
            if (false === $entry) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Brand soul content query with caching implemented
                $entry = $wpdb->get_row($wpdb->prepare(
                    "SELECT content, title FROM " . $wpdb->prefix . "aiohm_vector_entries WHERE content_id = %s AND content_type = 'brand_soul' LIMIT 1",
                    $content_id
                ), ARRAY_A);
                
                if ($entry) {
                    wp_cache_set($cache_key, $entry, 'aiohm_kb', 300); // 5 minute cache
                }
            }

            if (!$entry) {
                wp_send_json_error(['message' => __('Brand Soul content not found.', 'aiohm-knowledge-assistant')]);
                return;
            }

            wp_send_json_success([
                'content' => $entry['content'],
                'title' => $entry['title']
            ]);

        } catch (Exception $e) {
            wp_send_json_error(['message' => __('Error retrieving Brand Soul content.', 'aiohm-knowledge-assistant')]);
        }
    }

    /**
     * Handle AJAX request to get content for viewing (supports all content types)
     */
    public static function handle_get_content_for_view_ajax() {
        if (!check_ajax_referer('aiohm_admin_nonce', 'nonce', false) || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Security check failed.', 'aiohm-knowledge-assistant')]);
            return;
        }

        $content_id = isset($_POST['content_id']) ? sanitize_text_field(wp_unslash($_POST['content_id'])) : '';
        $content_type = isset($_POST['content_type']) ? sanitize_text_field(wp_unslash($_POST['content_type'])) : '';
        
        if (empty($content_id)) {
            wp_send_json_error(['message' => __('Content ID is required.', 'aiohm-knowledge-assistant')]);
            return;
        }

        try {
            global $wpdb;
            $rag_engine = new AIOHM_KB_RAG_Engine();
            $table_name = $wpdb->prefix . 'aiohm_vector_entries';

            // Get the content by content_id, including metadata with caching
            $cache_key = 'aiohm_content_' . $content_id;
            $entry = wp_cache_get($cache_key, 'aiohm_kb');
            
            if (false === $entry) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Content lookup query with caching implemented
                $entry = $wpdb->get_row($wpdb->prepare(
                    "SELECT content, title, content_type, metadata FROM " . $wpdb->prefix . "aiohm_vector_entries WHERE content_id = %s LIMIT 1",
                    $content_id
                ), ARRAY_A);
                
                if ($entry) {
                    wp_cache_set($cache_key, $entry, 'aiohm_kb', 300); // 5 minute cache
                }
            }

            if (!$entry) {
                wp_send_json_error(['message' => __('Content not found.', 'aiohm-knowledge-assistant')]);
                return;
            }

            // Handle PDF content display with proper parsing
            $formatted_content = $entry['content'];
            $content_type = $entry['content_type'];
            if ($content_type === 'application/pdf' || $content_type === 'PDF') {
                // Always try to show actual PDF content, re-parse if needed
                $should_reparse = false;
                
                // Check if we need to re-parse the PDF
                if (strpos($entry['content'], 'content extraction failed') !== false || 
                    strpos($entry['content'], 'PDF parser not available') !== false ||
                    strpos($entry['content'], 'Content will be processed when you interact') !== false ||
                    strpos($entry['content'], 'PDF Document uploaded') !== false ||
                    strlen(trim($entry['content'])) < 50) {
                    $should_reparse = true;
                }
                
                if ($should_reparse) {
                    // Try to re-extract PDF content
                    $metadata = json_decode($entry['metadata'], true);
                    
                    if (is_array($metadata) && isset($metadata['attachment_id'])) {
                        $attachment_path = get_attached_file($metadata['attachment_id']);
                        
                        if ($attachment_path && file_exists($attachment_path)) {
                            // Force re-parsing of the PDF using AI-enhanced processor
                            try {
                                
                                // Try AI PDF processor first (highest priority)
                                if (class_exists('AIOHM_AI_PDF_Processor')) {
                                    $ai_pdf_processor = new AIOHM_AI_PDF_Processor();
                                    $parsed_data = $ai_pdf_processor->parse($attachment_path);
                                    
                                    if ($parsed_data && !empty(trim($parsed_data['content']))) {
                                        $formatted_content = trim($parsed_data['content']);
                                        
                                        // Update the database with the new content
                                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- PDF reprocessing update
                                        $wpdb->update(
                                            $wpdb->prefix . 'aiohm_vector_entries',
                                            ['content' => $formatted_content],
                                            ['content_id' => $content_id],
                                            ['%s'],
                                            ['%s']
                                        );
                                        
                                        // Clear cache for updated entry
                                        $cache_key = 'aiohm_entry_' . $content_id;
                                        wp_cache_delete($cache_key, 'aiohm_kb');
                                    }
                                }
                                
                                // Fallback to regular PDF parser if AI processor fails
                                if (empty($formatted_content) || strpos($formatted_content, 'PDF Document uploaded') !== false) {
                                    if (class_exists('AIOHM_Parser_Factory')) {
                                        $parsed_data = AIOHM_Parser_Factory::parse_file($attachment_path, 'pdf');
                                        
                                        if ($parsed_data && !empty(trim($parsed_data['content']))) {
                                            $formatted_content = trim($parsed_data['content']);
                                            
                                            // Update the database with the new content
                                            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- PDF fallback parser update
                                            $wpdb->update(
                                                $wpdb->prefix . 'aiohm_vector_entries',
                                                ['content' => $formatted_content],
                                                ['content_id' => $content_id],
                                                ['%s'],
                                                ['%s']
                                            );
                                            
                                            // Clear cache for updated entry
                                            $cache_key = 'aiohm_entry_' . $content_id;
                                            wp_cache_delete($cache_key, 'aiohm_kb');
                                        }
                                    }
                                }
                                
                                if (empty($formatted_content) || strpos($formatted_content, 'PDF Document uploaded') !== false) {
                                    $formatted_content = "Unable to extract text content from this PDF.\n\n";
                                    $formatted_content .= "Possible reasons:\n";
                                    $formatted_content .= "• Image-only PDF (scanned document requiring OCR)\n";
                                    $formatted_content .= "• Password-protected or encrypted PDF\n";
                                    $formatted_content .= "• Complex formatting or unusual encoding\n";
                                    $formatted_content .= "• Corrupted or non-standard PDF file\n\n";
                                    $formatted_content .= "Solutions:\n";
                                    $formatted_content .= "• For scanned documents: Use OCR software to convert to text\n";
                                    $formatted_content .= "• For encrypted PDFs: Remove password protection first\n";
                                    $formatted_content .= "• Edit this entry manually to add the content\n";
                                    $formatted_content .= "• Try converting the PDF to a text format first";
                                }
                            } catch (Exception $e) {
                                $error_msg = $e->getMessage();
                                $formatted_content = "PDF Content Extraction Error\n\n";
                                
                                // Provide specific guidance based on error type
                                if (strpos($error_msg, 'encrypted') !== false || strpos($error_msg, 'password') !== false) {
                                    $formatted_content .= "🔒 This PDF is password-protected or encrypted.\n\n";
                                    $formatted_content .= "To access the content:\n";
                                    $formatted_content .= "• Remove password protection from the PDF\n";
                                    $formatted_content .= "• Upload the unprotected version\n";
                                    $formatted_content .= "• Or manually enter the content below";
                                } elseif (strpos($error_msg, 'image') !== false || strpos($error_msg, 'OCR') !== false || strpos($error_msg, 'scanned') !== false) {
                                    $formatted_content .= "📄 This appears to be a scanned document (image-based PDF).\n\n";
                                    $formatted_content .= "To extract text:\n";
                                    $formatted_content .= "• Use OCR software to convert images to text\n";
                                    $formatted_content .= "• Try online OCR tools like Google Drive or Adobe Acrobat\n";
                                    $formatted_content .= "• Or manually enter the content below";
                                } elseif (strpos($error_msg, 'complex formatting') !== false) {
                                    $formatted_content .= "📊 This PDF has complex formatting that prevents text extraction.\n\n";
                                    $formatted_content .= "To access the content:\n";
                                    $formatted_content .= "• Try copying text directly from a PDF viewer\n";
                                    $formatted_content .= "• Convert to a simpler format (e.g., Word, then plain text)\n";
                                    $formatted_content .= "• Or manually enter the content below";
                                } else {
                                    $formatted_content .= "⚠️ Text extraction failed: " . $error_msg . "\n\n";
                                    $formatted_content .= "You can manually enter the content below or try:\n";
                                    $formatted_content .= "• Re-uploading the PDF\n";
                                    $formatted_content .= "• Converting to a different format first\n";
                                    $formatted_content .= "• Checking if the PDF is corrupted";
                                }
                                
                                $formatted_content .= "\n\n--- Manual Content Entry ---\n";
                                $formatted_content .= "You can edit this entry to add the PDF content manually.";
                            }
                        } else {
                            $formatted_content = "📁 PDF file not found on server.\n\n";
                            $formatted_content .= "The original PDF file may have been moved or deleted.\n";
                            $formatted_content .= "You can edit this entry to add the content manually.";
                        }
                    } else {
                        $formatted_content = "📋 PDF metadata not available.\n\n";
                        $formatted_content .= "Unable to locate the original PDF file information.\n";
                        $formatted_content .= "You can edit this entry to add the content manually.";
                    }
                }
                
                // Ensure we never show generic messages for successfully stored content
                if (strpos($formatted_content, 'Content will be processed when you interact') !== false ||
                    strpos($formatted_content, 'PDF Document uploaded') !== false) {
                    $formatted_content = "PDF content is available. You can edit this entry to view or modify the content.";
                }
            }

            // Format content based on type for better display
            if ($content_type === 'application/json' || $content_type === 'text/json') {
                // Try to pretty-print JSON
                $decoded = json_decode($formatted_content, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $formatted_content = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
            } elseif ($content_type === 'text/csv') {
                // Format CSV with better spacing and headers
                $lines = explode("\n", $formatted_content);
                if (!empty($lines)) {
                    $formatted_lines = [];
                    foreach ($lines as $line) {
                        if (!empty(trim($line))) {
                            $formatted_lines[] = str_replace(',', ' | ', trim($line));
                        }
                    }
                    $formatted_content = implode("\n", $formatted_lines);
                }
            }
            
            wp_send_json_success([
                'content' => $formatted_content,
                'title' => $entry['title'],
                'content_type' => $entry['content_type']
            ]);

        } catch (Exception $e) {
            wp_send_json_error(['message' => __('Error retrieving content.', 'aiohm-knowledge-assistant')]);
        }
    }

    /**
     * Handle AJAX request to get PDF URL for viewing
     */
    public static function handle_get_pdf_url_ajax() {
        // Verify nonce and user permissions
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'aiohm_admin_nonce')) {
            wp_send_json_error(['message' => __('Security check failed.', 'aiohm-knowledge-assistant')]);
            return;
        }

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'aiohm-knowledge-assistant')]);
            return;
        }

        $content_id = isset($_POST['content_id']) ? sanitize_text_field(wp_unslash($_POST['content_id'])) : '';
        if (empty($content_id)) {
            wp_send_json_error(['message' => __('Content ID is required.', 'aiohm-knowledge-assistant')]);
            return;
        }

        try {
            global $wpdb;
            
            // Get the KB entry with caching
            $cache_key = 'aiohm_kb_entry_' . $content_id;
            $entry = wp_cache_get($cache_key, 'aiohm_kb');
            
            if (false === $entry) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- KB entry lookup with caching implemented
                $entry = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM " . $wpdb->prefix . "aiohm_vector_entries WHERE content_id = %s LIMIT 1",
                    $content_id
                ), ARRAY_A);
                
                if ($entry) {
                    wp_cache_set($cache_key, $entry, 'aiohm_kb', 300); // 5 minute cache
                }
            }

            if (!$entry) {
                wp_send_json_error(['message' => __('Content not found.', 'aiohm-knowledge-assistant')]);
                return;
            }

            // Check if it's a PDF
            if (!in_array($entry['content_type'], ['PDF', 'application/pdf'], true)) {
                wp_send_json_error(['message' => __('Content is not a PDF.', 'aiohm-knowledge-assistant')]);
                return;
            }

            $metadata = json_decode($entry['metadata'], true);
            $pdf_url = null;

            // Try to get PDF URL from metadata
            if (is_array($metadata)) {
                if (isset($metadata['attachment_id'])) {
                    $pdf_url = wp_get_attachment_url($metadata['attachment_id']);
                } elseif (isset($metadata['url'])) {
                    $pdf_url = $metadata['url'];
                } elseif (isset($metadata['file_path'])) {
                    // Convert file path to URL
                    $upload_dir = wp_upload_dir();
                    $file_path = $metadata['file_path'];
                    if (strpos($file_path, $upload_dir['basedir']) === 0) {
                        $pdf_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $file_path);
                    }
                }
            }

            if (!$pdf_url || !filter_var($pdf_url, FILTER_VALIDATE_URL)) {
                wp_send_json_error(['message' => __('PDF URL not available.', 'aiohm-knowledge-assistant')]);
                return;
            }

            wp_send_json_success([
                'pdf_url' => esc_url($pdf_url),
                'title' => $entry['title'],
                'content_type' => $entry['content_type']
            ]);

        } catch (Exception $e) {
            wp_send_json_error(['message' => __('Error retrieving PDF URL.', 'aiohm-knowledge-assistant')]);
        }
    }

    /**
     * Handles the AJAX request to get AI usage statistics
     */
    public static function handle_get_usage_stats_ajax() {
        if (!check_ajax_referer('aiohm_admin_nonce', 'nonce', false) || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Security check failed.', 'aiohm-knowledge-assistant')]);
            return;
        }

        try {
            global $wpdb;
            
            // Create usage stats table if it doesn't exist
            $table_name = $wpdb->prefix . 'aiohm_usage_tracking';
            $charset_collate = $wpdb->get_charset_collate();
            
            $sql = "CREATE TABLE IF NOT EXISTS $table_name (
                id int(11) NOT NULL AUTO_INCREMENT,
                provider varchar(20) NOT NULL,
                tokens_used int(11) NOT NULL DEFAULT 0,
                requests_count int(11) NOT NULL DEFAULT 1,
                cost_estimate decimal(10,6) NOT NULL DEFAULT 0.000000,
                usage_date date NOT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY provider_date (provider, usage_date)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);

            // Get current date and 30 days ago
            $today = gmdate('Y-m-d');
            $thirty_days_ago = gmdate('Y-m-d', strtotime('-30 days'));

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            // Usage analytics queries require direct database access with table name interpolation for performance tracking
            
            // Calculate total tokens for last 30 days
            $total_tokens_30d = $wpdb->get_var($wpdb->prepare(
                "SELECT SUM(tokens_used) FROM " . $wpdb->prefix . "aiohm_usage_tracking WHERE usage_date >= %s",
                $thirty_days_ago
            )) ?: 0;

            // Calculate today's tokens
            $tokens_today = $wpdb->get_var($wpdb->prepare(
                "SELECT SUM(tokens_used) FROM " . $wpdb->prefix . "aiohm_usage_tracking WHERE usage_date = %s",
                $today
            )) ?: 0;

            // Calculate estimated cost for last 30 days
            $estimated_cost = $wpdb->get_var($wpdb->prepare(
                "SELECT SUM(cost_estimate) FROM " . $wpdb->prefix . "aiohm_usage_tracking WHERE usage_date >= %s",
                $thirty_days_ago
            )) ?: 0;

            // Get breakdown by provider
            $provider_stats = $wpdb->get_results($wpdb->prepare(
                "SELECT 
                    provider,
                    SUM(tokens_used) as tokens,
                    SUM(requests_count) as requests,
                    SUM(cost_estimate) as cost
                FROM " . $wpdb->prefix . "aiohm_usage_tracking 
                WHERE usage_date >= %s 
                GROUP BY provider",
                $thirty_days_ago
            ), ARRAY_A);
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

            // Format provider data
            $providers = [
                'openai' => ['tokens' => 0, 'requests' => 0, 'cost' => '0.00'],
                'gemini' => ['tokens' => 0, 'requests' => 0, 'cost' => '0.00'],
                'claude' => ['tokens' => 0, 'requests' => 0, 'cost' => '0.00'],
                'shareai' => ['tokens' => 0, 'requests' => 0, 'cost' => '0.00']
            ];

            foreach ($provider_stats as $stat) {
                if (isset($providers[$stat['provider']])) {
                    $providers[$stat['provider']] = [
                        'tokens' => (int) $stat['tokens'],
                        'requests' => (int) $stat['requests'],
                        'cost' => number_format((float) $stat['cost'], 2)
                    ];
                }
            }

            wp_send_json_success([
                'total_tokens_30d' => (int) $total_tokens_30d,
                'tokens_today' => (int) $tokens_today,
                'estimated_cost' => number_format((float) $estimated_cost, 2),
                'providers' => $providers
            ]);

        } catch (Exception $e) {
            wp_send_json_error(['message' => __('Error retrieving usage statistics.', 'aiohm-knowledge-assistant')]);
        }
    }

    /**
     * Helper function to log AI usage (call this whenever AI APIs are used)
     */
    public static function log_ai_usage($provider, $tokens_used, $cost_estimate = 0) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'aiohm_usage_tracking';
        $today = gmdate('Y-m-d');
        
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        // Usage tracking operations require direct database access for real-time statistics logging with table name interpolation
        
        // Check if we already have an entry for today and this provider
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, tokens_used, requests_count, cost_estimate FROM " . $wpdb->prefix . "aiohm_usage_tracking 
             WHERE provider = %s AND usage_date = %s",
            $provider, $today
        ));
        
        if ($existing) {
            // Update existing record
            $wpdb->update(
                $table_name,
                [
                    'tokens_used' => $existing->tokens_used + $tokens_used,
                    'requests_count' => $existing->requests_count + 1,
                    'cost_estimate' => $existing->cost_estimate + $cost_estimate
                ],
                ['id' => $existing->id]
            );
        } else {
            // Create new record
            $wpdb->insert(
                $table_name,
                [
                    'provider' => $provider,
                    'tokens_used' => $tokens_used,
                    'requests_count' => 1,
                    'cost_estimate' => $cost_estimate,
                    'usage_date' => $today
                ]
            );
        }
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Handle download conversation as PDF
     */
    public static function handle_download_conversation_pdf_ajax() {
        if (!check_ajax_referer('aiohm_private_chat_nonce', 'nonce', false)) {
            wp_die(esc_html__('Security check failed.', 'aiohm-knowledge-assistant'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this feature.', 'aiohm-knowledge-assistant'));
        }
        
        try {
            $conversation_id = isset($_POST['conversation_id']) ? intval($_POST['conversation_id']) : 0;
            if (!$conversation_id) {
                wp_die(esc_html__('Invalid conversation ID.', 'aiohm-knowledge-assistant'));
            }
            
            global $wpdb;
            $user_id = get_current_user_id();
            
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            // PDF generation queries require direct database access with table name interpolation for user-owned conversation data
            
            // Get conversation details
            $conversation = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM " . $wpdb->prefix . "aiohm_conversations WHERE id = %d AND user_id = %d",
                $conversation_id, $user_id
            ));
            
            if (!$conversation) {
                wp_die(esc_html__('Conversation not found or access denied.', 'aiohm-knowledge-assistant'));
            }
            
            // Get all messages for this conversation
            $messages = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM " . $wpdb->prefix . "aiohm_messages WHERE conversation_id = %d ORDER BY created_at ASC",
                $conversation_id
            ));
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            
            // Include the PDF library loader and enhanced PDF class
            $pdf_loader = AIOHM_PDF_Library_Loader::get_instance();
            if (!$pdf_loader->load_mpdf()) {
                wp_die(esc_html__('PDF generation is not available.', 'aiohm-knowledge-assistant'));
            }
            
            // Create PDF instance
            $pdf = $pdf_loader->get_pdf();
            
            $pdf->AddPage();
            
            // Conversation details
            $pdf->ChapterTitle('Conversation: ' . $conversation->title);
            
            // Add conversation metadata using message blocks
            $pdf->MessageBlock('system', 'Created: ' . gmdate('F j, Y g:i A', strtotime($conversation->created_at)), $conversation->created_at);
            $pdf->MessageBlock('system', 'Project ID: ' . $conversation->project_id, $conversation->created_at);
            
            // Messages
            foreach ($messages as $message) {
                $pdf->MessageBlock($message->sender, $message->content, $message->created_at);
            }
            
            // Output PDF
            $filename = 'conversation-' . $conversation_id . '-' . gmdate('Y-m-d') . '.pdf';
            $pdf->Output($filename, 'D');
            exit;
            
        } catch (Exception $e) {
            wp_die(esc_html('Error generating PDF: ' . $e->getMessage()));
        }
    }

    /**
     * Handle adding conversation to knowledge base
     */
    public static function handle_add_conversation_to_kb_ajax() {
        if (!check_ajax_referer('aiohm_private_chat_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed.', 'aiohm-knowledge-assistant')]);
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'You do not have permission to use this feature.']);
        }
        
        try {
            $conversation_id = isset($_POST['conversation_id']) ? intval($_POST['conversation_id']) : 0;
            if (!$conversation_id) {
                wp_send_json_error(['message' => __('Invalid conversation ID.', 'aiohm-knowledge-assistant')]);
            }
            
            global $wpdb;
            $user_id = get_current_user_id();
            
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            // KB addition queries require direct database access with table name interpolation for user-owned conversation data
            
            // Get conversation details
            $conversation = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM " . $wpdb->prefix . "aiohm_conversations WHERE id = %d AND user_id = %d",
                $conversation_id, $user_id
            ));
            
            if (!$conversation) {
                wp_send_json_error(['message' => __('Conversation not found or access denied.', 'aiohm-knowledge-assistant')]);
            }
            
            // Get all messages for this conversation
            $messages = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM " . $wpdb->prefix . "aiohm_messages WHERE conversation_id = %d ORDER BY created_at ASC",
                $conversation_id
            ));
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            
            // Compile conversation content
            $content = "Conversation: " . $conversation->title . "\n";
            $content .= "Date: " . $conversation->created_at . "\n\n";
            
            foreach ($messages as $message) {
                $sender = ($message->sender === 'user') ? 'User' : 'Assistant';
                $content .= $sender . ": " . wp_strip_all_tags($message->content) . "\n\n";
            }
            
            // Add to knowledge base
            $rag_engine = new AIOHM_KB_RAG_Engine();
            $metadata = [
                'scope' => 'private',
                'source_type' => 'conversation',
                'source_id' => $conversation_id
            ];
            $result = $rag_engine->add_entry(
                $content,
                'conversation',
                'Conversation: ' . $conversation->title,
                $metadata,
                $user_id,
                0
            );
            
            if ($result) {
                wp_send_json_success(['message' => __('Conversation added to knowledge base successfully.', 'aiohm-knowledge-assistant')]);
            } else {
                wp_send_json_error(['message' => __('Failed to add conversation to knowledge base.', 'aiohm-knowledge-assistant')]);
            }
            
        } catch (Exception $e) {
            // translators: %s is the knowledge base addition error message
            wp_send_json_error(['message' => sprintf(__('Error adding to KB: %s', 'aiohm-knowledge-assistant'), $e->getMessage())]);
        }
    }
    
    /**
     * Handle research online functionality
     */
    public static function handle_research_online_ajax() {
        if (!check_ajax_referer('aiohm_private_chat_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed.', 'aiohm-knowledge-assistant')]);
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'You do not have permission to use this feature.']);
        }
        
        try {
            $url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
            $project_id = isset($_POST['project_id']) ? intval($_POST['project_id']) : 0;
            $conversation_id = isset($_POST['conversation_id']) ? intval($_POST['conversation_id']) : null;
            $research_prompt_base = isset($_POST['research_prompt']) ? sanitize_textarea_field(wp_unslash($_POST['research_prompt'])) : '';
            
            if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
                wp_send_json_error(['message' => __('Invalid URL provided.', 'aiohm-knowledge-assistant')]);
            }
            
            if (empty($project_id)) {
                wp_send_json_error(['message' => __('Project ID is required.', 'aiohm-knowledge-assistant')]);
            }
            
            // Fetch the webpage content
            $response = wp_remote_get($url, [
                'timeout' => 15,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (compatible; AIOHM-KB-Assistant/1.3.1; +https://knowledge.aiohm.app/)'
                ]
            ]);
            
            if (is_wp_error($response)) {
                // translators: %s is the webpage fetch error message
                wp_send_json_error(['message' => sprintf(__('Failed to fetch webpage: %s', 'aiohm-knowledge-assistant'), $response->get_error_message())]);
            }
            
            $content = wp_remote_retrieve_body($response);
            if (empty($content)) {
                wp_send_json_error(['message' => __('No content found at the provided URL.', 'aiohm-knowledge-assistant')]);
            }
            
            // Basic HTML content extraction
            $dom = new DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadHTML($content);
            libxml_clear_errors();
            
            // Remove script and style tags
            $scripts = $dom->getElementsByTagName('script');
            $styles = $dom->getElementsByTagName('style');
            
            for ($i = $scripts->length - 1; $i >= 0; $i--) {
                $scripts->item($i)->parentNode->removeChild($scripts->item($i));
            }
            
            for ($i = $styles->length - 1; $i >= 0; $i--) {
                $styles->item($i)->parentNode->removeChild($styles->item($i));
            }
            
            // Extract text content
            $textContent = wp_strip_all_tags($dom->textContent);
            $textContent = preg_replace('/\s+/', ' ', $textContent);
            $textContent = trim($textContent);
            
            // Limit content length
            if (strlen($textContent) > 5000) {
                $textContent = substr($textContent, 0, 5000) . '...';
            }
            
            if (empty($textContent)) {
                wp_send_json_error(['message' => __('No readable content found at the provided URL.', 'aiohm-knowledge-assistant')]);
            }
            
            // Get page title
            $titleNodes = $dom->getElementsByTagName('title');
            $pageTitle = $titleNodes->length > 0 ? $titleNodes->item(0)->textContent : 'Untitled Page';
            
            // Use AI to analyze the content
            $settings = AIOHM_KB_Assistant::get_settings();
            $ai_client = new AIOHM_KB_AI_GPT_Client($settings);
            
            // Use custom research prompt if provided, otherwise use default
            if (!empty($research_prompt_base)) {
                $research_prompt = $research_prompt_base . "\n\n" .
                                  "**Page Title:** {$pageTitle}\n" .
                                  "**URL:** {$url}\n\n" .
                                  "**Content:**\n{$textContent}";
            } else {
                // Fallback to AIOHM-branded default prompt
                $research_prompt = "As AIOHM's intelligent analysis system, conduct a comprehensive website evaluation that transforms raw content into strategic insights. Your analysis should reflect the precision and clarity that defines AIOHM's 'your brand voice... your AI' philosophy.\n\n" .
                                  "**WEBSITE ANALYSIS**\n" .
                                  "**Page Title:** {$pageTitle}\n" .
                                  "**URL:** {$url}\n\n" .
                                  "**Content:**\n{$textContent}\n\n" .
                                  "## EXECUTIVE SUMMARY\n" .
                                  "Provide a 2-3 sentence strategic overview of this website's purpose and value proposition.\n\n" .
                                  "## KEY INSIGHTS\n" .
                                  "• **Primary Focus**: What is this website's main purpose and target audience?\n" .
                                  "• **Value Proposition**: What unique benefits or solutions does it offer?\n" .
                                  "• **Key Stakeholders**: Important people, organizations, or entities mentioned\n" .
                                  "• **Content Quality**: Assessment of information depth and credibility\n\n" .
                                  "## STRATEGIC IMPLICATIONS\n" .
                                  "Analyze the broader significance of this content and its potential impact.\n\n" .
                                  "## ACTIONABLE RECOMMENDATIONS\n" .
                                  "Provide 3 specific, implementable insights based on this analysis.";
            }
            
            // Adjust system message based on whether custom prompt is used
            $system_message = !empty($research_prompt_base) 
                ? "You are an expert research analyst working with AIOHM Knowledge Assistant. Your brand voice is your AI - deliver insights that are thorough, actionable, and professionally structured. Follow the research perspective precisely while maintaining the high standards expected from AIOHM's intelligent analysis capabilities. Focus on delivering strategic value that empowers informed decision-making."
                : "You are AIOHM's web content analyst. Your brand voice is your AI - provide clear, structured analysis that transforms raw web content into actionable intelligence. Deliver insights with the precision and clarity that reflects AIOHM's commitment to intelligent knowledge transformation.";
            
            $analysis = $ai_client->get_chat_completion(
                $system_message,
                $research_prompt,
                0.3,
                $settings['muse_mode']['ai_model'] ?? 'gpt-3.5-turbo'
            );
            
            // Save to conversation if conversation_id exists
            if ($conversation_id) {
                global $wpdb;
                $user_id = get_current_user_id();
                
                // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
                // Direct insert required for message storage - AI analysis results must be persisted immediately
                $wpdb->insert(
                    $wpdb->prefix . 'aiohm_messages',
                    [
                        'conversation_id' => $conversation_id,
                        'user_id' => $user_id,
                        'sender' => 'assistant',
                        'content' => $analysis,
                        'created_at' => current_time('mysql')
                    ]
                );
                // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
            }
            
            wp_send_json_success([
                'reply' => $analysis,
                'conversation_id' => $conversation_id,
                'url' => $url,
                'title' => $pageTitle
            ]);
            
        } catch (Exception $e) {
            // translators: %s is the website research error message
            wp_send_json_error(['message' => sprintf(__('Error researching website: %s', 'aiohm-knowledge-assistant'), $e->getMessage())]);
        }
    }
    
    /**
     * Helper function to render images in WordPress-compliant way
     * Uses WordPress image functions when possible
     * 
     * @param string $url Image URL
     * @param string $alt Alt text
     * @param array $attributes Additional HTML attributes
     * @return string HTML img tag
     */
    public static function render_image($url, $alt = '', $attributes = []) {
        // Check if this is a WordPress attachment
        $attachment_id = attachment_url_to_postid($url);
        
        if ($attachment_id) {
            // Use WordPress function for attachments
            $image_attributes = array_merge(['alt' => $alt], $attributes);
            return wp_get_attachment_image($attachment_id, 'full', false, $image_attributes);
        }
        
        // For external URLs, build the tag manually with proper escaping
        $url = esc_url($url);
        $alt = esc_attr($alt);
        
        $attr_string = '';
        foreach ($attributes as $key => $value) {
            $attr_string .= ' ' . esc_attr($key) . '="' . esc_attr($value) . '"';
        }
        
        // Use wp_kses_post to ensure the HTML is safe
        // phpcs:ignore PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage -- External images cannot use wp_get_attachment_image()
        return wp_kses_post('<img src="' . $url . '" alt="' . $alt . '"' . $attr_string . ' />');
    }
    
    /**
     * Validate uploaded file for security
     * @param array $file File data from $_FILES
     * @return bool|WP_Error True if valid, WP_Error if invalid
     */
    private static function validate_uploaded_file($file) {
        // Check file size limits (10MB max)
        $max_size = 10 * 1024 * 1024; // 10MB
        if ($file['size'] > $max_size) {
            return new WP_Error('file_too_large', __('File size exceeds 10MB limit.', 'aiohm-knowledge-assistant'));
        }
        
        // Validate filename - allow WordPress to sanitize it
        $filename = sanitize_file_name($file['name']);
        if (empty($filename)) {
            return new WP_Error('invalid_filename', __('Invalid filename.', 'aiohm-knowledge-assistant'));
        }
        // Update the file array to use the sanitized filename
        $file['name'] = $filename;
        
        // Validate file extension
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $allowed_extensions = ['json', 'txt', 'csv', 'pdf', 'doc', 'docx', 'md', 'html', 'htm', 'xlsx', 'xls'];
        
        if (!in_array($ext, $allowed_extensions)) {
            return new WP_Error('invalid_extension', 
                sprintf(
                    /* translators: %s: comma-separated list of allowed file extensions */
                    __('File type not supported. Allowed: %s', 'aiohm-knowledge-assistant'), 
                    implode(', ', $allowed_extensions)
                )
            );
        }
        
        // MIME type validation
        $allowed_mime_types = [
            'json' => ['application/json', 'text/plain'],
            'txt' => ['text/plain'],
            'csv' => ['text/csv', 'text/plain'],
            'pdf' => ['application/pdf'],
            'doc' => ['application/msword'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'md' => ['text/markdown', 'text/plain'],
            'html' => ['text/html', 'text/plain'],
            'htm' => ['text/html', 'text/plain'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'xls' => ['application/vnd.ms-excel']
        ];
        
        $detected_type = mime_content_type($file['tmp_name']);
        if (!isset($allowed_mime_types[$ext]) || !in_array($detected_type, $allowed_mime_types[$ext])) {
            return new WP_Error('mime_type_mismatch', 
                sprintf(
                    /* translators: %1$s: detected MIME type, %2$s: file extension */
                    __('File MIME type (%1$s) does not match extension (%2$s).', 'aiohm-knowledge-assistant'), 
                    $detected_type, 
                    $ext
                )
            );
        }
        
        // Content validation for text files
        if (in_array($ext, ['json', 'txt', 'csv', 'md'])) {
            $content = file_get_contents($file['tmp_name']);
            if ($content === false) {
                return new WP_Error('unreadable_file', __('File content could not be read.', 'aiohm-knowledge-assistant'));
            }
            
            // Check for potentially malicious content
            if (preg_match('/<script|<iframe|javascript:|vbscript:|onload=|onerror=/i', $content)) {
                return new WP_Error('malicious_content', __('File contains potentially malicious content.', 'aiohm-knowledge-assistant'));
            }
            
            // For JSON files, validate JSON structure
            if ($ext === 'json') {
                json_decode($content);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return new WP_Error('invalid_json', __('Invalid JSON format.', 'aiohm-knowledge-assistant'));
                }
            }
        }
        
        return true;
    }

    /**
     * Handle file upload to knowledge base AJAX request
     */
    public static function handle_kb_file_upload_ajax() {
        
        // Enhanced rate limiting for file uploads
        if (!AIOHM_KB_Assistant::check_enhanced_rate_limit('file_upload', get_current_user_id(), 20)) {
            wp_send_json_error(['message' => __('Upload rate limit exceeded. Please wait before uploading more files.', 'aiohm-knowledge-assistant')]);
        }

        // Verify nonce
        check_ajax_referer('aiohm_admin_nonce', 'nonce');

        // Check user permissions
        $can_manage = current_user_can('manage_options');
        if (!$can_manage) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'aiohm-knowledge-assistant')]);
        }

        // Allow JSON, MD, HTML uploads during our upload process
        add_filter('upload_mimes', function($mimes) {
            $mimes['json'] = 'application/json';
            $mimes['md'] = 'text/markdown';
            $mimes['html'] = 'text/html';
            $mimes['htm'] = 'text/html';
            return $mimes;
        }, 999);
        
        // Override WordPress security restrictions for JSON, MD, and HTML files
        add_filter('wp_check_filetype_and_ext', function($data, $file, $filename, $mimes) {
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if ($ext === 'json') {
                $data['ext'] = 'json';
                $data['type'] = 'application/json';
                $data['proper_filename'] = $filename;
            } elseif ($ext === 'md') {
                $data['ext'] = 'md';
                $data['type'] = 'text/markdown';
                $data['proper_filename'] = $filename;
            } elseif ($ext === 'html') {
                $data['ext'] = 'html';
                $data['type'] = 'text/html';
                $data['proper_filename'] = $filename;
            } elseif ($ext === 'htm') {
                $data['ext'] = 'htm';
                $data['type'] = 'text/html';
                $data['proper_filename'] = $filename;
            }
            return $data;
        }, 999, 4);

        // Check if files were uploaded
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_FILES superglobal is validated and sanitized below
        if (empty($_FILES['files'])) {
            wp_send_json_error(['message' => __('No files were uploaded.', 'aiohm-knowledge-assistant')]);
        }

        $scope = sanitize_text_field(wp_unslash($_POST['scope'] ?? 'public'));
        if (!in_array($scope, ['public', 'private'])) {
            $scope = 'public';
        }

        // Validate and sanitize $_FILES data
        $files = [];
        if (isset($_FILES['files']) && is_array($_FILES['files'])) {
            $required_keys = ['name', 'type', 'tmp_name', 'error', 'size'];
            $files_valid = true;
            foreach ($required_keys as $key) {
                if (!isset($_FILES['files'][$key])) {
                    $files_valid = false;
                    break;
                }
            }
            if ($files_valid) {
                // Sanitize $_FILES data
                $files = [
                    'name' => isset($_FILES['files']['name']) ? array_map('sanitize_file_name', (array) $_FILES['files']['name']) : [],
                    'type' => isset($_FILES['files']['type']) ? array_map('sanitize_mime_type', (array) $_FILES['files']['type']) : [],
                    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tmp_name is system-generated and validated by WordPress core
                    'tmp_name' => isset($_FILES['files']['tmp_name']) ? (array) $_FILES['files']['tmp_name'] : [], // tmp_name doesn't need sanitization
                    'error' => isset($_FILES['files']['error']) ? array_map('intval', (array) $_FILES['files']['error']) : [],
                    'size' => isset($_FILES['files']['size']) ? array_map('intval', (array) $_FILES['files']['size']) : []
                ];
            } else {
                wp_send_json_error(['message' => __('Invalid file upload data.', 'aiohm-knowledge-assistant')]);
            }
        }
        $results = [];
        $uploads_crawler = new AIOHM_KB_Uploads_Crawler();
        $rag_engine = new AIOHM_KB_RAG_Engine();
        

        // Handle multiple files
        $file_count = is_array($files['name']) ? count($files['name']) : 1;
        
        for ($i = 0; $i < $file_count; $i++) {
            $file = [
                'name' => sanitize_file_name(is_array($files['name']) ? $files['name'][$i] : $files['name']),
                'type' => sanitize_mime_type(is_array($files['type']) ? $files['type'][$i] : $files['type']),
                'tmp_name' => is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'],
                'error' => intval(is_array($files['error']) ? $files['error'][$i] : $files['error']),
                'size' => intval(is_array($files['size']) ? $files['size'][$i] : $files['size'])
            ];
            

            if ($file['error'] !== UPLOAD_ERR_OK) {
                $results[] = [
                    'filename' => $file['name'],
                    'success' => false,
                    'message' => 'Upload error: ' . $file['error']
                ];
                continue;
            }

            // Enhanced file validation
            $validation_result = self::validate_uploaded_file($file);
            if (is_wp_error($validation_result)) {
                $results[] = [
                    'filename' => sanitize_text_field($file['name']),
                    'success' => false,
                    'message' => $validation_result->get_error_message()
                ];
                continue;
            }
            
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            try {
                // First, create a WordPress attachment for permanent storage
                
                // Include required WordPress files for file upload
                if (!function_exists('wp_handle_upload')) {
                    require_once(ABSPATH . 'wp-admin/includes/file.php');
                }
                if (!function_exists('wp_generate_attachment_metadata')) {
                    require_once(ABSPATH . 'wp-admin/includes/image.php');
                }
                
                // Handle file upload through WordPress
                $upload_overrides = array(
                    'test_form' => false,
                    'test_size' => true,
                    'test_upload' => true,
                );
                
                // Move uploaded file to WordPress uploads directory
                $uploaded_file = wp_handle_upload($file, $upload_overrides);
                
                if (!empty($uploaded_file['error'])) {
                    throw new Exception('File upload failed: ' . $uploaded_file['error']);
                }
                
                // Create attachment post
                $attachment = array(
                    'post_mime_type' => $uploaded_file['type'],
                    'post_title' => sanitize_file_name($file['name']),
                    'post_content' => '',
                    'post_status' => 'inherit'
                );
                
                $attachment_id = wp_insert_attachment($attachment, $uploaded_file['file']);
                
                if (is_wp_error($attachment_id)) {
                    throw new Exception('Failed to create attachment: ' . $attachment_id->get_error_message());
                }
                
                // Generate metadata for the attachment
                $attachment_metadata = wp_generate_attachment_metadata($attachment_id, $uploaded_file['file']);
                wp_update_attachment_metadata($attachment_id, $attachment_metadata);
                
                
                // Process the file content
                
                // Process file content using standard method for now
                $file_data = self::process_uploaded_file($uploaded_file['file'], $file['name'], $ext);
                
                
                if (!$file_data || empty(trim($file_data['content']))) {
                    throw new Exception('File content could not be extracted or is empty.');
                }

                // Add to knowledge base with attachment metadata
                $metadata = array_merge($file_data['metadata'], [
                    'scope' => $scope,
                    'attachment_id' => $attachment_id,
                    'file_path' => $uploaded_file['file'],
                    'file_url' => $uploaded_file['url']
                ]);
                // Set user_id and is_public based on scope
                $user_id = ($scope === 'public') ? 0 : get_current_user_id();
                $is_public = ($scope === 'public') ? 1 : 0;
                $result = $rag_engine->add_content_entry(
                    $file_data['content'],
                    $file_data['type'],
                    $file_data['title'],
                    $metadata,
                    $user_id,
                    $is_public
                );

                if (is_wp_error($result)) {
                    throw new Exception(sprintf(
                        /* translators: %s: error message */
                        __('Failed to add to knowledge base: %s', 'aiohm-knowledge-assistant'),
                        esc_html($result->get_error_message())
                    ));
                }
                
                if (!$result) {
                    throw new Exception('Knowledge base operation failed.');
                }

                // Clear cache after successful addition
                if (class_exists('AIOHM_KB_Manager')) {
                    $kb_manager = new AIOHM_KB_Manager();
                    $kb_manager->clear_kb_manager_cache();
                } else {
                    wp_cache_flush_group('aiohm_kb_manager');
                }
                wp_cache_flush_group('aiohm_kb');
                
                $result_data = [
                    'filename' => $file['name'],
                    'success' => true,
                    'message' => 'Successfully added to knowledge base'
                ];
                
                // Include processing details for enhanced feedback
                if (isset($file_data['processing_details'])) {
                    $result_data['processing_details'] = $file_data['processing_details'];
                }
                
                $results[] = $result_data;

            } catch (Exception $e) {
                $results[] = [
                    'filename' => $file['name'],
                    'success' => false,
                    'message' => $e->getMessage()
                ];
            }
        }

        $success_count = count(array_filter($results, function($r) { return $r['success']; }));
        $total_count = count($results);

        wp_send_json_success([
            'message' => "Processed $total_count files. $success_count successful.",
            'results' => $results,
            'success_count' => $success_count,
            'total_count' => $total_count
        ]);
    }

    /**
     * Process uploaded file content with security validation
     */
    private static function process_uploaded_file($temp_path, $filename, $ext) {
        if (!file_exists($temp_path) || !is_readable($temp_path)) {
            return null;
        }

        // Security: Check file size limit (5MB max)
        $max_file_size = 5 * 1024 * 1024; // 5MB
        if (filesize($temp_path) > $max_file_size) {
            throw new Exception('File too large. Maximum size is 5MB.');
        }

        $content = '';
        $title = $filename;
        $parser_metadata = [];
        $mime_type = wp_check_filetype($filename)['type'];

        // Try to use new parser architecture first
        try {
            if (class_exists('AIOHM_Parser_Factory')) {
                $parsed_data = AIOHM_Parser_Factory::parse_file($temp_path, $ext);
                if ($parsed_data && !empty($parsed_data['content'])) {
                    $content = $parsed_data['content'];
                    $title = $parsed_data['title'] ?? $filename;
                    $parser_metadata = $parsed_data['metadata'] ?? [];
                } else {
                    throw new Exception("New parser returned empty content");
                }
            } else {
                throw new Exception("Parser factory not available");
            }
        } catch (Exception $e) {
            
            // Fallback to legacy parsing methods
            switch ($ext) {
                case 'json':
                    $content = self::validate_and_process_json($temp_path);
                    break;
                case 'txt':
                case 'md':
                    $content = self::validate_and_process_text($temp_path);
                    break;
                case 'csv':
                    $content = self::validate_and_process_csv($temp_path);
                    break;

                case 'pdf':
                    try {
                        // Use the clean PDF parser
                        if (class_exists('AIOHM_Clean_PDF_Parser')) {
                            $parser = new AIOHM_Clean_PDF_Parser();
                            $result = $parser->parse($temp_path);
                            $content = $result['content'] ?? '';
                            
                            // Clean up extracted text
                            $content = trim($content);
                            if (empty($content)) {
                                throw new Exception('No readable text found in PDF.');
                            }
                        } else {
                            throw new Exception('PDF parser not available.');
                        }
                    } catch (Exception $e) {
                        $content = "PDF file: $filename (content extraction failed: " . $e->getMessage() . ")";
                    }
                    break;

                case 'docx':
                    try {
                        $zip = new ZipArchive();
                        if ($zip->open($temp_path) === TRUE) {
                            $content = $zip->getFromName('word/document.xml');
                            if ($content !== false) {
                                $content = wp_strip_all_tags($content);
                                $content = html_entity_decode($content, ENT_QUOTES, 'UTF-8');
                            }
                            $zip->close();
                        }
                        if (empty(trim($content))) {
                            $content = "Word document: $filename (content extraction failed)";
                        }
                    } catch (Exception $e) {
                        $content = "Word document: $filename (content extraction failed)";
                    }
                    break;

                case 'doc':
                    // DOC files require more complex parsing, fall back to filename
                    $content = "Word document: $filename (content extraction not supported for .doc files)";
                    break;

                case 'html':
                case 'htm':
                    // Try enhanced HTML extraction first
                    $html_result = self::extract_html_content($temp_path, $filename);
                    if (is_array($html_result)) {
                        $content = $html_result['content'];
                        $title = $html_result['title'];
                    } else {
                        $content = $html_result; // Fallback for error messages
                    }
                    break;

                case 'xlsx':
                case 'xls':
                    // Basic spreadsheet fallback - return descriptive content
                    $content = "Spreadsheet: $filename. This file contains tabular data. For best results, consider converting to CSV format.";
                    break;

                default:
                    return null;
            }
        }

        if (empty(trim($content))) {
            return null;
        }

        // Merge parser metadata with base metadata
        $base_metadata = [
            'size' => filesize($temp_path),
            'original_filename' => $filename,
            'file_type' => $ext,
            'mime_type' => $mime_type,
            'upload_method' => 'direct_upload'
        ];
        
        $metadata = array_merge($base_metadata, $parser_metadata);

        return [
            'content' => $content,
            'type' => strtoupper($ext), // Use uppercase extension like other crawlers
            'title' => $title,
            'metadata' => $metadata
        ];
    }

    /**
     * Allow JSON file uploads for knowledge base functionality
     */
    public static function allow_json_uploads($mimes) {
        // Only allow JSON, MD, HTML uploads for administrators and in admin context
        if (is_admin() && current_user_can('manage_options')) {
            $mimes['json'] = 'application/json';
            $mimes['md'] = 'text/markdown';
            $mimes['html'] = 'text/html';
            $mimes['htm'] = 'text/html';
        }
        return $mimes;
    }

    /**
     * Allow JSON, MD, and HTML file uploads by bypassing WordPress file type restrictions
     */
    public static function allow_json_file_upload($data, $file, $filename, $mimes) {
        // Only allow for administrators in admin context
        $ext = is_admin() && current_user_can('manage_options') ? strtolower(pathinfo($filename, PATHINFO_EXTENSION)) : '';
        
        if ($ext === 'json') {
            // Validate JSON file content
            if (isset($file) && is_readable($file)) {
                // Initialize WP_Filesystem if needed
                global $wp_filesystem;
                if (empty($wp_filesystem)) {
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                    WP_Filesystem();
                }
                $content = $wp_filesystem->get_contents($file);
                if ($content !== false) {
                    $decoded = json_decode($content);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        // Invalid JSON, reject the file
                        return $data;
                    }
                }
            }
            $data['ext'] = 'json';
            $data['type'] = 'application/json';
        } elseif ($ext === 'md') {
            $data['ext'] = 'md';
            $data['type'] = 'text/markdown';
        } elseif ($ext === 'html') {
            $data['ext'] = 'html';
            $data['type'] = 'text/html';
        } elseif ($ext === 'htm') {
            $data['ext'] = 'htm';
            $data['type'] = 'text/html';
        }
        
        return $data;
    }

    /**
     * Clear all caches related to core functionality
     */
    public static function clear_core_caches($user_id = null) {
        if ($user_id) {
            // Clear user-specific caches
            wp_cache_delete('aiohm_user_projects_' . $user_id, 'aiohm_core');
            wp_cache_delete('aiohm_user_conversations_' . $user_id, 'aiohm_core');
        } else {
            // Clear all core caches
            wp_cache_flush_group('aiohm_core');
        }
    }

    /**
     * Securely validate and process JSON files
     * @param string $file_path Path to JSON file
     * @return string Sanitized content
     * @throws Exception If validation fails
     */
    private static function validate_and_process_json($file_path) {
        global $wp_filesystem;
        
        // Initialize WP_Filesystem
        if (empty($wp_filesystem)) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        
        $content = $wp_filesystem->get_contents($file_path);
        if (false === $content) {
            throw new Exception('Unable to read JSON file');
        }
        
        // Clean up control characters and other problematic characters
        // Remove null bytes and control characters except newlines and tabs
        $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F]/', '', $content);
        // Fix common encoding issues
        $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
        // Remove BOM if present
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        // Try to fix common JSON issues
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $content = preg_replace('/[\x{FEFF}-\x{FFFF}]/u', '', $content);
        
        // Validate JSON structure
        $decoded = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // If JSON parsing fails, treat it as raw text content but still mark as JSON type
            // This allows users to upload malformed JSON files that can still be viewed/edited
            $result = sanitize_textarea_field($content);
            if (empty($result)) {
                throw new Exception('File content is empty after sanitization');
            }
            return $result;
        }
        
        // Recursively sanitize all string values
        $sanitized = self::sanitize_json_recursive($decoded);
        
        // Return as pretty-printed JSON
        $result = wp_json_encode($sanitized, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        if (empty($result)) {
            $result = sanitize_textarea_field($content);
            if (empty($result)) {
                throw new Exception('File content is empty after sanitization');
            }
            return $result;
        }
        
        return $result;
    }

    /**
     * Securely validate and process text files
     * @param string $file_path Path to text file
     * @return string Sanitized content
     * @throws Exception If validation fails
     */
    private static function validate_and_process_text($file_path) {
        global $wp_filesystem;
        
        // Initialize WP_Filesystem
        if (empty($wp_filesystem)) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        
        $content = $wp_filesystem->get_contents($file_path);
        if (false === $content) {
            throw new Exception('Unable to read text file');
        }
        
        // Check for binary content (potential malicious files)
        if (!mb_check_encoding($content, 'UTF-8')) {
            throw new Exception('File contains invalid UTF-8 content');
        }
        
        // Remove potential script tags and sanitize
        $content = wp_kses_post($content);
        
        // Additional security: remove any remaining script-like patterns
        $content = preg_replace('/<\s*script[^>]*>.*?<\s*\/\s*script\s*>/is', '', $content);
        $content = preg_replace('/javascript\s*:/i', '', $content);
        $content = preg_replace('/on\w+\s*=/i', '', $content);
        
        return trim($content);
    }

    /**
     * Securely validate and process CSV files
     * @param string $file_path Path to CSV file
     * @return string Sanitized content
     * @throws Exception If validation fails
     */
    private static function validate_and_process_csv($file_path) {
        global $wp_filesystem;
        
        // Initialize WP_Filesystem
        if (empty($wp_filesystem)) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        
        // Read file using WP_Filesystem
        $content = $wp_filesystem->get_contents($file_path);
        if (false === $content) {
            throw new Exception('Unable to read CSV file');
        }
        
        // Check for binary content
        if (!mb_check_encoding($content, 'UTF-8')) {
            throw new Exception('CSV file contains invalid UTF-8 content');
        }
        
        // Parse CSV and sanitize each cell
        $lines = str_getcsv($content, "\n");
        $sanitized_lines = [];
        
        foreach ($lines as $line) {
            if (empty(trim($line))) continue;
            
            $fields = str_getcsv($line);
            $sanitized_fields = array_map('sanitize_text_field', $fields);
            $sanitized_lines[] = $sanitized_fields;
        }
        
        // Convert back to CSV format using string concatenation instead of file operations
        $csv_output = '';
        foreach ($sanitized_lines as $line) {
            $csv_output .= '"' . implode('","', array_map('str_replace', array_fill(0, count($line), '"'), array_fill(0, count($line), '""'), $line)) . '"' . "\n";
        }
        
        return $csv_output;
    }

    /**
     * Recursively sanitize JSON data
     * @param mixed $data Data to sanitize
     * @return mixed Sanitized data
     */
    private static function sanitize_json_recursive($data) {
        if (is_array($data)) {
            return array_map([self::class, 'sanitize_json_recursive'], $data);
        } elseif (is_object($data)) {
            $sanitized = new stdClass();
            foreach ($data as $key => $value) {
                $sanitized->{sanitize_key($key)} = self::sanitize_json_recursive($value);
            }
            return $sanitized;
        } elseif (is_string($data)) {
            // Remove script tags and potential XSS vectors
            $data = wp_kses_post($data);
            $data = preg_replace('/<\s*script[^>]*>.*?<\s*\/\s*script\s*>/is', '', $data);
            return sanitize_text_field($data);
        }
        
        return $data;
    }

    /**
     * Add security headers for admin pages
     */
    public static function add_admin_security_headers() {
        $settings = AIOHM_KB_Assistant::get_settings();
        if (empty($settings['enable_csp'])) {
            return;
        }

        if (is_admin() && !headers_sent()) {
            $csp_string = self::build_csp_string(true);
            header("Content-Security-Policy: " . $csp_string);
            // Prevent MIME type sniffing
            header('X-Content-Type-Options: nosniff');
            
            // Prevent clickjacking
            header('X-Frame-Options: SAMEORIGIN');
            
            // Enable XSS filtering
            header('X-XSS-Protection: 1; mode=block');
            
            // Referrer policy
            header('Referrer-Policy: strict-origin-when-cross-origin');
            
            // Permissions policy (formerly Feature Policy)
            header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        }
    }

    /**
     * Add security headers for frontend pages
     */
    public static function add_frontend_security_headers() {
        $settings = AIOHM_KB_Assistant::get_settings();
        if (empty($settings['enable_csp'])) {
            return;
        }

        if (!is_admin() && !headers_sent()) {
            $csp_string = self::build_csp_string(false);
            header("Content-Security-Policy: " . $csp_string);
            // Prevent MIME type sniffing
            header('X-Content-Type-Options: nosniff');
            
            // Prevent clickjacking
            header('X-Frame-Options: SAMEORIGIN');
            
            // Enable XSS filtering
            header('X-XSS-Protection: 1; mode=block');
            
            // Referrer policy
            header('Referrer-Policy: strict-origin-when-cross-origin');
            
            // HSTS (if HTTPS is detected)
            if (is_ssl()) {
                header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
            }
        }
    }

    /**
     * Secure user session on login to prevent session fixation
     * @param string $user_login Username
     * @param WP_User $user User object
     */
    public static function secure_user_session($user_login, $user) {
        // WordPress handles session security natively through auth cookies
        // No need for manual session regeneration as WP manages this
        
        // Set secure session cookie if over HTTPS
        if (is_ssl()) {
            wp_set_auth_cookie($user->ID, false, true);
        }
        
        // Log successful login for security monitoring
    }

    /**
     * Secure session for new user registration
     * @param int $user_id User ID
     */
    public static function secure_new_user_session($user_id) {
        // Set secure session for new users
        if (is_ssl()) {
            wp_set_auth_cookie($user_id, false, true);
        }
        
        // Log new user registration
        $user = get_user_by('id', $user_id);
        if ($user) {
        }
    }

    /**
     * Enhanced authentication checks for sensitive operations
     * @param string $operation Operation being performed
     * @return bool True if authorized, false otherwise
     */
    private static function verify_enhanced_auth($operation = 'general') {
        // Check if user is logged in
        if (!is_user_logged_in()) {
            return false;
        }
        
        $user = wp_get_current_user();
        $user_id = $user->ID;
        
        // Check for recent authentication for sensitive operations
        $recent_auth_operations = ['delete_kb', 'export_kb', 'reset_kb', 'api_key_change'];
        
        if (in_array($operation, $recent_auth_operations)) {
            $last_auth = get_user_meta($user_id, '_aiohm_last_auth_verify', true);
            $auth_timeout = 300; // 5 minutes
            
            if (empty($last_auth) || (time() - $last_auth) > $auth_timeout) {
                // Require fresh authentication for sensitive operations
                return false;
            }
        }
        
        // Update last activity timestamp
        update_user_meta($user_id, '_aiohm_last_activity', time());
        
        return true;
    }

    /**
     * Mark user as recently authenticated for sensitive operations
     * @param int $user_id User ID
     */
    public static function mark_recent_auth($user_id = null) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        
        if ($user_id) {
            update_user_meta($user_id, '_aiohm_last_auth_verify', time());
        }
    }
    
    /**
     * Validate file content to prevent malicious uploads
     * @param string $file_path Path to the uploaded file
     * @param string $extension File extension
     * @param string $mime_type MIME type
     * @return bool True if file is valid, false otherwise
     */
    private static function validate_file_content($file_path, $extension, $mime_type) {
        // File must exist and be readable
        if (!is_readable($file_path)) {
            return false;
        }
        
        // Get file contents for validation (first 1KB only for performance)
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once ABSPATH . '/wp-admin/includes/file.php';
            WP_Filesystem();
        }
        
        $content = $wp_filesystem->get_contents($file_path);
        if ($content === false) {
            return false;
        }
        
        // Limit to first 1KB for performance
        $content = substr($content, 0, 1024);
        
        // Validate based on file type
        switch ($extension) {
            case 'txt':
                // Text files should not contain PHP tags or suspicious patterns
                if (strpos($content, '<?php') !== false || 
                    strpos($content, '<%') !== false ||
                    strpos($content, '<script') !== false) {
                    return false;
                }
                break;
                
            case 'json':
                // Validate JSON structure and content
                $decoded = json_decode($content, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return false;
                }
                // Check for suspicious content in JSON
                $json_string = json_encode($decoded);
                if (strpos($json_string, '<?php') !== false || 
                    strpos($json_string, '<script') !== false ||
                    strpos($json_string, 'eval(') !== false) {
                    return false;
                }
                break;
                
            case 'csv':
                // CSV files should not contain PHP or script tags
                if (strpos($content, '<?php') !== false || 
                    strpos($content, '<script') !== false ||
                    strpos($content, '<%') !== false) {
                    return false;
                }
                break;
                
            case 'pdf':
                // PDF files should start with PDF signature
                if (strpos($content, '%PDF-') !== 0) {
                    return false;
                }
                break;
                
            default:
                // Unknown file type - reject
                return false;
        }
        
        // Check file size is reasonable (additional safety check)
        $file_size = filesize($file_path);
        $max_sizes = [
            'txt' => 10 * 1024 * 1024,  // 10MB for text
            'csv' => 50 * 1024 * 1024,  // 50MB for CSV
            'json' => 10 * 1024 * 1024, // 10MB for JSON
            'pdf' => 100 * 1024 * 1024  // 100MB for PDF
        ];
        
        if ($file_size > ($max_sizes[$extension] ?? 10 * 1024 * 1024)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Helper function to verify nonce and permissions
     * @param string $nonce_action The nonce action to verify
     * @param string $capability Required capability (default: 'manage_options')
     */
    private static function verify_nonce_and_permission($nonce_action, $capability = 'manage_options') {
        if (!check_ajax_referer($nonce_action, 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed.', 'aiohm-knowledge-assistant')]);
        }
        
        if (!current_user_can($capability)) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'aiohm-knowledge-assistant')]);
        }
    }

    /**
     * Comprehensive input validation and sanitization function
     * @param mixed $input The input to validate and sanitize
     * @param string $type The type of validation to apply
     * @param array $options Additional validation options
     * @return mixed|WP_Error Sanitized input or WP_Error on validation failure
     */
    private static function validate_and_sanitize_input($input, $type = 'text', $options = []) {
        // Check for required fields
        if (!empty($options['required']) && (empty($input) || trim($input) === '')) {
            return new WP_Error('required_field', __('This field is required.', 'aiohm-knowledge-assistant'));
        }
        
        switch ($type) {
            case 'email':
                if (!is_email($input)) {
                    return new WP_Error('invalid_email', __('Invalid email address.', 'aiohm-knowledge-assistant'));
                }
                return sanitize_email($input);
                
            case 'url':
                if (!filter_var($input, FILTER_VALIDATE_URL)) {
                    return new WP_Error('invalid_url', __('Invalid URL format.', 'aiohm-knowledge-assistant'));
                }
                return esc_url_raw($input);
                
            case 'html':
                return wp_kses_post($input);
                
            case 'int':
                if (!is_numeric($input)) {
                    return new WP_Error('invalid_number', __('Invalid number format.', 'aiohm-knowledge-assistant'));
                }
                $value = intval($input);
                if (isset($options['min']) && $value < $options['min']) {
                    /* translators: %d: minimum number value */
                    return new WP_Error('number_too_small', sprintf(__('Number must be at least %d.', 'aiohm-knowledge-assistant'), $options['min']));
                }
                if (isset($options['max']) && $value > $options['max']) {
                    /* translators: %d: maximum number value */
                    return new WP_Error('number_too_large', sprintf(__('Number must be at most %d.', 'aiohm-knowledge-assistant'), $options['max']));
                }
                return $value;
                
            case 'float':
                if (!is_numeric($input)) {
                    return new WP_Error('invalid_number', __('Invalid number format.', 'aiohm-knowledge-assistant'));
                }
                return floatval($input);
                
            case 'array':
                if (!is_array($input)) {
                    return new WP_Error('invalid_array', __('Expected array input.', 'aiohm-knowledge-assistant'));
                }
                return array_map('sanitize_text_field', $input);
                
            case 'json':
                $decoded = json_decode($input, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return new WP_Error('invalid_json', __('Invalid JSON format.', 'aiohm-knowledge-assistant'));
                }
                return $decoded;
                
            case 'textarea':
                $sanitized = sanitize_textarea_field($input);
                if (isset($options['max_length']) && strlen($sanitized) > $options['max_length']) {
                    /* translators: %d: maximum character length */
                    return new WP_Error('text_too_long', sprintf(__('Text must be no more than %d characters.', 'aiohm-knowledge-assistant'), $options['max_length']));
                }
                return $sanitized;
                
            case 'slug':
                return sanitize_title($input);
                
            case 'key':
                return sanitize_key($input);
                
            default:
                $sanitized = sanitize_text_field($input);
                if (isset($options['max_length']) && strlen($sanitized) > $options['max_length']) {
                    /* translators: %d: maximum character length */
                    return new WP_Error('text_too_long', sprintf(__('Text must be no more than %d characters.', 'aiohm-knowledge-assistant'), $options['max_length']));
                }
                return $sanitized;
        }
    }


    /**
     * Handle getting debug information for support
     */
    public static function handle_get_debug_info_ajax() {
        check_ajax_referer('aiohm_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'aiohm-knowledge-assistant')]);
        }
        
        try {
            global $wpdb;
            $settings = AIOHM_KB_Assistant::get_settings();
            
            // Collect plugin settings (sanitized)
            $sanitized_settings = [];
            foreach ($settings as $key => $value) {
                if (strpos($key, 'key') !== false || strpos($key, 'token') !== false) {
                    $sanitized_settings[$key] = !empty($value) ? '[CONFIGURED]' : '[NOT SET]';
                } else {
                    $sanitized_settings[$key] = $value;
                }
            }
            
            // Check database tables
            $tables = [
                'aiohm_vector_entries',
                'aiohm_conversations',
                'aiohm_messages',
                'aiohm_projects'
            ];
            
            $database_info = [];
            foreach ($tables as $table) {
                $full_table_name = $wpdb->prefix . $table;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Debug check for table existence
                $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $full_table_name));
                $rows = 0;
                
                if ($exists) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Debug query for row count, table name uses WordPress prefix
                    $rows = $wpdb->get_var("SELECT COUNT(*) FROM `$full_table_name`");
                }
                
                $database_info[$table] = [
                    'exists' => !empty($exists),
                    'rows' => (int) $rows
                ];
            }
            
            // Error logging disabled in production version
            $recent_errors = [];
            
            wp_send_json_success([
                'settings' => $sanitized_settings,
                'database' => $database_info,
                'errors' => $recent_errors
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(['message' => __('Failed to collect debug information', 'aiohm-knowledge-assistant')]);
        }
    }

    /**
     * Handle testing all API connections
     */
    public static function handle_test_all_api_connections_ajax() {
        check_ajax_referer('aiohm_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'aiohm-knowledge-assistant')]);
        }
        
        try {
            $settings = AIOHM_KB_Assistant::get_settings();
            $results = [];
            
            // Test each configured API
            $apis_to_test = [
                'openai' => $settings['openai_api_key'] ?? '',
                'gemini' => $settings['gemini_api_key'] ?? '',
                'claude' => $settings['claude_api_key'] ?? '',
                'shareai' => $settings['shareai_api_key'] ?? ''
            ];
            
            foreach ($apis_to_test as $provider => $key_or_url) {
                if (empty($key_or_url)) {
                    $results[$provider] = [
                        'status' => 'not_configured',
                        'message' => __('Not configured', 'aiohm-knowledge-assistant')
                    ];
                    continue;
                }
                
                // Use existing API testing methods with full settings configuration
                try {
                    $test_result = [];
                    
                    // Create AI client with full settings to ensure proper initialization
                    $ai_client = new AIOHM_KB_AI_GPT_Client($settings);
                    
                    switch ($provider) {
                        case 'openai':
                            $test_result = $ai_client->test_api_connection();
                            break;
                        case 'gemini':
                            $test_result = $ai_client->test_gemini_api_connection();
                            break;
                        case 'claude':
                            $test_result = $ai_client->test_claude_api_connection();
                            break;
                        case 'shareai':
                            $test_result = $ai_client->test_shareai_api_connection();
                            break;
                    }
                    
                    if (!empty($test_result) && is_array($test_result) && $test_result['success']) {
                        $results[$provider] = [
                            'status' => 'success',
                            'message' => __('Connection successful', 'aiohm-knowledge-assistant')
                        ];
                    } else {
                        $results[$provider] = [
                            'status' => 'error',
                            'message' => $test_result['error'] ?? __('Connection failed', 'aiohm-knowledge-assistant')
                        ];
                    }
                } catch (Exception $e) {
                    $results[$provider] = [
                        'status' => 'error',
                        'message' => $e->getMessage()
                    ];
                }
            }
            
            wp_send_json_success($results);
            
        } catch (Exception $e) {
            wp_send_json_error(['message' => __('Failed to test API connections', 'aiohm-knowledge-assistant')]);
        }
    }

    /**
     * Handle checking database health
     */
    public static function handle_check_database_health_ajax() {
        check_ajax_referer('aiohm_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'aiohm-knowledge-assistant')]);
        }
        
        try {
            global $wpdb;
            $health_data = [];
            
            $tables = [
                'aiohm_vector_entries',
                'aiohm_conversations', 
                'aiohm_messages',
                'aiohm_projects'
            ];
            
            foreach ($tables as $table) {
                $full_table_name = $wpdb->prefix . $table;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Health check for table existence
                $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $full_table_name));
                
                if (!$exists) {
                    $health_data[$table] = [
                        'rows' => 0,
                        'status' => 'missing',
                        'issues' => ['Table does not exist']
                    ];
                    continue;
                }
                
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Health check row count, table name uses WordPress prefix
                $rows = $wpdb->get_var("SELECT COUNT(*) FROM `$full_table_name`");
                $issues = [];
                
                // Check for common issues
                if ($table === 'aiohm_vector_entries') {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Health check for empty vectors, table name uses WordPress prefix
                    $empty_vectors = $wpdb->get_var("SELECT COUNT(*) FROM `$full_table_name` WHERE vector_data IS NULL OR vector_data = ''");
                    if ($empty_vectors > 0) {
                        // translators: %d is the number of entries missing vector data
                        $issues[] = sprintf(__('%d entries missing vector data', 'aiohm-knowledge-assistant'), $empty_vectors);
                    }
                }
                
                $health_data[$table] = [
                    'rows' => (int) $rows,
                    'status' => empty($issues) ? 'healthy' : 'issues',
                    'issues' => $issues
                ];
            }
            
            wp_send_json_success($health_data);
            
        } catch (Exception $e) {
            wp_send_json_error(['message' => __('Failed to check database health', 'aiohm-knowledge-assistant')]);
        }
    }

    /**
     * Handle submitting support requests
     */
    public static function handle_submit_support_request_ajax() {
        check_ajax_referer('aiohm_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'aiohm-knowledge-assistant')]);
        }
        
        try {
            $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
            $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
            $type = isset($_POST['type']) ? sanitize_text_field(wp_unslash($_POST['type'])) : '';
            $description = isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '';
            $include_debug = !empty($_POST['include_debug']);
            $system_info = '';
            if (isset($_POST['system_info'])) {
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in loop below
                $raw_system_info = wp_unslash($_POST['system_info']);
                $sanitized_system_info = array();
                if (is_array($raw_system_info)) {
                    foreach ($raw_system_info as $key => $value) {
                        $sanitized_system_info[sanitize_key($key)] = sanitize_text_field($value);
                    }
                }
                $system_info = wp_json_encode($sanitized_system_info);
            }
            $debug_information = isset($_POST['debug_information']) ? sanitize_textarea_field(wp_unslash($_POST['debug_information'])) : '';
            
            if (empty($email) || empty($title) || empty($description)) {
                wp_send_json_error(['message' => __('Please fill in all required fields', 'aiohm-knowledge-assistant')]);
                return;
            }
            
            // Prepare email content
            $subject = "[AIOHM Support] {$type}: {$title}";
            $message = "Support Request Details:\n\n";
            $message .= "Type: {$type}\n";
            $message .= "Title: {$title}\n";
            $message .= "From: {$email}\n";
            $message .= "Site: " . get_site_url() . "\n";
            $message .= "Time: " . current_time('mysql') . "\n\n";
            $message .= "Description:\n{$description}\n\n";
            
            if ($include_debug && !empty($debug_information)) {
                $message .= "Debug Information:\n{$debug_information}\n\n";
            }
            
            if (!empty($system_info)) {
                $message .= "System Info:\n{$system_info}\n";
            }
            
            // Try to send email to support team
            $to = 'support@aiohm.app';
            $headers = [
                'Content-Type: text/plain; charset=UTF-8',
                'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>',
                'Reply-To: ' . $email
            ];
            
            $sent = wp_mail($to, $subject, $message, $headers);
            
            // Store in database as backup (always store, regardless of email success)
            global $wpdb;
            $table_name = $wpdb->prefix . 'aiohm_support_requests';
            
            // Create table if it doesn't exist
            $charset_collate = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE IF NOT EXISTS $table_name (
                id int(11) NOT NULL AUTO_INCREMENT,
                email varchar(255) NOT NULL,
                title varchar(500) NOT NULL,
                type varchar(100) NOT NULL,
                description text NOT NULL,
                debug_information longtext,
                system_info text,
                site_url varchar(255),
                submitted_at datetime DEFAULT CURRENT_TIMESTAMP,
                email_sent tinyint(1) DEFAULT 0,
                PRIMARY KEY (id)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
            
            // Insert the request
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Support request storage
            $wpdb->insert(
                $table_name,
                [
                    'email' => $email,
                    'title' => $title,
                    'type' => $type,
                    'description' => $description,
                    'debug_information' => $debug_information,
                    'system_info' => $system_info,
                    'site_url' => get_site_url(),
                    'email_sent' => $sent ? 1 : 0
                ],
                ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d']
            );
            
            // Log the request
            
            // Always show success since we stored it in database
            wp_send_json_success(['message' => __('Support request submitted successfully! We have received your request and will get back to you soon.', 'aiohm-knowledge-assistant')]);
            
        } catch (Exception $e) {
            wp_send_json_error(['message' => __('Failed to submit support request', 'aiohm-knowledge-assistant')]);
        }
    }

    /**
     * Handle submitting feature requests
     */
    public static function handle_submit_feature_request_ajax() {
        check_ajax_referer('aiohm_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'aiohm-knowledge-assistant')]);
        }
        
        try {
            $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
            $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
            $type = isset($_POST['type']) ? sanitize_text_field(wp_unslash($_POST['type'])) : '';
            $description = isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '';
            $include_debug = !empty($_POST['include_debug']);
            $system_info = '';
            if (isset($_POST['system_info'])) {
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in loop below
                $raw_system_info = wp_unslash($_POST['system_info']);
                $sanitized_system_info = array();
                if (is_array($raw_system_info)) {
                    foreach ($raw_system_info as $key => $value) {
                        $sanitized_system_info[sanitize_key($key)] = sanitize_text_field($value);
                    }
                }
                $system_info = wp_json_encode($sanitized_system_info);
            }
            $debug_information = isset($_POST['debug_information']) ? sanitize_textarea_field(wp_unslash($_POST['debug_information'])) : '';
            
            if (empty($email) || empty($title) || empty($description)) {
                wp_send_json_error(['message' => __('Please fill in all required fields', 'aiohm-knowledge-assistant')]);
                return;
            }
            
            // Prepare email content
            $subject = "[AIOHM Feature Request] {$title}";
            $message = "Feature Request Details:\n\n";
            $message .= "Type: {$type}\n";
            $message .= "Title: {$title}\n";
            $message .= "From: {$email}\n";
            $message .= "Site: " . get_site_url() . "\n";
            $message .= "Time: " . current_time('mysql') . "\n\n";
            $message .= "Description:\n{$description}\n\n";
            
            if ($include_debug && !empty($debug_information)) {
                $message .= "Debug Information:\n{$debug_information}\n\n";
            }
            
            if (!empty($system_info)) {
                $message .= "System Info:\n{$system_info}\n";
            }
            
            // Try to send email to development team
            $to = 'features@aiohm.app';
            $headers = [
                'Content-Type: text/plain; charset=UTF-8',
                'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>',
                'Reply-To: ' . $email
            ];
            
            $sent = wp_mail($to, $subject, $message, $headers);
            
            // Store in database as backup (always store, regardless of email success)
            global $wpdb;
            $table_name = $wpdb->prefix . 'aiohm_feature_requests';
            
            // Create table if it doesn't exist
            $charset_collate = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE IF NOT EXISTS $table_name (
                id int(11) NOT NULL AUTO_INCREMENT,
                email varchar(255) NOT NULL,
                title varchar(500) NOT NULL,
                type varchar(100) NOT NULL,
                description text NOT NULL,
                debug_information longtext,
                system_info text,
                site_url varchar(255),
                submitted_at datetime DEFAULT CURRENT_TIMESTAMP,
                email_sent tinyint(1) DEFAULT 0,
                PRIMARY KEY (id)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
            
            // Insert the request
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Feature request storage
            $wpdb->insert(
                $table_name,
                [
                    'email' => $email,
                    'title' => $title,
                    'type' => $type,
                    'description' => $description,
                    'debug_information' => $debug_information,
                    'system_info' => $system_info,
                    'site_url' => get_site_url(),
                    'email_sent' => $sent ? 1 : 0
                ],
                ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d']
            );
            
            // Log the request
            
            // Always show success since we stored it in database
            wp_send_json_success(['message' => __('Feature request submitted successfully! We have received your request and will review it for our roadmap.', 'aiohm-knowledge-assistant')]);
            
        } catch (Exception $e) {
            wp_send_json_error(['message' => __('Failed to submit feature request', 'aiohm-knowledge-assistant')]);
        }
    }
    
    /**
     * Handle JSON content update AJAX request
     */
    public static function handle_update_json_content_ajax() {
        // Verify nonce
        check_ajax_referer('aiohm_admin_nonce', 'nonce');

        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'aiohm-knowledge-assistant')]);
        }

        $content_id = sanitize_text_field(wp_unslash($_POST['content_id'] ?? ''));
        $raw_content = isset($_POST['content']) ? sanitize_textarea_field(wp_unslash($_POST['content'])) : '';

        if (empty($content_id) || empty($raw_content)) {
            wp_send_json_error(['message' => __('Missing required data.', 'aiohm-knowledge-assistant')]);
        }

        // Validate and sanitize content based on expected format (JSON for KB entries)
        $decoded_content = json_decode($raw_content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(['message' => __('Invalid content format.', 'aiohm-knowledge-assistant')]);
        }
        
        $new_content = wp_json_encode(self::sanitize_json_recursive($decoded_content));

        // Validate JSON
        $decoded = json_decode($new_content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(['message' => __('Invalid JSON: ', 'aiohm-knowledge-assistant') . esc_html(json_last_error_msg())]);
        }

        // Sanitize JSON recursively
        $sanitized = self::sanitize_json_recursive($decoded);
        $pretty_json = wp_json_encode($sanitized, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        // Update the knowledge base entry
        global $wpdb;
        $table_name = $wpdb->prefix . 'aiohm_vector_entries';
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Content update requires direct query, cache cleared after update
        $result = $wpdb->update(
            $table_name,
            ['content' => $pretty_json],
            ['content_id' => $content_id],
            ['%s'],
            ['%s']
        );

        if ($result === false) {
            wp_send_json_error(['message' => __('Failed to update content.', 'aiohm-knowledge-assistant')]);
        }

        wp_send_json_success(['message' => __('JSON content updated successfully.', 'aiohm-knowledge-assistant')]);
    }

    /**
     * Handle updating text content (MD, TXT) via AJAX
     */
    public static function handle_update_text_content_ajax() {
        // Verify nonce
        check_ajax_referer('aiohm_admin_nonce', 'nonce');

        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'aiohm-knowledge-assistant')]);
        }

        $content_id = sanitize_text_field(wp_unslash($_POST['content_id'] ?? ''));
        $raw_content = isset($_POST['content']) ? sanitize_textarea_field(wp_unslash($_POST['content'])) : '';
        $content_type = sanitize_text_field(wp_unslash($_POST['content_type'] ?? ''));

        if (empty($content_id) || empty($raw_content)) {
            wp_send_json_error(['message' => __('Missing required data.', 'aiohm-knowledge-assistant')]);
        }

        // Sanitize content based on content type
        if ($content_type === 'JSON' || $content_type === 'application/json') {
            $decoded_content = json_decode($raw_content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                wp_send_json_error(['message' => __('Invalid JSON format.', 'aiohm-knowledge-assistant')]);
            }
            $new_content = wp_json_encode(self::sanitize_json_recursive($decoded_content));
        } else {
            // For text content, sanitize as textarea content
            $new_content = sanitize_textarea_field($raw_content);
        }

        // Sanitize content based on type
        $sanitized_content = '';
        switch ($content_type) {
            case 'MD':
            case 'text/markdown':
                // For markdown, allow basic formatting tags
                $sanitized_content = wp_kses($new_content, [
                    'p' => [], 'br' => [], 'strong' => [], 'em' => [], 'ul' => [], 'ol' => [], 'li' => [],
                    'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
                    'blockquote' => [], 'code' => [], 'pre' => [], 'a' => ['href' => [], 'title' => []]
                ]);
                break;
            case 'PDF':
            case 'application/pdf':
                // For PDF content (manually edited), allow basic formatting while preserving line breaks
                $sanitized_content = wp_kses($new_content, [
                    'p' => [], 'br' => [], 'strong' => [], 'em' => [], 'ul' => [], 'ol' => [], 'li' => [],
                    'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => []
                ]);
                // If no HTML tags, preserve line breaks by converting them
                if ($sanitized_content === wp_strip_all_tags($new_content)) {
                    $sanitized_content = nl2br(sanitize_textarea_field($new_content));
                }
                break;
            case 'HTML':
            case 'HTM':
            case 'text/html':
                // For HTML content, allow more formatting tags
                $sanitized_content = wp_kses($new_content, [
                    'p' => [], 'br' => [], 'strong' => [], 'em' => [], 'ul' => [], 'ol' => [], 'li' => [],
                    'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
                    'blockquote' => [], 'code' => [], 'pre' => [], 'a' => ['href' => [], 'title' => []],
                    'div' => ['class' => []], 'span' => ['class' => []], 'table' => [], 'tr' => [], 'td' => [], 'th' => []
                ]);
                break;
            case 'TXT':
            case 'text/plain':
            case 'project_note':
            default:
                // For plain text and notes, basic sanitization
                $sanitized_content = sanitize_textarea_field($new_content);
                break;
        }

        if (empty($sanitized_content)) {
            wp_send_json_error(['message' => __('Content is empty after sanitization.', 'aiohm-knowledge-assistant')]);
        }

        // Update the knowledge base entry
        global $wpdb;
        $table_name = $wpdb->prefix . 'aiohm_vector_entries';
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Content update requires direct query, cache cleared after update
        $result = $wpdb->update(
            $table_name,
            ['content' => $sanitized_content],
            ['content_id' => $content_id],
            ['%s'],
            ['%s']
        );

        if ($result === false) {
            wp_send_json_error(['message' => __('Failed to update content.', 'aiohm-knowledge-assistant')]);
        }

        // Clear cache after update
        if (class_exists('AIOHM_KB_Manager')) {
            $kb_manager = new AIOHM_KB_Manager();
            $kb_manager->clear_kb_manager_cache();
        } else {
            wp_cache_flush_group('aiohm_kb_manager');
        }

        wp_send_json_success(['message' => __('Content updated successfully.', 'aiohm-knowledge-assistant')]);
    }
    
    /**
     * Handle PDF re-processing AJAX request
     */
    public static function handle_reprocess_pdf_content_ajax() {
        check_ajax_referer('aiohm_admin_nonce', 'nonce');
        
        $content_id = isset($_POST['content_id']) ? sanitize_text_field(wp_unslash($_POST['content_id'])) : '';
        
        if (empty($content_id)) {
            wp_send_json_error(['message' => __('Content ID is required.', 'aiohm-knowledge-assistant')]);
            return;
        }
        
        try {
            global $wpdb;
            
            // Get the original entry with metadata
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Content lookup for reprocessing
            $entry = $wpdb->get_row($wpdb->prepare(
                "SELECT content, title, content_type, metadata FROM " . $wpdb->prefix . "aiohm_vector_entries WHERE content_id = %s LIMIT 1",
                $content_id
            ), ARRAY_A);
            
            if (!$entry) {
                wp_send_json_error(['message' => __('Content not found.', 'aiohm-knowledge-assistant')]);
                return;
            }
            
            if ($entry['content_type'] !== 'application/pdf' && $entry['content_type'] !== 'PDF') {
                wp_send_json_error(['message' => __('This function is only for PDF content.', 'aiohm-knowledge-assistant')]);
                return;
            }
            
            // Try to get the original file path from metadata
            $metadata = json_decode($entry['metadata'], true);
            $file_path = null;
            
            if (is_array($metadata) && isset($metadata['attachment_id'])) {
                $file_path = get_attached_file($metadata['attachment_id']);
            }
            
            if (!$file_path || !file_exists($file_path)) {
                wp_send_json_error(['message' => __('Original PDF file not found on server.', 'aiohm-knowledge-assistant')]);
                return;
            }
            
            // Re-extract PDF content
            $new_content = self::extract_pdf_content($file_path, basename($file_path));
            
            if (empty(trim($new_content))) {
                wp_send_json_error(['message' => __('PDF re-processing failed - no content extracted.', 'aiohm-knowledge-assistant')]);
                return;
            }
            
            // Update the database entry
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Content update operation
            $updated = $wpdb->update(
                $wpdb->prefix . 'aiohm_vector_entries',
                ['content' => $new_content],
                ['content_id' => $content_id],
                ['%s'],
                ['%s']
            );
            
            if ($updated === false) {
                wp_send_json_error(['message' => __('Failed to update PDF content in database.', 'aiohm-knowledge-assistant')]);
                return;
            }
            
            
            // Prepare processing details for frontend
            $processing_details = [
                'extraction_quality' => strlen($new_content) > 1000 ? 'high' : (strlen($new_content) > 200 ? 'medium' : 'low'),
                'processing_log' => [
                    ['success', 'PDF file located successfully'],
                    ['success', 'Content re-extracted using improved methods'],
                    ['success', 'Database updated with new content'],
                    ['info', 'Extracted ' . strlen($new_content) . ' characters']
                ],
                'user_actions' => []
            ];
            
            // Add recommendations based on content quality
            if (strlen($new_content) < 200) {
                $processing_details['user_actions'][] = [
                    'message' => 'Content appears short - this may be a scanned PDF requiring OCR'
                ];
            }
            
            wp_send_json_success([
                'message' => __('PDF content re-processed successfully.', 'aiohm-knowledge-assistant'),
                'processing_details' => $processing_details
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(['message' => __('Error re-processing PDF: ', 'aiohm-knowledge-assistant') . $e->getMessage()]);
        }
    }
    
    /**
     * Handle HTML re-processing AJAX request
     */
    public static function handle_reprocess_html_content_ajax() {
        check_ajax_referer('aiohm_admin_nonce', 'nonce');
        
        $content_id = isset($_POST['content_id']) ? sanitize_text_field(wp_unslash($_POST['content_id'])) : '';
        
        if (empty($content_id)) {
            wp_send_json_error(['message' => __('Content ID is required.', 'aiohm-knowledge-assistant')]);
            return;
        }
        
        try {
            global $wpdb;
            
            // Get the original entry with metadata
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Content lookup for reprocessing
            $entry = $wpdb->get_row($wpdb->prepare(
                "SELECT content, title, content_type, metadata FROM " . $wpdb->prefix . "aiohm_vector_entries WHERE content_id = %s LIMIT 1",
                $content_id
            ), ARRAY_A);
            
            if (!$entry) {
                wp_send_json_error(['message' => __('Content not found.', 'aiohm-knowledge-assistant')]);
                return;
            }
            
            if (!in_array($entry['content_type'], ['text/html', 'HTML', 'HTM'])) {
                wp_send_json_error(['message' => __('This function is only for HTML content.', 'aiohm-knowledge-assistant')]);
                return;
            }
            
            // Try to get the original file path from metadata
            $metadata = json_decode($entry['metadata'], true);
            $file_path = null;
            
            if (is_array($metadata) && isset($metadata['attachment_id'])) {
                $file_path = get_attached_file($metadata['attachment_id']);
            }
            
            if (!$file_path || !file_exists($file_path)) {
                wp_send_json_error(['message' => __('Original HTML file not found on server.', 'aiohm-knowledge-assistant')]);
                return;
            }
            
            // Re-extract HTML content
            $html_result = self::extract_html_content($file_path, basename($file_path));
            
            $new_content = '';
            if (is_array($html_result)) {
                $new_content = $html_result['content'];
            } else {
                $new_content = $html_result; // Fallback for error messages
            }
            
            if (empty(trim($new_content))) {
                wp_send_json_error(['message' => __('HTML re-processing failed - no content extracted.', 'aiohm-knowledge-assistant')]);
                return;
            }
            
            // Update the database entry
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Content update operation
            $updated = $wpdb->update(
                $wpdb->prefix . 'aiohm_vector_entries',
                ['content' => $new_content],
                ['content_id' => $content_id],
                ['%s'],
                ['%s']
            );
            
            if ($updated === false) {
                wp_send_json_error(['message' => __('Failed to update HTML content in database.', 'aiohm-knowledge-assistant')]);
                return;
            }
            
            
            // Prepare processing details for frontend
            $processing_details = [
                'extraction_quality' => strlen($new_content) > 1000 ? 'high' : (strlen($new_content) > 200 ? 'medium' : 'low'),
                'processing_log' => [
                    ['success', 'HTML file located successfully'],
                    ['success', 'Content re-extracted with structure preservation'],
                    ['success', 'Database updated with new content'],
                    ['info', 'Extracted ' . strlen($new_content) . ' characters']
                ],
                'user_actions' => []
            ];
            
            // Add recommendations based on content quality
            if (strlen($new_content) < 200) {
                $processing_details['user_actions'][] = [
                    'message' => 'Content appears short - HTML may contain mostly scripts/styles'
                ];
            }
            
            wp_send_json_success([
                'message' => __('HTML content re-processed successfully.', 'aiohm-knowledge-assistant'),
                'processing_details' => $processing_details
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(['message' => __('Error re-processing HTML: ', 'aiohm-knowledge-assistant') . $e->getMessage()]);
        }
    }
    
}

} // End class_exists check
