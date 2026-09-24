=== MCP Abilities - Filesystem ===
Contributors: basicus
Tags: mcp, filesystem, ai, automation
Requires at least: 6.9
Tested up to: 7.1
Stable tag: 1.2.0
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Inspect and manage permitted WordPress files through eleven authenticated administrator abilities.

== Description ==

Read bounded file content, inspect directories, update permitted data files, and review recorded operations through MCP. All abilities require manage_options. Paths are limited to the WordPress root, with checks for protected paths, content, file types, and backups where supported.

Tested with WordPress 7.1-RC3.

= Requirements =

* WordPress 6.9 or newer with its built-in Abilities API; PHP 8.0 or newer.
* WordPress MCP Adapter for MCP transport.
* [MCP Expose Abilities](https://devenia.com/plugins/mcp-expose-abilities/) for the Devenia exposure workflow.
* An authenticated administrator and suitable filesystem permissions.

= Abilities =

* filesystem/read-file: bounded file content with protected secret-path checks.
* filesystem/write-file: create or overwrite permitted content.
* filesystem/append-file: append or prepend, checking the complete result.
* filesystem/delete-file: delete with an optional backup, enabled by default.
* filesystem/delete-directory: delete a directory; explicit recursive mode has no backup.
* filesystem/copy-file: copy permitted content, with explicit overwrite and destination backup.
* filesystem/move-file: move permitted content, backing up source and overwritten destination.
* filesystem/list-directory: list entries with depth, pattern, and item limits.
* filesystem/create-directory: create a directory and optional missing parents.
* filesystem/file-info: inspect metadata and permissions.
* filesystem/get-changelog: read recent filesystem operations, not plugin release notes.

= Boundaries =

Reads default to 256 KB and allow at most 1 MB. Complete write, append, copy, and move content is limited to 10 MB. PHP-like writes, protected core destinations, and selected secret source paths are rejected. All mutation abilities honor DISALLOW_FILE_MODS.

Required backup failure stops the corresponding file change. Write, append, and file deletion allow an explicit backup=false option. Directory deletion has no backup. Backups are stored under mcp-filesystem/backups next to the WordPress root (outside the web root; override with the mcp_filesystem_storage_dir filter); older folders are eligible for periodic cleanup. The mcp-filesystem/mcp-filesystem.log file contains paths, user details, client address, and supplied context. Hosting rules determine access to these files; backups are not a full-site recovery system.

== Installation ==

Fork (tylerthedev819): based on upstream 1.0.11. .php files may be written with PHP content (other file types are still scanned for PHP), backups and the log are stored outside the web root, and the upstream updater notice is not loaded.

1. Configure the required MCP stack and authenticated access.
2. Download the plugin from its public page.
3. Upload the ZIP through Plugins > Add New > Upload Plugin and activate it.
4. Discover the abilities and start with a directory listing or file read.

== Changelog ==

= 1.2.0 =
* Fork: merge upstream 1.0.11 (adds filesystem/delete-directory and path, content, and backup boundary fixes).
* Fork: allow writing .php files with PHP content (other types still scanned); store backups and log outside the web root; mark all abilities MCP-public; drop the updater notice.

= 1.0.11 =
* Add one dismissible Plugins-screen reminder when Devenia MCP Updater is missing or inactive, with persistent install or activate links. Automatic updates remain your choice in WordPress.

= 1.0.10 =
* Resolve destinations and recursive directory parents before changes.
* Allow permitted files and new directories in the WordPress root.
* Apply content, size, secret-source, core-path, and backup checks to copy and move.
* Validate complete append and prepend results.
* Protect exact core directories and honor disabled file modifications for directory creation and deletion.
* Restore default root listing and constrain recursive listing to the root.

= 1.0.9 =
* Fix operations logging after successful directory deletion.

== Links ==

* [Plugin page](https://devenia.com/plugins/mcp-abilities-filesystem/)
* [Download](https://downloads.devenia.com/mcp-abilities-filesystem.zip)
* [MCP Expose Abilities](https://devenia.com/plugins/mcp-expose-abilities/)
