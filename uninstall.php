<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 * Data is removed only if "delete data on uninstall" is enabled in settings.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/includes/uninstall-cleanup.php';
aiohm_kb_uninstall_cleanup();
