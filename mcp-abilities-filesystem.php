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

	/**
	 * Log filesystem operations to wp-content/mcp-filesystem.log
	 *
	 * @param string $operation The operation type (WRITE, DELETE, MOVE, COPY, APPEND).
	 * @param string $path      The file path being operated on.
	 * @param array  $details   Additional details (backup path, size, context, etc.).
	 */
	$mcp_log_filesystem_operation = function ( string $operation, string $path, array $details = array() ) use ( $mcp_cleanup_old_backups ): void {
		$log_file  = WP_CONTENT_DIR . '/mcp-filesystem.log';
		$timestamp = gmdate( 'Y-m-d H:i:s' );

		// Security audit info.
		$user    = wp_get_current_user();
		$user_id = $user->ID ?? 0;
		$email   = $user->user_email ?? 'unknown';
		$ip      = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';

		$entry = "[{$timestamp}] {$operation}\n";
		$entry .= "  File: {$path}\n";
		$entry .= "  User: {$email} (ID:{$user_id}) IP:{$ip}\n";

		if ( ! empty( $details['backup'] ) ) {
			$entry .= "  Backup: {$details['backup']}\n";
		}
		if ( ! empty( $details['size_before'] ) || ! empty( $details['size_after'] ) ) {
			$before = $details['size_before'] ?? '?';
			$after  = $details['size_after'] ?? '?';
			$entry .= "  Size: {$before} -> {$after} bytes\n";
		}
		if ( ! empty( $details['destination'] ) ) {
			$entry .= "  Destination: {$details['destination']}\n";
		}
		if ( ! empty( $details['context'] ) ) {
			$entry .= "  Context: {$details['context']}\n";
		}
		$entry .= "\n";

		// Append to log file (create if doesn't exist).
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();

		$existing_log = $wp_filesystem->exists( $log_file ) ? $wp_filesystem->get_contents( $log_file ) : '';
		$wp_filesystem->put_contents( $log_file, $existing_log . $entry, FS_CHMOD_FILE );

		// Cleanup old backups occasionally (1 in 10 chance to avoid overhead).
		if ( wp_rand( 1, 10 ) === 1 ) {
			$mcp_cleanup_old_backups();
		}
	};

	/**
	 * Check if a file write should be blocked for security reasons.
	 *
	 * @param string $path    The file path to check.
	 * @param string $content Optional content to scan for malicious patterns.
	 * @param int    $size    Optional content size in bytes.
	 * @return string|false Error message if blocked, false if allowed.
	 */
	$mcp_check_write_security = function ( string $path, string $content = '', int $size = 0 ): string|false {
		// Respect WordPress DISALLOW_FILE_EDIT and DISALLOW_FILE_MODS constants.
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			return 'File modifications are disabled by DISALLOW_FILE_MODS constant.';
		}

		// For PHP files, check DISALLOW_FILE_EDIT.
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( 'php' === $extension && defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
			return 'PHP file editing is disabled by DISALLOW_FILE_EDIT constant.';
		}

		// File size limit: 10MB max to prevent DoS.
		$max_size = 10 * 1024 * 1024; // 10MB
		if ( $size > $max_size ) {
			return 'File size exceeds 10MB limit.';
		}
		$filename  = basename( $path );
		$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		$dir       = dirname( $path );

		// Allow specific dotfiles that are legitimate config files.
		$allowed_dotfiles   = array( '.htaccess', '.htpasswd', '.user.ini' );
		$is_allowed_dotfile = in_array( $filename, $allowed_dotfiles, true );

		// Use WordPress sanitize_file_name to check for suspicious characters.
		if ( ! $is_allowed_dotfile ) {
			$sanitized = sanitize_file_name( $filename );
			if ( $sanitized !== $filename ) {
				return 'Filename contains invalid characters. WordPress sanitized version: ' . $sanitized;
			}
		}

		// Dangerous extensions that should never be written.
		$dangerous_anywhere = array( 'phar', 'exe', 'sh', 'bat', 'cmd', 'com', 'scr', 'cgi', 'pl', 'py' );
		if ( in_array( $extension, $dangerous_anywhere, true ) ) {
			return "Cannot write files with .{$extension} extension.";
		}

		// Block ALL PHP-like extensions everywhere.
		$php_extensions = array( 'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phar' );
		if ( in_array( $extension, $php_extensions, true ) ) {
			return 'Cannot write PHP files via filesystem abilities. Use plugins/upload for PHP deployment.';
		}

		// Block .htaccess in subdirectories (only allow in document root).
		if ( 'htaccess' === $extension && $filename === '.htaccess' ) {
			$real_dir = realpath( dirname( $path ) );
			$abspath  = rtrim( realpath( ABSPATH ), '/' );
			if ( $real_dir !== $abspath ) {
				return '.htaccess can only be modified in the site root directory.';
			}
			// Scan htaccess content for dangerous directives.
			if ( ! empty( $content ) ) {
				$dangerous_htaccess = array( 'AddType', 'SetHandler', 'php_value', 'php_flag', 'auto_prepend', 'auto_append' );
				foreach ( $dangerous_htaccess as $directive ) {
					if ( stripos( $content, $directive ) !== false ) {
						return "Dangerous .htaccess directive detected: {$directive}";
					}
				}
			}
		}

		// Use WordPress to validate the file type for upload-like operations.
		if ( ! empty( $extension ) ) {
			$allowed_mimes = get_allowed_mime_types();
			$filetype      = wp_check_filetype( $filename, $allowed_mimes );

			$always_allowed = array( 'htaccess', 'php', 'txt', 'log', 'json', 'xml', 'css', 'js', 'md', 'html', 'htm' );
			if ( ! in_array( $extension, $always_allowed, true ) && empty( $filetype['type'] ) ) {
				return "File type .{$extension} is not allowed by WordPress.";
			}
		}

		// Block files that look like web shells.
		$shell_patterns = array( 'c99', 'r57', 'wso', 'b374k', 'weevely', 'shell', 'alfa', 'bypass', 'backdoor' );
		$lower_filename = strtolower( $filename );
		foreach ( $shell_patterns as $pattern ) {
			if ( strpos( $lower_filename, $pattern ) !== false ) {
				return "Filename contains blocked pattern: {$pattern}";
			}
		}

		// Block double extensions that could be used to bypass filters.
		if ( preg_match( '/\.(php|phtml|phar)\.[^.]+$/i', $filename ) ) {
			return 'Double extensions with PHP are not allowed (e.g., file.php.jpg).';
		}

		// Content scanning for PHP files.
		$php_like_extensions = array( 'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phar' );
		if ( ! empty( $content ) && in_array( $extension, $php_like_extensions, true ) ) {
			$dangerous_patterns = array(
				'/\b(eval|assert|create_function)\s*\(/i',
				'/\b(base64_decode|gzinflate|gzuncompress|str_rot13)\s*\(/i',
				'/\b(shell_exec|exec|system|passthru|popen|proc_open)\s*\(/i',
				'/\$_(GET|POST|REQUEST|COOKIE)\s*\[.*\]\s*\(/i',
				'/\bpreg_replace\s*\(\s*[\'"].*\/e[\'"]/i',
				'/\\x[0-9a-fA-F]{2}/',
				'/\$[a-zA-Z_]\w*\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)/i',
				'/[\'"][a-z]{2,5}[\'"]\s*\.\s*[\'"][a-z]{2,5}[\'"]/i',
				'/\$\w+\s*=\s*[\'"][a-z_]+[\'"]\s*;\s*\$\w+\s*\(/i',
				'/\b(call_user_func|call_user_func_array)\s*\(/i',
				'/\b(array_map|array_filter|array_walk|array_reduce)\s*\(/i',
				'/\b(usort|uasort|uksort|preg_replace_callback)\s*\(/i',
				'/`[^`]+`/',
				'/\b(include|require|include_once|require_once)\s*\(\s*\$/i',
				'/\$\{/',
				'/\^/',
			);

			foreach ( $dangerous_patterns as $pattern ) {
				if ( preg_match( $pattern, $content ) ) {
					return 'PHP content contains potentially malicious code pattern.';
				}
			}
		}

		// POLYGLOT DETECTION: Scan ALL file content for PHP signatures.
		if ( ! empty( $content ) ) {
			$php_signatures = array(
				'<?php',
				'<?=',
				'<? ',
				"<?\t",
				"<?\n",
				"<?\r",
				'<%',
				'<script language="php">',
				"<script language='php'>",
				'+ADw-',  // UTF-7 encoded <
				"+ACE-",  // UTF-7 encoded !
				"<\x00?\x00",  // UTF-16LE encoded <?
				"\x00<\x00?",  // UTF-16BE encoded <?
			);

			foreach ( $php_signatures as $sig ) {
				if ( stripos( $content, $sig ) !== false ) {
					return 'File content contains PHP code. PHP cannot be embedded in any file type.';
				}
			}
		}

		return false;
	};

	/**
	 * Normalize the WordPress root path for prefix checks.
	 *
	 * @return string Normalized absolute root with trailing slash.
	 */
	$mcp_get_wp_root = function (): string {
		$root = realpath( ABSPATH );
		if ( false === $root ) {
			$root = ABSPATH;
		}
		return rtrim( wp_normalize_path( $root ), '/' ) . '/';
	};

	/**
	 * Check if a path is within the WordPress root directory.
	 *
	 * @param string $path Absolute path to validate.
	 * @return bool True if inside WordPress root.
	 */
	$mcp_is_path_in_wp_root = function ( string $path ) use ( $mcp_get_wp_root ): bool {
		$path = wp_normalize_path( $path );
		return strpos( $path, $mcp_get_wp_root() ) === 0;
	};

	// =========================================================================
	// FILESYSTEM - Get Changelog
	// =========================================================================
	wp_register_ability(
		'filesystem/get-changelog',
		array(
			'label'               => 'Get Filesystem Changelog',
			'description'         => '[FILESYSTEM] Returns recent filesystem operations log. Use this after context loss to understand what was changed.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'lines' => array(
						'type'        => 'integer',
						'description' => 'Number of lines to return (default 100, max 500).',
					),
				),
				'required'             => array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'log'     => array( 'type' => 'string' ),
					'path'    => array( 'type' => 'string' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input ): array {
				$log_file = WP_CONTENT_DIR . '/mcp-filesystem.log';
				$lines    = min( max( (int) ( $input['lines'] ?? 100 ), 1 ), 500 );

				if ( ! file_exists( $log_file ) ) {
					return array(
						'success' => true,
						'log'     => '',
						'path'    => $log_file,
						'message' => 'No filesystem operations have been logged yet.',
					);
				}

				global $wp_filesystem;
				if ( ! function_exists( 'WP_Filesystem' ) ) {
					require_once ABSPATH . 'wp-admin/includes/file.php';
				}
				WP_Filesystem();

				$content = $wp_filesystem->get_contents( $log_file );
				if ( false === $content ) {
					return array(
						'success' => false,
						'message' => 'Failed to read changelog.',
					);
				}

				$all_lines   = explode( "\n", $content );
				$total_lines = count( $all_lines );
				$start       = max( 0, $total_lines - $lines );
				$last_lines  = array_slice( $all_lines, $start );

				return array(
					'success' => true,
					'log'     => implode( "\n", $last_lines ),
					'path'    => $log_file,
					'message' => "Showing last {$lines} lines of {$total_lines} total.",
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
			'mcp' => array( 'public' => true, 'type' => 'tool' ),
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		),
		)
	);
error_log( "MCP Filesystem: Registered filesystem/get-changelog" );

	// =========================================================================
	// FILESYSTEM - Read File
	// =========================================================================
	wp_register_ability(
		'filesystem/read-file',
		array(
			'label'               => 'Read File',
			'description'         => '[FILESYSTEM] Reads file contents. Restricted to WordPress directory.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'path' => array(
						'type'        => 'string',
						'description' => 'File path (absolute or relative to WordPress root).',
					),
				),
				'required'             => array( 'path' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'  => array( 'type' => 'boolean' ),
					'content'  => array( 'type' => 'string' ),
					'path'     => array( 'type' => 'string' ),
					'size'     => array( 'type' => 'integer' ),
					'modified' => array( 'type' => 'string' ),
					'message'  => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input ) use ( $mcp_is_path_in_wp_root ): array {
				$path = $input['path'] ?? '';

				if ( empty( $path ) ) {
					return array(
						'success' => false,
						'message' => 'Path is required.',
					);
				}

				if ( strpos( $path, '/' ) !== 0 ) {
					$full_path = ABSPATH . $path;
				} else {
					$full_path = $path;
				}

				$full_path = realpath( $full_path );

				if ( false === $full_path ) {
					return array(
						'success' => false,
						'message' => 'File not found: ' . $input['path'],
					);
				}

				if ( ! $mcp_is_path_in_wp_root( $full_path ) ) {
					return array(
						'success' => false,
						'message' => 'Access denied. File must be within the WordPress root directory.',
					);
				}

				if ( ! is_file( $full_path ) ) {
					return array(
						'success' => false,
						'message' => 'Path is not a file: ' . $input['path'],
					);
				}

				// Initialize WP_Filesystem.
				global $wp_filesystem;
				if ( ! function_exists( 'WP_Filesystem' ) ) {
					require_once ABSPATH . 'wp-admin/includes/file.php';
				}
				WP_Filesystem();

				if ( ! $wp_filesystem->is_readable( $full_path ) ) {
					return array(
						'success' => false,
						'message' => 'File is not readable: ' . $input['path'],
					);
				}

				$content = $wp_filesystem->get_contents( $full_path );

				if ( false === $content ) {
					return array(
						'success' => false,
						'message' => 'Failed to read file: ' . $input['path'],
					);
				}

				return array(
					'success'  => true,
					'content'  => $content,
					'path'     => $full_path,
					'size'     => filesize( $full_path ),
					'modified' => gmdate( 'Y-m-d H:i:s', filemtime( $full_path ) ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
    'mcp' => array('public' => true, 'type' => 'tool'),
				'annotations' => array(
        'readonly'    => true,
        'destructive' => false,
        'idempotent'  => true,
    ),
    'mcp' => array(
        'public' => true,
        'type'   => 'tool',
    ),
),
		)
	);

	// =========================================================================
	// FILESYSTEM - Write File
	// =========================================================================
	wp_register_ability(
		'filesystem/write-file',
		array(
			'label'               => 'Write File',
			'description'         => '[FILESYSTEM] Creates/overwrites file. DESTRUCTIVE. Cannot modify core files.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'path'    => array(
						'type'        => 'string',
						'description' => 'File path (absolute or relative to WordPress root).',
					),
					'content' => array(
						'type'        => 'string',
						'description' => 'Content to write to the file.',
					),
					'backup'  => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Create a backup before overwriting (default: true).',
					),
					'context' => array(
						'type'        => 'string',
						'description' => 'Brief description of why this change is being made.',
					),
				),
				'required'             => array( 'path', 'content' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'     => array( 'type' => 'boolean' ),
					'message'     => array( 'type' => 'string' ),
					'path'        => array( 'type' => 'string' ),
					'backup_path' => array( 'type' => 'string' ),
					'bytes'       => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => function ( array $input ) use ( $mcp_create_backup, $mcp_log_filesystem_operation, $mcp_check_write_security, $mcp_is_path_in_wp_root ): array {
				$path    = $input['path'] ?? '';
				$content = $input['content'] ?? '';
				$backup  = $input['backup'] ?? true;
				$context = $input['context'] ?? '';

				if ( empty( $path ) ) {
					return array(
						'success' => false,
						'message' => 'Path is required.',
					);
				}

				if ( strpos( $path, '/' ) !== 0 ) {
					$full_path = ABSPATH . $path;
				} else {
					$full_path = $path;
				}

				$dir = dirname( $full_path );
				if ( ! is_dir( $dir ) ) {
					return array(
						'success' => false,
						'message' => 'Directory does not exist: ' . $dir,
					);
				}

				$real_dir = realpath( $dir );

				if ( ! $mcp_is_path_in_wp_root( $real_dir ) ) {
					return array(
						'success' => false,
						'message' => 'Access denied. File must be within the WordPress root directory.',
					);
				}

				$full_path_normalized = $real_dir . '/' . basename( $full_path );
				if ( strpos( $full_path_normalized, ABSPATH . 'wp-includes/' ) === 0 ||
					strpos( $full_path_normalized, ABSPATH . 'wp-admin/' ) === 0 ) {
					return array(
						'success' => false,
						'message' => 'Cannot modify WordPress core files in wp-includes or wp-admin.',
					);
				}

				$security_error = $mcp_check_write_security( $full_path_normalized, $content, strlen( $content ) );
				if ( $security_error ) {
					return array(
						'success' => false,
						'message' => $security_error,
					);
				}

				$backup_path  = null;
				$size_before  = file_exists( $full_path_normalized ) ? filesize( $full_path_normalized ) : 0;

				if ( $backup && file_exists( $full_path_normalized ) ) {
					$backup_path = $mcp_create_backup( $full_path_normalized );
					if ( false === $backup_path ) {
						return array(
							'success' => false,
							'message' => 'Failed to create backup.',
						);
					}
				}

				global $wp_filesystem;
				if ( ! function_exists( 'WP_Filesystem' ) ) {
					require_once ABSPATH . 'wp-admin/includes/file.php';
				}
				WP_Filesystem();

				$written = $wp_filesystem->put_contents( $full_path_normalized, $content, FS_CHMOD_FILE );

				if ( ! $written ) {
					return array(
						'success' => false,
						'message' => 'Failed to write file: ' . $path,
					);
				}

				$mcp_log_filesystem_operation( 'WRITE', $full_path_normalized, array(
					'backup'      => $backup_path,
					'size_before' => $size_before,
					'size_after'  => strlen( $content ),
					'context'     => $context,
				) );

				$result = array(
					'success' => true,
					'message' => 'File written successfully.',
					'path'    => $full_path_normalized,
					'bytes'   => strlen( $content ),
				);

				if ( $backup_path ) {
					$result['backup_path'] = $backup_path;
				}

				return $result;
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'mcp' => array('public' => true, 'type' => 'tool'),
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// FILESYSTEM - Append to File
	// =========================================================================
	wp_register_ability(
		'filesystem/append-file',
		array(
			'label'               => 'Append to File',
			'description'         => '[FILESYSTEM] Appends to file (e.g., .htaccess rules).',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'path'    => array(
						'type'        => 'string',
						'description' => 'File path (absolute or relative to WordPress root).',
					),
					'content' => array(
						'type'        => 'string',
						'description' => 'Content to append to the file.',
					),
					'prepend' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'If true, add content to the beginning instead of the end.',
					),
					'backup'  => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Create a backup before modifying (default: true).',
					),
					'context' => array(
						'type'        => 'string',
						'description' => 'Brief description of why this change is being made.',
					),
				),
				'required'             => array( 'path', 'content' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'     => array( 'type' => 'boolean' ),
					'message'     => array( 'type' => 'string' ),
					'path'        => array( 'type' => 'string' ),
					'backup_path' => array( 'type' => 'string' ),
					'bytes'       => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => function ( array $input ) use ( $mcp_create_backup, $mcp_log_filesystem_operation, $mcp_check_write_security, $mcp_is_path_in_wp_root ): array {
				$path    = $input['path'] ?? '';
				$content = $input['content'] ?? '';
				$prepend = $input['prepend'] ?? false;
				$backup  = $input['backup'] ?? true;
				$context = $input['context'] ?? '';

				if ( empty( $path ) ) {
					return array(
						'success' => false,
						'message' => 'Path is required.',
					);
				}

				if ( strpos( $path, '/' ) !== 0 ) {
					$full_path = ABSPATH . $path;
				} else {
					$full_path = $path;
				}

				$full_path = realpath( $full_path );

				if ( false === $full_path || ! file_exists( $full_path ) ) {
					return array(
						'success' => false,
						'message' => 'File not found: ' . $input['path'],
					);
				}

				if ( ! $mcp_is_path_in_wp_root( $full_path ) ) {
					return array(
						'success' => false,
						'message' => 'Access denied. File must be within the WordPress root directory.',
					);
				}

				if ( strpos( $full_path, ABSPATH . 'wp-includes/' ) === 0 ||
					strpos( $full_path, ABSPATH . 'wp-admin/' ) === 0 ) {
					return array(
						'success' => false,
						'message' => 'Cannot modify WordPress core files.',
					);
				}

				$security_error = $mcp_check_write_security( $full_path, $content, strlen( $content ) );
				if ( $security_error ) {
					return array(
						'success' => false,
						'message' => $security_error,
					);
				}

				$backup_path = null;
				$size_before = filesize( $full_path );

				if ( $backup ) {
					$backup_path = $mcp_create_backup( $full_path );
					if ( false === $backup_path ) {
						return array(
							'success' => false,
							'message' => 'Failed to create backup.',
						);
					}
				}

				global $wp_filesystem;
				if ( ! function_exists( 'WP_Filesystem' ) ) {
					require_once ABSPATH . 'wp-admin/includes/file.php';
				}
				WP_Filesystem();

				$existing = $wp_filesystem->get_contents( $full_path );
				if ( false === $existing ) {
					return array(
						'success' => false,
						'message' => 'Failed to read file before append.',
					);
				}

				$new_content = $prepend ? $content . $existing : $existing . $content;
				$written     = $wp_filesystem->put_contents( $full_path, $new_content, FS_CHMOD_FILE );

				if ( ! $written ) {
					return array(
						'success' => false,
						'message' => 'Failed to append to file.',
					);
				}

				$size_after = filesize( $full_path );
				$bytes      = max( 0, $size_after - $size_before );

				$mcp_log_filesystem_operation( 'APPEND', $full_path, array(
					'backup'      => $backup_path,
					'size_before' => $size_before,
					'size_after'  => $size_after,
					'context'     => $context . ( $prepend ? ' (prepend)' : '' ),
				) );

				$result = array(
					'success' => true,
					'message' => 'Content appended successfully.',
					'path'    => $full_path,
					'bytes'   => $bytes,
				);

				if ( $backup_path ) {
					$result['backup_path'] = $backup_path;
				}

				return $result;
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'mcp' => array('public' => true, 'type' => 'tool'),
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// FILESYSTEM - List Directory
	// =========================================================================
	wp_register_ability(
		'filesystem/list-directory',
		array(
			'label'               => 'List Directory',
			'description'         => '[FILESYSTEM] Lists directory contents. Safe read-only operation.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'path'      => array(
						'type'        => 'string',
						'default'     => '.',
						'description' => 'Directory path (absolute or relative to WordPress root).',
					),
					'recursive' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Include subdirectories recursively (max 2 levels deep).',
					),
					'pattern'   => array(
						'type'        => 'string',
						'description' => 'Filter files by pattern (e.g., "*.php", "*.js").',
					),
				),
				'required'             => array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'path'    => array( 'type' => 'string' ),
					'items'   => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'name'     => array( 'type' => 'string' ),
								'type'     => array( 'type' => 'string' ),
								'size'     => array( 'type' => 'integer' ),
								'modified' => array( 'type' => 'string' ),
								'path'     => array( 'type' => 'string' ),
							),
						),
					),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input ) use ( $mcp_is_path_in_wp_root ): array {
				$path      = $input['path'] ?? '.';
				$recursive = $input['recursive'] ?? false;
				$pattern   = $input['pattern'] ?? null;

				if ( '.' === $path || empty( $path ) ) {
					$full_path = ABSPATH;
				} elseif ( strpos( $path, '/' ) !== 0 ) {
					$full_path = ABSPATH . $path;
				} else {
					$full_path = $path;
				}

				$full_path = realpath( $full_path );

				if ( false === $full_path ) {
					return array(
						'success' => false,
						'message' => 'Directory not found: ' . $input['path'],
					);
				}

				if ( ! $mcp_is_path_in_wp_root( $full_path ) ) {
					return array(
						'success' => false,
						'message' => 'Access denied. Directory must be within the WordPress root directory.',
					);
				}

				if ( ! is_dir( $full_path ) ) {
					return array(
						'success' => false,
						'message' => 'Path is not a directory.',
					);
				}

				$items = array();

				$list_dir = function ( $dir, $depth = 0 ) use ( &$list_dir, &$items, $recursive, $pattern ) {
					if ( $depth > 2 ) {
						return;
					}

					$files = scandir( $dir );
					foreach ( $files as $file ) {
						if ( '.' === $file || '..' === $file ) {
							continue;
						}

						$file_path = $dir . '/' . $file;

						if ( $pattern && ! fnmatch( $pattern, $file ) && is_file( $file_path ) ) {
							continue;
						}

						$is_dir = is_dir( $file_path );
						$items[] = array(
							'name'     => $file,
							'type'     => $is_dir ? 'directory' : 'file',
							'size'     => $is_dir ? 0 : filesize( $file_path ),
							'modified' => gmdate( 'Y-m-d H:i:s', filemtime( $file_path ) ),
							'path'     => $file_path,
						);

						if ( $recursive && $is_dir ) {
							$list_dir( $file_path, $depth + 1 );
						}
					}
				};

				$list_dir( $full_path );

				return array(
					'success' => true,
					'path'    => $full_path,
					'items'   => $items,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'mcp' => array('public' => true, 'type' => 'tool'),
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// FILESYSTEM - Delete File
	// =========================================================================
	wp_register_ability(
		'filesystem/delete-file',
		array(
			'label'               => 'Delete File',
			'description'         => '[FILESYSTEM] Deletes file. DESTRUCTIVE. Creates backup by default.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'path'    => array(
						'type'        => 'string',
						'description' => 'File path to delete.',
					),
					'backup'  => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Create a backup before deleting (default: true).',
					),
					'context' => array(
						'type'        => 'string',
						'description' => 'Brief description of why this file is being deleted.',
					),
				),
				'required'             => array( 'path' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'     => array( 'type' => 'boolean' ),
					'message'     => array( 'type' => 'string' ),
					'backup_path' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input ) use ( $mcp_create_backup, $mcp_log_filesystem_operation, $mcp_is_path_in_wp_root ): array {
				$path    = $input['path'] ?? '';
				$backup  = $input['backup'] ?? true;
				$context = $input['context'] ?? '';

				if ( empty( $path ) ) {
					return array(
						'success' => false,
						'message' => 'Path is required.',
					);
				}

				if ( strpos( $path, '/' ) !== 0 ) {
					$full_path = ABSPATH . $path;
				} else {
					$full_path = $path;
				}

				$full_path = realpath( $full_path );

				if ( false === $full_path || ! file_exists( $full_path ) ) {
					return array(
						'success' => false,
						'message' => 'File not found.',
					);
				}

				if ( ! $mcp_is_path_in_wp_root( $full_path ) ) {
					return array(
						'success' => false,
						'message' => 'Access denied. File must be within the WordPress root directory.',
					);
				}

				if ( is_dir( $full_path ) ) {
					return array(
						'success' => false,
						'message' => 'Cannot delete directories. Use filesystem/delete-directory instead.',
					);
				}

				if ( strpos( $full_path, ABSPATH . 'wp-includes/' ) === 0 ||
					strpos( $full_path, ABSPATH . 'wp-admin/' ) === 0 ) {
					return array(
						'success' => false,
						'message' => 'Cannot delete WordPress core files.',
					);
				}

				$critical = array( 'wp-config.php', '.htaccess', 'index.php' );
				if ( in_array( basename( $full_path ), $critical, true ) && dirname( $full_path ) === rtrim( ABSPATH, '/' ) ) {
					return array(
						'success' => false,
						'message' => 'Cannot delete critical WordPress files.',
					);
				}

				$file_size   = filesize( $full_path );
				$backup_path = null;

				if ( $backup ) {
					$backup_path = $mcp_create_backup( $full_path );
					if ( false === $backup_path ) {
						return array(
							'success' => false,
							'message' => 'Failed to create backup.',
						);
					}
				}

				wp_delete_file( $full_path );
				if ( file_exists( $full_path ) ) {
					return array(
						'success' => false,
						'message' => 'Failed to delete file.',
					);
				}

				$mcp_log_filesystem_operation( 'DELETE', $full_path, array(
					'backup'      => $backup_path,
					'size_before' => $file_size,
					'context'     => $context,
				) );

				$result = array(
					'success' => true,
					'message' => 'File deleted successfully.',
				);

				if ( $backup_path ) {
					$result['backup_path'] = $backup_path;
				}

				return $result;
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'mcp' => array('public' => true, 'type' => 'tool'),
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// FILESYSTEM - File Info
	// =========================================================================
	wp_register_ability(
		'filesystem/file-info',
		array(
			'label'               => 'Get File Info',
			'description'         => '[FILESYSTEM] Gets file/directory metadata. Safe read-only operation.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'path' => array(
						'type'        => 'string',
						'description' => 'File or directory path.',
					),
				),
				'required'             => array( 'path' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'     => array( 'type' => 'boolean' ),
					'path'        => array( 'type' => 'string' ),
					'type'        => array( 'type' => 'string' ),
					'size'        => array( 'type' => 'integer' ),
					'permissions' => array( 'type' => 'string' ),
					'owner'       => array( 'type' => 'string' ),
					'group'       => array( 'type' => 'string' ),
					'created'     => array( 'type' => 'string' ),
					'modified'    => array( 'type' => 'string' ),
					'accessed'    => array( 'type' => 'string' ),
					'readable'    => array( 'type' => 'boolean' ),
					'writable'    => array( 'type' => 'boolean' ),
					'message'     => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input ) use ( $mcp_is_path_in_wp_root ): array {
				$path = $input['path'] ?? '';

				if ( empty( $path ) ) {
					return array(
						'success' => false,
						'message' => 'Path is required.',
					);
				}

				if ( strpos( $path, '/' ) !== 0 ) {
					$full_path = ABSPATH . $path;
				} else {
					$full_path = $path;
				}

				$full_path = realpath( $full_path );

				if ( false === $full_path ) {
					return array(
						'success' => false,
						'message' => 'Path not found.',
					);
				}

				if ( ! $mcp_is_path_in_wp_root( $full_path ) ) {
					return array(
						'success' => false,
						'message' => 'Access denied. Path must be within the WordPress root directory.',
					);
				}

				$stat = stat( $full_path );

				// Initialize WP_Filesystem for permission checks.
				global $wp_filesystem;
				if ( ! function_exists( 'WP_Filesystem' ) ) {
					require_once ABSPATH . 'wp-admin/includes/file.php';
				}
				WP_Filesystem();

				return array(
					'success'     => true,
					'path'        => $full_path,
					'type'        => is_dir( $full_path ) ? 'directory' : 'file',
					'size'        => $stat['size'],
					'permissions' => substr( sprintf( '%o', fileperms( $full_path ) ), -4 ),
					'owner'       => function_exists( 'posix_getpwuid' ) ? posix_getpwuid( $stat['uid'] )['name'] ?? $stat['uid'] : $stat['uid'],
					'group'       => function_exists( 'posix_getgrgid' ) ? posix_getgrgid( $stat['gid'] )['name'] ?? $stat['gid'] : $stat['gid'],
					'created'     => gmdate( 'Y-m-d H:i:s', $stat['ctime'] ),
					'modified'    => gmdate( 'Y-m-d H:i:s', $stat['mtime'] ),
					'accessed'    => gmdate( 'Y-m-d H:i:s', $stat['atime'] ),
					'readable'    => $wp_filesystem->is_readable( $full_path ),
					'writable'    => $wp_filesystem->is_writable( $full_path ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'mcp' => array('public' => true, 'type' => 'tool'),
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// FILESYSTEM - Create Directory
	// =========================================================================
	wp_register_ability(
		'filesystem/create-directory',
		array(
			'label'               => 'Create Directory',
			'description'         => '[FILESYSTEM] Creates directory.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'path'        => array(
						'type'        => 'string',
						'description' => 'Directory path to create.',
					),
					'permissions' => array(
						'type'        => 'string',
						'default'     => '0755',
						'description' => 'Permissions for the new directory (default: 0755).',
					),
					'recursive'   => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Create parent directories if they do not exist.',
					),
				),
				'required'             => array( 'path' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
					'path'    => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input ): array {
				$path        = $input['path'] ?? '';
				$permissions = octdec( $input['permissions'] ?? '0755' );
				$recursive   = $input['recursive'] ?? true;

				if ( empty( $path ) ) {
					return array(
						'success' => false,
						'message' => 'Path is required.',
					);
				}

				if ( strpos( $path, '/' ) !== 0 ) {
					$full_path = ABSPATH . $path;
				} else {
					$full_path = $path;
				}

				$parent = dirname( $full_path );
				$real_parent = realpath( $parent );

			