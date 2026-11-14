<?php
/**
 * Scan website content template - Branded Version with all functionality and styles restored.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The controller (settings-page.php) prepares these variables before including this template.
$aiohm_settings         = class_exists( 'AIOHM_KB_Assistant' ) ? AIOHM_KB_Assistant::get_settings() : array();
$aiohm_default_provider = $aiohm_settings['default_ai_provider'] ?? 'openai';

// Check if the selected default provider has an API key configured
$aiohm_has_openai = ! empty( $aiohm_settings['openai_api_key'] )
	|| ( function_exists( 'aiohm_kb_is_wp_ai_connector_configured' ) && aiohm_kb_is_wp_ai_connector_configured( 'openai' ) )
	|| ! empty( get_option( 'connectors_ai_openai_api_key' ) )
	|| ( defined( 'OPENAI_API_KEY' ) && OPENAI_API_KEY )
	|| getenv( 'OPENAI_API_KEY' );

$aiohm_has_gemini = ! empty( $aiohm_settings['gemini_api_key'] )
	|| ( function_exists( 'aiohm_kb_is_wp_ai_connector_configured' ) && aiohm_kb_is_wp_ai_connector_configured( 'gemini' ) )
	|| ! empty( get_option( 'connectors_ai_google_api_key' ) )
	|| ! empty( get_option( 'connectors_ai_gemini_api_key' ) )
	|| ( defined( 'GOOGLE_API_KEY' ) && GOOGLE_API_KEY )
	|| ( defined( 'GEMINI_API_KEY' ) && GEMINI_API_KEY )
	|| getenv( 'GOOGLE_API_KEY' )
	|| getenv( 'GEMINI_API_KEY' );

$aiohm_has_claude = ! empty( $aiohm_settings['claude_api_key'] )
	|| ( function_exists( 'aiohm_kb_is_wp_ai_connector_configured' ) && aiohm_kb_is_wp_ai_connector_configured( 'claude' ) )
	|| ! empty( get_option( 'connectors_ai_anthropic_api_key' ) )
	|| ! empty( get_option( 'connectors_ai_claude_api_key' ) )
	|| ( defined( 'ANTHROPIC_API_KEY' ) && ANTHROPIC_API_KEY )
	|| ( defined( 'CLAUDE_API_KEY' ) && CLAUDE_API_KEY )
	|| getenv( 'ANTHROPIC_API_KEY' )
	|| getenv( 'CLAUDE_API_KEY' );

$aiohm_has_shareai = ! empty( $aiohm_settings['shareai_api_key'] );

$aiohm_api_key_exists = false;
switch ( $aiohm_default_provider ) {
	case 'openai':
		$aiohm_api_key_exists = $aiohm_has_openai;
		break;
	case 'gemini':
		$aiohm_api_key_exists = $aiohm_has_gemini;
		break;
	case 'claude':
		$aiohm_api_key_exists = $aiohm_has_claude;
		break;
	case 'shareai':
		$aiohm_api_key_exists = $aiohm_has_shareai;
		break;
	default:
		// For unknown providers, don't assume OpenAI - require proper configuration
		$aiohm_api_key_exists = false;
}

// Also get provider display name for better error messaging
$aiohm_provider_names        = array(
	'openai'  => 'OpenAI',
	'gemini'  => 'Gemini',
	'claude'  => 'Claude',
	'shareai' => 'ShareAI',
);
$aiohm_current_provider_name = $aiohm_provider_names[ $aiohm_default_provider ] ?? 'Unknown Provider';
$aiohm_total_links           = ( $site_stats['posts']['total'] ?? 0 ) + ( $site_stats['pages']['total'] ?? 0 );
?>
<div class="wrap aiohm-scan-page" id="aiohm-scan-page">
	<h1><?php esc_html_e( 'Build Your Knowledge Base', 'aiohm-knowledge-assistant' ); ?></h1>
	<p class="page-description"><?php esc_html_e( 'Scan your website\'s posts, pages, and media library to add content to your AI\'s knowledge base.', 'aiohm-knowledge-assistant' ); ?></p>

	<div id="aiohm-admin-notice" class="notice is-dismissible aiohm-hidden" tabindex="-1" role="alert" aria-live="polite">
		<p></p>
	</div>

	<?php if ( ! $aiohm_api_key_exists ) : ?>
		<div class="notice notice-warning">
			<h3><?php esc_html_e( 'Action Required: Add Your API Key', 'aiohm-knowledge-assistant' ); ?></h3>
			<p>
			<?php
				printf(
					/* translators: %s is the name of the AI provider (e.g., OpenAI, Gemini, Claude) */
					esc_html__( 'Content scanning is disabled because your %s API key has not been configured. Please add your key to enable this feature.', 'aiohm-knowledge-assistant' ),
					esc_html( $aiohm_current_provider_name )
				);
			?>
			</p>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=aiohm-settings' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Go to Settings', 'aiohm-knowledge-assistant' ); ?></a>
		</div>
	<?php endif; ?>

	<div class="aiohm-scan-section-wrapper">
		<div class="aiohm-scan-section">
			<h2><?php esc_html_e( 'Content Stats', 'aiohm-knowledge-assistant' ); ?></h2>
			<p><?php esc_html_e( 'An overview of all scannable content from your website and Media Library.', 'aiohm-knowledge-assistant' ); ?></p>
			
			<div class="aiohm-stats-boxes">
				<div class="aiohm-stats-box">
					<div class="stats-box-header">
						<h4><?php esc_html_e( 'Website Content Breakdown', 'aiohm-knowledge-assistant' ); ?></h4>
					</div>
					<div class="stats-box-content">
						<div class="stat-item total-stat">
							<strong><?php esc_html_e( 'Total Website Content:', 'aiohm-knowledge-assistant' ); ?></strong>
							<span class="stat-number">
							<?php
								$aiohm_total_website_content = ( $site_stats['posts']['total'] ?? 0 ) +
														( $site_stats['pages']['total'] ?? 0 ) +
														( $site_stats['products']['total'] ?? 0 ) +
														( $site_stats['comments']['total'] ?? 0 ) +
														( $site_stats['custom_fields']['total'] ?? 0 );
								echo esc_html( $aiohm_total_website_content );
							?>
							</span>
							<span class="stat-label">(Posts + Pages + Products + Comments + Custom Fields)</span>
						</div>
						<div class="stat-item">
							<strong><?php esc_html_e( 'Posts:', 'aiohm-knowledge-assistant' ); ?></strong>
							<span>
							<?php
								printf(
									/* translators: %1$d is total posts, %2$d is indexed posts, %3$d is pending posts */
									esc_html__( '%1$d total, %2$d indexed, %3$d pending', 'aiohm-knowledge-assistant' ),
									esc_html( $site_stats['posts']['total'] ?? 0 ),
									esc_html( $site_stats['posts']['indexed'] ?? 0 ),
									esc_html( $site_stats['posts']['pending'] ?? 0 )
								);
								?>
								</span>
						</div>
						<div class="stat-item">
							<strong><?php esc_html_e( 'Pages:', 'aiohm-knowledge-assistant' ); ?></strong>
							<span>
							<?php
								printf(
									/* translators: %1$d is total pages, %2$d is indexed pages, %3$d is pending pages */
									esc_html__( '%1$d total, %2$d indexed, %3$d pending', 'aiohm-knowledge-assistant' ),
									esc_html( $site_stats['pages']['total'] ?? 0 ),
									esc_html( $site_stats['pages']['indexed'] ?? 0 ),
									esc_html( $site_stats['pages']['pending'] ?? 0 )
								);
								?>
								</span>
						</div>
						
						<!-- WooCommerce Products -->
						<div class="stat-item">
							<strong><?php esc_html_e( 'Products:', 'aiohm-knowledge-assistant' ); ?></strong>
							<span>
							<?php
							if ( class_exists( 'WooCommerce' ) ) {
								printf(
									/* translators: %1$d is total products, %2$d is indexed products, %3$d is pending products */
									esc_html__( '%1$d total, %2$d indexed, %3$d pending', 'aiohm-knowledge-assistant' ),
									esc_html( $site_stats['products']['total'] ?? 0 ),
									esc_html( $site_stats['products']['indexed'] ?? 0 ),
									esc_html( $site_stats['products']['pending'] ?? 0 )
								);
							} else {
								esc_html_e( 'WooCommerce not installed', 'aiohm-knowledge-assistant' );
							}
							?>
							</span>
						</div>
						
						<!-- Comments -->
						<div class="stat-item">
							<strong><?php esc_html_e( 'Comments:', 'aiohm-knowledge-assistant' ); ?></strong>
							<span>
							<?php
								printf(
									/* translators: %1$d is total comments, %2$d is indexed comments, %3$d is pending comments */
									esc_html__( '%1$d total, %2$d indexed, %3$d pending', 'aiohm-knowledge-assistant' ),
									esc_html( $site_stats['comments']['total'] ?? 0 ),
									esc_html( $site_stats['comments']['indexed'] ?? 0 ),
									esc_html( $site_stats['comments']['pending'] ?? 0 )
								);
								?>
								</span>
						</div>
						
						<!-- Custom Fields -->
						<div class="stat-item">
							<strong><?php esc_html_e( 'Custom Fields:', 'aiohm-knowledge-assistant' ); ?></strong>
							<span>
							<?php
								printf(
									/* translators: %1$d is total custom fields, %2$d is indexed custom fields, %3$d is pending custom fields */
									esc_html__( '%1$d total, %2$d indexed, %3$d pending', 'aiohm-knowledge-assistant' ),
									esc_html( $site_stats['custom_fields']['total'] ?? 0 ),
									esc_html( $site_stats['custom_fields']['indexed'] ?? 0 ),
									esc_html( $site_stats['custom_fields']['pending'] ?? 0 )
								);
								?>
								</span>
						</div>
					</div>
				</div>
				
				<div class="aiohm-stats-box">
					<div class="stats-box-header">
						<h4><?php esc_html_e( 'Media Library Breakdown', 'aiohm-knowledge-assistant' ); ?></h4>
					</div>
					<div class="stats-box-content">
						<div class="stat-item total-stat">
							<strong><?php esc_html_e( 'Total Media Files:', 'aiohm-knowledge-assistant' ); ?></strong>
							<span class="stat-number"><?php echo esc_html( $uploads_stats['total_files'] ?? 0 ); ?></span>
							<span class="stat-label">(Indexed: <?php echo esc_html( $uploads_stats['indexed_files'] ?? 0 ); ?>, Pending: <?php echo esc_html( $uploads_stats['pending_files'] ?? 0 ); ?>)</span>
						</div>
					</div>
					<?php
					if ( ! empty( $uploads_stats['by_type'] ) ) {
						foreach ( $uploads_stats['by_type'] as $type => $aiohm_data ) {
							$aiohm_size_formatted = size_format( $aiohm_data['size'] ?? 0 );
							echo '<div class="stat-item"><strong>' . esc_html( strtoupper( $type ) ) . ' Files:</strong> <span>' . esc_html(
								// translators: %1$d is total files, %2$d is indexed files, %3$d is pending files, %4$s is file size
								sprintf( __( '%1$d total, %2$d indexed, %3$d pending (%4$s)', 'aiohm-knowledge-assistant' ), $aiohm_data['count'] ?? 0, $aiohm_data['indexed'] ?? 0, $aiohm_data['pending'] ?? 0, $aiohm_size_formatted )
							) . '</span></div>';
						}
					} else {
						echo '<p>' . esc_html__( 'Supported files include .txt, .json, .csv, .pdf, .doc, .docx, and .md from your Media Library.', 'aiohm-knowledge-assistant' ) . '</p>';
					}
					?>
				</div>
			</div>
		</div>
	</div>

	<div class="aiohm-scan-columns-wrapper">
		<div class="aiohm-scan-column">
			<div class="aiohm-scan-section">
				<h2><?php esc_html_e( 'Website Content Scanner', 'aiohm-knowledge-assistant' ); ?></h2>
				<p><?php esc_html_e( 'Use the button to find or re-scan your posts and pages.', 'aiohm-knowledge-assistant' ); ?></p>
				<button type="button" class="button button-primary" id="scan-website-btn" <?php disabled( ! $aiohm_api_key_exists ); ?>><?php esc_html_e( 'Re-Scan Posts & Pages', 'aiohm-knowledge-assistant' ); ?></button>
				<div id="pending-content-area">
					<h3><?php esc_html_e( 'Scan Results', 'aiohm-knowledge-assistant' ); ?></h3>
					<div id="scan-results-container"></div>
					<button type="button" class="button button-primary" id="add-selected-to-kb-btn" <?php disabled( ! $aiohm_api_key_exists ); ?>><?php esc_html_e( 'Add Selected to KB', 'aiohm-knowledge-assistant' ); ?></button>
					<div id="website-scan-progress" class="aiohm-scan-progress"><div class="progress-info"><span class="progress-label">Processing...</span><span class="progress-percentage">0%</span></div><div class="progress-bar-wrapper"><div class="progress-bar-inner"></div></div></div>
				</div>
			</div>
		</div>
		<div class="aiohm-scan-column">
			<div class="aiohm-scan-section">
				<h2><?php esc_html_e( 'Upload Folder Scanner', 'aiohm-knowledge-assistant' ); ?></h2>
				<p>
				<?php
					// translators: %s is the WordPress Media Library text that will be bolded
					printf( esc_html__( 'Scan your %s for readable files like .txt, .json, .csv, and .pdf.', 'aiohm-knowledge-assistant' ), '<strong>' . esc_html__( 'WordPress Media Library', 'aiohm-knowledge-assistant' ) . '</strong>' );
				?>
				</p>
				<button type="button" class="button button-primary" id="scan-uploads-btn" <?php disabled( ! $aiohm_api_key_exists ); ?>><?php esc_html_e( 'Re-Scan Media Library', 'aiohm-knowledge-assistant' ); ?></button>
				<div id="pending-uploads-area">
					<h3><?php esc_html_e( 'Uploads Scan Results', 'aiohm-knowledge-assistant' ); ?></h3>
					<div id="scan-uploads-container"></div>
					<button type="button" class="button button-primary" id="add-uploads-to-kb-btn" <?php disabled( ! $aiohm_api_key_exists ); ?>><?php esc_html_e( 'Add Selected to KB', 'aiohm-knowledge-assistant' ); ?></button>
					<div id="uploads-scan-progress" class="aiohm-scan-progress"><div class="progress-info"><span class="progress-label">Processing...</span><span class="progress-percentage">0%</span></div><div class="progress-bar-wrapper"><div class="progress-bar-inner"></div></div></div>
				</div>
			</div>
		</div>
	</div>
</div>