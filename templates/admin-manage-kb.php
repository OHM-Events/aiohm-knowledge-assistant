<?php
/**
 * Template for the Manage Knowledge Base admin page.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Free version - all users have access to core features
$aiohm_has_club_access = true;

// Include the header for consistent branding
require_once AIOHM_KB_PLUGIN_DIR . 'templates/partials/header.php';
?>

<div class="wrap aiohm-manage-kb-page">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Manage Knowledge Base', 'aiohm-knowledge-assistant' ); ?></h1>
	<button type="button" id="add-content-btn" class="page-title-action aiohm-btn-primary"><?php esc_html_e( 'Add New Content', 'aiohm-knowledge-assistant' ); ?></button>
	<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'aiohm-scan-content' ), admin_url( 'admin.php' ) ) ); ?>" class="page-title-action aiohm-btn-secondary scan-website-link"><?php esc_html_e( 'Scan Website', 'aiohm-knowledge-assistant' ); ?></a>
	<p class="page-description"><?php esc_html_e( 'View, organize, and manage all your knowledge base entries in one place.', 'aiohm-knowledge-assistant' ); ?></p>
	

	<div id="aiohm-admin-notice" class="notice is-dismissible admin-notice-hidden" tabindex="-1" role="alert" aria-live="polite"></div>

	<hr class="wp-header-end">

	<div class="aiohm-knowledge-intro">
		<div class="knowledge-section public-section">
			<div class="section-header">
				<h3><span class="section-icon">🌍</span> <?php esc_html_e( 'Public Knowledge (Mirror Mode)', 'aiohm-knowledge-assistant' ); ?></h3>
				<?php if ( $aiohm_has_club_access ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=aiohm-mirror-mode' ) ); ?>" class="button aiohm-btn-secondary section-link">
						<span class="dashicons dashicons-admin-settings"></span> <?php esc_html_e( 'Configure Mirror Mode', 'aiohm-knowledge-assistant' ); ?>
					</a>
				<?php else : ?>
					<!-- Mirror Mode button removed -->
				<?php endif; ?>
			</div>
			<p>
			<?php
				// translators: %s is the word "Public" that will be bolded
				printf( esc_html__( '%s entries are part of the global knowledge base. They are used by your AI assistant to answer questions from any website visitor.', 'aiohm-knowledge-assistant' ), '<strong>' . esc_html__( 'Public', 'aiohm-knowledge-assistant' ) . '</strong>' );
			?>
				</p>
			<p><?php esc_html_e( 'This is perfect for general support, FAQs, and public information about your brand.', 'aiohm-knowledge-assistant' ); ?></p>
		</div>
		<div class="knowledge-section private-section">
			<div class="section-header">
				<h3><span class="section-icon">🔒</span> <?php esc_html_e( 'Private Knowledge (Muse Mode)', 'aiohm-knowledge-assistant' ); ?></h3>
				<?php if ( $aiohm_has_club_access ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=aiohm-muse-mode' ) ); ?>" class="button aiohm-btn-secondary section-link">
						<span class="dashicons dashicons-admin-settings"></span> <?php esc_html_e( 'Configure Muse Mode', 'aiohm-knowledge-assistant' ); ?>
					</a>
				<?php else : ?>
					<!-- Muse Mode button removed -->
				<?php endif; ?>
			</div>
			<p>
			<?php
				// translators: %s is the word "Private" that will be bolded
				printf( esc_html__( '%s entries are only accessible to you when using the Brand Assistant chat (Muse Mode).', 'aiohm-knowledge-assistant' ), '<strong>' . esc_html__( 'Private', 'aiohm-knowledge-assistant' ) . '</strong>' );
			?>
				</p>
			<p><?php esc_html_e( 'Use this for personal notes, strategic insights, or confidential brand guidelines that only you should access.', 'aiohm-knowledge-assistant' ); ?></p>
		</div>
	</div>

	<!-- Hidden form for custom bulk actions -->
	<form id="aiohm-bulk-form" method="post" class="bulk-form-hidden">
		<input type="hidden" name="page" value="aiohm-manage-kb" />
		<input type="hidden" name="aiohm_bulk_action" id="aiohm-bulk-action-input" value="" />
		<?php wp_nonce_field( 'aiohm_bulk_action', 'aiohm_bulk_nonce' ); ?>
		<div id="aiohm-bulk-ids"></div>
	</form>

	<form id="kb-filter-form" method="get" action="">
		<input type="hidden" name="page" value="aiohm-manage-kb" />
		
		<?php
		// The display() method of WP_List_Table will render bulk actions and filters
		// via its built-in functionality and the extra_tablenav() method.
		if ( isset( $list_table ) ) {
			$list_table->display();
		} else {
			echo '<p>No knowledge base entries found.</p>';
		}
		?>
	</form>

	<div id="aiohm-kb-actions" class="kb-actions-container">
		<form method="post" action="options.php">
			<?php settings_fields( 'aiohm_kb_settings' ); ?>
			<div class="aiohm-settings-section">
				<h2><?php esc_html_e( 'Knowledge Base Actions', 'aiohm-knowledge-assistant' ); ?></h2>
				<div class="actions-grid-wrapper">

					<div class="action-box">
						<h3><?php esc_html_e( 'Export Knowledge Base', 'aiohm-knowledge-assistant' ); ?></h3>
						<p class="description"><?php esc_html_e( 'Export your knowledge base entries as JSON files. Choose to export public entries or private entries separately.', 'aiohm-knowledge-assistant' ); ?></p>
						<div class="export-buttons">
							<button type="button" class="button aiohm-btn-primary" id="export-public-btn"><span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Export Public', 'aiohm-knowledge-assistant' ); ?></button>
							<button type="button" class="button aiohm-btn-secondary" id="export-private-btn"><span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Export Private', 'aiohm-knowledge-assistant' ); ?></button>
						</div>
					</div>

					<div class="action-box">
						<h3><?php esc_html_e( 'Restore Knowledge Base', 'aiohm-knowledge-assistant' ); ?></h3>
						<p class="description"><?php esc_html_e( 'Overwrite all existing public knowledge base entries from a previously saved JSON file.', 'aiohm-knowledge-assistant' ); ?></p>
						<div class="restore-controls">
							<div class="file-input-group">
								<input type="file" id="restore-kb-file" accept=".json" class="restore-file-input">
								<label for="restore-kb-file" class="button aiohm-btn-secondary"><span class="dashicons dashicons-upload"></span> <?php esc_html_e( 'Choose File...', 'aiohm-knowledge-assistant' ); ?></label>
								<span id="restore-file-name" class="file-name-display"></span>
							</div>
							<button type="button" class="button aiohm-btn-primary button-hero" id="restore-kb-btn" disabled><?php esc_html_e( 'Restore KB', 'aiohm-knowledge-assistant' ); ?></button>
						</div>
					</div>


					<div class="action-box reset-action">
						<h3><?php esc_html_e( 'Reset Knowledge Base', 'aiohm-knowledge-assistant' ); ?></h3>
						<p class="description warning-description"><strong><?php esc_html_e( 'Warning: This will permanently delete ALL knowledge base entries (public & private). This cannot be undone.', 'aiohm-knowledge-assistant' ); ?></strong></p>
						<button type="button" class="button aiohm-btn-danger button-hero" id="reset-kb-btn"><span class="dashicons dashicons-trash"></span> <?php esc_html_e( 'Reset Entire KB', 'aiohm-knowledge-assistant' ); ?></button>
					</div>
				</div>
			</div>
			<?php // The submit button for 'Save Schedule Setting' is removed as it's no longer relevant here. ?>
		</form>
	</div>
</div>



<?php
// Include the footer for consistent branding
require_once AIOHM_KB_PLUGIN_DIR . 'templates/partials/footer.php';
?>