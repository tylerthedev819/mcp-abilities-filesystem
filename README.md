# MCP Abilities - Filesystem

Secure file operations for WordPress via MCP.

[![GitHub release](https://img.shields.io/github/v/release/bjornfix/mcp-abilities-filesystem)](https://github.com/bjornfix/mcp-abilities-filesystem/releases)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)

**Tested up to:** 6.9
**Stable tag:** 1.1.0
**License:** GPLv2 or later
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html

## What It Does

This add-on plugin exposes filesystem operations through MCP (Model Context Protocol). Your AI assistant can read plugin files, edit configuration, manage uploads - all through conversation. Security-hardened to prevent PHP injection and path traversal attacks.

**Part of the [MCP Expose Abilities](https://devenia.com/plugins/mcp-expose-abilities/) ecosystem.**

## Requirements

- WordPress 6.9+
- PHP 8.0+
- [Abilities API](https://github.com/WordPress/abilities-api) plugin
- [MCP Adapter](https://github.com/WordPress/mcp-adapter) plugin

## Installation

1. Install the required plugins (Abilities API, MCP Adapter)
2. Download the latest release from [Releases](https://github.com/bjornfix/mcp-abilities-filesystem/releases)
3. Upload via WordPress Admin → Plugins → Add New → Upload Plugin
4. Activate the plugin

## Abilities (10)

| Ability | Description |
|---------|-------------|
| `filesystem/read-file` | Read file contents (text or binary) |
| `filesystem/write-file` | Write content to file (PHP blocked) |
| `filesystem/append-file` | Append content to existing file |
| `filesystem/delete-file` | Delete file (creates backup first) |
| `filesystem/copy-file` | Copy file to new location |
| `filesystem/move-file` | Move or rename file |
| `filesystem/list-directory` | List directory contents |
| `filesystem/create-directory` | Create new directory |
| `filesystem/file-info` | Get file metadata (size, dates, permissions) |
| `filesystem/get-changelog` | Get changelog from plugin/theme |

## Usage Examples

### Read a file

```json
{
  "ability_name": "filesystem/read-file",
  "parameters": {
    "path": "wp-content/plugins/my-plugin/config.json"
  }
}
```

### Write a file

```json
{
  "ability_name": "filesystem/write-file",
  "parameters": {
    "path": "wp-content/uploads/data/export.csv",
    "content": "name,email\nJohn,john@example.com"
  }
}
```

### List directory

```json
{
  "ability_name": "filesystem/list-directory",
  "parameters": {
    "path": "wp-content/uploads/2024/",
    "recursive": false
  }
}
```

### Delete with backup

```json
{
  "ability_name": "filesystem/delete-file",
  "parameters": {
    "path": "wp-content/uploads/old-file.txt"
  }
}
```

Files are backed up to `mcp-filesystem/backups/YYYY-MM-DD/` (a sibling of the WordPress root, outside the web root) before deletion. Override with the `mcp_filesystem_storage_dir` filter.

## Security Features

This plugin includes extensive security hardening:

- **PHP Injection Detection** - Blocks `<?php`, `<?=`, and obfuscated PHP patterns
- **Encoding Bypass Protection** - Detects UTF-7, UTF-16, and Base64 encoded PHP
- **Path Traversal Protection** - Blocks `../` and absolute paths outside WordPress
- **Directory Restrictions** - Limited to the WordPress root directory
- **Automatic Backups** - Files backed up before deletion
- **50+ Attack Vectors Tested** - Comprehensive security testing

## Changelog

### 1.0.3
- Improve log append efficiency for filesystem operations

### 1.0.2
- Security: Restrict filesystem operations to the WordPress root directory
- Fixed: Use WP_Filesystem for backups and copy operations

### 1.0.1
- Fixed: Use WP_Filesystem API instead of native PHP functions
- Fixed: Proper sanitization of REMOTE_ADDR

## License

GPL-2.0+

## Author

[Devenia](https://devenia.com) - We've been doing SEO and web development since 1993.

## Links

- [Plugin Page](https://devenia.com/plugins/mcp-expose-abilities/)
- [Core Plugin (MCP Expose Abilities)](https://github.com/bjornfix/mcp-expose-abilities)
- [All Add-on Plugins](https://devenia.com/plugins/mcp-expose-abilities/#add-ons)
