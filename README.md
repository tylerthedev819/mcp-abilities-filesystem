# MCP Abilities - Filesystem

Inspect WordPress files, update a permitted text file, and check the recorded change from your MCP client. This add-on provides eleven administrator-only abilities for file and directory work inside the WordPress root.

[![Release 1.0.11](https://img.shields.io/badge/release-1.0.11-blue.svg)](https://downloads.devenia.com/mcp-abilities-filesystem.zip)
[![License GPLv2 or later](https://img.shields.io/badge/license-GPLv2%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![WordPress 6.9+](https://img.shields.io/badge/WordPress-6.9%2B-blue.svg)](https://wordpress.org/download/)
[![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-purple.svg)](https://www.php.net/downloads.php)

**Stable tag:** 1.2.0
**Tested up to:** WordPress 7.1 (7.1-RC3)
**License:** GPLv2 or later
**Tags:** mcp, filesystem, ai, automation

## What It Does

Read a bounded part of a file, inspect its metadata, or list a directory before changing it. Write and append permitted content, create directories, copy or move files, and delete selected paths. File operations use path checks, content restrictions, and backups where supported.

## The Real Workflow

1. Connect an MCP client as a WordPress administrator with `manage_options`.
2. Discover the filesystem abilities and inspect the target directory and file.
3. Specify the exact change, including overwrite or recursive deletion when needed.
4. Call the ability and inspect its `success`, message, and returned paths.
5. Read the file back and inspect the operations log when the operation records one.

For example: “Read the current robots.txt, replace it with this approved text, keep the default backup, and read it back.” The add-on operates on the physical file; it does not edit WordPress virtual robots.txt output.

## Why This Feels Different

The client receives typed WordPress operations with concrete results. A file read has a size limit, overwriting a copy destination requires an explicit option, and a failed required backup stops the corresponding change. The client can use these results to check a maintenance action in the same conversation.

## Before vs After

| Task | Manual file workflow | With this add-on |
| --- | --- | --- |
| Inspect a text file | Open a separate file tool and locate the site | Read its relative WordPress path |
| Replace a data file | Save a backup and upload new content | Write permitted content with a default backup |
| Check an automated change | Compare files and separate notes | Read the result and inspect recorded operations |

## Who It Is For

WordPress administrators and developers who need authenticated agent access to site files. The abilities support text and data maintenance, upload-directory inspection, and targeted cleanup. They require administrative access and do not provide a general PHP code editor.

## Requirements

- [WordPress 6.9 or newer](https://wordpress.org/download/), including its built-in [Abilities API](https://developer.wordpress.org/apis/abilities-api/).
- [PHP 8.0 or newer](https://www.php.net/downloads.php).
- [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter) for MCP transport.
- [MCP Expose Abilities](https://devenia.com/plugins/mcp-expose-abilities/) for the Devenia MCP exposure workflow.
- An authenticated WordPress user with `manage_options` and filesystem access sufficient for the requested operation.

The plugin registers abilities through the native API. Adapter and exposure configuration determine their availability to an MCP client. WordPress 6.9+ does not require a separate Abilities API plugin.

## Documentation

Read the [Filesystem plugin page](https://devenia.com/plugins/mcp-abilities-filesystem/) and the [MCP Expose Abilities documentation](https://devenia.com/plugins/mcp-expose-abilities/) for the plugin and connection workflow.

## Start Here

Install and configure the MCP stack, activate this add-on, then discover its abilities. Start with `filesystem/list-directory` and `filesystem/read-file`. Inspect the current data before requesting a write.

## Abilities (11)

| Ability | Public behavior |
| --- | --- |
| `filesystem/read-file` | Read bounded content; reject protected secret paths. |
| `filesystem/write-file` | Create or overwrite a permitted file; backup defaults to true. |
| `filesystem/append-file` | Append or prepend to an existing file; validate the complete result. |
| `filesystem/delete-file` | Delete a file; backup defaults to true. |
| `filesystem/delete-directory` | Delete a directory; recursive deletion defaults to false and has no backup. |
| `filesystem/copy-file` | Copy permitted file content; explicit overwrite backs up the destination. |
| `filesystem/move-file` | Move permitted file content; back up the source and any overwritten destination. |
| `filesystem/list-directory` | List directory entries with depth, pattern, and item limits. |
| `filesystem/create-directory` | Create a directory, optionally with missing parents. |
| `filesystem/file-info` | Return path metadata, permissions, and access information. |
| `filesystem/get-changelog` | Return recent entries from this add-on's operations log. |

`get-changelog` is an operations log reader, not a plugin or theme release-notes reader. Directory listing defaults to 1,000 entries, accepts at most 5,000, and reports truncation. Recursive depth defaults to 2 and is capped at 8.

## Usage Examples

These objects identify an ability and its input. Use your client's discovered execution interface to submit them.

Read a file:

```json
{"ability_name":"filesystem/read-file","parameters":{"path":"robots.txt"}}
```

Write a file in an existing directory:

```json
{"ability_name":"filesystem/write-file","parameters":{"path":"wp-content/uploads/inventory.csv","content":"product,quantity\nExample,12\n","context":"Update approved inventory export"}}
```

Inspect a directory:

```json
{"ability_name":"filesystem/list-directory","parameters":{"path":"wp-content/uploads","recursive":false,"max_items":100}}
```

Review recorded changes:

```json
{"ability_name":"filesystem/get-changelog","parameters":{"lines":20}}
```

## Safety and Ownership Boundaries

Every ability requires `manage_options`. Paths resolve within the WordPress root. Root listing is allowed; deleting the WordPress root is rejected. Destination checks resolve symbolic links and existing ancestors, and recursive listing skips paths that resolve outside the root.

Reads reject the root `wp-config.php`, selected environment and SSH key filenames, and common key or certificate extensions. The same source restriction applies to copy and move. This is a defined filename policy, not automatic detection of every secret stored in arbitrary files.

Writes reject PHP-like and executable target extensions, disallowed WordPress file types, unsafe filenames, detected PHP content, and restricted root `.htaccess` directives. Write, append, copy, and move validate complete content against the 10 MB limit. Their destinations cannot be inside `wp-admin` or `wp-includes`; move also protects core sources. File and directory deletion protect those core paths. All mutation abilities honor `DISALLOW_FILE_MODS`.

File reads default to 256 KB and allow at most 1 MB. A truncated read does not prove the remainder of a file is safe or unchanged. Content pattern checks do not establish that arbitrary file content is harmless.

Write, append, and file deletion allow callers to disable their default backup. Copy overwrite backs up the destination; move backs up the source and any overwritten destination. Required backup failure stops these operations. Directory deletion has no backup and supports explicit recursive deletion.

Backups live under `mcp-filesystem/backups/YYYY-MM-DD/` next to the WordPress root (outside the web root; override with the `mcp_filesystem_storage_dir` filter); folders older than seven days are eligible for periodic cleanup. The operations log is `mcp-filesystem/mcp-filesystem.log` and includes paths, user details, client address, and supplied context. Hosting access rules determine whether these files can be served publicly. Keep sensitive data out of operation context and manage access to backups and logs. These backups are not a full-site recovery system.

## Installation


Fork (tylerthedev819): based on upstream 1.0.11. .php files may be written with PHP content (other file types are still scanned for PHP), backups and the log are stored outside the web root, and the upstream updater notice is not loaded.

1. Meet the requirements and configure authenticated MCP access.
2. [Download the plugin ZIP](https://downloads.devenia.com/mcp-abilities-filesystem.zip).
3. In WordPress, use Plugins → Add New → Upload Plugin, then activate it.
4. Discover the eleven filesystem abilities and try a read-only operation.

## Recent Changes


### 1.0.11

Add one dismissible Plugins-screen reminder when Devenia MCP Updater is missing or inactive, with persistent install or activate links. Automatic updates remain your choice in WordPress.

### 1.0.10

- Resolve destination paths and recursive directory parents before filesystem changes.
- Allow permitted files and new directories directly under the WordPress root.
- Apply content, size, secret-source, core-path, and backup checks consistently to copy and move.
- Validate the complete result of append and prepend operations.
- Protect exact core directories from deletion and honor disabled file modifications for directory creation and deletion.
- Restore default root listing and keep recursive listing inside the resolved root.

### 1.0.9

- Fix operations logging after successful directory deletion.

## Contributing

Submit focused changes with a reproduction through the registered ability and tests for the affected behavior. Keep schemas, permission checks, and documented limits consistent.

## License

[GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html).

## Author

[basicus](https://profiles.wordpress.org/basicus/)

## Links

- [Plugin page](https://devenia.com/plugins/mcp-abilities-filesystem/)
- [Download](https://downloads.devenia.com/mcp-abilities-filesystem.zip)
- [MCP Expose Abilities](https://devenia.com/plugins/mcp-expose-abilities/)
