<?php
/**
 * Plugin Name: MCP Abilities - Filesystem
 * Plugin URI: https://github.com/bjornfix/mcp-abilities-filesystem
 * Description: Filesystem abilities for MCP. Read, write, copy, move, and delete files within WordPress. Security-hardened with PHP injection detection.
 * Version: 1.0.2
 * Author: Devenia
 * Author URI: https://devenia.com
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Requires at least: 6.9
 * Requires PHP: 8.0
 *
 * @package MCP_Abilities_Filesystem
 */

declare( strict_types=1 );

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

error_log( 'MCP Filesystem: Plugin file loaded' );

/**
 * Check if Abilities API is available.
 *
*/
function mcp_filesystem_check_dependencies(): bool {
    error_log( 'MCP Filesystem: Checking dependencies...' );
    if ( ! function_exists( 'wp_register_ability' ) ) {
        error_log( 'MCP Filesystem: wp_register_ability function NOT found' );
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>MCP Abilities - Filesystem</strong> requires the <a href="https://github.com/WordPress/abilities-api">Abilities API</a> plugin to be installed and activated.</p></div>';
        } );
        return false;
    }
    error_log( 'MCP Filesystem: wp_register_ability function FOUND' );
    return true;
}

/**
 * Register filesystem abilities.
 */
function mcp_register_filesystem_abilities(): void {
	error_log( 'MCP Filesystem: Starting to register filesystem abilities' );
	if ( ! mcp_filesystem_check_dependencies() ) {
		return;
	}

error_log( "MCP Filesystem: Dependencies OK, defining helpers" );
	// =========================================================================
	// HELPER FUNCTIONS
	// =========================================================================

	/**
	 * Get the MCP backup directory path and ensure it exists.
	 *
	 * @return string The backup directory path.
	 */
	$mcp_get_backup_dir = function (): string {
		$backup_dir = WP_CONTENT_DIR . '/mcp-backups/' . gmdate( 'Y-m-d' );
		if ( ! is_dir( $backup_dir ) ) {
			wp_mkdir_p( $backup_dir );
		}
		return $backup_dir;
	};

	/**
	 * Create a backup of a file in the centralized backup directory.
	 *
	 * @param string $source_path The file to backup.
	 * @return string|false The backup path on success, false on failure.
	 */
	$mcp_create_backup = function ( string $source_path ) use ( $mcp_get_backup_dir ): string|false {
		if ( ! file_exists( $source_path ) ) {
			return false;
		}

		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();

		$backup_dir  = $mcp_get_backup_dir();
		$filename    = basename( $source_path );
		$backup_name = $filename . '.bak.' . gmdate( 'His' );
		$backup_path = $backup_dir . '/' . $backup_name;

		// Handle duplicate names within same second.
		$counter = 1;
		while ( file_exists( $backup_path ) ) {
			$backup_path = $backup_dir . '/' . $filename . '.bak.' . gmdate( 'His' ) . '.' . $counter;
			$counter++;
		}

		if ( $wp_filesystem->copy( $source_path, $backup_path, true, FS_CHMOD_FILE ) ) {
			return $backup_path;
		}

		return false;
	};

	/**
	 * Clean up old backup folders (older than 7 days).
	 */
	$mcp_cleanup_old_backups = function (): void {
		$backup_base = WP_CONTENT_DIR . '/mcp-backups';
		if ( ! is_dir( $backup_base ) ) {
			return;
		}

		// Initialize WP_Filesystem.
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();

		$cutoff = strtotime( '-7 days' );
		$dirs   = glob( $backup_base . '/20*-*-*', GLOB_ONLYDIR );

		foreach ( $dirs as $dir ) {
			$date_str = basename( $dir );
			$date_ts  = strtotime( $date_str );
			if ( $date_ts && $date_ts < $cutoff ) {
				// Delete all files in the directory.
				$files = glob( $dir . '/*' );
				foreach ( $files as $file ) {
					if ( is_file( $file ) ) {
						wp_delete_file( $file );
					}
				}
				$wp_filesystem->rmdir( $dir );
			}
		}
	};

add_action( 'wp_abilities_api_init', 'mcp_register_filesystem_abilities' );
