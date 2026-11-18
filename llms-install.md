# Installing Llamly MCP (instructions for AI agents)

Llamly MCP is a WordPress plugin. The MCP server runs inside the user's WordPress site, so there is no package to install locally. Guide the user through these steps.

## 1. Install the plugin (the user does this in wp-admin)

Ask the user to log in to WordPress admin, go to **Plugins > Add New**, search for **Llamly MCP**, then install and activate it. Requirements: WordPress 6.2+, PHP 8.0+.

If the user has WP-CLI access to the site, this also works:

```bash
wp plugin install llamly --activate
```

## 2. Get the endpoint

Ask for the site domain. The endpoint is:

```
https://SITE_DOMAIN/wp-json/llamly/v1/endpoint
```

## 3. Connect

Pick the method for the client you are running in.

- **OAuth clients (claude.ai, Claude Desktop, ChatGPT, Cursor):** add a remote MCP server using the endpoint above. The user approves access on their site and selects read-only or read-write permissions. The site must use public HTTPS.
- **Key-based clients (Claude Code, Codex, Cline, Cursor, OpenCode):** ask the user to open **Llamly > Connections** in wp-admin, select your app, and generate an access key. The key appears once. Then configure a Streamable HTTP server with the header `Authorization: Bearer KEY`.

Claude Code example:

```bash
claude mcp add --transport http my-site https://SITE_DOMAIN/wp-json/llamly/v1/endpoint --header "Authorization: Bearer KEY"
```

Cline / generic JSON config:

```json
{
  "mcpServers": {
    "llamly": {
      "type": "streamableHttp",
      "url": "https://SITE_DOMAIN/wp-json/llamly/v1/endpoint",
      "headers": { "Authorization": "Bearer KEY" }
    }
  }
}
```

## 4. Verify

Call the site information tool. If the connection fails, ask the user to open **Llamly > Connections > Run checks** (the Doctor), which tests the server setup and identifies host configuration issues.

Never ask the user to paste their WordPress password. Llamly only needs its own access key or OAuth approval.
