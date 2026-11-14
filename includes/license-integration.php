<?php
/**
 * Access control. Since 3.0.0 every feature is free and open source,
 * so every site has full access. The class is kept for compatibility.
 */

if (!defined('ABSPATH')) {
    exit;
}

class AIOHM_KB_License_Integration {

    public static function init() {
    }

    /**
     * Every site has full access since 3.0.0
     * @return bool True if user has active pro license
     */
    private static function has_active_pro_license() {
        return true;
    }

    /**
     * Check if user has club-level access (Pro license required)
     * @return bool True if user has pro license
     */
    public static function aiohm_user_has_club_access() {
        return self::has_active_pro_license();
    }

    /**
     * Check if user has private access (Pro license required)
     * @return bool True if user has pro license
     */
    public static function aiohm_user_has_private_access() {
        return self::has_active_pro_license();
    }

    /**
     * License details (static since 3.0.0)
     * @return array|null License details or null if not available
     */
    public static function get_user_license_details() {
        return [
            'id' => null,
            'plan_id' => null,
            'plan_name' => 'Open source',
            'is_active' => true,
            'is_lifetime' => true,
            'is_trial' => false,
            'expires_at' => null,
        ];
    }

    /**
     * Check if user has MCP API access (Pro license required)
     * Pro license holders automatically get MCP access
     * @return bool True if user has pro license
     */
    public static function aiohm_user_has_mcp_access() {
        return self::has_active_pro_license();
    }

    /**
     * Get the user's effective access levels for MCP
     * Pro license holders get access to all MCP features
     * @return array Access levels array or empty array if no access
     */
    public static function get_user_mcp_access_levels() {
        if (!self::aiohm_user_has_mcp_access()) {
            return [];
        }

        // Pro license holders get access to all levels
        return [
            'tribal' => true,
            'club' => true,
            'private' => true,
            'mcp_api' => true
        ];
    }

    /**
     * Get user display name (legacy compatibility method)
     * @return string|null User display name or null
     */
    public static function get_user_display_name() {
        $current_user = wp_get_current_user();
        return $current_user->exists() ? $current_user->display_name : null;
    }

    /**
     * Check if current user is site administrator (for backward compatibility)
     * @return bool True if current user is admin
     */
    public static function is_site_admin() {
        return current_user_can('manage_options');
    }
}

// Maintain backward compatibility by creating an alias
class_alias('AIOHM_KB_License_Integration', 'AIOHM_KB_PMP_Integration');