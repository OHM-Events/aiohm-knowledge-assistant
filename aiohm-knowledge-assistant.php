<?php
/**
 * Plugin Name: AIOHM Knowledge Assistant
 * Plugin URI:  https://knowledge.aiohm.app/
 * Description: WordPress 7.0-ready AI knowledge assistant with intelligent chatbot, brand voice alignment, privacy controls, and multi-provider AI support.
 * Version:     3.0.0
 * Author:      OHM Events Agency
 * Author URI:  https://www.ohm.events
 * Text Domain: aiohm-knowledge-assistant
 * Domain Path: /languages
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.2
 * Tested up to: 7.0
 * Requires PHP: 7.4
 */

/**
 * Main plugin file for AIOHM Knowledge Assistant
 * Handles plugin initialization, database setup, and core functionality
 */

if (!defined("ABSPATH")) {
    exit();
}

/**
 * Removed editor-only stub definitions to prevent redeclaration fatals during activation.
 * The plugin now relies solely on WordPress core-provided functions and constants.
 */

define("AIOHM_KB_VERSION", "3.0.0");
define("AIOHM_KB_PLUGIN_FILE", __FILE__);
define("AIOHM_KB_PLUGIN_DIR", plugin_dir_path(__FILE__));
define("AIOHM_KB_INCLUDES_DIR", AIOHM_KB_PLUGIN_DIR . "includes/");
define("AIOHM_KB_PLUGIN_URL", plugin_dir_url(__FILE__));

class AIOHM_KB_Assistant
{
    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->init_hooks();
    }

    private function init_hooks()
    {
        register_activation_hook(AIOHM_KB_PLUGIN_FILE, [$this, "activate"]);
        register_deactivation_hook(AIOHM_KB_PLUGIN_FILE, [$this, "deactivate"]);

        // Ensure HTTPS URLs in admin
        add_filter("upload_dir", [$this, "force_https_upload_dir"]);
        add_action("plugins_loaded", [$this, "load_dependencies"]);
        add_action("admin_init", [$this, "maybe_upgrade"]);
        add_action("init", [$this, "init_plugin"]);
        add_action("wp_enqueue_scripts", [$this, "register_frontend_assets"]);
        add_filter(
            "plugin_action_links_" . plugin_basename(AIOHM_KB_PLUGIN_FILE),
            [$this, "add_settings_link"],
        );

        add_action("aiohm_process_file_queue", [
            $this,
            "handle_background_file_processing",
        ]);
        add_action("aiohm_cleanup_completed_queue", [
            $this,
            "cleanup_completed_queue",
        ]);

        // Add WordPress 5.7+ script attributes support for enhanced security
        if (version_compare(get_bloginfo("version"), "5.7", ">=")) {
            add_filter("wp_script_attributes", [
                $this,
                "add_script_security_attributes",
            ]);
        }

        // Reduce heartbeat frequency to prevent 503 errors
        add_filter("heartbeat_settings", [$this, "modify_heartbeat_settings"]);

        // Disable heartbeat on admin pages to prevent conflicts
        add_action("admin_enqueue_scripts", [
            $this,
            "disable_heartbeat_on_admin",
        ]);

        // Add AJAX error handling to prevent 503 errors
        add_action("wp_ajax_nopriv_handle_ajax_error", [
            $this,
            "handle_ajax_error",
        ]);
        add_action("wp_ajax_handle_ajax_error", [$this, "handle_ajax_error"]);

        // Add shutdown function to catch fatal errors during AJAX requests
        add_action("shutdown", [$this, "catch_ajax_fatal_errors"]);

        // Add memory limit checks for AJAX requests
        add_action("admin_init", [$this, "increase_memory_limit_for_ajax"]);
    }

    public function register_frontend_assets()
    {
        // Register PDF generation assets
        wp_register_style(
            "aiohm-pdf-generation",
            AIOHM_KB_PLUGIN_URL . "assets/css/aiohm-pdf-generation.css",
            [],
            AIOHM_KB_VERSION,
        );

        // Use modern WordPress 6.3+ script registration with async/defer support
        if (version_compare(get_bloginfo("version"), "6.3", ">=")) {
            // Modern registration with strategy parameter
            wp_register_script(
                "aiohm-pdf-generation",
                AIOHM_KB_PLUGIN_URL . "assets/js/aiohm-pdf-generation.js",
                [],
                AIOHM_KB_VERSION,
                [
                    "in_footer" => true,
                    "strategy" => "defer",
                ],
            );

            wp_register_script(
                "aiohm-chat",
                AIOHM_KB_PLUGIN_URL . "assets/js/aiohm-chat.js",
                ["jquery"],
                AIOHM_KB_VERSION,
                [
                    "in_footer" => true,
                    "strategy" => "defer",
                ],
            );

            wp_register_script(
                "aiohm-chat-shortcode",
                AIOHM_KB_PLUGIN_URL . "assets/js/aiohm-chat-shortcode.js",
                ["jquery"],
                AIOHM_KB_VERSION,
                [
                    "in_footer" => true,
                    "strategy" => "defer",
                ],
            );

            wp_register_script(
                "aiohm-qa-shortcode",
                AIOHM_KB_PLUGIN_URL . "assets/js/aiohm-qa-shortcode.js",
                ["jquery"],
                AIOHM_KB_VERSION,
                [
                    "in_footer" => true,
                    "strategy" => "defer",
                ],
            );

            wp_register_script(
                "aiohm-search-shortcode",
                AIOHM_KB_PLUGIN_URL . "assets/js/aiohm-search-shortcode.js",
                ["jquery"],
                AIOHM_KB_VERSION,
                [
                    "in_footer" => true,
                    "strategy" => "defer",
                ],
            );
        } else {
            // Fallback for WordPress < 6.3
            wp_register_script(
                "aiohm-pdf-generation",
                AIOHM_KB_PLUGIN_URL . "assets/js/aiohm-pdf-generation.js",
                [],
                AIOHM_KB_VERSION,
                true,
            );
            wp_register_script(
                "aiohm-chat",
                AIOHM_KB_PLUGIN_URL . "assets/js/aiohm-chat.js",
                ["jquery"],
                AIOHM_KB_VERSION,
                true,
            );
            wp_register_script(
                "aiohm-chat-shortcode",
                AIOHM_KB_PLUGIN_URL . "assets/js/aiohm-chat-shortcode.js",
                ["jquery"],
                AIOHM_KB_VERSION,
                true,
            );
            wp_register_script(
                "aiohm-qa-shortcode",
                AIOHM_KB_PLUGIN_URL . "assets/js/aiohm-qa-shortcode.js",
                ["jquery"],
                AIOHM_KB_VERSION,
                true,
            );
            wp_register_script(
                "aiohm-search-shortcode",
                AIOHM_KB_PLUGIN_URL . "assets/js/aiohm-search-shortcode.js",
                ["jquery"],
                AIOHM_KB_VERSION,
                true,
            );

            // Add defer attribute using wp_script_add_data for backward compatibility
            if (function_exists("wp_script_add_data")) {
                wp_script_add_data("aiohm-pdf-generation", "strategy", "defer");
                wp_script_add_data("aiohm-chat", "strategy", "defer");
                wp_script_add_data("aiohm-chat-shortcode", "strategy", "defer");
                wp_script_add_data("aiohm-qa-shortcode", "strategy", "defer");
                wp_script_add_data(
                    "aiohm-search-shortcode",
                    "strategy",
                    "defer",
                );
            }
        }

        // Register styles (no changes needed)
        wp_register_style(
            "aiohm-chat",
            AIOHM_KB_PLUGIN_URL . "assets/css/aiohm-chat.css",
            [],
            AIOHM_KB_VERSION,
        );
        wp_register_style(
            "aiohm-qa-shortcode",
            AIOHM_KB_PLUGIN_URL . "assets/css/aiohm-qa-shortcode.css",
            [],
            AIOHM_KB_VERSION,
        );
        wp_register_style(
            "aiohm-search-shortcode",
            AIOHM_KB_PLUGIN_URL . "assets/css/aiohm-search-shortcode.css",
            [],
            AIOHM_KB_VERSION,
        );
    }

    public function load_dependencies()
    {
        $base_files = [
            "db-optimizer.php",
            "parsers/abstract-parser.php",
            "parsers/parser-factory.php",
            "parsers/text-parser.php",
            "parsers/data-parser.php",
            "parsers/clean-pdf-parser.php",
            "parsers/html-parser.php",
            "parsers/spreadsheet-parser.php",
            "parsers/ai-fallback-parser.php",

            "pdf-library-loader.php",
            "rag-engine.php",
            "ai-gpt-client.php",
            "user-functions.php",

            "crawler-site.php",
            "crawler-uploads.php",
            "aiohm-kb-manager.php",
            "chat-box.php",

            "core-init.php",
            "settings-page.php",
            "shortcode-chat.php",
            "shortcode-search.php",
            "shortcode-qa.php",
            "shortcode-private-assistant.php",
            "frontend-widget.php",
        ];

        // All features are free and open source since 3.0.0.
        $premium_files = ["mcp-handler.php", "license-integration.php"];
        $files = array_merge($base_files, $premium_files);

        foreach ($files as $file) {
            $file_path = AIOHM_KB_INCLUDES_DIR . $file;
            if (file_exists($file_path)) {
                require_once $file_path;
            }
        }

        // Instantiate MCP handler early so rest_api_init hooks before REST request dispatch
        if (class_exists("AIOHM_KB_MCP_Handler")) {
            AIOHM_KB_MCP_Handler::get_instance();
        }
    }

    public function init_plugin()
    {
        AIOHM_KB_Core_Init::init();
        AIOHM_KB_Settings_Page::init();
        AIOHM_KB_Shortcode_Chat::init();
        AIOHM_KB_Shortcode_Search::init();
        AIOHM_KB_Shortcode_QA::init();
        AIOHM_KB_Frontend_Widget::init();

        // Initialize private assistant (FREE feature)
        if (class_exists("AIOHM_KB_Shortcode_Private_Assistant")) {
            AIOHM_KB_Shortcode_Private_Assistant::init();
        }

        // Initialize MCP handler for API access
        if (class_exists("AIOHM_KB_MCP_Handler")) {
            AIOHM_KB_MCP_Handler::get_instance();
        }
    }

    public function activate()
    {
        global $wp_version;
        if (version_compare($wp_version, "6.2", "<")) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(
                esc_html__(
                    "AIOHM Knowledge Assistant requires WordPress 6.2 or higher.",
                    "aiohm-knowledge-assistant",
                ),
                esc_html__(
                    "Plugin Activation Error",
                    "aiohm-knowledge-assistant",
                ),
                ["back_link" => true],
            );
        }

        require_once AIOHM_KB_INCLUDES_DIR . "rag-engine.php";
        $this->create_tables();
        $this->maybe_update_content_type_column(); // Force migration on activation
        $this->create_project_tables(); // Moved this before conversations
        $this->create_conversation_tables();
        $this->create_mcp_tables(); // Create MCP API tables
        $this->maybe_update_messages_table(); // Update messages table schema
        $this->maybe_add_conversation_index(); // Add performance index for conversations
        $this->maybe_migrate_soul_signature(); // Migrate soul signature formatting
        $this->maybe_standardize_content_types(); // Standardize content type naming
        $this->set_default_options();
        flush_rewrite_rules();
    }

    public function deactivate()
    {
        flush_rewrite_rules();
    }

    /**
     * Encrypt API key for secure storage
     *
     * @param string $api_key The API key to encrypt
     * @return string The encrypted API key
     */
    public static function encrypt_api_key($api_key)
    {
        if (empty($api_key)) {
            return "";
        }

        $key = wp_hash(SECURE_AUTH_KEY . LOGGED_IN_KEY);

        try {
            $iv = random_bytes(16);
        } catch (Exception $e) {
            $iv = openssl_random_pseudo_bytes(16);
            if ($iv === false) {
                return "";
            }
        }

        $encrypted = openssl_encrypt($api_key, "AES-256-CBC", $key, 0, $iv);

        if ($encrypted === false) {
            return "";
        }

        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt API key for use
     *
     * @param string $encrypted_key The encrypted API key
     * @return string The decrypted API key
     */
    public static function decrypt_api_key($encrypted_key, $depth = 0)
    {
        // Prevent infinite recursion - maximum 2 decryption attempts
        if ($depth > 1) {
            return "";
        }

        if (empty($encrypted_key)) {
            return "";
        }

        $data = base64_decode($encrypted_key);
        if ($data === false || strlen($data) < 16) {
            return "";
        }

        $key = wp_hash(SECURE_AUTH_KEY . LOGGED_IN_KEY);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);

        $decrypted = openssl_decrypt($encrypted, "AES-256-CBC", $key, 0, $iv);
        if ($decrypted === false) {
            return "";
        }

        // Check if result looks like it might be double-encrypted (base64-like pattern)
        if (
            $depth === 0 &&
            strlen($decrypted) > 50 &&
            preg_match('/^[A-Za-z0-9+\/]+=*$/', $decrypted)
        ) {
            $second_decrypted = self::decrypt_api_key($decrypted, $depth + 1);
            if (!empty($second_decrypted) && strlen($second_decrypted) < 100) {
                return $second_decrypted;
            }
        }

        return $decrypted;
    }

    public static function get_settings()
    {
        $default_settings = [
            "aiohm_app_email" => "",
            "external_api_consent" => true,

            "openai_api_key" => "",
            "gemini_api_key" => "",
            "claude_api_key" => "",
            "shareai_api_key" => "",
            "shareai_model" => "llama4:17b-scout-16e-instruct-fp16",
            "groq_api_key" => "",
            "groq_model" => "llama-3.3-70b-versatile",
            "private_llm_server_url" => "",
            "private_llm_model" => "llama2",
            "default_ai_provider" => "ollama",

            "pro_features" => [
                "ollama_enabled" => true,
                "ollama_server_url" => "http://localhost:11434",
                "ollama_model" => "llama3.2:latest",
                "mcp_enabled" => false,
            ],

            "chat_enabled" => true,
            "show_floating_chat" => false,

            "chunk_size" => 1000,
            "chunk_overlap" => 200,

            "mirror_mode" => [
                "qa_system_message" =>
                    "You are the official AIOHM Knowledge Assistant for \"%site_name%\" - where your brand voice becomes your AI.\n\nYour mission is to embody AIOHM's 'your brand voice... your AI' philosophy by reflecting the authentic personality and expertise of this brand. Today is %day_of_week%, %current_date%.\n\n## BRAND VOICE PHILOSOPHY\nYou represent the AIOHM principle: every AI interaction should authentically reflect the unique voice, values, and character of the brand you serve. You're not generic - you're the digital embodiment of this brand's wisdom.\n\n## RESPONSE EXCELLENCE\n\n**Primary Directive:**\nAnswer using the context below as your authoritative knowledge source - this contains the brand's approved messaging and expertise.\n\n**Brand Voice Expression:**\n• Respond with genuine understanding that reflects this brand's unique personality\n• Maintain professional warmth that matches the brand's character\n• Be comprehensive yet concise - every word should add authentic value\n• Let the brand's expertise and authority shine through your responses\n\n**Knowledge Boundaries:**\nWhen context is insufficient: \"I want to give you accurate information that truly reflects our expertise. For this specific question, connecting directly with our team would serve you best - they can provide the personalized insight you're looking for.\"\n\n**Technical Standards:**\nUse basic HTML formatting only (<strong>, <em>, <ul>, <li>). No Markdown.\n\n## BRAND CONTEXT & KNOWLEDGE BASE\n{context}",
                "qa_temperature" => "0.8",
                "business_name" => get_bloginfo("name"),
            ],

            "muse_mode" => [
                "system_prompt" =>
                    "You are Muse, the private AIOHM brand assistant where your expertise becomes their brand voice, their AI.\n\n## YOUR CORE MISSION\nTransform brand development into an intelligent, intuitive process by synthesizing public brand information with private Brand Soul insights. You embody AIOHM's philosophy: authentic AI assistance that truly reflects and enhances the user's unique brand voice.\n\n## BRAND VOICE EXPERTISE\nYou don't just assist - you become the digital extension of their brand intelligence:\n• **Voice Synthesis**: Blend their Brand Soul archetype with practical brand strategy\n• **Strategic Thinking**: Connect creative vision with actionable business outcomes\n• **Authentic Expression**: Help them articulate their brand truth, not generic messaging\n• **Creative Partnership**: Function as their brand's creative and strategic co-pilot\n\n## AIOHM COMMUNICATION STANDARDS\n**Writing Excellence:**\n• Write with precision and clarity - every word serves the brand's purpose\n• Use direct, confident language that reflects their brand authority\n• Structure information for immediate comprehension and action\n• Support strategic recommendations with relevant context and examples\n\n**Engagement Approach:**\n• Address them directly as the brand owner and creative decision-maker\n• Reference their Brand Soul answers to personalize every interaction\n• Ask strategic questions that unlock deeper brand insights\n• Provide frameworks and systems, not just individual solutions\n\n## STRATEGIC METHODOLOGY\n1. **Context Integration**: Synthesize all Brand Soul insights with current brand challenge\n2. **Archetype Alignment**: Ensure recommendations match their chosen brand personality\n3. **Actionable Intelligence**: Transform insights into specific, implementable strategies\n4. **Brand Consistency**: Maintain voice and messaging consistency across all touchpoints\n5. **Growth Orientation**: Focus on scalable solutions that enhance brand value\n\n## BRAND INTELLIGENCE CONTEXT\n{context}",
                "temperature" => "0.7",
                "assistant_name" => "Muse",
                "start_fullscreen" => true,
            ],
        ];
        $saved_settings = get_option("aiohm_kb_settings", []);
        // Ensure settings are always an array to prevent fatal errors with corrupted options
        if (!is_array($saved_settings)) {
            $saved_settings = [];
        }
        $settings = wp_parse_args($saved_settings, $default_settings);

        if (isset($saved_settings["mirror_mode"])) {
            $settings["mirror_mode"] = wp_parse_args(
                $saved_settings["mirror_mode"],
                $default_settings["mirror_mode"],
            );
        } else {
            $settings["mirror_mode"] = $default_settings["mirror_mode"];
        }

        if (isset($saved_settings["muse_mode"])) {
            $settings["muse_mode"] = wp_parse_args(
                $saved_settings["muse_mode"],
                $default_settings["muse_mode"],
            );
        } else {
            $settings["muse_mode"] = $default_settings["muse_mode"];
        }

        if (isset($saved_settings["pro_features"])) {
            $settings["pro_features"] = wp_parse_args(
                $saved_settings["pro_features"],
                $default_settings["pro_features"],
            );
        } else {
            $settings["pro_features"] = $default_settings["pro_features"];
        }

        $encrypted_api_keys = [
            "openai_api_key",
            "gemini_api_key",
            "claude_api_key",
            "groq_api_key",
        ];
        foreach ($encrypted_api_keys as $key) {
            if (!empty($settings[$key])) {
                $decrypted = self::decrypt_api_key($settings[$key]);
                if (!empty($decrypted)) {
                    $settings[$key] = $decrypted;
                } else {
                    $settings[$key] = "";
                }
            }
        }

        if (!empty($settings["shareai_api_key"])) {
            if (
                strlen($settings["shareai_api_key"]) > 100 ||
                preg_match("/[+\/=]/", $settings["shareai_api_key"])
            ) {
                $current_settings = get_option("aiohm_kb_settings", []);
                // The key is corrupted (looks like base64), clear it from both the current settings and the database option.
                $current_settings["shareai_api_key"] = "";
                update_option("aiohm_kb_settings", $current_settings);
                $settings["shareai_api_key"] = "";
            }
        }

        return $settings;
    }

    private function create_tables()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . "aiohm_vector_entries";

        $this->maybe_update_content_type_column();
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL DEFAULT 0,
            content_id varchar(255) NOT NULL,
            content_type varchar(100) NOT NULL,
            title text NOT NULL,
            content longtext NOT NULL,
            vector_data longtext,
            metadata longtext,
            is_public tinyint(1) DEFAULT 0 NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY content_id (content_id),
            KEY is_public (is_public),
            FULLTEXT KEY content (content)
        ) $charset_collate;";
        require_once ABSPATH . "wp-admin/includes/upgrade.php";
        dbDelta($sql);
    }

    /**
     * Update content_type column size if needed for existing installations
     */
    private function maybe_update_content_type_column()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . "aiohm_vector_entries";

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $table_exists = $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $table_name),
        );

        if (!$table_exists) {
            return;
        }

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $safe_table = esc_sql($table_name);
        $sql = "SHOW COLUMNS FROM `{$safe_table}` LIKE %s";
        $column_info = $wpdb->get_row($wpdb->prepare($sql, "content_type"));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

        if ($column_info) {
            if (strpos($column_info->Type, "varchar(50)") !== false) {
                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
                $safe_table = esc_sql($table_name);
                $sql = "ALTER TABLE `{$safe_table}` MODIFY COLUMN `content_type` varchar(100) NOT NULL";
                $result = $wpdb->query($sql);
                // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

                // Silently handle migration result
            }
        }
    }

    private function maybe_migrate_soul_signature()
    {
        $migration_key = "aiohm_soul_signature_migration_v2";
        $migration_done = get_option($migration_key, false);

        if ($migration_done) {
            return; // Migration already completed
        }

        $settings = self::get_settings();
        $needs_update = false;

        if (!empty($settings["mirror_mode"]["qa_system_message"])) {
            $current_message = $settings["mirror_mode"]["qa_system_message"];

            if (
                strpos($current_message, "Core Instructions:") !== false ||
                strpos($current_message, "**Core Instructions:**") !== false ||
                strpos(
                    $current_message,
                    "1. Primary Directive: Your primary goal",
                ) !== false
            ) {
                $new_format =
                    "You are the official AI Knowledge Assistant for \"%site_name%\".\n\nYour mission is to embody our brand voice and assist website visitors with helpful, accurate information. Today is %day_of_week%, %current_date%.\n\nResponse Guidelines:\n\n1. Answer based on the provided context below - this is your primary information source\n\n2. Maintain our brand personality:\n   • Be helpful and thoughtful, not robotic\n   • Stay professional but warm\n   • Be concise and clear\n\n3. When you don't have enough information:\n   Say: \"I don't have enough information to answer that accurately. Please contact us directly for personalized help.\"\n\n4. Use basic HTML formatting only (no Markdown)\n\nContext:\n{context}";

                $settings["mirror_mode"]["qa_system_message"] = $new_format;
                $needs_update = true;
            }
        }

        if ($needs_update) {
            update_option("aiohm_kb_settings", $settings);
        }

        update_option($migration_key, true);
    }

    private function create_conversation_tables()
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . "wp-admin/includes/upgrade.php";

        $table_conversations = $wpdb->prefix . "aiohm_conversations";
        $sql_conversations = "CREATE TABLE $table_conversations (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            project_id mediumint(9) NOT NULL,
            user_id BIGINT(20) NOT NULL,
            title VARCHAR(255) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY project_id (project_id),
            KEY user_updated_idx (user_id, updated_at)
        ) $charset_collate;";
        dbDelta($sql_conversations);

        $table_messages = $wpdb->prefix . "aiohm_messages";
        $sql_messages = "CREATE TABLE $table_messages (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            conversation_id BIGINT(20) NOT NULL,
            user_id BIGINT(20) NOT NULL DEFAULT 0,
            sender ENUM('user', 'ai', 'system', 'assistant') NOT NULL,
            content LONGTEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY conversation_id (conversation_id),
            KEY user_id (user_id)
        ) $charset_collate;";
        dbDelta($sql_messages);
    }

    private function create_project_tables()
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . "wp-admin/includes/upgrade.php";

        $table_name_projects = $wpdb->prefix . "aiohm_projects";
        $sql_projects = "CREATE TABLE $table_name_projects (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            project_name varchar(255) NOT NULL,
            notes LONGTEXT,
            creation_date datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id)
        ) $charset_collate;";
        dbDelta($sql_projects);

        $table_name_conversations = $wpdb->prefix . "aiohm_conversations";
        $cache_key = "aiohm_conversations_schema_v1";
        $schema_version = get_option($cache_key, "");

        if ($schema_version !== "1.2.0") {
            $sql_update = "CREATE TABLE $table_name_conversations (
                id BIGINT(20) NOT NULL AUTO_INCREMENT,
                project_id mediumint(9) NOT NULL DEFAULT 0,
                user_id BIGINT(20) NOT NULL,
                title VARCHAR(255) NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
                PRIMARY KEY  (id),
                KEY user_id (user_id),
                KEY project_id (project_id)
            ) $charset_collate;";

            require_once ABSPATH . "wp-admin/includes/upgrade.php";
            dbDelta($sql_update);

            update_option($cache_key, "1.2.0");
        }
    }

    /**
     * Run schema steps once after an in-place update (wp.org or zip),
     * since updates do not fire the activation hook.
     */
    public function maybe_upgrade()
    {
        if (get_option("aiohm_kb_db_version") === AIOHM_KB_VERSION) {
            return;
        }
        $this->create_mcp_tables();
        update_option("aiohm_kb_db_version", AIOHM_KB_VERSION, false);
    }

    /**
     * Create MCP token management tables
     */
    private function create_mcp_tables()
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Check if tables already exist
        $table_name_tokens = $wpdb->prefix . "aiohm_mcp_tokens";
        $table_name_usage = $wpdb->prefix . "aiohm_mcp_usage";

        $cache_key_tokens = "aiohm_mcp_tokens_schema_version";
        $cache_key_usage = "aiohm_mcp_usage_schema_version";
        $tokens_version = get_option($cache_key_tokens, "0");
        $usage_version = get_option($cache_key_usage, "0");

        require_once ABSPATH . "wp-admin/includes/upgrade.php";

        // Create tokens table
        if ($tokens_version !== "1.0.0") {
            $sql_tokens = "CREATE TABLE $table_name_tokens (
				id bigint(20) NOT NULL AUTO_INCREMENT,
				token_name varchar(255) NOT NULL,
				token_hash varchar(64) NOT NULL,
				token_type enum('public', 'private') DEFAULT 'private' NOT NULL,
				permissions longtext NOT NULL,
				expires_at datetime DEFAULT NULL,
				created_by bigint(20) UNSIGNED NOT NULL,
				created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
				last_used_at datetime DEFAULT NULL,
				is_active tinyint(1) DEFAULT 1 NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY token_hash (token_hash),
				KEY created_by (created_by),
				KEY is_active (is_active),
				KEY expires_at (expires_at),
				KEY token_type (token_type)
			) $charset_collate;";
            dbDelta($sql_tokens);
            update_option($cache_key_tokens, "1.0.0");
        }

        // Create usage table
        if ($usage_version !== "1.0.0") {
            $sql_usage = "CREATE TABLE $table_name_usage (
				id bigint(20) NOT NULL AUTO_INCREMENT,
				token_id bigint(20) NOT NULL,
				endpoint varchar(100) NOT NULL,
				action varchar(50) NOT NULL,
				request_data longtext,
				response_status int(3) NOT NULL,
				response_time int(11) DEFAULT 0,
				user_agent text,
				ip_address varchar(45),
				created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
				PRIMARY KEY (id),
				KEY token_id (token_id),
				KEY endpoint (endpoint),
				KEY created_at (created_at)
			) $charset_collate;";
            dbDelta($sql_usage);
            update_option($cache_key_usage, "1.0.0");
        }
    }

    /**
     * Update messages table to include user_id column for existing installations
     */
    private function maybe_update_messages_table()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . "aiohm_messages";
        $cache_key = "aiohm_messages_schema_v1";
        $schema_version = get_option($cache_key, "");

        if ($schema_version !== "1.3.1") {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $table_exists = $wpdb->get_var(
                $wpdb->prepare("SHOW TABLES LIKE %s", $table_name),
            );

            if ($table_exists) {
                // Check if user_id column already exists
                $column_exists = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'user_id'",
                        $wpdb->dbname,
                        $table_name,
                    ),
                );
                // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

                if (!$column_exists) {
                    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange

                    // Add user_id column
                    $wpdb->query(
                        "ALTER TABLE `" .
                            esc_sql($table_name) .
                            "` ADD COLUMN user_id BIGINT(20) NOT NULL DEFAULT 0 AFTER conversation_id",
                    );

                    // Add index for user_id
                    $wpdb->query(
                        "ALTER TABLE `" .
                            esc_sql($table_name) .
                            "` ADD INDEX user_id (user_id)",
                    );

                    // Update sender enum to include 'assistant'
                    $wpdb->query(
                        "ALTER TABLE `" .
                            esc_sql($table_name) .
                            '` MODIFY COLUMN sender ENUM(\'user\', \'ai\', \'system\', \'assistant\') NOT NULL',
                    );

                    // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
                }
            }

            update_option($cache_key, "1.3.1");
        }
    }

    /**
     * Add composite index for conversation queries if it doesn't exist
     */
    private function maybe_add_conversation_index()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . "aiohm_conversations";
        $migration_key = "aiohm_conversation_index_v1";

        if (get_option($migration_key, false)) {
            return;
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $table_exists = $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $table_name),
        );

        if (!$table_exists) {
            return;
        }

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $safe_table = esc_sql($table_name);
        $sql = "SHOW INDEX FROM `{$safe_table}` WHERE Key_name = %s";
        $index_exists = $wpdb->get_row(
            $wpdb->prepare($sql, "user_updated_idx"),
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter

        if (!$index_exists) {
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
            $safe_table = esc_sql($table_name);
            $sql = "ALTER TABLE `{$safe_table}` ADD INDEX `user_updated_idx` (`user_id`, `updated_at`)";
            $result = $wpdb->query($sql);
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

            // Silently handle index creation result
        }

        update_option($migration_key, true);
    }

    /**
     * Standardize content type naming across the system
     */
    private function maybe_standardize_content_types()
    {
        $migration_key = "aiohm_content_type_standardization_v1";
        $migration_done = get_option($migration_key, false);

        if ($migration_done) {
            return; // Migration already completed
        }

        global $wpdb;
        $table_name = $wpdb->prefix . "aiohm_vector_entries";

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $table_exists = $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $table_name),
        );

        if (!$table_exists) {
            update_option($migration_key, true);
            return;
        }

        $content_type_mappings = [
            "brand-soul" => "brand_soul",
            "brand-core" => "brand_core",
            "brand_core" => "brand_core",
            "github" => "repository",
            "custom" => "custom_content",
        ];

        $migrations_performed = 0;

        foreach ($content_type_mappings as $old_type => $new_type) {
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}aiohm_vector_entries WHERE content_type = %s",
                    $old_type,
                ),
            );

            if ($count > 0) {
                $updated = $wpdb->update(
                    $table_name,
                    ["content_type" => $new_type],
                    ["content_type" => $old_type],
                    ["%s"],
                    ["%s"],
                );

                if ($updated !== false) {
                    $migrations_performed += $updated;
                }
            }
        }
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

        if ($migrations_performed > 0) {
            wp_cache_flush();
        }

        update_option($migration_key, true);
    }

    private function set_default_options()
    {
        if (get_option("aiohm_kb_settings") === false) {
            $basic_defaults = [
                "aiohm_app_email" => "",

                "openai_api_key" => "",
                "gemini_api_key" => "",
                "claude_api_key" => "",
                "shareai_api_key" => "",
                "shareai_model" => "llama4:17b-scout-16e-instruct-fp16",
                "private_llm_server_url" => "",
                "private_llm_model" => "llama2",
                "default_ai_provider" => "",

                "chat_enabled" => true,
                "show_floating_chat" => false,

                "chunk_size" => 1000,
                "chunk_overlap" => 200,
            ];

            // Add default pro features settings - all enabled by default
            $basic_defaults["pro_features"] = [
                "ollama_enabled" => true,
                "ollama_server_url" => "http://localhost:11434",
                "ollama_model" => "llama3.2",
                "private_hub_enabled" => true,
                "private_hub_name" => "My Knowledge Hub",
                "mcp_enabled" => true,
                "mcp_rate_limit" => 1000,
                "mcp_require_https" => false,
            ];

            add_option("aiohm_kb_settings", $basic_defaults);

            // Auto-generate default MCP token if pro features are available
            $this->maybe_generate_default_mcp_token();
        }
    }

    /**
     * Generate a default MCP API token for easy setup
     */
    private function maybe_generate_default_mcp_token()
    {
        // Only generate if we have pro features and MCP handler available
        if (class_exists("AIOHM_KB_MCP_Handler")) {
            {
                $mcp_handler = AIOHM_KB_MCP_Handler::get_instance();

                // Check if there are already tokens
                global $wpdb;
                $table_name = $wpdb->prefix . "aiohm_mcp_tokens";
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                $existing_tokens = (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM `" . esc_sql($table_name) . "`",
                );

                // Only create default token if no tokens exist
                if ($existing_tokens == 0) {
                    $token_data = [
                        "name" => "Default API Token",
                        "type" => "full_access",
                        "expires_at" => null, // Never expires
                        "user_id" => get_current_user_id() ?: 1, // Use current user or admin
                    ];

                    $result = $mcp_handler->create_token($token_data);
                    if (!is_wp_error($result)) {
                        // Store a flag that we created a default token
                        update_option("aiohm_default_token_created", true);
                    }
                }
            }
        }
    }

    public function add_settings_link($links)
    {
        $settings_link =
            '<a href="' .
            admin_url("admin.php?page=aiohm-settings") .
            '">' .
            __("Settings", "aiohm-knowledge-assistant") .
            "</a>";
        $docs_link =
            '<a href="https://knowledge.aiohm.app/docs/" target="_blank">' .
            __("Docs", "aiohm-knowledge-assistant") .
            "</a>";
        array_unshift($links, $settings_link);
        array_unshift($links, $docs_link);
        return $links;
    }

    /**
     * Force HTTPS URLs in upload directory paths
     */
    public function force_https_upload_dir($uploads)
    {
        if (is_ssl() || is_admin()) {
            $uploads["url"] = str_replace(
                "http://",
                "https://",
                $uploads["url"],
            );
            $uploads["baseurl"] = str_replace(
                "http://",
                "https://",
                $uploads["baseurl"],
            );
        }
        return $uploads;
    }

    /**
     * Validate content ID format to prevent SQL injection
     *
     * @param string $content_id Content ID to validate
     * @return bool|WP_Error True if valid, WP_Error if invalid
     */
    public static function validate_content_id($content_id)
    {
        if (empty($content_id)) {
            return new WP_Error(
                "empty_content_id",
                __("Content ID cannot be empty.", "aiohm-knowledge-assistant"),
            );
        }

        if (strlen($content_id) > 255) {
            return new WP_Error(
                "content_id_too_long",
                __("Content ID is too long.", "aiohm-knowledge-assistant"),
            );
        }

        if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $content_id)) {
            return new WP_Error(
                "invalid_content_id",
                __(
                    "Content ID contains invalid characters.",
                    "aiohm-knowledge-assistant",
                ),
            );
        }

        return true;
    }

    /**
     * Validate and sanitize API key
     *
     * @param string $api_key API key to validate
     * @param string $provider Provider type (openai, claude, gemini, shareai)
     * @return string|WP_Error Sanitized API key or WP_Error if invalid
     */
    public static function validate_and_sanitize_api_key(
        $api_key,
        $provider = "",
    ) {
        if (empty($api_key)) {
            return new WP_Error(
                "empty_api_key",
                __("API key cannot be empty.", "aiohm-knowledge-assistant"),
            );
        }

        $sanitized = preg_replace(
            '/[<>"\'\x00-\x1f\x7f-\xff]/',
            "",
            trim($api_key),
        );

        switch ($provider) {
            case "openai":
                if (!preg_match('/^sk-[a-zA-Z0-9]{20,}$/', $sanitized)) {
                    return new WP_Error(
                        "invalid_openai_key",
                        __(
                            "Invalid OpenAI API key format.",
                            "aiohm-knowledge-assistant",
                        ),
                    );
                }
                break;
            case "claude":
                if (!preg_match('/^sk-ant-[a-zA-Z0-9_-]{90,}$/', $sanitized)) {
                    return new WP_Error(
                        "invalid_claude_key",
                        __(
                            "Invalid Claude API key format.",
                            "aiohm-knowledge-assistant",
                        ),
                    );
                }
                break;
            case "gemini":
                if (!preg_match('/^AIza[a-zA-Z0-9_-]{35}$/', $sanitized)) {
                    return new WP_Error(
                        "invalid_gemini_key",
                        __(
                            "Invalid Gemini API key format.",
                            "aiohm-knowledge-assistant",
                        ),
                    );
                }
                break;
            case "shareai":
                $sanitized = preg_replace("/[^a-zA-Z0-9\-]/", "", $sanitized);
                if (strlen($sanitized) < 10) {
                    return new WP_Error(
                        "invalid_shareai_key",
                        __(
                            "ShareAI API key too short.",
                            "aiohm-knowledge-assistant",
                        ),
                    );
                }
                break;
            case "groq":
                if (!preg_match('/^gsk_[a-zA-Z0-9]{50,}$/', $sanitized)) {
                    return new WP_Error(
                        "invalid_groq_key",
                        __(
                            "Invalid Groq API key format.",
                            "aiohm-knowledge-assistant",
                        ),
                    );
                }
                break;
        }

        return $sanitized;
    }

    /**
     * Enhanced rate limiting for sensitive endpoints
     *
     * @param string $endpoint Endpoint identifier
     * @param int    $user_id User ID
     * @param int    $limit Rate limit (default 50 per hour)
     * @return bool True if within limit, false if exceeded
     */
    public static function check_enhanced_rate_limit(
        $endpoint,
        $user_id = 0,
        $limit = 50,
    ) {
        if ($user_id === 0) {
            $user_id = get_current_user_id();
        }

        $user_ip = self::get_client_ip();
        $user_agent = isset($_SERVER["HTTP_USER_AGENT"])
            ? sanitize_text_field(wp_unslash($_SERVER["HTTP_USER_AGENT"]))
            : "unknown";
        $device_fingerprint = self::generate_device_fingerprint();

        $checks = [];

        if ($user_id > 0) {
            $user_key = "aiohm_rate_limit_{$endpoint}_user_{$user_id}";
            $user_count = get_transient($user_key);
            $checks["user"] = [
                "key" => $user_key,
                "count" => $user_count ?: 0,
                "limit" => $limit,
            ];
        }

        $ip_key = "aiohm_rate_limit_{$endpoint}_ip_" . md5($user_ip);
        $ip_count = get_transient($ip_key);
        $checks["ip"] = [
            "key" => $ip_key,
            "count" => $ip_count ?: 0,
            "limit" => $limit,
        ];

        $device_key =
            "aiohm_rate_limit_{$endpoint}_device_" . md5($device_fingerprint);
        $device_count = get_transient($device_key);
        $checks["device"] = [
            "key" => $device_key,
            "count" => $device_count ?: 0,
            "limit" => $limit,
        ];

        $session_key =
            "aiohm_rate_limit_{$endpoint}_session_" .
            md5($user_ip . "_" . md5($user_agent));
        $session_count = get_transient($session_key);
        $checks["session"] = [
            "key" => $session_key,
            "count" => $session_count ?: 0,
            "limit" => $limit,
        ];

        $browser_signature = self::get_browser_signature();
        $browser_key =
            "aiohm_rate_limit_{$endpoint}_browser_" . md5($browser_signature);
        $browser_count = get_transient($browser_key);
        $checks["browser"] = [
            "key" => $browser_key,
            "count" => $browser_count ?: 0,
            "limit" => $limit * 2,
        ]; // Slightly higher limit for browser-based

        $global_key = "aiohm_rate_limit_{$endpoint}_global";
        $global_count = get_transient($global_key);
        $global_limit = $limit * 100; // Allow 100x normal limit globally
        $checks["global"] = [
            "key" => $global_key,
            "count" => $global_count ?: 0,
            "limit" => $global_limit,
        ];

        foreach ($checks as $check_type => $check) {
            if ($check["count"] >= $check["limit"]) {
                return false;
            }
        }

        foreach ($checks as $check) {
            set_transient($check["key"], $check["count"] + 1, HOUR_IN_SECONDS);
        }

        return true;
    }

    /**
     * Get client IP address securely
     *
     * @return string Client IP address
     */
    private static function get_client_ip()
    {
        $ip = isset($_SERVER["REMOTE_ADDR"])
            ? sanitize_text_field(wp_unslash($_SERVER["REMOTE_ADDR"]))
            : "127.0.0.1";

        if (
            defined("AIOHM_TRUST_PROXY_HEADERS") &&
            AIOHM_TRUST_PROXY_HEADERS === true
        ) {
            $trusted_headers = [
                "HTTP_CF_CONNECTING_IP", // Cloudflare
                "HTTP_X_FORWARDED_FOR", // Load balancer/proxy (most common)
                "HTTP_X_REAL_IP", // Nginx proxy
                "HTTP_CLIENT_IP", // Less common proxy header
            ];

            foreach ($trusted_headers as $header) {
                if (!empty($_SERVER[$header])) {
                    $header_ip = sanitize_text_field(
                        wp_unslash($_SERVER[$header]),
                    );

                    if (strpos($header_ip, ",") !== false) {
                        $header_ip = trim(explode(",", $header_ip)[0]);
                    }

                    if (
                        filter_var(
                            $header_ip,
                            FILTER_VALIDATE_IP,
                            FILTER_FLAG_NO_PRIV_RANGE |
                                FILTER_FLAG_NO_RES_RANGE,
                        )
                    ) {
                        $ip = $header_ip;
                        break; // Use the first valid public IP found
                    }
                }
            }
        }

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $ip = "127.0.0.1";
        }

        return $ip;
    }

    /**
     * Sanitize log messages to prevent API key exposure
     *
     * @param string $message Log message to sanitize
     * @return string Sanitized message
     */
    private static function sanitize_log_message($message)
    {
        if (!is_string($message)) {
            return wp_json_encode($message);
        }

        $patterns = [
            "/sk-[a-zA-Z0-9_-]{20,}/" => "OPENAI_API_KEY_HIDDEN",
            "/pk-[a-zA-Z0-9_-]{20,}/" => "OPENAI_PUBLIC_KEY_HIDDEN",

            "/sk-ant-[a-zA-Z0-9_-]{90,}/" => "CLAUDE_API_KEY_HIDDEN",

            "/AIza[a-zA-Z0-9_-]{35}/" => "GEMINI_API_KEY_HIDDEN",

            "/\b[a-zA-Z0-9_-]{32,}\b/" => "POTENTIAL_API_KEY_HIDDEN",

            '/"(api[_-]?key|apikey|key|token|secret)"\s*:\s*"[^"]{10,}"/' =>
                '"$1":"API_KEY_HIDDEN"',

            "/Authorization:\s*Bearer\s+[^\s]{10,}/" =>
                "Authorization: Bearer API_KEY_HIDDEN",
            "/Authorization:\s*[a-zA-Z0-9_-]{20,}/" =>
                "Authorization: API_KEY_HIDDEN",

            '/(password|token|secret|key)["\']?\s*[:=]\s*["\'][^"\']{5,}["\']/' =>
                '$1: "SENSITIVE_DATA_HIDDEN"',

            "/[A-Za-z0-9+\/]{50,}={0,2}/" => "BASE64_DATA_HIDDEN",

            "/api[_-]?key=([a-zA-Z0-9_-]{20,})/" => "api_key=API_KEY_HIDDEN",

            "/\b[a-zA-Z0-9+\/]{40,}\b/" => "LONG_TOKEN_HIDDEN",
        ];

        foreach ($patterns as $pattern => $replacement) {
            $message = preg_replace($pattern, $replacement, $message);
        }

        if (strlen($message) > 2000) {
            $message = substr($message, 0, 1997) . "...";
        }

        return $message;
    }

    /**
     * Generate device fingerprint for enhanced rate limiting
     *
     * @return string Device fingerprint hash
     */
    private static function generate_device_fingerprint()
    {
        $components = [];

        $headers = [
            "HTTP_ACCEPT",
            "HTTP_ACCEPT_LANGUAGE",
            "HTTP_ACCEPT_ENCODING",
            "HTTP_USER_AGENT",
            "HTTP_DNT", // Do Not Track header
            "HTTP_SEC_FETCH_SITE",
            "HTTP_SEC_FETCH_MODE",
            "HTTP_SEC_FETCH_DEST",
        ];

        foreach ($headers as $header) {
            if (isset($_SERVER[$header])) {
                $components[] = sanitize_text_field(
                    wp_unslash($_SERVER[$header]),
                );
            }
        }

        if (isset($_SERVER["REMOTE_PORT"])) {
            $components[] = sanitize_text_field(
                wp_unslash($_SERVER["REMOTE_PORT"]),
            );
        }

        return hash("sha256", implode("|", $components));
    }

    /**
     * Get browser signature based on multiple headers
     *
     * @return string Browser signature
     */
    private static function get_browser_signature()
    {
        $signature_parts = [];

        $user_agent = isset($_SERVER["HTTP_USER_AGENT"])
            ? sanitize_text_field(wp_unslash($_SERVER["HTTP_USER_AGENT"]))
            : "";

        if (preg_match("/Chrome\/([0-9]+)/", $user_agent, $matches)) {
            $signature_parts[] = "Chrome-" . $matches[1];
        } elseif (preg_match("/Firefox\/([0-9]+)/", $user_agent, $matches)) {
            $signature_parts[] = "Firefox-" . $matches[1];
        } elseif (preg_match("/Safari\/([0-9]+)/", $user_agent, $matches)) {
            $signature_parts[] = "Safari-" . $matches[1];
        } elseif (preg_match("/Edge\/([0-9]+)/", $user_agent, $matches)) {
            $signature_parts[] = "Edge-" . $matches[1];
        } else {
            $signature_parts[] = "Unknown-Browser";
        }

        if (strpos($user_agent, "Windows") !== false) {
            $signature_parts[] = "Windows";
        } elseif (strpos($user_agent, "Macintosh") !== false) {
            $signature_parts[] = "Mac";
        } elseif (strpos($user_agent, "Linux") !== false) {
            $signature_parts[] = "Linux";
        } elseif (strpos($user_agent, "Mobile") !== false) {
            $signature_parts[] = "Mobile";
        }

        if (isset($_SERVER["HTTP_ACCEPT_LANGUAGE"])) {
            $lang = sanitize_text_field(
                wp_unslash($_SERVER["HTTP_ACCEPT_LANGUAGE"]),
            );
            $primary_lang = strtok($lang, ",");
            $signature_parts[] = $primary_lang;
        }

        return implode("-", $signature_parts);
    }

    /**
     * Handle background file processing via WordPress cron
     */
    public function handle_background_file_processing()
    {
        require_once AIOHM_KB_INCLUDES_DIR . "crawler-uploads.php";
        $uploads_crawler = new AIOHM_KB_Uploads_Crawler();

        $uploads_crawler->process_background_queue();
    }

    /**
     * Clean up completed queues
     *
     * @param string $queue_key Queue identifier to clean up
     */
    public function cleanup_completed_queue($queue_key)
    {
        if (empty($queue_key)) {
            return;
        }

        $queue_data = get_option($queue_key);
        if (
            $queue_data &&
            isset($queue_data["status"]) &&
            $queue_data["status"] === "completed"
        ) {
            delete_option($queue_key);
        }
    }

    /**
     * Add security attributes to AIOHM scripts for WordPress 5.7+
     *
     * @param array $attr Script attributes
     * @return array Modified attributes
     */
    public function add_script_security_attributes($attr)
    {
        // Only apply to AIOHM scripts
        $aiohm_scripts = [
            "aiohm-pdf-generation",
            "aiohm-chat",
            "aiohm-qa-shortcode",
            "aiohm-search-shortcode",
            "aiohm-private-chat",
        ];

        if (isset($attr["id"]) && in_array($attr["id"], $aiohm_scripts)) {
            // Add data attributes for enhanced security and debugging
            $attr["data-plugin"] = "aiohm-knowledge-assistant";
            $attr["data-version"] = AIOHM_KB_VERSION;

            // Add integrity check for external CDN scripts if any
            if (isset($attr["src"]) && strpos($attr["src"], "cdn.") !== false) {
                $attr["crossorigin"] = "anonymous";
            }
        }

        return $attr;
    }

    /**
     * Modify heartbeat settings to reduce frequency and prevent 503 errors
     *
     * @param array $settings Heartbeat settings
     * @return array Modified settings
     */
    public function modify_heartbeat_settings($settings)
    {
        // Increase heartbeat interval to reduce server load
        $settings["interval"] = 120; // 2 minutes instead of default 15-60 seconds

        // Reduce main interval as well
        $settings["minimalInterval"] = 60; // 1 minute minimum

        return $settings;
    }

    /**
     * Disable heartbeat on admin pages to prevent 503 errors
     */
    public function disable_heartbeat_on_admin()
    {
        // Only disable on admin pages that are causing issues, keep on frontend
        if (is_admin()) {
            global $pagenow;
            // Disable heartbeat on problematic admin pages
            $disable_pages = [
                "admin.php",
                "edit.php",
                "post.php",
                "upload.php",
            ];
            if (in_array($pagenow, $disable_pages, true)) {
                wp_deregister_script("heartbeat");
            }
        }
    }

    /**
     * Handle AJAX errors to prevent 503 responses
     */
    public function handle_ajax_error()
    {
        // Verify nonce for security
        if (
            !isset($_POST["nonce"]) ||
            !wp_verify_nonce(
                sanitize_text_field(wp_unslash($_POST["nonce"])),
                "aiohm_ajax_error_nonce",
            )
        ) {
            wp_send_json_error(["message" => "Security check failed."]);
            return;
        }

        // Check user permissions
        if (!current_user_can("manage_options")) {
            wp_send_json_error(["message" => "Insufficient permissions."]);
            return;
        }

        // Log the error for debugging
        $error_message = isset($_POST["error"])
            ? sanitize_text_field(wp_unslash($_POST["error"]))
            : "Unknown AJAX error";
        $action = isset($_POST["action"])
            ? sanitize_text_field(wp_unslash($_POST["action"]))
            : "unknown";

        // Log to WordPress debug log if enabled (only in development)
        if (
            defined("WP_DEBUG") &&
            WP_DEBUG &&
            defined("WP_DEBUG_LOG") &&
            WP_DEBUG_LOG
        ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Only used in debug mode
            error_log(
                "AIOHM AJAX Error - Action: " .
                    $action .
                    " - Error: " .
                    $error_message,
            );
        }

        // Return a proper JSON response instead of causing 503
        wp_send_json_error([
            "message" => "An error occurred while processing your request.",
            "action" => $action,
        ]);
    }

    /**
     * Catch fatal errors during AJAX requests and return proper JSON response
     */
    public function catch_ajax_fatal_errors()
    {
        // Only handle AJAX requests
        if (!defined("DOING_AJAX") || !DOING_AJAX) {
            return;
        }

        // Check for fatal errors
        $error = error_get_last();
        if (
            $error &&
            in_array(
                $error["type"],
                [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR],
                true,
            )
        ) {
            // Log the fatal error (only in debug mode with logging enabled)
            if (
                defined("WP_DEBUG") &&
                WP_DEBUG &&
                defined("WP_DEBUG_LOG") &&
                WP_DEBUG_LOG
            ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Only used in debug mode
                error_log(
                    "AIOHM Fatal Error in AJAX: " .
                        $error["message"] .
                        " in " .
                        $error["file"] .
                        ":" .
                        $error["line"],
                );
            }

            // If headers not sent yet, send a proper JSON error response
            if (!headers_sent()) {
                wp_send_json_error([
                    "message" =>
                        "A server error occurred while processing your request.",
                    "error_type" => "fatal_error",
                ]);
            }
        }
    }

    /**
     * Increase memory limit for AJAX requests to prevent 503 errors
     */
    public function increase_memory_limit_for_ajax()
    {
        // Only increase memory limit for AJAX requests
        if (defined("DOING_AJAX") && DOING_AJAX) {
            // Increase memory limit to 256MB for AJAX requests using WordPress function
            if (function_exists("wp_raise_memory_limit")) {
                wp_raise_memory_limit("ajax");
            }

            // Note: Execution time limits are handled by server configuration
            // WordPress recommends not using set_time_limit() in production
        }
    }
}

AIOHM_KB_Assistant::get_instance();
