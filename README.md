<p align="center"><img src="assets/icon.png" width="128" height="128" alt="Llamly"></p>

<h1 align="center">Llamly MCP: a WordPress MCP server</h1>

<p align="center">Connect WordPress to the AI you already use, safely. Every change logged, one-click undo.</p>

<p align="center">
  <a href="https://wordpress.org/plugins/llamly/">WordPress.org</a> ·
  <a href="https://llamly.com/free/?utm_source=github&utm_medium=directory&utm_campaign=mcp-directories">Website</a> ·
  <a href="https://llamly.com/guides/?utm_source=github&utm_medium=directory&utm_campaign=mcp-directories">Guides</a>
</p>

Llamly MCP is a free WordPress plugin that turns your site into a remote MCP server (Model Context Protocol). ChatGPT, Claude, Claude Code, Codex, Cursor, OpenCode, and other MCP clients can then write, edit, and organize your content in plain language.

It runs inside your WordPress site: **no relay, no third-party server in between, no Llamly account and no AI provider API key.** You bring the AI subscription you already have.

> This repository holds the MCP registry metadata and install docs. The plugin itself is distributed on [WordPress.org](https://wordpress.org/plugins/llamly/) (GPLv2 or later).

## Why Llamly

- **Undo and redo.** Every change an agent makes is written to a journal first, so you can undo almost any change from the Activity screen or by asking your agent.
- **You stay in charge.** Safe mode (on by default) requires the agent to confirm before deleting or undoing anything. An optional approval queue holds publishing until you approve it in wp-admin.
- **Content, never administration.** The agent works with content permissions only. It never touches site settings, users, plugins, themes, or code, and never writes unfiltered HTML.
- **Your data stays on your site.** Self-hosted, no account needed, and no outbound calls at runtime.
- **Access control and audit log.** Read-only or scoped keys, revocable OAuth grants, full request logging, and per-connection rate limits.

## What your agent can do

- **Content:** list, read, create, update, and trash posts, pages, and public custom post types, and restore revisions.
- **Gutenberg:** read pages as a block tree and edit blocks in one atomic batch; manage patterns, templates, template parts, and global styles.
- **Media:** upload files, then edit titles, captions, and alt text.
- **Site structure:** manage categories, tags, taxonomies, menus, navigation, and comments.
- **Elementor:** inspect pages, templates, widgets, and global styles (read-only).

Try: *"publish the three drafts about our new service"*, *"find every image without alt text and fill it in"*, *"move Contact to the end of the main menu"*, *"undo the last change you made"*.

## Install

1. In wp-admin, go to **Plugins > Add New**, search for **Llamly MCP**, install and activate it.
2. Open **Llamly > Connections**. Each app includes a setup recipe with your site address prefilled.

Your endpoint is:

```
https://your-site.example/wp-json/llamly/v1/endpoint
```

OAuth discovery is published at `/.well-known/oauth-protected-resource` and `/.well-known/oauth-authorization-server`.

## Connect your AI app

**claude.ai and Claude Desktop (OAuth).** Click **Add to Claude** in the Llamly recipe, or go to Customize > Connectors > Add custom connector and paste your endpoint. Approve access on your site. Requires a public HTTPS site.

**ChatGPT (OAuth).** On chatgpt.com, turn on Developer mode in Settings > Security and login, add a custom MCP server with your endpoint and OAuth authentication, then approve access on your site. Requires a plan with developer mode (Plus, Pro, Business, Enterprise, or Edu).

**Claude Code (access key).** Create a key in the Llamly recipe, then run:

```bash
claude mcp add --transport http my-site https://your-site.example/wp-json/llamly/v1/endpoint --header "Authorization: Bearer YOUR_KEY"
```

**Codex (access key).**

```bash
export LLAMLY_API_KEY="YOUR_KEY"
codex mcp add my-site --url https://your-site.example/wp-json/llamly/v1/endpoint --bearer-token-env-var LLAMLY_API_KEY
```

**Cursor.** Add to `~/.cursor/mcp.json` and approve access on your site when Cursor prompts (OAuth), or add an `Authorization: Bearer YOUR_KEY` header:

```json
{ "mcpServers": { "my-site": { "url": "https://your-site.example/wp-json/llamly/v1/endpoint" } } }
```

Recipes for OpenCode, Antigravity CLI, and the Claude Desktop local bridge are in the plugin's Connections tab. Key-based apps also work on a local WordPress site.

## Requirements

WordPress 6.2+, PHP 8.0+, MySQL 5.7.8+ or MariaDB 10.3+ (InnoDB). OAuth apps require a public HTTPS address. The built-in **Doctor** checks your server and tells you what to ask your host.

## Support

Questions and bug reports: [WordPress.org support forum](https://wordpress.org/support/plugin/llamly/).

## License

GPLv2 or later. Made by [DotRex](https://llamly.com/?utm_source=github&utm_medium=directory&utm_campaign=mcp-directories).
