<?php
/**
 * Plugin Name: MCP Abilities - Filesystem
 * Plugin URI: https://devenia.com/plugins/mcp-abilities-filesystem/
 * Description: Read, inspect, and manage permitted WordPress files through authenticated abilities with path, content, and backup checks.
 * Version: 1.0.11
 * Author: basicus
 * Author URI: https://profiles.wordpress.org/basicus/
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

require_once __DIR__ . '/includes/devenia-updater-notice.php';
mcp_abilities_filesystem_Updater_Notice::register( __FILE__ );

/**
 * Check if Abilities API is available.
 */
function mcp_filesystem_check_dependencies(): bool {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>MCP Abilities - Filesystem</strong> requires WordPress 6.9 or newer with the built-in Abilities API available.</p></div>';
		} );
		return false;
	}
	return true;
}

/**
 * Register filesystem abilities.
 */
function mcp_register_filesystem_abilities(): void {
	if ( ! mcp_filesystem_check_dependencies() ) {
		return;
	}

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

			// Append to log file (create if it doesn't exist) via WP_Filesystem only.
			global $wp_filesystem;
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			WP_Filesystem();

			if ( is_object( $wp_filesystem ) ) {
				$existing_log = $wp_filesystem->exists( $log_file ) ? $wp_filesystem->get_contents( $log_file ) : '';
				if ( false === $existing_log ) {
					$existing_log = '';
				}
				$wp_filesystem->put_contents( $log_file, $existing_log . $entry, FS_CHMOD_FILE );
			}

		// Cleanup old backups occasionally (1 in 10 chance to avoid overhead).
		if ( wp_rand( 1, 10 ) === 1 ) {
			$mcp_cleanup_old_backups();
		}
	};

	$mcp_modifications_disabled = function (): bool {
		return defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS;
	};

	$mcp_is_core_path = function ( string $path ): bool {
		$root = rtrim( wp_normalize_path( realpath( ABSPATH ) ), '/' );
		$path = rtrim( wp_normalize_path( $path ), '/' );
		foreach ( array( 'wp-admin', 'wp-includes' ) as $directory ) {
			$core = $root . '/' . $directory;
			if ( $path === $core || strpos( $path, $core . '/' ) === 0 ) {
				return true;
			}
		}
		return false;
	};

	/**
	 * Check if a file write should be blocked for security reasons.
	 *
	 * @param string $path    The file path to check.
	 * @param string $content Optional content to scan for malicious patterns.
	 * @param int    $size    Optional content size in bytes.
	 * @return string|false Error message if blocked, false if allowed.
	 */
	$mcp_check_write_security = function ( string $path, string $content = '', int $size = 0 ) use ( $mcp_is_core_path, $mcp_modifications_disabled ): string|false {
		if ( $mcp_is_core_path( $path ) ) {
			return 'Cannot modify WordPress core files.';
		}
		// Respect WordPress DISALLOW_FILE_EDIT and DISALLOW_FILE_MODS constants.
		if ( $mcp_modifications_disabled() ) {
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
		$mcp_is_path_in_wp_root = function ( string $path, bool $allow_root = false ) use ( $mcp_get_wp_root ): bool {
			$path = wp_normalize_path( $path );
			return strpos( $path, $mcp_get_wp_root() ) === 0 || ( $allow_root && $path === rtrim( $mcp_get_wp_root(), '/' ) );
		};

	/** Resolve a target through its nearest existing canonical parent. */
	$mcp_resolve_destination = function ( string $path, bool $recursive = false ) use ( $mcp_is_path_in_wp_root ): string|false {
		if ( file_exists( $path ) || is_link( $path ) ) {
			$resolved = realpath( $path );
		} else {
			$ancestor = dirname( $path );
			$segments = array( basename( $path ) );
			while ( $recursive && ! file_exists( $ancestor ) && ! is_link( $ancestor ) && dirname( $ancestor ) !== $ancestor ) {
				array_unshift( $segments, basename( $ancestor ) );
				$ancestor = dirname( $ancestor );
			}
			if ( array_intersect( array( '.', '..', '' ), $segments ) ) {
				return false;
			}
			$parent = realpath( $ancestor );
			$resolved = false === $parent || ! is_dir( $parent ) ? false : $parent . '/' . implode( '/', $segments );
		}
		if ( false === $resolved || ! $mcp_is_path_in_wp_root( $resolved ) || is_dir( $resolved ) ) {
			return false;
		}
		return $resolved;
	};

		/**
		 * Block reads of sensitive config/secret files.
		 *
		 * @param string $full_path Absolute normalized path.
		 * @return bool
		 */
		$mcp_is_sensitive_read_path = function ( string $full_path ): bool {
			$normalized = strtolower( wp_normalize_path( $full_path ) );
			$basename   = basename( $normalized );

			$blocked_exact = array(
				strtolower( wp_normalize_path( ABSPATH . 'wp-config.php' ) ),
			);
			if ( in_array( $normalized, $blocked_exact, true ) ) {
				return true;
			}

			$blocked_names = array(
				'.env',
				'.env.local',
				'.env.production',
				'.env.development',
				'id_rsa',
				'id_dsa',
				'authorized_keys',
			);
			if ( in_array( $basename, $blocked_names, true ) ) {
				return true;
			}

			if ( preg_match( '/\.(key|pem|p12|pfx|crt)$/i', $basename ) ) {
				return true;
			}

			return false;
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
		)
	);

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
						'max_bytes' => array(
							'type'        => 'integer',
							'default'     => 262144,
							'minimum'     => 1,
							'maximum'     => 1048576,
							'description' => 'Maximum allowed file size to read (default 256KB, max 1MB).',
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
				'execute_callback'    => function ( array $input ) use ( $mcp_is_path_in_wp_root, $mcp_is_sensitive_read_path ): array {
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

					if ( $mcp_is_sensitive_read_path( $full_path ) ) {
						return array(
							'success' => false,
							'message' => 'Access denied for sensitive file path.',
						);
					}

					$file_size = filesize( $full_path );
					if ( false === $file_size ) {
						return array(
							'success' => false,
							'message' => 'Failed to stat file size: ' . $input['path'],
						);
					}
					$max_bytes = isset( $input['max_bytes'] ) ? (int) $input['max_bytes'] : 262144;
					$max_bytes = max( 1, min( 1048576, $max_bytes ) );
					if ( $file_size > $max_bytes ) {
						return array(
							'success' => false,
							'message' => "File is too large to read safely ({$file_size} bytes). Increase max_bytes up to 1048576 if needed.",
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
						'size'     => $file_size,
						'modified' => gmdate( 'Y-m-d H:i:s', filemtime( $full_path ) ),
					);
				},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
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
			'execute_callback'    => function ( array $input ) use ( $mcp_create_backup, $mcp_log_filesystem_operation, $mcp_check_write_security, $mcp_resolve_destination ): array {
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

				$full_path_normalized = $mcp_resolve_destination( $full_path );
				if ( false === $full_path_normalized ) {
					return array( 'success' => false, 'message' => 'Access denied. File must be within the WordPress root directory.' );
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
				$security_error = $mcp_check_write_security( $full_path, $new_content, strlen( $new_content ) );
				if ( $security_error ) {
					return array( 'success' => false, 'message' => $security_error );
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
					'max_depth' => array(
						'type'        => 'integer',
						'description' => 'Max recursion depth when recursive is true (default 2, max 8).',
					),
						'pattern'   => array(
							'type'        => 'string',
							'description' => 'Filter files by pattern (e.g., "*.php", "*.js").',
						),
						'max_items' => array(
							'type'        => 'integer',
							'default'     => 1000,
							'minimum'     => 1,
							'maximum'     => 5000,
							'description' => 'Maximum number of items to return before truncating.',
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
						'returned' => array( 'type' => 'integer' ),
						'truncated' => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => function ( array $input ) use ( $mcp_is_path_in_wp_root ): array {
					$path      = $input['path'] ?? '.';
					$recursive = $input['recursive'] ?? false;
					$pattern   = $input['pattern'] ?? null;
					$max_depth = isset( $input['max_depth'] ) ? (int) $input['max_depth'] : 2;
					$max_depth = max( 1, min( 8, $max_depth ) );
					$max_items = isset( $input['max_items'] ) ? (int) $input['max_items'] : 1000;
					$max_items = max( 1, min( 5000, $max_items ) );

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

				if ( ! $mcp_is_path_in_wp_root( $full_path, true ) ) {
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
					$truncated = false;

					$list_dir = function ( $dir, $depth = 0 ) use ( &$list_dir, &$items, &$truncated, $recursive, $pattern, $max_depth, $max_items, $mcp_is_path_in_wp_root ) {
						if ( $depth > $max_depth ) {
							return;
						}

					$files = scandir( $dir );
						foreach ( $files as $file ) {
							if ( count( $items ) >= $max_items ) {
								$truncated = true;
								return;
							}

							if ( '.' === $file || '..' === $file ) {
								continue;
							}

						$file_path = $dir . '/' . $file;
						$canonical_path = realpath( $file_path );
						if ( false === $canonical_path || ! $mcp_is_path_in_wp_root( $canonical_path ) ) {
							continue;
						}

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
								if ( $truncated ) {
									return;
								}
							}
						}
					};

				$list_dir( $full_path );

					return array(
						'success'   => true,
						'path'      => $full_path,
						'items'     => $items,
						'returned'  => count( $items ),
						'truncated' => $truncated,
						'message'   => $truncated ? 'Result truncated at max_items limit.' : 'Directory listed successfully.',
					);
				},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
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
			'execute_callback'    => function ( array $input ) use ( $mcp_modifications_disabled, $mcp_is_core_path, $mcp_create_backup, $mcp_log_filesystem_operation, $mcp_is_path_in_wp_root ): array {
				if ( $mcp_modifications_disabled() ) {
					return array( 'success' => false, 'message' => 'File modifications are disabled by DISALLOW_FILE_MODS constant.' );
				}

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

				if ( $mcp_is_core_path( $full_path ) ) {
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
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// FILESYSTEM - Delete Directory
	// =========================================================================
	wp_register_ability(
		'filesystem/delete-directory',
		array(
			'label'               => 'Delete Directory',
			'description'         => '[FILESYSTEM] Deletes a directory. DESTRUCTIVE. Recursive delete optional.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'path' ),
				'properties'           => array(
					'path'      => array(
						'type'        => 'string',
						'description' => 'Directory path to delete.',
					),
					'recursive' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Delete recursively (default false).',
					),
					'context'   => array(
						'type'        => 'string',
						'description' => 'Brief description of why this directory is being deleted.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input ) use ( $mcp_modifications_disabled, $mcp_is_core_path, $mcp_log_filesystem_operation, $mcp_is_path_in_wp_root ): array {
				if ( $mcp_modifications_disabled() ) {
					return array( 'success' => false, 'message' => 'File modifications are disabled by DISALLOW_FILE_MODS constant.' );
				}

				$path      = $input['path'] ?? '';
				$recursive = ! empty( $input['recursive'] );
				$context   = $input['context'] ?? '';

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
						'message' => 'Directory not found.',
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

				if ( $mcp_is_core_path( $full_path ) ) {
					return array(
						'success' => false,
						'message' => 'Cannot delete WordPress core directories.',
					);
				}

				global $wp_filesystem;
				if ( ! function_exists( 'WP_Filesystem' ) ) {
					require_once ABSPATH . 'wp-admin/includes/file.php';
				}
				WP_Filesystem();

				$deleted = $wp_filesystem->delete( $full_path, $recursive, 'd' );
				if ( ! $deleted ) {
					return array(
						'success' => false,
						'message' => 'Failed to delete directory.',
					);
				}

				$mcp_log_filesystem_operation( 'DELETE_DIRECTORY', $full_path, array(
					'recursive' => $recursive,
					'context'   => $context,
				) );

				return array(
					'success' => true,
					'message' => 'Directory deleted successfully.',
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
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

				if ( ! $mcp_is_path_in_wp_root( $full_path, true ) ) {
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
			'execute_callback'    => function ( array $input ) use ( $mcp_modifications_disabled, $mcp_resolve_destination ): array {
				if ( $mcp_modifications_disabled() ) {
					return array( 'success' => false, 'message' => 'File modifications are disabled by DISALLOW_FILE_MODS constant.' );
				}

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

				$full_path = $mcp_resolve_destination( $full_path, $recursive );
				if ( false === $full_path ) {
					return array( 'success' => false, 'message' => 'Destination must be a new path within the WordPress root directory.' );
				}

				if ( file_exists( $full_path ) ) {
					return array(
						'success' => false,
						'message' => 'Path already exists.',
					);
				}

				// Initialize WP_Filesystem.
				global $wp_filesystem;
				if ( ! function_exists( 'WP_Filesystem' ) ) {
					require_once ABSPATH . 'wp-admin/includes/file.php';
				}
				WP_Filesystem();

				$created = $recursive ? wp_mkdir_p( $full_path ) : $wp_filesystem->mkdir( $full_path, $permissions );
				if ( ! $created ) {
					return array(
						'success' => false,
						'message' => 'Failed to create directory.',
					);
				}

				// Apply custom permissions if different from default.
				if ( $permissions !== 0755 ) {
					$wp_filesystem->chmod( $full_path, $permissions );
				}

				return array(
					'success' => true,
					'message' => 'Directory created successfully.',
					'path'    => realpath( $full_path ),
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// FILESYSTEM - Copy File
	// =========================================================================
	wp_register_ability(
		'filesystem/copy-file',
		array(
			'label'               => 'Copy File',
			'description'         => '[FILESYSTEM] Copies file.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'source'    => array(
						'type'        => 'string',
						'description' => 'Source file path.',
					),
					'dest'      => array(
						'type'        => 'string',
						'description' => 'Destination file path.',
					),
					'overwrite' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Overwrite destination if it exists.',
					),
					'context'   => array(
						'type'        => 'string',
						'description' => 'Brief description of why this copy is being made.',
					),
				),
				'required'             => array( 'source', 'dest' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'     => array( 'type' => 'boolean' ),
					'message'     => array( 'type' => 'string' ),
					'source'      => array( 'type' => 'string' ),
					'dest'        => array( 'type' => 'string' ),
					'backup_path' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input ) use ( $mcp_create_backup, $mcp_log_filesystem_operation, $mcp_check_write_security, $mcp_is_path_in_wp_root, $mcp_resolve_destination, $mcp_is_sensitive_read_path ): array {
				$source    = $input['source'] ?? '';
				$dest      = $input['dest'] ?? '';
				$overwrite = $input['overwrite'] ?? false;
				$context   = $input['context'] ?? '';

				if ( empty( $source ) || empty( $dest ) ) {
					return array(
						'success' => false,
						'message' => 'Source and destination are required.',
					);
				}

				$source_path = strpos( $source, '/' ) !== 0 ? ABSPATH . $source : $source;
				$dest_path   = strpos( $dest, '/' ) !== 0 ? ABSPATH . $dest : $dest;

				$source_path = realpath( $source_path );

				if ( false === $source_path ) {
					return array(
						'success' => false,
						'message' => 'Source file not found.',
					);
				}

				if ( ! $mcp_is_path_in_wp_root( $source_path ) ) {
					return array(
						'success' => false,
						'message' => 'Source access denied. Path must be within the WordPress root directory.',
					);
				}

				$final_dest = $mcp_resolve_destination( $dest_path );
				if ( false === $final_dest ) {
					return array( 'success' => false, 'message' => 'Destination access denied. Path must be within the WordPress root directory.' );
				}
				if ( $mcp_is_sensitive_read_path( $source_path ) || ! is_file( $source_path ) ) {
					return array( 'success' => false, 'message' => 'Source access denied.' );
				}
				global $wp_filesystem;
				if ( ! function_exists( 'WP_Filesystem' ) ) {
					require_once ABSPATH . 'wp-admin/includes/file.php';
				}
				WP_Filesystem();
				$source_content = $wp_filesystem->get_contents( $source_path );
				if ( false === $source_content ) {
					return array( 'success' => false, 'message' => 'Failed to read source file.' );
				}
				$security_error = $mcp_check_write_security( $final_dest, $source_content, strlen( $source_content ) );
				if ( $security_error ) {
					return array(
						'success' => false,
						'message' => $security_error,
					);
				}

				$backup_path = null;

				if ( file_exists( $final_dest ) ) {
					if ( ! $overwrite ) {
						return array(
							'success' => false,
							'message' => 'Destination already exists. Use overwrite=true to replace.',
						);
					}
					$backup_path = $mcp_create_backup( $final_dest );
					if ( false === $backup_path ) {
						return array( 'success' => false, 'message' => 'Failed to create destination backup.' );
					}
				}

				if ( ! $wp_filesystem->copy( $source_path, $final_dest, $overwrite, FS_CHMOD_FILE ) ) {
					return array(
						'success' => false,
						'message' => 'Failed to copy file.',
					);
				}

				$mcp_log_filesystem_operation( 'COPY', $source_path, array(
					'destination' => $final_dest,
					'backup'      => $backup_path,
					'context'     => $context,
				) );

				$result = array(
					'success' => true,
					'message' => 'File copied successfully.',
					'source'  => $source_path,
					'dest'    => $final_dest,
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
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// FILESYSTEM - Move/Rename File
	// =========================================================================
	wp_register_ability(
		'filesystem/move-file',
		array(
			'label'               => 'Move/Rename File',
			'description'         => '[FILESYSTEM] Moves/renames file. DESTRUCTIVE.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'source'    => array(
						'type'        => 'string',
						'description' => 'Source file path.',
					),
					'dest'      => array(
						'type'        => 'string',
						'description' => 'Destination file path.',
					),
					'overwrite' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Overwrite destination if it exists.',
					),
					'context'   => array(
						'type'        => 'string',
						'description' => 'Brief description of why this move is being made.',
					),
				),
				'required'             => array( 'source', 'dest' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'            => array( 'type' => 'boolean' ),
					'message'            => array( 'type' => 'string' ),
					'source'             => array( 'type' => 'string' ),
					'dest'               => array( 'type' => 'string' ),
					'source_backup_path' => array( 'type' => 'string' ),
					'dest_backup_path'   => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input ) use ( $mcp_is_core_path, $mcp_create_backup, $mcp_log_filesystem_operation, $mcp_check_write_security, $mcp_is_path_in_wp_root, $mcp_resolve_destination, $mcp_is_sensitive_read_path ): array {
				$source    = $input['source'] ?? '';
				$dest      = $input['dest'] ?? '';
				$overwrite = $input['overwrite'] ?? false;
				$context   = $input['context'] ?? '';

				if ( empty( $source ) || empty( $dest ) ) {
					return array(
						'success' => false,
						'message' => 'Source and destination are required.',
					);
				}

				$source_path = strpos( $source, '/' ) !== 0 ? ABSPATH . $source : $source;
				$dest_path   = strpos( $dest, '/' ) !== 0 ? ABSPATH . $dest : $dest;

				$source_path = realpath( $source_path );

				if ( false === $source_path ) {
					return array(
						'success' => false,
						'message' => 'Source file not found.',
					);
				}

				if ( ! $mcp_is_path_in_wp_root( $source_path ) ) {
					return array(
						'success' => false,
						'message' => 'Source access denied. Path must be within the WordPress root directory.',
					);
				}

				if ( $mcp_is_core_path( $source_path ) ) {
					return array(
						'success' => false,
						'message' => 'Cannot move WordPress core files.',
					);
				}

				$final_dest = $mcp_resolve_destination( $dest_path );
				if ( false === $final_dest ) {
					return array( 'success' => false, 'message' => 'Destination access denied. Path must be within the WordPress root directory.' );
				}
				if ( $mcp_is_sensitive_read_path( $source_path ) || ! is_file( $source_path ) ) {
					return array( 'success' => false, 'message' => 'Source access denied.' );
				}
				global $wp_filesystem;
				if ( ! function_exists( 'WP_Filesystem' ) ) {
					require_once ABSPATH . 'wp-admin/includes/file.php';
				}
				WP_Filesystem();
				$source_content = $wp_filesystem->get_contents( $source_path );
				if ( false === $source_content ) {
					return array( 'success' => false, 'message' => 'Failed to read source file.' );
				}
				$security_error = $mcp_check_write_security( $final_dest, $source_content, strlen( $source_content ) );
				if ( $security_error ) {
					return array(
						'success' => false,
						'message' => $security_error,
					);
				}

				$source_backup_path = null;
				$dest_backup_path   = null;

				$source_backup_path = $mcp_create_backup( $source_path );
				if ( false === $source_backup_path ) {
					return array(
						'success' => false,
						'message' => 'Failed to create source backup.',
					);
				}

				if ( file_exists( $final_dest ) ) {
					if ( ! $overwrite ) {
						return array(
							'success' => false,
							'message' => 'Destination already exists. Use overwrite=true to replace.',
						);
					}
					$dest_backup_path = $mcp_create_backup( $final_dest );
					if ( false === $dest_backup_path ) {
						return array( 'success' => false, 'message' => 'Failed to create destination backup.' );
					}
				}

				if ( ! $wp_filesystem->move( $source_path, $final_dest, $overwrite ) ) {
					return array(
						'success' => false,
						'message' => 'Failed to move file.',
					);
				}

				$mcp_log_filesystem_operation( 'MOVE', $source_path, array(
					'destination' => $final_dest,
					'backup'      => $source_backup_path,
					'context'     => $context,
				) );

				$result = array(
					'success'            => true,
					'message'            => 'File moved successfully.',
					'source'             => $source_path,
					'dest'               => $final_dest,
					'source_backup_path' => $source_backup_path,
				);

				if ( $dest_backup_path ) {
					$result['dest_backup_path'] = $dest_backup_path;
				}

				return $result;
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
			),
		)
	);
}
add_action( 'wp_abilities_api_init', 'mcp_register_filesystem_abilities' );
