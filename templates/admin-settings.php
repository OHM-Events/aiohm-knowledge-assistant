<?php
/**
 * Admin settings template
 * Configuration interface for AI providers, plugin options, and access controls
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$aiohm_settings            = class_exists( 'AIOHM_KB_Assistant' ) ? wp_parse_args( AIOHM_KB_Assistant::get_settings(), array() ) : array();
$aiohm_can_access_settings = true; // All users can access settings in free version
$aiohm_has_private_access  = false; // Private features removed for WordPress.org compliance
$aiohm_default_ai_provider = $aiohm_settings['default_ai_provider'] ?? '';
if ( function_exists( 'aiohm_kb_normalize_wp_ai_provider_id' ) ) {
	$aiohm_default_ai_provider = aiohm_kb_normalize_wp_ai_provider_id( $aiohm_default_ai_provider );
}
?>

<div class="wrap aiohm-settings-page">
	<h1 class="aiohm-settings-title"><?php esc_html_e( 'AIOHM Settings - Scan & Manage', 'aiohm-knowledge-assistant' ); ?></h1>
	
	<div id="aiohm-admin-notice" class="notice is-dismissible aiohm-hidden" tabindex="-1" role="alert" aria-live="polite">
		<p></p>
	</div>


	<form id="aiohm-settings-form" method="post" action="options.php">
		<?php settings_fields( 'aiohm_kb_settings' ); ?>

		<div class="aiohm-card">
			<h2><?php esc_html_e( 'Configuration & Privacy Settings', 'aiohm-knowledge-assistant' ); ?></h2>
			<p class="aiohm-section-description"><?php esc_html_e( 'Configure your primary AI provider and privacy consent preferences.', 'aiohm-knowledge-assistant' ); ?></p>
			
			<div class="aiohm-shareai-two-column-layout">
				<div class="aiohm-shareai-settings-column">
					<div class="aiohm-shareai-settings-box">
						<?php
						$aiohm_has_openai  = ! empty( $aiohm_settings['openai_api_key'] )
							|| ( function_exists( 'aiohm_kb_is_wp_ai_connector_configured' ) && aiohm_kb_is_wp_ai_connector_configured( 'openai' ) )
							|| ! empty( get_option( 'connectors_ai_openai_api_key' ) )
							|| ( defined( 'OPENAI_API_KEY' ) && OPENAI_API_KEY )
							|| getenv( 'OPENAI_API_KEY' );
						$aiohm_has_gemini  = ! empty( $aiohm_settings['gemini_api_key'] )
							|| ( function_exists( 'aiohm_kb_is_wp_ai_connector_configured' ) && aiohm_kb_is_wp_ai_connector_configured( 'gemini' ) )
							|| ! empty( get_option( 'connectors_ai_google_api_key' ) )
							|| ! empty( get_option( 'connectors_ai_gemini_api_key' ) )
							|| ( defined( 'GOOGLE_API_KEY' ) && GOOGLE_API_KEY )
							|| ( defined( 'GEMINI_API_KEY' ) && GEMINI_API_KEY )
							|| getenv( 'GOOGLE_API_KEY' )
							|| getenv( 'GEMINI_API_KEY' );
						$aiohm_has_claude  = ! empty( $aiohm_settings['claude_api_key'] )
							|| ( function_exists( 'aiohm_kb_is_wp_ai_connector_configured' ) && aiohm_kb_is_wp_ai_connector_configured( 'claude' ) )
							|| ! empty( get_option( 'connectors_ai_anthropic_api_key' ) )
							|| ! empty( get_option( 'connectors_ai_claude_api_key' ) )
							|| ( defined( 'ANTHROPIC_API_KEY' ) && ANTHROPIC_API_KEY )
							|| ( defined( 'CLAUDE_API_KEY' ) && CLAUDE_API_KEY )
							|| getenv( 'ANTHROPIC_API_KEY' )
							|| getenv( 'CLAUDE_API_KEY' );
						$aiohm_has_shareai = ! empty( $aiohm_settings['shareai_api_key'] );
						$aiohm_has_groq    = ! empty( $aiohm_settings['groq_api_key'] );

						// Check for Ollama
						$aiohm_pro_features = $aiohm_settings['pro_features'] ?? array();
						$aiohm_has_ollama   = ! empty( $aiohm_pro_features['ollama_enabled'] );

						$aiohm_has_any_api = $aiohm_has_openai || $aiohm_has_gemini || $aiohm_has_claude || $aiohm_has_shareai || $aiohm_has_groq || $aiohm_has_ollama;
						$aiohm_enabled_provider_values = array();
						foreach (
							array(
								'shareai' => $aiohm_has_shareai,
								'openai'  => $aiohm_has_openai,
								'claude'  => $aiohm_has_claude,
								'gemini'  => $aiohm_has_gemini,
								'groq'    => $aiohm_has_groq,
								'ollama'  => $aiohm_has_ollama,
							) as $aiohm_provider_value => $aiohm_provider_enabled
						) {
							if ( $aiohm_provider_enabled ) {
								$aiohm_enabled_provider_values[] = $aiohm_provider_value;
							}
						}
						if ( ! in_array( $aiohm_default_ai_provider, $aiohm_enabled_provider_values, true ) ) {
							$aiohm_default_ai_provider = '';
						}
						?>
						
						<?php if ( $aiohm_has_any_api ) : ?>
						<h3 class="aiohm-service-title">
							<?php esc_html_e( 'Default AI Provider', 'aiohm-knowledge-assistant' ); ?>
						</h3>
						
						<p class="aiohm-settings-field-description"><?php esc_html_e( 'Choose your preferred AI provider for processing requests.', 'aiohm-knowledge-assistant' ); ?></p>
						
						<button type="button" class="button button-primary aiohm-save-default-provider aiohm-hidden aiohm-save-btn-spacing"><?php esc_html_e( 'Save Selection', 'aiohm-knowledge-assistant' ); ?></button>
						
						<div class="shareai-setting-row">
							
							<!-- All AI Providers (6-column grid) -->
							<div class="aiohm-provider-selection aiohm-provider-grid">
								<label class="aiohm-provider-option <?php echo ! $aiohm_has_shareai ? 'disabled' : ''; ?>" data-provider="shareai">
									<input type="radio" name="aiohm_kb_settings[default_ai_provider]" value="shareai" <?php checked( $aiohm_default_ai_provider, 'shareai' ); ?> <?php echo ! $aiohm_has_shareai ? 'disabled' : ''; ?>>
									<div class="aiohm-provider-icon">
										<img src="<?php echo esc_url( AIOHM_KB_PLUGIN_URL . 'assets/images/shareai-icon.jpeg' ); ?>" alt="ShareAI">
									</div>
									<div class="aiohm-provider-info">
										<div class="aiohm-provider-name">ShareAI</div>
										<div class="aiohm-provider-description"><?php echo $aiohm_has_shareai ? 'Affordable AI' : 'API Key Required'; ?></div>
									</div>
									<?php if ( ! $aiohm_has_shareai ) : ?>
									<div class="aiohm-provider-overlay">🔒</div>
									<?php endif; ?>
								</label>
								
								<label class="aiohm-provider-option <?php echo ! $aiohm_has_openai ? 'disabled' : ''; ?>" data-provider="openai">
									<input type="radio" name="aiohm_kb_settings[default_ai_provider]" value="openai" <?php checked( $aiohm_default_ai_provider, 'openai' ); ?> <?php echo ! $aiohm_has_openai ? 'disabled' : ''; ?>>
									<div class="aiohm-provider-icon">
										<img src="<?php echo esc_url( AIOHM_KB_PLUGIN_URL . 'assets/images/openai-icon.svg' ); ?>" alt="OpenAI">
									</div>
									<div class="aiohm-provider-info">
										<div class="aiohm-provider-name">OpenAI</div>
										<div class="aiohm-provider-description"><?php echo $aiohm_has_openai ? 'GPT-4, GPT-3.5' : 'API Key Required'; ?></div>
									</div>
									<?php if ( ! $aiohm_has_openai ) : ?>
									<div class="aiohm-provider-overlay">🔒</div>
									<?php endif; ?>
								</label>
								
								<label class="aiohm-provider-option <?php echo ! $aiohm_has_claude ? 'disabled' : ''; ?>" data-provider="anthropic">
									<input type="radio" name="aiohm_kb_settings[default_ai_provider]" value="claude" <?php checked( $aiohm_default_ai_provider, 'claude' ); ?> <?php echo ! $aiohm_has_claude ? 'disabled' : ''; ?>>
									<div class="aiohm-provider-icon">
										<img src="<?php echo esc_url( AIOHM_KB_PLUGIN_URL . 'assets/images/claude-icon.svg' ); ?>" alt="Anthropic Claude">
									</div>
									<div class="aiohm-provider-info">
										<div class="aiohm-provider-name">Claude</div>
										<div class="aiohm-provider-description"><?php echo $aiohm_has_claude ? 'Anthropic AI' : 'API Key Required'; ?></div>
									</div>
									<?php if ( ! $aiohm_has_claude ) : ?>
									<div class="aiohm-provider-overlay">🔒</div>
									<?php endif; ?>
								</label>
								
								<label class="aiohm-provider-option <?php echo ! $aiohm_has_gemini ? 'disabled' : ''; ?>" data-provider="google">
									<input type="radio" name="aiohm_kb_settings[default_ai_provider]" value="gemini" <?php checked( $aiohm_default_ai_provider, 'gemini' ); ?> <?php echo ! $aiohm_has_gemini ? 'disabled' : ''; ?>>
									<div class="aiohm-provider-icon">
										<img src="<?php echo esc_url( AIOHM_KB_PLUGIN_URL . 'assets/images/gemini-icon.svg' ); ?>" alt="Google Gemini">
									</div>
									<div class="aiohm-provider-info">
										<div class="aiohm-provider-name">Gemini</div>
										<div class="aiohm-provider-description"><?php echo $aiohm_has_gemini ? 'Google AI' : 'API Key Required'; ?></div>
									</div>
									<?php if ( ! $aiohm_has_gemini ) : ?>
									<div class="aiohm-provider-overlay">🔒</div>
									<?php endif; ?>
								</label>

								<label class="aiohm-provider-option <?php echo ! $aiohm_has_groq ? 'disabled' : ''; ?>" data-provider="groq">
									<input type="radio" name="aiohm_kb_settings[default_ai_provider]" value="groq" <?php checked( $aiohm_default_ai_provider, 'groq' ); ?> <?php echo ! $aiohm_has_groq ? 'disabled' : ''; ?>>
									<div class="aiohm-provider-icon">
										<img src="<?php echo esc_url( AIOHM_KB_PLUGIN_URL . 'assets/images/groq-icon.svg' ); ?>" alt="Groq">
									</div>
									<div class="aiohm-provider-info">
										<div class="aiohm-provider-name">Groq</div>
										<div class="aiohm-provider-description"><?php echo $aiohm_has_groq ? 'Ultra-Fast AI' : 'API Key Required'; ?></div>
									</div>
									<?php if ( ! $aiohm_has_groq ) : ?>
									<div class="aiohm-provider-overlay">🔒</div>
									<?php endif; ?>
								</label>

								<label class="aiohm-provider-option <?php echo ! $aiohm_has_ollama ? 'disabled' : ''; ?>" data-provider="ollama">
									<input type="radio" name="aiohm_kb_settings[default_ai_provider]" value="ollama" <?php checked( $aiohm_default_ai_provider, 'ollama' ); ?> <?php echo ! $aiohm_has_ollama ? 'disabled' : ''; ?>>
									<div class="aiohm-provider-icon">
										<img src="<?php echo esc_url( AIOHM_KB_PLUGIN_URL . 'assets/images/ollama-icon.png' ); ?>" alt="Ollama">
									</div>
									<div class="aiohm-provider-info">
										<div class="aiohm-provider-name">Ollama</div>
										<div class="aiohm-provider-description"><?php echo $aiohm_has_ollama ? 'Local AI' : 'Pro Feature'; ?></div>
									</div>
									<?php if ( ! $aiohm_has_ollama ) : ?>
									<div class="aiohm-provider-overlay">🔒</div>
									<?php endif; ?>
								</label>

							</div>
						</div>
						<?php else : ?>
						<div class="aiohm-notice-box">
							<h3><?php esc_html_e( 'No AI Providers Configured', 'aiohm-knowledge-assistant' ); ?></h3>
							<p><?php esc_html_e( 'Please configure at least one AI provider below to select a default service.', 'aiohm-knowledge-assistant' ); ?></p>
						</div>
						<?php endif; ?>
					</div>
				</div>
				
				<!-- Right Column: Privacy & Consent -->
				<div class="aiohm-shareai-info-column">
					<div class="aiohm-model-info-box aiohm-wp-connectors-quick-card">
						<h3 class="aiohm-service-title">
							<span class="dashicons dashicons-admin-links"></span>
							<?php esc_html_e( 'WordPress 7.0 Connectors', 'aiohm-knowledge-assistant' ); ?>
						</h3>
						<a href="<?php echo esc_url( admin_url( 'options-connectors.php' ) ); ?>" class="button button-secondary aiohm-wp-connectors-button">
							<?php esc_html_e( 'Open Connectors', 'aiohm-knowledge-assistant' ); ?>
						</a>
					</div>

					<div class="aiohm-model-info-box">
						<h3 class="aiohm-service-title">
							🔒 <?php esc_html_e( 'Privacy & Consent', 'aiohm-knowledge-assistant' ); ?>
						</h3>
						
						<div class="shareai-setting-row">
							<label for="external_api_consent">
								<input type="checkbox" id="external_api_consent" name="aiohm_kb_settings[external_api_consent]" value="1" <?php checked( $aiohm_settings['external_api_consent'] ?? false ); ?> />
								<span>
									<strong><?php esc_html_e( 'I consent to making external API calls to AI providers', 'aiohm-knowledge-assistant' ); ?></strong>
								</span>
							</label>
						</div>
						
						<div class="shareai-setting-row">
							<button type="button" class="button aiohm-shareai-btn aiohm-save-privacy-consent"><?php esc_html_e( 'Save Settings', 'aiohm-knowledge-assistant' ); ?></button>
						</div>
						
						<div class="aiohm-privacy-notice">
							<p><strong><?php esc_html_e( 'Data Processing Notice:', 'aiohm-knowledge-assistant' ); ?></strong></p>
							<p><?php esc_html_e( 'This plugin makes API calls to external AI services (OpenAI, Google Gemini, Claude, ShareAI, etc.) to process your content and provide AI responses.', 'aiohm-knowledge-assistant' ); ?></p>
						</div>
					</div>
				</div>
			</div>
		</div>

		<!-- Free AI Services Section -->
		<div class="aiohm-card">
			<h2><?php esc_html_e( 'Free AI Services & Connectors', 'aiohm-knowledge-assistant' ); ?></h2>
			<p class="aiohm-section-description"><?php esc_html_e( 'Get started with AI for free using these services and connectors. Perfect for testing and development.', 'aiohm-knowledge-assistant' ); ?></p>
			
			<!-- ShareAI Configuration -->
			<h3 class="aiohm-service-title">
				<img src="<?php echo esc_url( AIOHM_KB_PLUGIN_URL . 'assets/images/shareai-icon.jpeg' ); ?>" alt="ShareAI" class="aiohm-service-icon" />
				<?php esc_html_e( 'ShareAI', 'aiohm-knowledge-assistant' ); ?>
			</h3>
			<div class="aiohm-shareai-two-column-layout">
							<!-- Left Column: Settings -->
							<div class="aiohm-shareai-settings-column">
								<div class="aiohm-shareai-settings-box">
									<div class="shareai-setting-row">
										<label for="shareai_api_key" class="shareai-inline-label"><?php esc_html_e( 'API Key:', 'aiohm-knowledge-assistant' ); ?></label>
										<div class="aiohm-api-key-wrapper">
											<div class="aiohm-key-input-group">
												<input type="password" id="shareai_api_key" name="aiohm_kb_settings[shareai_api_key]" value="<?php echo esc_attr( $aiohm_settings['shareai_api_key'] ?? '' ); ?>" placeholder="Enter your ShareAI API key" class="regular-text">
												<button type="button" class="aiohm-show-hide-key" data-target="shareai_api_key"><span class="dashicons dashicons-visibility"></span></button>
											</div>
											<div class="aiohm-api-key-actions">
												<button type="button" class="button aiohm-shareai-btn aiohm-test-api-key" data-target="shareai_api_key" data-type="shareai"><?php esc_html_e( 'Test API & Usage', 'aiohm-knowledge-assistant' ); ?></button>
												<button type="button" class="button aiohm-shareai-btn aiohm-save-api-key" data-target="shareai_api_key" data-type="shareai"><?php esc_html_e( 'Save', 'aiohm-knowledge-assistant' ); ?></button>
											</div>
										</div>
									</div>
									
									<div class="shareai-setting-row">
										<label for="shareai_model" class="shareai-inline-label"><?php esc_html_e( 'Model:', 'aiohm-knowledge-assistant' ); ?></label>
										<select id="shareai_model" name="aiohm_kb_settings[shareai_model]" class="regular-text">
											<optgroup label="Command Models">
												<option value="deepseek-r1:7b" <?php selected( $aiohm_settings['shareai_model'] ?? '', 'deepseek-r1:7b' ); ?>>DeepSeek R1 7B</option>
												<option value="deepseek-r1:14b" <?php selected( $aiohm_settings['shareai_model'] ?? '', 'deepseek-r1:14b' ); ?>>DeepSeek R1 14B</option>
												<option value="deepseek-r1:32b" <?php selected( $aiohm_settings['shareai_model'] ?? '', 'deepseek-r1:32b' ); ?>>DeepSeek R1 32B</option>
												<option value="deepseek-r1:671b" <?php selected( $aiohm_settings['shareai_model'] ?? '', 'deepseek-r1:671b' ); ?>>DeepSeek R1 671B</option>
												<option value="llama4:17b-scout-16e-instruct-fp16" <?php selected( $aiohm_settings['shareai_model'] ?? 'llama4:17b-scout-16e-instruct-fp16', 'llama4:17b-scout-16e-instruct-fp16' ); ?>>Llama 4 17B Scout (Recommended)</option>
												<option value="llama4:90b-instruct-fp16" <?php selected( $aiohm_settings['shareai_model'] ?? '', 'llama4:90b-instruct-fp16' ); ?>>Llama 4 90B Instruct</option>
												<option value="qwen2.5:0.5b" <?php selected( $aiohm_settings['shareai_model'] ?? '', 'qwen2.5:0.5b' ); ?>>Qwen 2.5 0.5B</option>
												<option value="qwen2.5:1.5b" <?php selected( $aiohm_settings['shareai_model'] ?? '', 'qwen2.5:1.5b' ); ?>>Qwen 2.5 1.5B</option>
												<option value="qwen2.5:3b" <?php selected( $aiohm_settings['shareai_model'] ?? '', 'qwen2.5:3b' ); ?>>Qwen 2.5 3B</option>
												<option value="qwen2.5:7b" <?php selected( $aiohm_settings['shareai_model'] ?? '', 'qwen2.5:7b' ); ?>>Qwen 2.5 7B</option>
												<option value="qwen2.5:14b" <?php selected( $aiohm_settings['shareai_model'] ?? '', 'qwen2.5:14b' ); ?>>Qwen 2.5 14B</option>
												<option value="qwen2.5:32b" <?php selected( $aiohm_settings['shareai_model'] ?? '', 'qwen2.5:32b' ); ?>>Qwen 2.5 32B</option>
												<option value="qwen2.5:72b" <?php selected( $aiohm_settings['shareai_model'] ?? '', 'qwen2.5:72b' ); ?>>Qwen 2.5 72B</option>
											</optgroup>
											<optgroup label="Chat Models">
												<option value="llama3.2:1b" <?php selected( $aiohm_settings['shareai_model'] ?? '', 'llama3.2:1b' ); ?>>Llama 3.2 1B</option>
												<option value="llama3.2:3b" <?php selected( $aiohm_settings['shareai_model'] ?? '', 'llama3.2:3b' ); ?>>Llama 3.2 3B</option>
												<option value="llama3.3:70b" <?php selected( $aiohm_settings['shareai_model'] ?? '', 'llama3.3:70b' ); ?>>Llama 3.3 70B</option>
												<option value="qwen2.5-coder:1.5b" <?php selected( $aiohm_settings['shareai_model'] ?? '', 'qwen2.5-coder:1.5b' ); ?>>Qwen 2.5 Coder 1.5B</option>
												<option value="qwen2.5-coder:7b" <?php selected( $aiohm_settings['shareai_model'] ?? '', 'qwen2.5-coder:7b' ); ?>>Qwen 2.5 Coder 7B</option>
												<option value="qwen2.5-coder:14b" <?php selected( $aiohm_settings['shareai_model'] ?? '', 'qwen2.5-coder:14b' ); ?>>Qwen 2.5 Coder 14B</option>
												<option value="qwen2.5-coder:32b" <?php selected( $aiohm_settings['shareai_model'] ?? '', 'qwen2.5-coder:32b' ); ?>>Qwen 2.5 Coder 32B</option>
											</optgroup>
										</select>
									</div>
									
									<div class="shareai-usage-notice">
										<p><strong><?php esc_html_e( 'Affordable AI tokens with ShareAI', 'aiohm-knowledge-assistant' ); ?></strong><br>
										<?php esc_html_e( 'ShareAI connects you to a global compute network, making AI more affordable or free if you share your device\'s idle power.', 'aiohm-knowledge-assistant' ); ?></p>
										
										<p><strong><?php esc_html_e( 'To get started:', 'aiohm-knowledge-assistant' ); ?></strong><br>
										<?php
											printf(
												wp_kses(
													// translators: %s is the URL to the ShareAI Console
													__( 'Generate your API key in the <a href="%s" target="_blank">ShareAI Console</a>, add it to AIOHM settings, and ensure your chosen model is active.', 'aiohm-knowledge-assistant' ),
													array(
														'a' => array(
															'href' => array(),
															'target' => array(),
														),
													)
												),
												esc_url( 'https://console.shareai.now/app/api-key/?utm_source=AIOHM&utm_medium=plugin&utm_campaign=shareai_integration' )
											);
											?>
											</p>
									</div>
								</div>
							</div>

							<!-- Right Column: Model Information -->
							<div class="aiohm-shareai-info-column">
								<div class="aiohm-model-info-box">
									<h4 class="aiohm-model-info-title"><?php esc_html_e( 'Model Information', 'aiohm-knowledge-assistant' ); ?></h4>
									<div id="aiohm-model-info-content" class="aiohm-model-info-content">
										<!-- Default placeholder content -->
										<div class="aiohm-model-placeholder">
											<div class="aiohm-placeholder-icon">📋</div>
											<h5><?php esc_html_e( 'Select a Model and Save', 'aiohm-knowledge-assistant' ); ?></h5>
											<p><?php esc_html_e( 'Choose your preferred ShareAI model from the dropdown and click "Save" to view detailed information about the model\'s capabilities, training, and best use cases.', 'aiohm-knowledge-assistant' ); ?></p>
											<div class="aiohm-placeholder-steps">
												<div class="aiohm-step">
													<span class="aiohm-step-number">1</span>
													<span><?php esc_html_e( 'Select model from dropdown', 'aiohm-knowledge-assistant' ); ?></span>
												</div>
												<div class="aiohm-step">
													<span class="aiohm-step-number">2</span>
													<span><?php esc_html_e( 'Click "Save" button', 'aiohm-knowledge-assistant' ); ?></span>
												</div>
												<div class="aiohm-step">
													<span class="aiohm-step-number">3</span>
													<span><?php esc_html_e( 'View model details here', 'aiohm-knowledge-assistant' ); ?></span>
												</div>
											</div>
										</div>
									</div>
								</div>
							</div>
						</div>
		</div>


		<?php $aiohm_uses_wp_connectors = function_exists( 'wp_get_connectors' ); ?>

		<div class="aiohm-premium-services-columns <?php echo $aiohm_uses_wp_connectors ? 'aiohm-single-premium-column' : ''; ?>">
			<?php if ( ! $aiohm_uses_wp_connectors ) : ?>
			<!-- Left Column: Gemini and OpenAI -->
			<div class="aiohm-premium-column">
				<!-- Gemini -->
				<h3 class="aiohm-service-title"><?php esc_html_e( 'Gemini API Key', 'aiohm-knowledge-assistant' ); ?></h3>
				<div class="aiohm-service-content">
					<div class="aiohm-api-key-wrapper">
						<div class="aiohm-key-input-group">
							<input type="password" id="gemini_api_key" name="aiohm_kb_settings[gemini_api_key]" value="<?php echo esc_attr( $aiohm_settings['gemini_api_key'] ?? '' ); ?>" class="regular-text">
							<button type="button" class="aiohm-show-hide-key" data-target="gemini_api_key"><span class="dashicons dashicons-visibility"></span></button>
						</div>
						<div class="aiohm-api-key-actions">
							<button type="button" class="button button-secondary aiohm-test-api-key" data-target="gemini_api_key" data-type="gemini"><?php esc_html_e( 'Test API', 'aiohm-knowledge-assistant' ); ?></button>
							<button type="button" class="button button-secondary aiohm-save-api-key" data-target="gemini_api_key" data-type="gemini"><?php esc_html_e( 'Save', 'aiohm-knowledge-assistant' ); ?></button>
						</div>
					</div>
					<p class="description">
					<?php
						printf(
							wp_kses(
								__( 'You can get your Gemini API key from the <a href="%s" target="_blank">Google AI Studio</a>.', 'aiohm-knowledge-assistant' ),
								array(
									'a' => array(
										'href' => array(),
										'target' => array(),
									),
								)
							),
							esc_url( 'https://aistudio.google.com/app/apikey' )
						);
					?>
					</p>
				</div>

				<!-- OpenAI -->
				<h3 class="aiohm-service-title"><?php esc_html_e( 'OpenAI API Key', 'aiohm-knowledge-assistant' ); ?></h3>
				<div class="aiohm-service-content">
					<div class="aiohm-api-key-wrapper">
						<div class="aiohm-key-input-group">
							<input type="password" id="openai_api_key" name="aiohm_kb_settings[openai_api_key]" value="<?php echo esc_attr( $aiohm_settings['openai_api_key'] ?? '' ); ?>" class="regular-text">
							<button type="button" class="aiohm-show-hide-key" data-target="openai_api_key"><span class="dashicons dashicons-visibility"></span></button>
						</div>
						<div class="aiohm-api-key-actions">
							<button type="button" class="button button-secondary aiohm-test-api-key" data-target="openai_api_key" data-type="openai"><?php esc_html_e( 'Test API', 'aiohm-knowledge-assistant' ); ?></button>
							<button type="button" class="button button-secondary aiohm-save-api-key" data-target="openai_api_key" data-type="openai"><?php esc_html_e( 'Save', 'aiohm-knowledge-assistant' ); ?></button>
						</div>
					</div>
					<p class="description">
					<?php
						printf(
							wp_kses(
								__( 'You can get your OpenAI API key from the <a href="%s" target="_blank">OpenAI API keys page</a>.', 'aiohm-knowledge-assistant' ),
								array(
									'a' => array(
										'href' => array(),
										'target' => array(),
									),
								)
							),
							esc_url( 'https://platform.openai.com/account/api-keys' )
						);
					?>
					</p>
				</div>
			</div>
			<?php endif; ?>

			<!-- Right Column: Claude and Groq -->
			<div class="aiohm-premium-column">
				<?php if ( ! $aiohm_uses_wp_connectors ) : ?>
				<!-- Claude -->
				<h3 class="aiohm-service-title"><?php esc_html_e( 'Claude API Key', 'aiohm-knowledge-assistant' ); ?></h3>
				<div class="aiohm-service-content">
					<div class="aiohm-api-key-wrapper">
						<div class="aiohm-key-input-group">
							<input type="password" id="claude_api_key" name="aiohm_kb_settings[claude_api_key]" value="<?php echo esc_attr( $aiohm_settings['claude_api_key'] ?? '' ); ?>" class="regular-text">
							<button type="button" class="aiohm-show-hide-key" data-target="claude_api_key"><span class="dashicons dashicons-visibility"></span></button>
						</div>
						<div class="aiohm-api-key-actions">
							<button type="button" class="button button-secondary aiohm-test-api-key" data-target="claude_api_key" data-type="claude"><?php esc_html_e( 'Test API', 'aiohm-knowledge-assistant' ); ?></button>
							<button type="button" class="button button-secondary aiohm-save-api-key" data-target="claude_api_key" data-type="claude"><?php esc_html_e( 'Save', 'aiohm-knowledge-assistant' ); ?></button>
						</div>
					</div>
					<p class="description">
					<?php
						printf(
							wp_kses(
								__( 'You can get your Claude API key from your <a href="%s" target="_blank">Anthropic Account Settings</a>.', 'aiohm-knowledge-assistant' ),
								array(
									'a' => array(
										'href' => array(),
										'target' => array(),
									),
								)
							),
							esc_url( 'https://console.anthropic.com/account/keys' )
						);
					?>
					</p>
				</div>
				<?php endif; ?>

				
        <div class="aiohm-card">
            <h3 class="aiohm-service-title">
                <img src="<?php echo esc_url( AIOHM_KB_PLUGIN_URL . 'assets/images/groq-icon.svg' ); ?>" alt="Groq" class="aiohm-service-icon" />
                <?php esc_html_e( 'Groq', 'aiohm-knowledge-assistant' ); ?>
            </h3>

            <div class="aiohm-shareai-two-column-layout">
                <div class="aiohm-shareai-settings-column">
                    <div class="aiohm-shareai-settings-box">
                        <div class="shareai-setting-row">
                            <label for="groq_api_key" class="shareai-inline-label"><?php esc_html_e( 'API Key:', 'aiohm-knowledge-assistant' ); ?></label>
                            <div class="aiohm-api-key-wrapper">
                                <div class="aiohm-key-input-group">
                                    <input type="password" id="groq_api_key" name="aiohm_kb_settings[groq_api_key]" value="<?php echo esc_attr( $aiohm_settings['groq_api_key'] ?? '' ); ?>" placeholder="Enter your Groq API key" class="regular-text">
                                    <button type="button" class="aiohm-show-hide-key" data-target="groq_api_key"><span class="dashicons dashicons-visibility"></span></button>
                                </div>
                                <div class="aiohm-api-key-actions">
                                    <button type="button" class="button aiohm-shareai-btn aiohm-test-api-key" data-target="groq_api_key" data-type="groq"><?php esc_html_e( 'Test API', 'aiohm-knowledge-assistant' ); ?></button>
                                    <button type="button" class="button aiohm-shareai-btn aiohm-save-api-key" data-target="groq_api_key" data-type="groq"><?php esc_html_e( 'Save', 'aiohm-knowledge-assistant' ); ?></button>
                                </div>
                            </div>
                        </div>

                        <div class="shareai-setting-row">
                            <label for="groq_model" class="shareai-inline-label"><?php esc_html_e( 'Model:', 'aiohm-knowledge-assistant' ); ?></label>
                            <select id="groq_model" name="aiohm_kb_settings[groq_model]" class="regular-text">
                                <option value="groq-default" <?php selected( $aiohm_settings['groq_model'] ?? '', 'groq-default' ); ?>>Groq Default (Recommended)</option>
                                <option value="groq-small" <?php selected( $aiohm_settings['groq_model'] ?? '', 'groq-small' ); ?>>Groq Small</option>
                                <option value="groq-medium" <?php selected( $aiohm_settings['groq_model'] ?? '', 'groq-medium' ); ?>>Groq Medium</option>
                                <option value="groq-large" <?php selected( $aiohm_settings['groq_model'] ?? '', 'groq-large' ); ?>>Groq Large</option>
                            </select>
                        </div>

                        <div class="shareai-usage-notice">
                            <p><strong><?php esc_html_e( 'Groq API Access', 'aiohm-knowledge-assistant' ); ?></strong><br>
                            <?php esc_html_e( 'Use your Groq API key for fast, low-latency model inference in AIOHM.', 'aiohm-knowledge-assistant' ); ?></p>

                            <p><strong><?php esc_html_e( 'To get started:', 'aiohm-knowledge-assistant' ); ?></strong><br>
                            <?php
                                printf(
                                    wp_kses(
                                        /* translators: %s is the URL to the Groq Console */
                                        __( 'Generate your API key in the <a href="%s" target="_blank">Groq Console</a>, add it to AIOHM settings, and ensure your chosen model is active.', 'aiohm-knowledge-assistant' ),
                                        array(
                                            'a' => array(
                                                'href' => array(),
                                                'target' => array(),
                                            ),
                                        )
                                    ),
                                    esc_url( 'https://console.groq.com/keys' )
                                );
                            ?></p>
                        </div>
                    </div>
                </div>

                <div class="aiohm-shareai-info-column">
                    <div class="aiohm-model-info-box">
                        <h4 class="aiohm-model-info-title"><?php esc_html_e( 'Groq Model Information', 'aiohm-knowledge-assistant' ); ?></h4>
                        <div class="aiohm-model-info-content">
                            <div class="aiohm-model-placeholder">
                                <div class="aiohm-placeholder-icon">⚡</div>
                                <h5><?php esc_html_e( 'Save your Groq API key first', 'aiohm-knowledge-assistant' ); ?></h5>
                                <p><?php esc_html_e( 'Enter and save your Groq key to access model details and performance recommendations.', 'aiohm-knowledge-assistant' ); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="aiohm-card">
            <h3 class="aiohm-service-title">
                <img src="<?php echo esc_url( AIOHM_KB_PLUGIN_URL . 'assets/images/ollama-icon.png' ); ?>" alt="Ollama" class="aiohm-service-icon" />
                <?php esc_html_e( 'Ollama', 'aiohm-knowledge-assistant' ); ?>
            </h3>
            <div class="aiohm-shareai-two-column-layout">
                <div class="aiohm-shareai-settings-column">
                    <div class="aiohm-shareai-settings-box">
                        <div class="shareai-setting-row">
                            <label for="private_llm_server_url" class="shareai-inline-label"><?php esc_html_e( 'Ollama Server URL:', 'aiohm-knowledge-assistant' ); ?></label>
                            <div class="aiohm-api-key-wrapper">
                                <input type="text" id="private_llm_server_url" name="aiohm_kb_settings[pro_features][ollama_server_url]" value="<?php echo esc_attr( $aiohm_settings['pro_features']['ollama_server_url'] ?? 'http://localhost:11434' ); ?>" placeholder="http://localhost:11434" class="regular-text">
                                <div class="aiohm-api-key-actions">
                                    <button type="button" class="button aiohm-shareai-btn aiohm-detect-ollama"><?php esc_html_e( 'Detect Local', 'aiohm-knowledge-assistant' ); ?></button>
                                    <button type="button" class="button aiohm-shareai-btn aiohm-test-api-key" data-type="ollama"><?php esc_html_e( 'Test Connection', 'aiohm-knowledge-assistant' ); ?></button>
                                    <button type="button" class="button aiohm-shareai-btn aiohm-save-api-key" data-target="private_llm_server_url" data-type="ollama"><?php esc_html_e( 'Save', 'aiohm-knowledge-assistant' ); ?></button>
                                </div>
                            </div>
                        </div>

                        <div class="shareai-setting-row">
                            <label for="private_llm_model" class="shareai-inline-label"><?php esc_html_e( 'Model:', 'aiohm-knowledge-assistant' ); ?></label>
                            <select id="private_llm_model" name="aiohm_kb_settings[pro_features][ollama_model]" class="regular-text">
                                <option value="llama3.2:latest" <?php selected( $aiohm_settings['pro_features']['ollama_model'] ?? '', 'llama3.2:latest' ); ?>>Llama 3.2 (Latest)</option>
                                <option value="llama3.2:3b" <?php selected( $aiohm_settings['pro_features']['ollama_model'] ?? '', 'llama3.2:3b' ); ?>>Llama 3.2 3B</option>
                                <option value="llama3.2:1b" <?php selected( $aiohm_settings['pro_features']['ollama_model'] ?? '', 'llama3.2:1b' ); ?>>Llama 3.2 1B</option>
                                <option value="mistral:latest" <?php selected( $aiohm_settings['pro_features']['ollama_model'] ?? '', 'mistral:latest' ); ?>>Mistral (Latest)</option>
                                <option value="codellama:latest" <?php selected( $aiohm_settings['pro_features']['ollama_model'] ?? '', 'codellama:latest' ); ?>>Code Llama</option>
                            </select>
                        </div>
                        <div id="ollama-model-notice" class="aiohm-model-notice"></div>

                        <div class="shareai-usage-notice">
                            <p><strong><?php esc_html_e( 'Ollama Local Access', 'aiohm-knowledge-assistant' ); ?></strong><br>
                            <?php esc_html_e( 'No cloud API key is required for Ollama. AIOHM can connect directly to a local Ollama server when it is installed and running.', 'aiohm-knowledge-assistant' ); ?></p>
                            <p><strong><?php esc_html_e( 'To get started:', 'aiohm-knowledge-assistant' ); ?></strong><br>
                            <?php
                                printf(
                                    wp_kses(
                                        /* translators: %s is the URL to the Ollama website */
                                        __( 'If Ollama is installed locally, run it and use the URL <code>http://localhost:11434</code>, then click <strong>Detect Local</strong> or test the connection. Learn more at <a href="%s" target="_blank">Ollama</a>.', 'aiohm-knowledge-assistant' ),
                                        array(
                                            'code' => array(),
                                            'strong' => array(),
                                            'a' => array(
                                                'href' => array(),
                                                'target' => array(),
                                            ),
                                        )
                                    ),
                                    esc_url( 'https://ollama.ai/' )
                                );
                            ?></p>
                        </div>
                    </div>
                </div>

                <div class="aiohm-shareai-info-column">
                    <div class="aiohm-model-info-box">
                        <h4 class="aiohm-model-info-title"><?php esc_html_e( 'Ollama Model Information', 'aiohm-knowledge-assistant' ); ?></h4>
                        <div class="aiohm-model-info-content">
                            <div class="aiohm-model-placeholder">
                                <div class="aiohm-placeholder-icon">🐙</div>
                                <h5><?php esc_html_e( 'Save your Ollama API key first', 'aiohm-knowledge-assistant' ); ?></h5>
                                <p><?php esc_html_e( 'Enter and save your Ollama key to access model details and environment recommendations.', 'aiohm-knowledge-assistant' ); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
<!-- Plugin Features Section -->
		<div class="aiohm-card">
			<h2><?php esc_html_e( 'Plugin Features & Shortcodes', 'aiohm-knowledge-assistant' ); ?></h2>
			<p class="aiohm-section-description"><?php esc_html_e( 'Enable and configure shortcodes for your WordPress site.', 'aiohm-knowledge-assistant' ); ?></p>
			
			<div class="aiohm-shareai-two-column-layout">
				<!-- Left Column: Search Shortcode Features -->
				<div class="aiohm-shareai-settings-column">
					<div class="aiohm-shareai-settings-box">
						<h3 class="aiohm-service-title">
							<?php esc_html_e( 'Search Shortcode Features', 'aiohm-knowledge-assistant' ); ?>
						</h3>
						<p class="aiohm-section-description"><?php esc_html_e( 'Available to all users without membership requirements.', 'aiohm-knowledge-assistant' ); ?></p>
						
						<table class="form-table">
							<tr>
								<th scope="row"><?php esc_html_e( 'Enable Search Shortcode', 'aiohm-knowledge-assistant' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="aiohm_kb_settings[enable_search_shortcode]" value="1" <?php checked( $aiohm_settings['enable_search_shortcode'] ?? false ); ?> />
										<?php
										// translators: %s is the shortcode for knowledge base search
										printf( esc_html__( 'Enable the %s shortcode for knowledge base search.', 'aiohm-knowledge-assistant' ), '<code>[aiohm_search]</code>' );
										?>
									</label>
								</td>
							</tr>
							
							<tr>
								<th scope="row"><?php esc_html_e( 'Enable Q&A Generator Shortcode', 'aiohm-knowledge-assistant' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="aiohm_kb_settings[enable_qa_shortcode]" value="1" <?php checked( $aiohm_settings['enable_qa_shortcode'] ?? false ); ?> />
										<?php
										// translators: %s is the shortcode for Q&A generator
										printf( esc_html__( 'Enable the %s shortcode for Q&A generation from your knowledge base.', 'aiohm-knowledge-assistant' ), '<code>[aiohm_qa_generator]</code>' );
										?>
									</label>
								</td>
							</tr>
						</table>
					</div>
				</div>

				<!-- Right Column: Chatbot Shortcode Features -->
				<div class="aiohm-shareai-settings-column">
					<div class="aiohm-shareai-settings-box">
						<h3 class="aiohm-service-title">
							<?php esc_html_e( 'Chatbot Shortcode Features', 'aiohm-knowledge-assistant' ); ?>
						</h3>
						<p class="aiohm-section-description"><?php esc_html_e( 'Additional shortcode features for your WordPress site.', 'aiohm-knowledge-assistant' ); ?></p>
						
						<table class="form-table">
							<tr>
								<th scope="row"><?php esc_html_e( 'Enable Q&A Chatbot', 'aiohm-knowledge-assistant' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="aiohm_kb_settings[chat_enabled]" value="1" <?php checked( $aiohm_settings['chat_enabled'] ?? false ); ?> />
										<?php
										// translators: %s is the shortcode for the Q&A chatbot
										printf( esc_html__( 'Enable the %s shortcode.', 'aiohm-knowledge-assistant' ), '<code>[aiohm_chat]</code>' );
										?>
									</label>
									<p class="description"><?php esc_html_e( 'Public-facing chatbot for website visitors.', 'aiohm-knowledge-assistant' ); ?></p>
								</td>
							</tr>
							
							<tr>
								<th scope="row"><?php esc_html_e( 'Enable Private Assistant', 'aiohm-knowledge-assistant' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="aiohm_kb_settings[enable_private_assistant]" value="1" <?php checked( $aiohm_settings['enable_private_assistant'] ?? false ); ?> />
										<?php
										// translators: %s is the shortcode for the private brand assistant
										printf( esc_html__( 'Enable the %s shortcode.', 'aiohm-knowledge-assistant' ), '<code>[aiohm_private_assistant]</code>' );
										?>
									</label>
									<p class="description"><?php esc_html_e( 'Private AI assistant with private knowledge base.', 'aiohm-knowledge-assistant' ); ?></p>
								</td>
							</tr>
						</table>
					</div>
				</div>
			</div>
		</div>

		<!-- Plugin Data Management -->
		<div class="aiohm-card">
			<h2><?php esc_html_e( 'Plugin Data Management', 'aiohm-knowledge-assistant' ); ?></h2>
			<p class="aiohm-section-description"><?php esc_html_e( 'Control how your plugin data is handled during uninstallation.', 'aiohm-knowledge-assistant' ); ?></p>
			
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Uninstall Behavior', 'aiohm-knowledge-assistant' ); ?></th>
					<td>
						<fieldset>
							<label for="delete_data_on_uninstall">
								<input type="checkbox" id="delete_data_on_uninstall" name="aiohm_kb_settings[delete_data_on_uninstall]" value="1" <?php checked( $aiohm_settings['delete_data_on_uninstall'] ?? false ); ?>>
								<?php esc_html_e( 'Delete all plugin data when uninstalling', 'aiohm-knowledge-assistant' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'When checked, all plugin data will be permanently deleted when you uninstall the plugin. This includes:', 'aiohm-knowledge-assistant' ); ?>
								<br>• <?php esc_html_e( 'All API keys and settings', 'aiohm-knowledge-assistant' ); ?>
								<br>• <?php esc_html_e( 'Knowledge base entries and vector embeddings', 'aiohm-knowledge-assistant' ); ?>
								<br>• <?php esc_html_e( 'Chat conversations and messages', 'aiohm-knowledge-assistant' ); ?>
								<br>• <?php esc_html_e( 'Project data and custom database tables', 'aiohm-knowledge-assistant' ); ?>
								<br><strong><?php esc_html_e( 'Warning: This action cannot be undone!', 'aiohm-knowledge-assistant' ); ?></strong>
							</p>
						</fieldset>
					</td>
				</tr>
			</table>
		</div>
		
		<?php submit_button( 'Save All Settings' ); ?>
	</form>
</div>
