<?php
/**
 * Admin Mirror Mode Settings page template.
 * Final, complete, and stable version with all features, styles, and full scripts.
 */

if (!defined("ABSPATH")) {
    exit();
}

// Fetch all settings and then get the specific part for Mirror Mode
$aiohm_all_settings = AIOHM_KB_Assistant::get_settings();
$aiohm_settings = $aiohm_all_settings["mirror_mode"] ?? [];
$aiohm_global_settings = $aiohm_all_settings; // for API keys

// All users have access in free version
$aiohm_has_private_access = false; // Private features removed from free version

// Helper function for color contrast
function aiohm_is_color_dark($hex)
{
    if (empty($hex)) {
        return false;
    }
    $hex = str_replace("#", "", $hex);
    if (strlen($hex) == 3) {
        $hex =
            str_repeat(substr($hex, 0, 1), 2) .
            str_repeat(substr($hex, 1, 1), 2) .
            str_repeat(substr($hex, 2, 1), 2);
    }
    if (strlen($hex) != 6) {
        return false;
    }
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
    return $luminance < 0.5;
}

$aiohm_default_prompt =
    "You are the official AIOHM Knowledge Assistant for \"%site_name%\" - where your brand voice becomes your AI.\n\nYour core mission is to embody our brand's essence: \"%site_tagline%\" while demonstrating that authentic AI assistance reflects the true voice behind the brand. Today is %day_of_week%, %current_date%.\n\n## BRAND VOICE PHILOSOPHY\nYou represent the AIOHM principle: 'your brand voice... your AI' - meaning every response should authentically reflect the unique personality, values, and communication style of this brand. You're not a generic chatbot; you're the digital embodiment of this brand's wisdom and character.\n\n## CORE INSTRUCTIONS\n\n### 1. PRIMARY DIRECTIVE\nAnswer questions using the context provided below as your authoritative knowledge source. This context contains the brand's approved messaging, expertise, and authentic voice patterns.\n\n### 2. AUTHENTIC VOICE EXPRESSION\n• **Emotional Intelligence**: Respond with genuine understanding, not algorithmic politeness\n• **Brand Personality**: Let the unique character of this brand shine through every interaction\n• **Conversational Presence**: Engage as a knowledgeable insider, not a distant assistant\n• **Value-Driven Responses**: Prioritize being genuinely helpful over being merely correct\n\n### 3. COMMUNICATION STANDARDS\n• **Format**: Use basic HTML tags for clarity (<strong>, <em>, <ul>, <li>). No Markdown.\n• **Tone Consistency**: Match the brand's established voice throughout the conversation\n• **Response Quality**: Be comprehensive yet concise - every word should add value\n• **Engagement**: Never end with generic questions like \"Do you need help with anything else?\"\n\n### 4. KNOWLEDGE BOUNDARIES\nWhen the provided context lacks sufficient information: \"I want to give you accurate information that truly reflects our expertise. For this specific question, connecting directly with our team would serve you best - they can provide the personalized insight you're looking for. You'll find contact information on our contact page.\"\n\n## BRAND CONTEXT & KNOWLEDGE BASE\n{context}";

// Get the saved message (migration is handled in plugin activation)
$aiohm_saved_message = $aiohm_settings["qa_system_message"] ?? "";
$aiohm_qa_system_message = !empty($aiohm_saved_message)
    ? $aiohm_saved_message
    : $aiohm_default_prompt;
?>

<div class="wrap aiohm-settings-page aiohm-mirror-mode-page">
	<div class="aiohm-page-header">
		<h1><?php esc_html_e(
      "Mirror Mode Customization",
      "aiohm-knowledge-assistant",
  ); ?></h1>
		<p class="page-description"><?php esc_html_e(
      'Configure your public-facing AI assistant. Changes are previewed instantly on the right - save when you\'re happy with the results.',
      "aiohm-knowledge-assistant",
  ); ?></p>
	</div>

	<div id="aiohm-admin-notice" class="notice is-dismissible admin-notice-hidden" tabindex="-1" role="alert" aria-live="polite"><p></p></div>

	<div class="aiohm-mirror-mode-layout">

		<div class="aiohm-settings-form-wrapper">
			<form id="mirror-mode-settings-form">
				<?php wp_nonce_field(
        "aiohm_mirror_mode_nonce",
        "aiohm_mirror_mode_nonce_field",
    ); ?>

				<!-- Basic Settings Section -->
				<div class="aiohm-settings-section">
					<h3 class="aiohm-section-title"><?php esc_html_e(
         "Basic Settings",
         "aiohm-knowledge-assistant",
     ); ?></h3>

					<div class="aiohm-setting-block">
						<div class="aiohm-setting-header">
							<label for="business_name"><?php esc_html_e(
           "Business Name",
           "aiohm-knowledge-assistant",
       ); ?></label>
						</div>
						<input type="text" id="business_name" name="aiohm_kb_settings[mirror_mode][business_name]" value="<?php echo esc_attr(
          $aiohm_settings["business_name"] ?? get_bloginfo("name"),
      ); ?>" placeholder="<?php esc_attr_e(
    "Enter your business name",
    "aiohm-knowledge-assistant",
); ?>">
						<p class="description"><?php esc_html_e(
          'This name appears in the chat header and helps visitors know they\'re talking to your brand.',
          "aiohm-knowledge-assistant",
      ); ?></p>
					</div>

					<div class="aiohm-setting-block">
						<div class="aiohm-setting-header">
							<label for="ai_model_selector"><?php esc_html_e(
           "AI Model",
           "aiohm-knowledge-assistant",
       ); ?></label>
						</div>
						<?php
						$aiohm_has_openai = ! empty( $aiohm_global_settings['openai_api_key'] )
							|| ( function_exists( 'aiohm_kb_is_wp_ai_connector_configured' ) && aiohm_kb_is_wp_ai_connector_configured( 'openai' ) )
							|| ! empty( get_option( 'connectors_ai_openai_api_key' ) )
							|| ( defined( 'OPENAI_API_KEY' ) && OPENAI_API_KEY )
							|| getenv( 'OPENAI_API_KEY' );

						$aiohm_has_gemini = ! empty( $aiohm_global_settings['gemini_api_key'] )
							|| ( function_exists( 'aiohm_kb_is_wp_ai_connector_configured' ) && aiohm_kb_is_wp_ai_connector_configured( 'gemini' ) )
							|| ! empty( get_option( 'connectors_ai_google_api_key' ) )
							|| ! empty( get_option( 'connectors_ai_gemini_api_key' ) )
							|| ( defined( 'GOOGLE_API_KEY' ) && GOOGLE_API_KEY )
							|| ( defined( 'GEMINI_API_KEY' ) && GEMINI_API_KEY )
							|| getenv( 'GOOGLE_API_KEY' )
							|| getenv( 'GEMINI_API_KEY' );

						$aiohm_has_claude = ! empty( $aiohm_global_settings['claude_api_key'] )
							|| ( function_exists( 'aiohm_kb_is_wp_ai_connector_configured' ) && aiohm_kb_is_wp_ai_connector_configured( 'claude' ) )
							|| ! empty( get_option( 'connectors_ai_anthropic_api_key' ) )
							|| ! empty( get_option( 'connectors_ai_claude_api_key' ) )
							|| ( defined( 'ANTHROPIC_API_KEY' ) && ANTHROPIC_API_KEY )
							|| ( defined( 'CLAUDE_API_KEY' ) && CLAUDE_API_KEY )
							|| getenv( 'ANTHROPIC_API_KEY' )
							|| getenv( 'CLAUDE_API_KEY' );

						$aiohm_has_groq = ! empty( $aiohm_global_settings['groq_api_key'] );
						$aiohm_has_shareai = ! empty( $aiohm_global_settings['shareai_api_key'] );
						?>
						<select id="ai_model_selector" name="aiohm_kb_settings[mirror_mode][ai_model]">
							<?php if ($aiohm_has_openai): ?>
								<option value="gpt-3.5-turbo" <?php selected(
            $aiohm_settings["ai_model"] ?? "gpt-3.5-turbo",
            "gpt-3.5-turbo",
        ); ?>>OpenAI: GPT-3.5 Turbo (Fast & Reliable)</option>
								<option value="gpt-4" <?php selected(
            $aiohm_settings["ai_model"] ?? "",
            "gpt-4",
        ); ?>>OpenAI: GPT-4 (Advanced)</option>
							<?php endif; ?>
							<?php if ($aiohm_has_gemini): ?>
								<option value="gemini-pro" <?php selected(
            $aiohm_settings["ai_model"] ?? "",
            "gemini-pro",
        ); ?>>Google: Gemini Pro</option>
							<?php endif; ?>
							<?php if ($aiohm_has_claude): ?>
								<option value="claude-3-sonnet" <?php selected(
            $aiohm_settings["ai_model"] ?? "",
            "claude-3-sonnet",
        ); ?>>Anthropic: Claude 3 Sonnet</option>
							<?php endif; ?>
							<?php if ($aiohm_has_groq): ?>
								<option value="groq-llama-3.3-70b-versatile" <?php selected(
            $aiohm_settings["ai_model"] ?? "",
            "groq-llama-3.3-70b-versatile",
        ); ?>>Groq: Llama 3.3 70B (Ultra-Fast)</option>
							<?php endif; ?>
							<?php if ($aiohm_has_shareai): ?>
								<option value="shareai-llama4:17b-scout-16e-instruct-fp16" <?php selected(
            $aiohm_settings["ai_model"] ?? "",
            "shareai-llama4:17b-scout-16e-instruct-fp16",
        ); ?>>ShareAI</option>
							<?php endif; ?>
						</select>
						<p class="description"><?php esc_html_e(
          "Choose the AI model that powers your chatbot. Models are available based on your API key configuration in Settings.",
          "aiohm-knowledge-assistant",
      ); ?></p>
					</div>

					<div class="aiohm-setting-block">
						<label for="qa_temperature"><?php esc_html_e(
          "Creativity Level",
          "aiohm-knowledge-assistant",
      ); ?>: <span class="temp-value"><?php echo esc_attr(
    $aiohm_settings["qa_temperature"] ?? "0.8",
); ?></span></label>
						<input type="range" id="qa_temperature" name="aiohm_kb_settings[mirror_mode][qa_temperature]" value="<?php echo esc_attr(
          $aiohm_settings["qa_temperature"] ?? "0.8",
      ); ?>" min="0" max="1" step="0.1">
						<div class="range-labels">
							<span><?php esc_html_e("Focused", "aiohm-knowledge-assistant"); ?></span>
							<span><?php esc_html_e("Creative", "aiohm-knowledge-assistant"); ?></span>
						</div>
						<p class="description"><?php esc_html_e(
          "Lower values give more predictable, focused responses. Higher values add creativity and variety.",
          "aiohm-knowledge-assistant",
      ); ?></p>
					</div>
				</div>

				<!-- AI Personality Section -->
				<div class="aiohm-settings-section">
					<h3 class="aiohm-section-title"><?php esc_html_e(
         "AI Personality",
         "aiohm-knowledge-assistant",
     ); ?></h3>

					<div class="aiohm-setting-block">
						<div class="aiohm-setting-header">
							<label for="qa_system_message"><?php esc_html_e(
           "Soul Signature (System Prompt)",
           "aiohm-knowledge-assistant",
       ); ?></label>
							<button type="button" id="reset-prompt-btn" class="button-link"><?php esc_html_e(
           "Reset to Default",
           "aiohm-knowledge-assistant",
       ); ?></button>
						</div>
						<textarea id="qa_system_message" name="aiohm_kb_settings[mirror_mode][qa_system_message]" rows="20"><?php echo esc_textarea(
          $aiohm_qa_system_message,
      ); ?></textarea>
						<p class="description">
						<?php printf(
          /* translators: 1: Site name placeholder, 2: Site tagline placeholder, 3: Current date placeholder, 4: Day of week placeholder */
          esc_html__(
              'This defines your AI\'s personality, tone, and behavior. Use %1$s, %2$s, %3$s, and %4$s as dynamic placeholders.',
              "aiohm-knowledge-assistant",
          ),
          "%site_name%",
          "%site_tagline%",
          "%current_date%",
          "%day_of_week%",
      ); ?>
						</p>
					</div>

					<div class="aiohm-setting-block">
						<label for="welcome_message"><?php esc_html_e(
          "Welcome Message",
          "aiohm-knowledge-assistant",
      ); ?></label>
						<textarea id="welcome_message" name="aiohm_kb_settings[mirror_mode][welcome_message]" rows="3" placeholder="<?php esc_attr_e(
          'Hey there! I\'m your AI assistant...',
          "aiohm-knowledge-assistant",
      ); ?>"><?php echo esc_textarea(
    $aiohm_settings["welcome_message"] ?? "",
); ?></textarea>
						<p class="description"><?php esc_html_e(
          "The first message visitors see when the chat loads. Keep it friendly and informative.",
          "aiohm-knowledge-assistant",
      ); ?></p>
					</div>
				</div>

				<!-- Visual Design Section -->
				<div class="aiohm-settings-section">
					<h3 class="aiohm-section-title"><?php esc_html_e(
         "Visual Design",
         "aiohm-knowledge-assistant",
     ); ?></h3>

					<div class="aiohm-setting-block">
						<label for="ai_avatar"><?php esc_html_e(
          "AI Avatar",
          "aiohm-knowledge-assistant",
      ); ?></label>
						<div class="aiohm-avatar-uploader">
							<input type="text" id="ai_avatar" name="aiohm_kb_settings[mirror_mode][ai_avatar]" value="<?php echo esc_attr(
           $aiohm_settings["ai_avatar"] ?? "",
       ); ?>" placeholder="<?php esc_attr_e(
    "Enter image URL",
    "aiohm-knowledge-assistant",
); ?>">
							<button type="button" class="button button-secondary" id="upload_ai_avatar_button"><?php esc_html_e(
           "Upload",
           "aiohm-knowledge-assistant",
       ); ?></button>
						</div>
						<p class="description"><?php esc_html_e(
          'Upload or enter the URL for the AI\'s avatar image. This appears next to each AI response.',
          "aiohm-knowledge-assistant",
      ); ?></p>
					</div>

					<div class="aiohm-setting-block">
						<label><?php esc_html_e("Color Scheme", "aiohm-knowledge-assistant"); ?></label>
						<div class="aiohm-color-grid">
							<div class="aiohm-color-item">
								<label for="primary_color"><?php esc_html_e(
            "Primary Color",
            "aiohm-knowledge-assistant",
        ); ?></label>
								<input type="color" id="primary_color" name="aiohm_kb_settings[mirror_mode][primary_color]" value="<?php echo esc_attr(
            $aiohm_settings["primary_color"] ?? "#1f5014",
        ); ?>">
								<span class="color-description"><?php esc_html_e(
            "Header & buttons",
            "aiohm-knowledge-assistant",
        ); ?></span>
							</div>
							<div class="aiohm-color-item">
								<label for="background_color"><?php esc_html_e(
            "Background Color",
            "aiohm-knowledge-assistant",
        ); ?></label>
								<input type="color" id="background_color" name="aiohm_kb_settings[mirror_mode][background_color]" value="<?php echo esc_attr(
            $aiohm_settings["background_color"] ?? "#f0f4f8",
        ); ?>">
								<span class="color-description"><?php esc_html_e(
            "Chat background",
            "aiohm-knowledge-assistant",
        ); ?></span>
							</div>
							<div class="aiohm-color-item">
								<label for="text_color"><?php esc_html_e(
            "Header Text",
            "aiohm-knowledge-assistant",
        ); ?></label>
								<input type="color" id="text_color" name="aiohm_kb_settings[mirror_mode][text_color]" value="<?php echo esc_attr(
            $aiohm_settings["text_color"] ?? "#ffffff",
        ); ?>">
								<span class="color-description"><?php esc_html_e(
            "Header text color",
            "aiohm-knowledge-assistant",
        ); ?></span>
							</div>
						</div>
						<p class="description"><?php esc_html_e(
          "Customize the color scheme to match your brand. Changes are previewed instantly in the test chat.",
          "aiohm-knowledge-assistant",
      ); ?></p>
					</div>
				</div>

				<!-- Integration Section -->
				<div class="aiohm-settings-section">
					<h3 class="aiohm-section-title"><?php esc_html_e(
         "Integration Options",
         "aiohm-knowledge-assistant",
     ); ?></h3>

					<div class="aiohm-setting-block">
						<label for="meeting_button_url"><?php esc_html_e(
          "Book a Meeting URL",
          "aiohm-knowledge-assistant",
      ); ?></label>
						<input type="url" id="meeting_button_url" name="aiohm_kb_settings[mirror_mode][meeting_button_url]" value="<?php echo esc_attr(
          $aiohm_settings["meeting_button_url"] ?? "",
      ); ?>" placeholder="https://calendly.com/your-link">
						<p class="description"><?php esc_html_e(
          'Add your booking link (Calendly, etc.) to replace the "Powered by" text with a meeting button.',
          "aiohm-knowledge-assistant",
      ); ?></p>
					</div>
				</div>

				<div class="form-actions">
					<button type="button" id="save-mirror-mode-settings" class="button button-primary"><?php esc_html_e(
         "Save Settings",
         "aiohm-knowledge-assistant",
     ); ?></button>
				</div>
			</form>
		</div>

		<div class="aiohm-test-column">
			<div class="aiohm-test-header">
				<h3><?php esc_html_e("Live Preview", "aiohm-knowledge-assistant"); ?></h3>
				<p class="description"><?php esc_html_e(
        "Changes on the left are applied instantly here. Test your chatbot before saving.",
        "aiohm-knowledge-assistant",
    ); ?></p>
				<div class="aiohm-shortcode-info">
					<strong><?php esc_html_e("Usage:", "aiohm-knowledge-assistant"); ?></strong>
					<code>[aiohm_chat]</code>
					<span class="aiohm-copy-shortcode" title="<?php esc_attr_e(
         "Click to copy",
         "aiohm-knowledge-assistant",
     ); ?>">📋</span>
				</div>
			</div>
			<div id="aiohm-test-chat" class="aiohm-chat-container">
				<div class="aiohm-chat-header">
					<div class="aiohm-chat-title-preview"><?php echo esc_html(
         $aiohm_settings["business_name"] ?? "Live Preview",
     ); ?></div>
					<div class="aiohm-chat-status">
						<span class="aiohm-status-indicator" data-status="ready"></span>
						<span class="aiohm-status-text">Ready</span>
					</div>
				</div>
				<div class="aiohm-chat-messages">
					<div class="aiohm-message aiohm-message-bot">
						<div class="aiohm-message-avatar">
							<?php echo wp_kses_post(
           AIOHM_KB_Core_Init::render_image(
               $aiohm_settings["ai_avatar"] ??
                   AIOHM_KB_PLUGIN_URL . "assets/images/OHM-logo.png",
               esc_attr__("AI Avatar", "aiohm-knowledge-assistant"),
               ["class" => "aiohm-avatar-preview"],
           ),
       ); ?>
						</div>
						<div class="aiohm-message-bubble"><div class="aiohm-message-content">Ask a question to test the settings from the left. Your changes are applied instantly here without saving.</div></div>
					</div>
				</div>
				<div class="aiohm-chat-input-container">
					<div class="aiohm-chat-input-wrapper">
						<textarea class="aiohm-chat-input" placeholder="Ask your question here..." rows="1"></textarea>
						<button type="button" class="aiohm-chat-send-btn" disabled><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg></button>
					</div>
				</div>
				<div class="aiohm-chat-footer-preview">
				</div>
			</div>

			<div class="aiohm-search-container-wrapper">
				<div class="aiohm-search-controls">
					<div class="aiohm-search-form">
						<div class="aiohm-search-input-wrapper">
							<input type="text" class="aiohm-search-input" placeholder="Search knowledge base...">
							<button type="button" class="aiohm-search-btn">
								<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><path d="m21 21-4.35-4.35"></path></svg>
							</button>
						</div>
					</div>
					<div class="aiohm-search-filters">
						<select id="aiohm-test-search-filter" name="content_type">
							<option value="">All Types</option>
							<option value="post">Posts</option>
							<option value="page">Pages</option>
							<option value="application/pdf">PDF</option>
							<option value="text/plain">TXT</option>
						</select>
					</div>
				</div>
				<div class="aiohm-search-results"></div>
			</div>

			<div class="q-and-a-generator">
				<h3><?php esc_html_e(
        "Generate Sample Q&A",
        "aiohm-knowledge-assistant",
    ); ?></h3>
				<p class="description">Generate a random question and answer from your knowledge base to test the AI's understanding.</p>
				<button type="button" id="generate-q-and-a" class="button button-secondary"><?php esc_html_e(
        "Generate Sample Q&A",
        "aiohm-knowledge-assistant",
    ); ?></button>
				<div id="q-and-a-results" class="q-and-a-container"></div>
			</div>

		</div>
	</div>
</div>
