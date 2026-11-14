<?php
/**
 * Data cleanup used by uninstall.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Uninstall cleanup, called from uninstall.php.
 */
function aiohm_kb_uninstall_cleanup()
{
    // Check if user wants to delete data on uninstall
    $settings = get_option("aiohm_kb_settings", []);
    $delete_data =
        isset($settings["delete_data_on_uninstall"]) &&
        $settings["delete_data_on_uninstall"];

    // If user doesn't want to delete data, just exit
    if (!$delete_data) {
        return;
    }

    // Remove plugin options
    delete_option("aiohm_kb_settings");
    delete_option("aiohm_kb_version");

    // Remove plugin database tables
    global $wpdb;

    $tables_to_drop = [
        $wpdb->prefix . "aiohm_vector_entries",
        $wpdb->prefix . "aiohm_conversations",
        $wpdb->prefix . "aiohm_messages",
        $wpdb->prefix . "aiohm_projects",
        $wpdb->prefix . "aiohm_project_notes",
        $wpdb->prefix . "aiohm_usage_tracking",
    ];

    foreach ($tables_to_drop as $table) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Direct query necessary for table dropping during uninstall, table names are predefined
        $wpdb->query("DROP TABLE IF EXISTS `$table`");
    }

    // Remove scheduled hooks
    wp_clear_scheduled_hook("aiohm_scheduled_scan");

    // Remove user meta data
    delete_metadata("user", 0, "aiohm_user_settings", "", true);
    delete_metadata("user", 0, "aiohm_brand_soul_answers", "", true);

    // Remove post meta data
    delete_metadata("post", 0, "_aiohm_indexed", "", true);

    // Remove uploaded files
    $upload_dir = wp_upload_dir();
    $aiohm_upload_dir = $upload_dir["basedir"] . "/aiohm_project_files";

    if (is_dir($aiohm_upload_dir)) {
        /**
         * Recursively delete directory and all its contents
         *
         * @param string $dir Directory path to delete
         * @return bool True on success, false on failure
         */
        function aiohm_delete_directory($dir)
        {
            if (!is_dir($dir)) {
                return false;
            }

            $files = array_diff(scandir($dir), [".", ".."]);
            foreach ($files as $file) {
                $path = $dir . DIRECTORY_SEPARATOR . $file;
                is_dir($path)
                    ? aiohm_delete_directory($path)
                    : wp_delete_file($path);
            }
            global $wp_filesystem;
            if (empty($wp_filesystem)) {
                require_once ABSPATH . "/wp-admin/includes/file.php";
                WP_Filesystem();
            }
            return $wp_filesystem->rmdir($dir);
        }

        aiohm_delete_directory($aiohm_upload_dir);
    }

    // Clear any cached data
    wp_cache_flush();
}
