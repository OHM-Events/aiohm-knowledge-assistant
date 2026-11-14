<?php
/**
 * Admin Dashboard template - Clean WordPress.org Version
 * Main dashboard interface with simplified navigation for all features
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$aiohm_default_tab  = 'welcome';
$aiohm_allowed_tabs = array( 'welcome', 'features', 'setup', 'pro' );
$aiohm_current_tab  = $aiohm_default_tab;

//phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ) {
    //phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$aiohm_requested_tab = sanitize_key( wp_unslash( $_GET['tab'] ) );

	if ( in_array( $aiohm_requested_tab, $aiohm_allowed_tabs, true ) ) {
		$aiohm_current_tab = $aiohm_requested_tab;
	}
}

$aiohm_settings = AIOHM_KB_Assistant::get_settings();
?>

<div class="wrap aiohm-dashboard">

	<div class="aiohm-header">
		<h1><?php esc_html_e( 'AIOHM Assistant Dashboard', 'aiohm-knowledge-assistant' ); ?></h1>
		<p class="aiohm-tagline"><?php esc_html_e( "Welcome! Let's turn your content into an expert AI assistant.", 'aiohm-knowledge-assistant' ); ?></p>
	</div>

	<nav class="nav-tab-wrapper">
		<a href="?page=aiohm-knowledge-assistant&tab=welcome" class="nav-tab <?php echo esc_attr( $aiohm_current_tab == 'welcome' ? 'nav-tab-active' : '' ); ?>"><?php esc_html_e( 'Welcome', 'aiohm-knowledge-assistant' ); ?></a>
		<a href="?page=aiohm-knowledge-assistant&tab=features" class="nav-tab <?php echo esc_attr( $aiohm_current_tab == 'features' ? 'nav-tab-active' : '' ); ?>"><?php esc_html_e( 'Features', 'aiohm-knowledge-assistant' ); ?></a>
		<a href="?page=aiohm-knowledge-assistant&tab=setup" class="nav-tab <?php echo esc_attr( $aiohm_current_tab == 'setup' ? 'nav-tab-active' : '' ); ?>"><?php esc_html_e( 'Quick Setup', 'aiohm-knowledge-assistant' ); ?></a>
	</nav>

	<div class="aiohm-tab-content">

		<?php if ( $aiohm_current_tab === 'welcome' ) : ?>
			<section class="aiohm-sales-page aiohm-welcome-tab">
				<div class="container">
					<h2 class="headline"><?php esc_html_e( '4 Steps to Turn Your Site Into a Living Knowledge Base', 'aiohm-knowledge-assistant' ); ?></h2>
					<div class="benefits-grid">
						<div class="benefit">
							<h3><?php esc_html_e( '1. Configure AI Provider', 'aiohm-knowledge-assistant' ); ?></h3>
							<p><?php esc_html_e( 'Add your OpenAI, Claude, Gemini, or ShareAI API key to get started.', 'aiohm-knowledge-assistant' ); ?></p>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=aiohm-settings' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Configure AI', 'aiohm-knowledge-assistant' ); ?></a>
						</div>
						<div class="benefit">
							<h3><?php esc_html_e( '2. Build Knowledge Base', 'aiohm-knowledge-assistant' ); ?></h3>
							<p><?php esc_html_e( 'Scan your website content and upload documents to create your AI knowledge base.', 'aiohm-knowledge-assistant' ); ?></p>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=aiohm-scan-content' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Scan Content', 'aiohm-knowledge-assistant' ); ?></a>
						</div>
						<div class="benefit">
							<h3><?php esc_html_e( '3. Define Brand Voice', 'aiohm-knowledge-assistant' ); ?></h3>
							<p><?php esc_html_e( 'Complete the AI Brand Core questionnaire to align your AI with your unique voice.', 'aiohm-knowledge-assistant' ); ?></p>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=aiohm-brand-soul' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Brand Core', 'aiohm-knowledge-assistant' ); ?></a>
						</div>
						<div class="benefit">
							<h3><?php esc_html_e( '4. Customize & Deploy', 'aiohm-knowledge-assistant' ); ?></h3>
							<p><?php esc_html_e( 'Configure Mirror Mode and Muse Mode, then add chatbot shortcodes to your site.', 'aiohm-knowledge-assistant' ); ?></p>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=aiohm-mirror-mode' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Customize', 'aiohm-knowledge-assistant' ); ?></a>
						</div>
					</div>
				</div>
			</section>

		<?php elseif ( $aiohm_current_tab === 'features' ) : ?>
			<section class="aiohm-sales-page aiohm-features-tab">
				<div class="container">
					<h2 class="headline"><?php esc_html_e( 'All Features Available', 'aiohm-knowledge-assistant' ); ?></h2>
					<p class="intro"><?php esc_html_e( 'Your WordPress.org version includes all core features with no restrictions.', 'aiohm-knowledge-assistant' ); ?></p>
					<div class="benefits-grid">
						<div class="benefit">
							<h3><?php esc_html_e( 'Multi-Provider AI Support', 'aiohm-knowledge-assistant' ); ?></h3>
							<p><?php esc_html_e( 'OpenAI GPT, Claude, Gemini, and ShareAI integration. Use your own API keys.', 'aiohm-knowledge-assistant' ); ?></p>
						</div>
						<div class="benefit">
							<h3><?php esc_html_e( 'Smart Knowledge Base', 'aiohm-knowledge-assistant' ); ?></h3>
							<p><?php esc_html_e( 'Automatic content indexing with PDF, CSV, TXT support and vector search.', 'aiohm-knowledge-assistant' ); ?></p>
						</div>
						<div class="benefit">
							<h3><?php esc_html_e( 'Brand Voice Training', 'aiohm-knowledge-assistant' ); ?></h3>
							<p><?php esc_html_e( 'AI Brand Core questionnaire to align your assistant with your unique voice.', 'aiohm-knowledge-assistant' ); ?></p>
						</div>
						<div class="benefit">
							<h3><?php esc_html_e( 'Mirror & Muse Modes', 'aiohm-knowledge-assistant' ); ?></h3>
							<p><?php esc_html_e( 'Public chatbot and private assistant modes for different use cases.', 'aiohm-knowledge-assistant' ); ?></p>
						</div>
						<div class="benefit">
							<h3><?php esc_html_e( 'Easy Integration', 'aiohm-knowledge-assistant' ); ?></h3>
							<p><?php esc_html_e( 'Simple shortcodes and widgets to add AI chat to any page or post.', 'aiohm-knowledge-assistant' ); ?></p>
						</div>
						<div class="benefit">
							<h3><?php esc_html_e( 'Privacy Focused', 'aiohm-knowledge-assistant' ); ?></h3>
							<p><?php esc_html_e( 'Your data, your API keys, your control. No external dependencies required.', 'aiohm-knowledge-assistant' ); ?></p>
						</div>
					</div>
				</div>
			</section>

		<?php elseif ( $aiohm_current_tab === 'setup' ) : ?>
			<section class="aiohm-sales-page aiohm-setup-tab">
				<div class="container">
					<h2 class="headline"><?php esc_html_e( 'Quick Setup Guide', 'aiohm-knowledge-assistant' ); ?></h2>
					<div class="benefits-grid">
						<div class="benefit">
							<h3><?php esc_html_e( 'Step 1: AI Configuration', 'aiohm-knowledge-assistant' ); ?></h3>
							<p><?php esc_html_e( 'Go to Settings and add your AI provider API key (OpenAI, Claude, Gemini, or ShareAI).', 'aiohm-knowledge-assistant' ); ?></p>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=aiohm-settings' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Configure AI', 'aiohm-knowledge-assistant' ); ?></a>
						</div>
						<div class="benefit">
							<h3><?php esc_html_e( 'Step 2: Content Scanning', 'aiohm-knowledge-assistant' ); ?></h3>
							<p><?php esc_html_e( 'Scan your website to build the knowledge base your AI will use to answer questions.', 'aiohm-knowledge-assistant' ); ?></p>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=aiohm-scan-content' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Scan Content', 'aiohm-knowledge-assistant' ); ?></a>
						</div>
						<div class="benefit">
							<h3><?php esc_html_e( 'Step 3: Brand Voice Setup', 'aiohm-knowledge-assistant' ); ?></h3>
							<p><?php esc_html_e( 'Define your brand personality so your AI assistant speaks with your authentic voice.', 'aiohm-knowledge-assistant' ); ?></p>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=aiohm-brand-soul' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Setup Brand Voice', 'aiohm-knowledge-assistant' ); ?></a>
						</div>
						<div class="benefit">
							<h3><?php esc_html_e( 'Step 4: Add to Your Site', 'aiohm-knowledge-assistant' ); ?></h3>
							<p><?php esc_html_e( 'Use shortcodes to add AI chat to your pages: [aiohm_chat] for public chat, [aiohm_search] for search.', 'aiohm-knowledge-assistant' ); ?></p>
							<div class="shortcode-examples">
								<code>[aiohm_chat]</code> - Public chatbot<br>
								<code>[aiohm_search]</code> - Knowledge base search<br>
								<code>[aiohm_private_assistant]</code> - Private AI assistant
							</div>
						</div>
					</div>
				</div>
			</section>

		<?php endif; ?>

	</div>
</div>
