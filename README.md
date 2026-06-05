# MCP Abilities - Filesystem

Filesystem abilities for MCP. Read, write, copy, move, and delete files within WordPress. Security-hardened with PHP injection detection.

[![GitHub release](https://img.shields.io/github/v/release/bjornfix/mcp-abilities-filesystem)](https://github.com/bjornfix/mcp-abilities-filesystem/releases)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)
[![WordPress](https://img.shields.io/badge/WordPress-6.9%2B-blue.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-purple.svg)](https://php.net)

**Tested up to:** 7.0
**Stable tag:** 1.0.6
**License:** GPLv2 or later
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html

## What It Does

Filesystem abilities for MCP. Read, write, copy, move, and delete files within WordPress. Security-hardened with PHP injection detection.

This plugin is part of the Devenia MCP abilities ecosystem. It gives an MCP-capable agent a focused, authenticated way to work with Filesystem work inside WordPress through MCP.

**Example:** "Handle this WordPress maintenance task directly." - The agent can inspect the site, call the relevant ability, and return the result without making the human click through wp-admin for every step.

## The Real Workflow

In practice, the human should not have to memorize every ability name.

The normal pattern is:

1. install the base MCP stack
2. install only the add-ons the site actually needs
3. let the agent discover the available abilities
4. give the agent a clear task with boundaries
5. verify the result in WordPress

The human's job is mostly to describe the goal.
The agent's job is to figure out the mechanics.

## Why This Feels Different

Most WordPress automation still leaves the repetitive part to the human.

This plugin is different because the agent can act inside the site through a narrow, authenticated ability surface:

- inspect current site state before changing anything
- run the specific action needed for the task
- return structured results that are easy to verify
- keep the workflow inside WordPress instead of a separate checklist

That changes the experience from:

- `Here is what you should do in wp-admin`

to:

- `Tell the agent what needs doing, and let it carry out the work`

## Before vs After

### Before

- ask the AI what to do
- copy the answer into WordPress by hand
- click through wp-admin for the repetitive bits
- postpone maintenance because the task is tedious

### After

- tell the agent what needs doing
- let it inspect the relevant WordPress state
- let it run the targeted ability
- verify the result and move on

## Who It Is For

This is a good fit for:

- agencies managing WordPress sites with AI-assisted maintenance
- operators who want agents to do real WordPress work instead of producing instructions
- teams already using MCP Expose Abilities
- sites where this WordPress area is updated often enough to deserve automation

It is especially useful when the manual version is repetitive enough that important maintenance gets delayed.

## Documentation

Start with the main plugin page and base stack documentation:

- [MCP Expose Abilities](https://devenia.com/plugins/mcp-expose-abilities/)
- [Plugin Page](https://devenia.com/plugins/mcp-expose-abilities/#add-ons)
- [Getting Started](https://github.com/bjornfix/mcp-expose-abilities/wiki/Getting-Started)
- [Install Order and Dependencies](https://github.com/bjornfix/mcp-expose-abilities/wiki/Install-Order-and-Dependencies)

If you are using an AI agent, the simplest instruction is often just:

- `Read https://github.com/bjornfix/mcp-expose-abilities and figure out the stack before making changes.`

## Start Here

If you are new to the stack, use this order:

1. Install **Abilities API**.
2. Install **MCP Adapter**.
3. Install **MCP Expose Abilities**.
4. Install **MCP Abilities - Filesystem**.
5. Confirm the new abilities appear in discovery.
6. Give the agent a clear task that uses this add-on.

If you skip base-stack verification and start with add-ons immediately, troubleshooting gets harder than it needs to be.

## Abilities (11)

| Ability | Description |
|---------|-------------|
| `filesystem/read-file` | Read file contents (text or binary) |
| `filesystem/write-file` | Write content to file (PHP blocked) |
| `filesystem/append-file` | Append content to existing file |
| `filesystem/delete-file` | Delete file (creates backup first) |
| `filesystem/delete-directory` | Delete directory (optional recursive delete) |
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

Files are backed up to `wp-content/mcp-backups/YYYY-MM-DD/` before deletion.

## Security Features

This plugin includes extensive security hardening:

- **PHP Injection Detection** - Blocks `<?php`, `<?=`, and obfuscated PHP patterns
- **Encoding Bypass Protection** - Detects UTF-7, UTF-16, and Base64 encoded PHP
- **Path Traversal Protection** - Blocks `../` and absolute paths outside WordPress
- **Directory Restrictions** - Limited to the WordPress root directory
- **Automatic Backups** - Files backed up before deletion
- **50+ Attack Vectors Tested** - Comprehensive security testing

## Changelog

### 1.0.6
- Docs: expanded the WordPress-standard `readme.txt` so the published ZIP now includes fuller requirements, abilities, use cases, and Devenia ecosystem links

### 1.0.5
- Added: `max_items` limit for safer recursive directory listing
- Added: `returned` and `truncated` output fields for list-directory

### 1.0.3
- Improve log append efficiency for filesystem operations

### 1.0.2
- Security: Restrict filesystem operations to the WordPress root directory
- Fixed: Use WP_Filesystem for backups and copy operations

### 1.0.1
- Fixed: Use WP_Filesystem API instead of native PHP functions
- Fixed: Proper sanitization of REMOTE_ADDR

## Contributing

PRs welcome. Keep changes focused on the plugin's WordPress ability surface and preserve authenticated, explicit workflows.

## License

GPL-2.0+

## Author

[Devenia](https://devenia.com) - We've been doing SEO and web development since 1993.

## Links

- [Plugin Page](https://devenia.com/plugins/mcp-expose-abilities/#add-ons)
- [MCP Expose Abilities](https://devenia.com/plugins/mcp-expose-abilities/)
- [GitHub Releases](https://github.com/bjornfix/mcp-abilities-filesystem/releases)

## Star and Share

If this plugin saves you time or makes WordPress maintenance easier to verify, please:

- star the repo
- share it with people running WordPress sites
- point them to the main plugin page so they can see what the ecosystem can actually do

Why do it?

Because agent-friendly open WordPress tooling helps more of the boring but important work get done.
