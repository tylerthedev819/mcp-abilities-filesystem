=== MCP Abilities - Filesystem ===
Contributors: basicus
Tags: mcp, filesystem, ai, automation
Requires at least: 6.9
Tested up to: 7.0
Stable tag: 1.0.7
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Secure file operations for WordPress via MCP.

== Description ==

This add-on plugin exposes filesystem operations through MCP (Model Context Protocol). Your AI assistant can read plugin files, edit configuration, manage uploads, and inspect filesystem changes through conversation. Security-hardened to prevent PHP injection and path traversal attacks.

Part of the [MCP Expose Abilities](https://devenia.com/plugins/mcp-expose-abilities/) ecosystem.

= Requirements =

* [MCP Expose Abilities](https://github.com/bjornfix/mcp-expose-abilities) (core plugin)

= Abilities Included =

**filesystem/read-file** - Read file contents from the WordPress tree.

**filesystem/write-file / append-file** - Write or append file content with safety restrictions.

**filesystem/delete-file / delete-directory** - Delete paths with backup or recursive options where supported.

**filesystem/copy-file / move-file** - Copy, rename, or move files within the allowed scope.

**filesystem/list-directory / file-info / create-directory** - Inspect and manage directories safely.

**filesystem/get-changelog** - Read the filesystem operations log for recent plugin-side changes.

= Use Cases =

* Inspect plugin or theme configuration files without shell access
* Update JSON, CSV, or text files inside the WordPress tree
* Review recent filesystem changes after an automation run
* Audit upload folders or plugin directories safely through MCP
* Create or move files while keeping operations constrained to the site root

== Installation ==

1. Install the required plugins (Abilities API, MCP Adapter, MCP Expose Abilities)
2. Download the latest release
3. Upload via WordPress Admin → Plugins → Add New → Upload Plugin
4. Activate the plugin
5. The abilities are now available via the MCP endpoint

= Links =

* [Plugin Page](https://devenia.com/plugins/mcp-expose-abilities/)
* [Core Plugin (MCP Expose Abilities)](https://github.com/bjornfix/mcp-expose-abilities)
* [All Add-on Plugins](https://devenia.com/plugins/mcp-expose-abilities/#add-ons)

== Changelog ==

= 1.0.7 =
* Update tested WordPress version metadata for Plugin Check.
* Align public release identity with the Basicus author/contributor rule.

= 1.0.6 =
* Docs: expanded the WordPress-standard `readme.txt` so the published ZIP now includes fuller requirements, abilities, use cases, and Devenia ecosystem links

= 1.0.5 =
* Added: max_items cap for list-directory to prevent oversized responses
* Added: returned/truncated fields for AI-safe pagination behavior

= 1.0.4 =
* Fixed: Removed hard plugin header dependency on abilities-api to avoid slug-mismatch activation blocking


= 1.0.3 =
* Improve log append efficiency for filesystem operations

= 1.0.2 =
* Security: Restrict filesystem operations to the WordPress root directory
* Fixed: Use WP_Filesystem for backups and copy operations

= 1.0.1 =
* Fixed: Use WP_Filesystem API instead of native PHP functions
* Fixed: Proper sanitization of REMOTE_ADDR

= 1.0.0 =
* Initial release
