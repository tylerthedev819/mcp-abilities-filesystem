=== MCP Abilities - Filesystem ===
Contributors: devenia
Tags: mcp, filesystem, ai, automation
Requires at least: 6.9
Tested up to: 6.9
Stable tag: 1.0.2
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Secure file operations for WordPress via MCP.

== Description ==

This add-on plugin exposes filesystem operations through MCP (Model Context Protocol). Your AI assistant can read plugin files, edit configuration, manage uploads - all through conversation. Security-hardened to prevent PHP injection and path traversal attacks.

Part of the MCP Expose Abilities ecosystem.

== Installation ==

1. Install the required plugins (Abilities API, MCP Adapter)
2. Download the latest release
3. Upload via WordPress Admin → Plugins → Add New → Upload Plugin
4. Activate the plugin

== Changelog ==

= 1.0.2 =
* Security: Restrict filesystem operations to the WordPress root directory
* Fixed: Use WP_Filesystem for backups and copy operations

= 1.0.1 =
* Fixed: Use WP_Filesystem API instead of native PHP functions
* Fixed: Proper sanitization of REMOTE_ADDR

= 1.0.0 =
* Initial release
