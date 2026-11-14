<?php
/**
 * Admin Pro Features template
 * Advanced settings for Ollama integration and MCP (Model Context Protocol) settings
 * Free and open source since 3.0.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$aiohm_settings = get_option( 'aiohm_kb_settings', array() );
$aiohm_user_id = get_current_user_id();

// Get default settings from main plugin file
$aiohm_main_defaults = AIOHM_KB_Assistant::get_settings();
$aiohm_pro_defaults = array(
	'ollama_server_url' => 'http://localhost:11434',
	'ollama_model' => 'llama3.2:latest',
	'ollama_enabled' => true, // Enable Ollama by default
	'mcp_enabled' => false,
	'mcp_server_url' => 'http://localhost:3000',
	'mcp_api_key' => '',
	'mcp_timeout' => 30,
	'mcp_rate_limit' => 1000,
	'mcp_require_https' => true,
	'private_hub_enabled' => true,
	'private_hub_name' => get_bloginfo('name') . ' Knowledge Hub',
);
if ( isset( $aiohm_main_defaults['pro_features'] ) && is_array( $aiohm_main_defaults['pro_features'] ) ) {
	$aiohm_pro_defaults = wp_parse_args( $aiohm_main_defaults['pro_features'], $aiohm_pro_defaults );
}

$aiohm_pro_settings = isset($aiohm_settings['pro_features']) ? wp_parse_args($aiohm_settings['pro_features'], $aiohm_pro_defaults) : $aiohm_pro_defaults;

// Legacy MCP settings fallback
$aiohm_mcp_enabled = !empty($aiohm_settings['mcp_enabled']) || !empty($aiohm_pro_settings['mcp_enabled']);
$aiohm_mcp_rate_limit = $aiohm_settings['mcp_rate_limit'] ?? $aiohm_pro_settings['mcp_rate_limit'];
$aiohm_mcp_require_https = $aiohm_settings['mcp_require_https'] ?? $aiohm_pro_settings['mcp_require_https'];
?>

<div class="wrap aiohm-settings-page">
	<h1><?php esc_html_e( 'Pro Features - Private Knowledge Hub', 'aiohm-knowledge-assistant' ); ?></h1>
	<p class="page-description"><?php esc_html_e( 'Advanced settings for local AI processing, MCP integration, and private knowledge hub functionality.', 'aiohm-knowledge-assistant' ); ?></p>

	<div id="aiohm-admin-notice" class="notice is-dismissible aiohm-hidden" tabindex="-1" role="alert" aria-live="polite">
		<p></p>
	</div>

	<form id="aiohm-pro-features-form" method="post" action="">
		<?php wp_nonce_field( 'aiohm_pro_features_nonce', 'aiohm_admin_pro_nonce' ); ?>

		<div class="aiohm-two-column-layout">
			<!-- Left Column -->
			<div class="aiohm-column aiohm-column-left">
				
				<!-- Private Knowledge Hub Settings -->
				<div class="aiohm-card">
					<h2><?php esc_html_e( 'Private Knowledge Hub', 'aiohm-knowledge-assistant' ); ?></h2>
					<p class="aiohm-section-description"><?php esc_html_e( 'Configure your private brand knowledge hub for enhanced privacy and control.', 'aiohm-knowledge-assistant' ); ?></p>

					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="private_hub_enabled"><?php esc_html_e( 'Enable Private Hub', 'aiohm-knowledge-assistant' ); ?></label>
							</th>
							<td>
								<label class="aiohm-switch">
									<input type="checkbox"
										   id="private_hub_enabled"
										   name="aiohm_kb_settings[pro_features][private_hub_enabled]"
										   value="1"
										   <?php checked( $aiohm_pro_settings['private_hub_enabled'] ); ?>>
									<span class="aiohm-slider"></span>
								</label>
								<p class="description"><?php esc_html_e( 'Enable private knowledge hub mode for enhanced data privacy and control.', 'aiohm-knowledge-assistant' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="private_hub_name"><?php esc_html_e( 'Hub Name', 'aiohm-knowledge-assistant' ); ?></label>
							</th>
							<td>
								<input type="text"
									   id="private_hub_name"
									   name="aiohm_kb_settings[pro_features][private_hub_name]"
									   value="<?php echo esc_attr( $aiohm_pro_settings['private_hub_name'] ); ?>"
									   class="regular-text">
								<p class="description"><?php esc_html_e( 'Name for your private knowledge hub (e.g., "Acme Corp Knowledge Hub").', 'aiohm-knowledge-assistant' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<!-- Ollama Local AI Settings -->
				<div class="aiohm-card">
					<h2><?php esc_html_e( 'Ollama Local AI Integration', 'aiohm-knowledge-assistant' ); ?></h2>
					<p class="aiohm-section-description"><?php esc_html_e( 'Connect to your local Ollama instance for completely private AI processing. No data leaves your server.', 'aiohm-knowledge-assistant' ); ?></p>

					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="ollama_enabled"><?php esc_html_e( 'Enable Ollama', 'aiohm-knowledge-assistant' ); ?></label>
							</th>
							<td>
								<label class="aiohm-switch">
									<input type="checkbox"
										   id="ollama_enabled"
										   name="aiohm_kb_settings[pro_features][ollama_enabled]"
										   value="1"
										   <?php checked( $aiohm_pro_settings['ollama_enabled'] ); ?>>
									<span class="aiohm-slider"></span>
								</label>
								<p class="description"><?php esc_html_e( 'Enable Ollama for local AI processing. Requires Ollama to be installed and running.', 'aiohm-knowledge-assistant' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="ollama_server_url"><?php esc_html_e( 'Ollama Server URL', 'aiohm-knowledge-assistant' ); ?></label>
							</th>
							<td>
								<div class="ollama-server-input-group">
									<input type="url"
										   id="ollama_server_url"
										   name="aiohm_kb_settings[pro_features][ollama_server_url]"
										   value="<?php echo esc_attr( $aiohm_pro_settings['ollama_server_url'] ); ?>"
										   class="regular-text"
										   placeholder="http://localhost:11434">
									<div class="ollama-preset-buttons">
										<button type="button" class="button button-small preset-url" data-url="http://localhost:11434"><?php esc_html_e( 'Local', 'aiohm-knowledge-assistant' ); ?></button>
										<button type="button" class="button button-small preset-url" data-url="https://ollama.servbay.host"><?php esc_html_e( 'ServBay', 'aiohm-knowledge-assistant' ); ?></button>
									</div>
								</div>
								<p class="description">
									<?php esc_html_e( 'URL of your Ollama server. Use preset buttons for quick setup:', 'aiohm-knowledge-assistant' ); ?>
									<br><strong><?php esc_html_e( 'Local:', 'aiohm-knowledge-assistant' ); ?></strong> <?php esc_html_e( 'Default localhost installation', 'aiohm-knowledge-assistant' ); ?>
									<br><strong><?php esc_html_e( 'ServBay:', 'aiohm-knowledge-assistant' ); ?></strong> <?php esc_html_e( 'For ServBay environment users (https://ollama.servbay.host)', 'aiohm-knowledge-assistant' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="ollama_model"><?php esc_html_e( 'Ollama Model', 'aiohm-knowledge-assistant' ); ?></label>
							</th>
							<td>
								<select id="ollama_model"
										name="aiohm_kb_settings[pro_features][ollama_model]"
										class="regular-text">
									<option value="llama3.2:latest" <?php selected( $aiohm_pro_settings['ollama_model'], 'llama3.2:latest' ); ?>>Llama 3.2 (Latest)</option>
									<option value="llama3.2:3b" <?php selected( $aiohm_pro_settings['ollama_model'], 'llama3.2:3b' ); ?>>Llama 3.2 3B</option>
									<option value="llama3.2:1b" <?php selected( $aiohm_pro_settings['ollama_model'], 'llama3.2:1b' ); ?>>Llama 3.2 1B</option>
									<option value="llama3.1:8b" <?php selected( $aiohm_pro_settings['ollama_model'], 'llama3.1:8b' ); ?>>Llama 3.1 8B</option>
									<option value="llama3.1:70b" <?php selected( $aiohm_pro_settings['ollama_model'], 'llama3.1:70b' ); ?>>Llama 3.1 70B</option>
									<option value="mistral:latest" <?php selected( $aiohm_pro_settings['ollama_model'], 'mistral:latest' ); ?>>Mistral (Latest)</option>
									<option value="codellama:latest" <?php selected( $aiohm_pro_settings['ollama_model'], 'codellama:latest' ); ?>>Code Llama</option>
									<option value="phi3:latest" <?php selected( $aiohm_pro_settings['ollama_model'], 'phi3:latest' ); ?>>Phi-3</option>
									<option value="gemma2:latest" <?php selected( $aiohm_pro_settings['ollama_model'], 'gemma2:latest' ); ?>>Gemma 2</option>
								</select>
								<p class="description"><?php esc_html_e( 'Select the Ollama model to use. Make sure the model is downloaded locally.', 'aiohm-knowledge-assistant' ); ?></p>
								<button type="button" id="test-ollama-connection" class="button"><?php esc_html_e( 'Test Connection', 'aiohm-knowledge-assistant' ); ?></button>
								<span id="ollama-test-result" class="aiohm-test-result"></span>
							</td>
						</tr>
					</table>
				</div>
			</div>

			<!-- Right Column -->
			<div class="aiohm-column aiohm-column-right">
				
				<!-- MCP Integration Settings -->
				<div class="aiohm-card">
					<h2><?php esc_html_e( 'MCP (Model Context Protocol) Settings', 'aiohm-knowledge-assistant' ); ?></h2>
					<p class="aiohm-section-description"><?php esc_html_e( 'Enable MCP API to share your knowledge base with external AI assistants and applications through standardized endpoints.', 'aiohm-knowledge-assistant' ); ?></p>

					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="mcp_enabled"><?php esc_html_e( 'Enable MCP API', 'aiohm-knowledge-assistant' ); ?></label>
							</th>
							<td>
								<label class="aiohm-switch">
									<input type="checkbox"
										   id="mcp_enabled"
										   name="aiohm_kb_settings[pro_features][mcp_enabled]"
										   value="1"
										   <?php checked( $aiohm_mcp_enabled ); ?>>
									<span class="aiohm-slider"></span>
								</label>
								<p class="description"><?php esc_html_e( 'Enable Model Context Protocol API for external AI assistant integrations.', 'aiohm-knowledge-assistant' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="mcp_rate_limit"><?php esc_html_e( 'Rate Limit', 'aiohm-knowledge-assistant' ); ?></label>
							</th>
							<td>
								<input type="number"
									   id="mcp_rate_limit"
									   name="aiohm_kb_settings[pro_features][mcp_rate_limit]"
									   value="<?php echo esc_attr( $aiohm_mcp_rate_limit ); ?>"
									   min="100"
									   max="10000"
									   class="small-text"> <?php esc_html_e( 'requests per hour', 'aiohm-knowledge-assistant' ); ?>
								<p class="description"><?php esc_html_e( 'Maximum API requests per hour per token (100-10000).', 'aiohm-knowledge-assistant' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="mcp_require_https"><?php esc_html_e( 'Require HTTPS', 'aiohm-knowledge-assistant' ); ?></label>
							</th>
							<td>
								<label class="aiohm-switch">
									<input type="checkbox"
										   id="mcp_require_https"
										   name="aiohm_kb_settings[pro_features][mcp_require_https]"
										   value="1"
										   <?php checked( $aiohm_mcp_require_https ); ?>>
									<span class="aiohm-slider"></span>
								</label>
								<p class="description"><?php esc_html_e( 'Require HTTPS for MCP API connections (recommended for security).', 'aiohm-knowledge-assistant' ); ?></p>
							</td>
						</tr>
					</table>

					<!-- MCP Endpoint Information -->
					<div class="mcp-endpoint-info">
						<h3><?php esc_html_e( 'MCP Endpoint Information', 'aiohm-knowledge-assistant' ); ?></h3>
						<div class="endpoint-urls">
							<div class="endpoint-item">
								<label><?php esc_html_e( 'MCP Manifest URL:', 'aiohm-knowledge-assistant' ); ?></label>
								<code class="endpoint-url"><?php echo esc_url(home_url('/wp-json/aiohm/mcp/v1/manifest')); ?></code>
								<button type="button" class="button button-small copy-endpoint" data-url="<?php echo esc_url(home_url('/wp-json/aiohm/mcp/v1/manifest')); ?>"><?php esc_html_e( 'Copy', 'aiohm-knowledge-assistant' ); ?></button>
							</div>
							<div class="endpoint-item">
								<label><?php esc_html_e( 'Knowledge Base API:', 'aiohm-knowledge-assistant' ); ?></label>
								<code class="endpoint-url"><?php echo esc_url(home_url('/wp-json/aiohm/mcp/v1/kb')); ?></code>
								<button type="button" class="button button-small copy-endpoint" data-url="<?php echo esc_url(home_url('/wp-json/aiohm/mcp/v1/kb')); ?>"><?php esc_html_e( 'Copy', 'aiohm-knowledge-assistant' ); ?></button>
							</div>
						</div>
						<p class="description"><?php esc_html_e( 'Use these URLs to configure MCP-compatible AI assistants and applications.', 'aiohm-knowledge-assistant' ); ?></p>
					</div>
				</div>

				<!-- MCP Token Management -->
				<div class="aiohm-card">
					<h2><?php esc_html_e( 'MCP API Token Management', 'aiohm-knowledge-assistant' ); ?></h2>
					<p class="aiohm-section-description"><?php esc_html_e( 'Create and manage API tokens for external applications to access your knowledge base.', 'aiohm-knowledge-assistant' ); ?></p>

					<!-- Token Success Display Section -->
					<div id="token-success-display" class="aiohm-card aiohm-hidden">
						<h3><?php esc_html_e('Token Generated Successfully', 'aiohm-knowledge-assistant'); ?></h3>
						<p><?php esc_html_e('Please copy and save this token immediately. It will not be shown again for security reasons.', 'aiohm-knowledge-assistant'); ?></p>

						<div class="token-display">
							<label><?php esc_html_e('API Token:', 'aiohm-knowledge-assistant'); ?></label>
							<div class="token-copy-group">
								<input type="text" id="generated-token" readonly class="large-text">
								<button type="button" id="copy-token" class="button button-secondary">
									<?php esc_html_e('Copy', 'aiohm-knowledge-assistant'); ?>
								</button>
							</div>
						</div>

						<div class="token-usage-info">
							<h4><?php esc_html_e('How to Use This Token', 'aiohm-knowledge-assistant'); ?></h4>
							<p><?php esc_html_e('Include this token in the Authorization header of your MCP requests:', 'aiohm-knowledge-assistant'); ?></p>
							<code>Authorization: Bearer YOUR_TOKEN_HERE</code>
						</div>

						<button type="button" id="hide-token-success" class="button button-primary"><?php esc_html_e('Close', 'aiohm-knowledge-assistant'); ?></button>
					</div>

					<!-- Create New Token Form -->
					<div class="create-token-section">
						<h3><?php esc_html_e( 'Generate New Token', 'aiohm-knowledge-assistant' ); ?></h3>
						<form id="create-token-form" method="post">
							<?php wp_nonce_field( 'aiohm_mcp_nonce', 'aiohm_mcp_nonce' ); ?>

							<table class="form-table">
								<tr>
									<th scope="row"><?php esc_html_e('Token Name', 'aiohm-knowledge-assistant'); ?></th>
									<td>
										<input type="text" name="token_name" placeholder="<?php esc_attr_e('e.g., Mobile App, Claude Desktop', 'aiohm-knowledge-assistant'); ?>" required maxlength="255" class="regular-text">
										<p class="description"><?php esc_html_e('A descriptive name to identify this token', 'aiohm-knowledge-assistant'); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e('Token Type', 'aiohm-knowledge-assistant'); ?></th>
									<td>
										<fieldset>
											<label>
												<input type="radio" name="token_type" value="private" checked>
												<strong><?php esc_html_e('Private', 'aiohm-knowledge-assistant'); ?></strong>
												<p class="description"><?php esc_html_e('Access to all knowledge base content including private posts, drafts, and uploads.', 'aiohm-knowledge-assistant'); ?></p>
											</label><br>
											<label>
												<input type="radio" name="token_type" value="public">
												<strong><?php esc_html_e('Public', 'aiohm-knowledge-assistant'); ?></strong>
												<p class="description"><?php esc_html_e('Access limited to published WordPress posts and pages only.', 'aiohm-knowledge-assistant'); ?></p>
											</label>
										</fieldset>
									</td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e('Expiration', 'aiohm-knowledge-assistant'); ?></th>
									<td>
										<select name="expires_days" class="regular-text">
											<option value=""><?php esc_html_e('Never expires', 'aiohm-knowledge-assistant'); ?></option>
											<option value="7"><?php esc_html_e('7 days', 'aiohm-knowledge-assistant'); ?></option>
											<option value="30"><?php esc_html_e('30 days', 'aiohm-knowledge-assistant'); ?></option>
											<option value="90"><?php esc_html_e('90 days', 'aiohm-knowledge-assistant'); ?></option>
											<option value="365"><?php esc_html_e('1 year', 'aiohm-knowledge-assistant'); ?></option>
										</select>
										<p class="description"><?php esc_html_e('Set token expiration for enhanced security', 'aiohm-knowledge-assistant'); ?></p>
									</td>
								</tr>
							</table>

							<p class="submit">
								<button type="submit" class="button button-secondary"><?php esc_html_e('Generate Token', 'aiohm-knowledge-assistant'); ?></button>
							</p>
						</form>
					</div>
				</div>

				<!-- Existing Tokens Section -->
				<div class="aiohm-card">
					<h2><?php esc_html_e( 'Existing API Tokens', 'aiohm-knowledge-assistant' ); ?></h2>
					<p class="aiohm-section-description"><?php esc_html_e( 'View and manage your existing API tokens. You can revoke or remove tokens that are no longer needed.', 'aiohm-knowledge-assistant' ); ?></p>
					
					<div id="mcp-tokens-list">
						<div class="loading-tokens"><?php esc_html_e( 'Loading tokens...', 'aiohm-knowledge-assistant' ); ?></div>
					</div>
				</div>
			</div>
		</div>

		<!-- Single Save Button -->
		<div class="aiohm-save-section">
			<p class="submit">
				<input type="submit" name="submit" id="submit" class="button button-primary button-large" value="<?php esc_attr_e( 'Save All Pro Features Settings', 'aiohm-knowledge-assistant' ); ?>">
			</p>
		</div>
	</form>

	<!-- Privacy and Security Notice -->
	<div class="aiohm-card">
		<h2><?php esc_html_e( 'Privacy & Security Notice', 'aiohm-knowledge-assistant' ); ?></h2>
		<div class="aiohm-privacy-notice">
			<h3><?php esc_html_e( 'Enhanced Privacy with Local Processing', 'aiohm-knowledge-assistant' ); ?></h3>
			<ul>
				<li><strong><?php esc_html_e( 'Ollama Integration:', 'aiohm-knowledge-assistant' ); ?></strong> <?php esc_html_e( 'When enabled, AI processing occurs entirely on your server. No data is sent to external providers.', 'aiohm-knowledge-assistant' ); ?></li>
				<li><strong><?php esc_html_e( 'MCP Integration:', 'aiohm-knowledge-assistant' ); ?></strong> <?php esc_html_e( 'Connects to external AI assistants with full control over data access and permissions.', 'aiohm-knowledge-assistant' ); ?></li>
				<li><strong><?php esc_html_e( 'Private Hub Mode:', 'aiohm-knowledge-assistant' ); ?></strong> <?php esc_html_e( 'Creates a completely private knowledge hub for your brand with customizable access controls.', 'aiohm-knowledge-assistant' ); ?></li>
			</ul>
		</div>
	</div>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
	// Test Ollama connection
	$('#test-ollama-connection').click(function() {
		var button = $(this);
		var result = $('#ollama-test-result');
		var url = $('#ollama_server_url').val();

		if (!url) {
			result.html('<span style="color: red;">Please enter Ollama server URL</span>');
			return;
		}

		button.prop('disabled', true).text('Testing...');
		result.html('<span style="color: blue;">Testing connection...</span>');

		$.ajax({
			url: '<?php echo esc_url( admin_url('admin-ajax.php') ); ?>',
			type: 'POST',
			data: {
				action: 'aiohm_test_ollama_connection',
				nonce: '<?php echo esc_attr( wp_create_nonce( 'aiohm_admin_nonce' ) ); ?>',
				server_url: url
			},
			success: function(response) {
				if (response.success) {
					result.html('<span style="color: green;">✓ ' + response.data + '</span>');
				} else {
					result.html('<span style="color: red;">✗ Connection failed: ' + response.data + '</span>');
				}
			},
			error: function() {
				result.html('<span style="color: red;">✗ Connection test failed</span>');
			},
			complete: function() {
				button.prop('disabled', false).text('Test Connection');
			}
		});
	});

	// Copy endpoint URLs
	$('.copy-endpoint').click(function() {
		var url = $(this).data('url');
		navigator.clipboard.writeText(url).then(function() {
			// Show success feedback
			var button = $(this);
			var originalText = button.text();
			button.text('Copied!');
			setTimeout(function() {
				button.text(originalText);
			}, 2000);
		});
	});

	// Ollama server preset buttons
	$('.preset-url').click(function() {
		var url = $(this).data('url');
		$('#ollama_server_url').val(url).trigger('change');

		// Visual feedback
		var button = $(this);
		var originalText = button.text();
		button.addClass('button-primary').text('Set!');
		setTimeout(function() {
			button.removeClass('button-primary').text(originalText);
		}, 1500);
	});

	// MCP Token Management
	function loadMCPTokens() {
		$.ajax({
			url: '<?php echo esc_url( admin_url('admin-ajax.php') ); ?>',
			type: 'POST',
			data: {
				action: 'aiohm_list_mcp_tokens',
				nonce: '<?php echo esc_attr( wp_create_nonce( 'aiohm_mcp_nonce' ) ); ?>'
			},
			success: function(response) {
				if (response.success) {
					$('#mcp-tokens-list').html(response.data);
				} else {
					$('#mcp-tokens-list').html('<p>No tokens found.</p>');
				}
			},
			error: function() {
				$('#mcp-tokens-list').html('<p>Error loading tokens.</p>');
			}
		});
	}

	// Load tokens on page load
	loadMCPTokens();

	// Create MCP token - First save all Pro Features settings, then generate token
	$('#create-token-form').submit(function(e) {
		e.preventDefault();

		// First, save all Pro Features settings
		var proFeaturesFormData = $('#aiohm-pro-features-form').serialize() + '&action=aiohm_save_pro_features';
		
		$.ajax({
			url: '<?php echo esc_url( admin_url('admin-ajax.php') ); ?>',
			type: 'POST',
			data: proFeaturesFormData,
			success: function(saveResponse) {
				if (saveResponse.success) {
					console.log('Pro Features settings saved successfully');
					
					// Now generate the MCP token
					var tokenFormData = $('#create-token-form').serialize() + '&action=aiohm_generate_mcp_token';
					
					$.ajax({
						url: '<?php echo esc_url( admin_url('admin-ajax.php') ); ?>',
						type: 'POST',
						data: tokenFormData,
						success: function(response) {
							if (response.success && response.data.token) {
								$('#generated-token').val(response.data.token);
								$('#token-success-display').removeClass('aiohm-hidden');
								$('#create-token-form')[0].reset();
								loadMCPTokens(); // Refresh token list
							} else {
								var errorMessage = 'Unknown error';
								if (response.data && response.data.message) {
									errorMessage = response.data.message;
								} else if (response.data && typeof response.data === 'string') {
									errorMessage = response.data;
								}
								alert('Error generating token: ' + errorMessage);
							}
						},
						error: function() {
							alert('Error generating token');
						}
					});
				} else {
					var errorMessage = 'Unknown error';
					if (saveResponse.data && saveResponse.data.message) {
						errorMessage = saveResponse.data.message;
					} else if (saveResponse.data && typeof saveResponse.data === 'string') {
						errorMessage = saveResponse.data;
					}
					alert('Error saving settings: ' + errorMessage);
				}
			},
			error: function() {
				alert('Error saving Pro Features settings');
			}
		});
	});

	// Copy generated token
	$('#copy-token').click(function() {
		var tokenField = $('#generated-token')[0];
		tokenField.select();
		document.execCommand('copy');

		var button = $(this);
		var originalText = button.text();
		button.text('Copied!');
		setTimeout(function() {
			button.text(originalText);
		}, 2000);
	});

	// Hide token success
	$('#hide-token-success').click(function() {
		$('#token-success-display').addClass('aiohm-hidden');
	});

	// Handle submit button click directly
	$('#submit').on('click', function(e) {
		console.log('Submit button clicked!');
		e.preventDefault();
		
		console.log('Processing form submission...');
		
		// Show saving indicator
		$('#aiohm-admin-notice').removeClass('notice-error notice-success aiohm-hidden').addClass('notice-info').find('p').text('Saving settings...');
		$('#submit').prop('disabled', true).val('Saving...');
		
		var formData = $('#aiohm-pro-features-form').serialize();
		console.log('Form data being sent:', formData);
		
		$.ajax({
			url: '<?php echo esc_url( admin_url('admin-ajax.php') ); ?>',
			type: 'POST',
			data: formData + '&action=aiohm_save_pro_features',
			success: function(response) {
				console.log('AJAX response:', response);
				$('#submit').prop('disabled', false).val('<?php esc_attr_e( 'Save All Pro Features Settings', 'aiohm-knowledge-assistant' ); ?>');
				
				if (response.success) {
					$('#aiohm-admin-notice').removeClass('notice-error notice-info').addClass('notice-success').find('p').text('Settings saved successfully.');
					setTimeout(function() {
						$('#aiohm-admin-notice').addClass('aiohm-hidden');
					}, 3000);
				} else {
					console.error('Save failed:', response);
					console.error('Error details:', response.data);
					var errorMessage = 'Unknown error';
					if (response.data) {
						if (response.data.message) {
							errorMessage = response.data.message;
						} else if (typeof response.data === 'string') {
							errorMessage = response.data;
						} else {
							errorMessage = JSON.stringify(response.data);
						}
					}
					$('#aiohm-admin-notice').removeClass('notice-success notice-info').addClass('notice-error').find('p').text('Save failed: ' + errorMessage);
				}
			},
			error: function(xhr, status, error) {
				console.error('AJAX error:', xhr, status, error);
				$('#submit').prop('disabled', false).val('<?php esc_attr_e( 'Save All Pro Features Settings', 'aiohm-knowledge-assistant' ); ?>');
				$('#aiohm-admin-notice').removeClass('notice-success notice-info').addClass('notice-error').find('p').text('Save failed: ' + error);
			}
		});
	});

	// Token management actions (revoke, remove)
	$(document).on('click', '.revoke-token', function() {
		var tokenId = $(this).data('token-id');
		if (confirm('Are you sure you want to revoke this token?')) {
			$.ajax({
				url: '<?php echo esc_url( admin_url('admin-ajax.php') ); ?>',
				type: 'POST',
				data: {
					action: 'aiohm_revoke_mcp_token',
					token_id: tokenId,
					nonce: '<?php echo esc_attr( wp_create_nonce( 'aiohm_mcp_nonce' ) ); ?>'
				},
				success: function(response) {
					if (response.success) {
						loadMCPTokens();
					} else {
						var errorMessage = 'Unknown error';
						if (response.data && response.data.message) {
							errorMessage = response.data.message;
						} else if (response.data && typeof response.data === 'string') {
							errorMessage = response.data;
						}
						alert('Error revoking token: ' + errorMessage);
					}
				}
			});
		}
	});

	$(document).on('click', '.remove-token', function() {
		var tokenId = $(this).data('token-id');
		if (confirm('Are you sure you want to permanently remove this token?')) {
			$.ajax({
				url: '<?php echo esc_url( admin_url('admin-ajax.php') ); ?>',
				type: 'POST',
				data: {
					action: 'aiohm_remove_mcp_token',
					token_id: tokenId,
					nonce: '<?php echo esc_attr( wp_create_nonce( 'aiohm_mcp_nonce' ) ); ?>'
				},
				success: function(response) {
					if (response.success) {
						loadMCPTokens();
					} else {
						var errorMessage = 'Unknown error';
						if (response.data && response.data.message) {
							errorMessage = response.data.message;
						} else if (response.data && typeof response.data === 'string') {
							errorMessage = response.data;
						}
						alert('Error removing token: ' + errorMessage);
					}
				}
			});
		}
	});
});
</script>

<style>
/* Two Column Layout */
.aiohm-two-column-layout {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 30px;
	margin-bottom: 30px;
}

.aiohm-column {
	display: flex;
	flex-direction: column;
	gap: 20px;
}

/* Responsive design */
@media (max-width: 1200px) {
	.aiohm-two-column-layout {
		grid-template-columns: 1fr;
		gap: 20px;
	}
}

/* Card styling */
.aiohm-card {
	background: #fff;
	border: 1px solid #ccd0d4;
	border-radius: 4px;
	padding: 20px;
	box-shadow: 0 1px 1px rgba(0,0,0,.04);
}

.aiohm-card h2 {
	margin-top: 0;
	margin-bottom: 10px;
	color: #23282d;
	font-size: 1.3em;
}

.aiohm-section-description {
	color: #666;
	margin-bottom: 20px;
	font-style: italic;
}

/* Save section styling */
.aiohm-save-section {
	text-align: center;
	padding: 20px 0;
	border-top: 1px solid #ddd;
	margin-top: 30px;
}

.aiohm-save-section .button-large {
	padding: 12px 36px;
	font-size: 16px;
	font-weight: 600;
}

/* Enhanced styling for integrated features */
.aiohm-privacy-notice {
	background: #f0f8ff;
	border: 1px solid #0073aa;
	border-radius: 4px;
	padding: 15px;
	margin-top: 10px;
}

.aiohm-privacy-notice h3 {
	margin-top: 0;
	color: #0073aa;
}

.aiohm-privacy-notice ul {
	margin: 10px 0;
}

.aiohm-privacy-notice li {
	margin-bottom: 8px;
}

.aiohm-test-result {
	margin-left: 10px;
	font-weight: bold;
}

.aiohm-switch {
	position: relative;
	display: inline-block;
	width: 50px;
	height: 24px;
}

.aiohm-switch input {
	opacity: 0;
	width: 0;
	height: 0;
}

.aiohm-slider {
	position: absolute;
	cursor: pointer;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background-color: #ccc;
	transition: .4s;
	border-radius: 24px;
}

.aiohm-slider:before {
	position: absolute;
	content: "";
	height: 18px;
	width: 18px;
	left: 3px;
	bottom: 3px;
	background-color: white;
	transition: .4s;
	border-radius: 50%;
}

input:checked + .aiohm-slider {
	background-color: #0073aa;
}

input:checked + .aiohm-slider:before {
	transform: translateX(26px);
}

/* MCP-specific styles */
.mcp-endpoint-info {
	background: #f9f9f9;
	border: 1px solid #ddd;
	border-radius: 4px;
	padding: 15px;
	margin-top: 20px;
}

.endpoint-item {
	display: flex;
	align-items: center;
	margin-bottom: 10px;
	gap: 10px;
}

.endpoint-item label {
	min-width: 150px;
	font-weight: bold;
}

.endpoint-url {
	flex: 1;
	background: #fff;
	padding: 5px 8px;
	border: 1px solid #ddd;
	border-radius: 3px;
}

.token-display {
	background: #f9f9f9;
	border: 1px solid #ddd;
	border-radius: 4px;
	padding: 15px;
	margin: 15px 0;
}

.token-copy-group {
	display: flex;
	gap: 10px;
	margin-top: 5px;
}

.token-usage-info {
	margin-top: 15px;
}

.token-usage-info code {
	display: block;
	background: #f1f1f1;
	padding: 10px;
	border-radius: 3px;
	margin: 5px 0;
}

.create-token-section, .existing-tokens-section {
	margin-top: 30px;
}

.permission-desc {
	display: block;
	font-style: italic;
	color: #666;
	margin-left: 20px;
}

.aiohm-hidden {
	display: none !important;
}

/* Ollama server input group styling */
.ollama-server-input-group {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.ollama-preset-buttons {
	display: flex;
	gap: 8px;
	align-items: center;
}

.preset-url {
	min-width: 70px;
	transition: all 0.3s ease;
}

.preset-url:hover {
	background-color: #0073aa;
	border-color: #0073aa;
	color: white;
}

/* Form table improvements */
.form-table th {
	width: 200px;
	vertical-align: top;
	padding-top: 15px;
}

.form-table td {
	padding-top: 10px;
	padding-bottom: 15px;
}

/* Notice styling */
.notice {
	margin: 15px 0;
	padding: 12px;
}
</style>
