=== Llamly MCP - AI Agent with ChatGPT & Claude ===
Contributors: dotrex
Tags: mcp, ai-agent, chatgpt, claude, model-context-protocol
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage your site with ChatGPT, Claude or any AI subscription you already have. Free WordPress MCP server with undo and approvals.

== Description ==

Llamly MCP connects your WordPress site to the AI you already use: ChatGPT, Claude, Codex, Cursor and other AI agents. Ask in plain language and your agent writes, edits and organizes the site for you, with your WordPress content permissions, a full change history and undo. It is a free WordPress MCP server (Model Context Protocol) that runs inside your own site: no relay, no Llamly server in between, no Llamly account and no API key from an AI provider.

https://www.youtube.com/watch?v=mJwEgav2r40

**Use the AI subscription you already have. Keep every change reversible.**

* **Manage your site by chat**: create, edit and publish posts and pages, edit Gutenberg blocks, upload media, organize categories, tags and menus, moderate comments and look up users.
* **Undo and redo**: every change made by an agent is written to a journal first, so almost everything can be undone from the Activity screen or by asking your agent.
* **You stay in charge**: safe mode makes the agent confirm before it deletes or undoes something. Turn on the approval queue and publishing waits for you in wp-admin, with a before and after of each change.
* **Content, never administration**: the agent works with your content permissions only. Whatever your role, it never changes site settings, users, plugins, themes or code, and never writes unfiltered HTML.
* **Bring your own AI**: works with ChatGPT, claude.ai, Claude Desktop, Claude Code, Codex, Cursor, OpenCode and Antigravity CLI. Llamly has no AI of its own and no usage meter: the agent you already pay for does the work.
* **Your data stays on your site**: self-hosted, no account, no relay. Your AI app connects to your site, and Llamly sends nothing out unless you opt in to the optional usage statistics (see External services).
* **Ready in a few minutes**: a step-by-step recipe for each app with your site address already filled in, an "Add to Claude" button, and a Doctor that checks your server and tells you what to ask your host.

Ask things like "publish the three drafts about our new service", "find every image without alt text and fill it in", "add a FAQ section to the pricing page", "move Contact to the end of the main menu" or "undo the last change you made", and your agent does it on your site.

= What is a WordPress MCP server? =

MCP (Model Context Protocol) is the open standard AI apps use to work with other software. A WordPress MCP server gives ChatGPT, Claude or any MCP-capable agent a set of typed, permission-checked actions on your site, such as publishing a post or updating a menu, instead of you copying and pasting between a chat window and wp-admin. Llamly MCP is that server, installed like any other plugin.

= What your agent can do =

* **Content**: posts, pages and public custom post types: list, read, create, update, trash, revisions and restore of a revision.
* **Gutenberg**: read any page as a block tree and edit blocks in one atomic batch; patterns, templates and template parts, and global styles on block themes (custom CSS stays in the Site Editor and is never changed by Llamly).
* **Media**: upload files, edit titles, captions and alt text, delete media.
* **Site structure**: categories, tags and other taxonomies, menus and navigation, comments, and users (read only, within your role).
* **Site information**: name, addresses, WordPress and PHP versions, theme, locale and limits.
* **Elementor**: when Elementor is installed, read pages, templates, widgets and global styles (read only).
* **WordPress Abilities API**: on WordPress versions with the Abilities API, Llamly tools can also be exposed as abilities (off by default), with the same permissions and approval rules.

= Connect ChatGPT to WordPress =

ChatGPT signs in with OAuth, so no key is needed. On chatgpt.com, turn on Developer mode in Settings > Security and login, add a custom MCP server with your Llamly endpoint as the server URL and OAuth authentication, then approve access on your site. Needs a ChatGPT plan with developer mode (Plus, Pro, Business, Enterprise or Edu) and your site on a public HTTPS address.

= Connect Claude to WordPress (claude.ai and Claude Desktop) =

Watch the 2-minute tutorial:

https://www.youtube.com/watch?v=0lrDl_NwaSw

Click "Add to Claude" in the Llamly recipe, review the request on your site, choose read only or read and write, and click Approve access. The same connector shows up in Claude Desktop on the same account. Needs a public HTTPS address; for a site on your computer, Claude Desktop uses the local bridge shown in the recipe.

= Connect Claude Code, Codex, Cursor, OpenCode and Antigravity CLI =

Create an access key in the Llamly recipe and paste the ready-made command. For Claude Code:

    claude mcp add --transport http my-site https://your-site.example/wp-json/llamly/v1/endpoint --header "Authorization: Bearer YOUR_KEY"

The Connections tab shows the exact command or config for each app, with your endpoint filled in. These apps also work with a local WordPress site, with no public address needed.

= Safe by design =

https://www.youtube.com/watch?v=Peu4aEIbxo0

You are handing an AI real access to your site, so Llamly is built to keep you in charge:

* **Change journal with undo and redo**: before-state snapshots of every change, undo from Activity or by the agent (`wp_undo_change` / `wp_redo_change`), newest first in batches, and conflicts with later human edits are reported instead of overwritten.
* **Safe mode** (on by default): the agent must show you what it will do and repeat the call with `confirm: true` before it deletes or trashes something, undoes a change, makes a destructive redo or takes an irreversible action. The agent controls that flag, so it is a brake and not a security boundary.
* **Content permissions only**: every agent request runs under a capability ceiling. The key or OAuth grant acts as its WordPress user for content, but never with administrator capabilities (site settings, users, plugins, themes, code files, import and export, unfiltered HTML), whatever that user's role. All markup the agent writes goes through the WordPress content filter: scripts, styles, iframes, inline event handlers, `javascript:` links and Custom HTML blocks are refused or removed.
* **Approval queue** (optional): holds publishing or changing anything visitors see until a human approves it in wp-admin. Requests expire after 7 days, and the agent cannot approve its own request.
* **Access control**: each key can have full access, read only access or chosen tool categories; OAuth connections are read only or read and write. Keys and tokens are stored hashed and can be revoked at any time.
* **Audit log and limits**: every MCP request is logged, with rate limiting per connection (120 requests per minute by default) and an origin allowlist.
* **One gate for every write**: input schema, your WordPress capabilities, the scope of the connection, the policy above, a durable snapshot and a revision check before anything changes.

Undo brings content back, but it cannot take back effects outside your database such as caches and CDNs. See Limitations below.

= What Llamly does not do =

No arbitrary SQL, shell, WP-CLI or file editing; no site settings, user changes or server logs; no installing, updating or switching plugins and themes; no fetching of remote URLs; no writing to Elementor. Llamly has no internal AI chat and does not send your content to any AI provider: the AI app you connect calls your site, not the other way around.

= Requirements =

* WordPress 6.2 or newer and PHP 8.0 or newer (tested up to WordPress 7.1).
* MySQL 5.7.8 or newer, or MariaDB 10.3 or newer, with InnoDB tables. Not supported for writes: Galera clusters (`wsrep_on=ON`) and a custom `wp-content/db.php` drop-in.
* The database user must be allowed to use `GET_LOCK()` and to run `SET SESSION TRANSACTION ISOLATION LEVEL`. Llamly sets REPEATABLE READ for each write and restores the previous level, so the host default does not need to change.
* `innodb_flush_log_at_trx_commit` must be 1 or 2. Value 1 is fully durable; with 2 an operating system crash or power loss can lose about one second of committed changes, including journal entries. Value 0 is refused.
* `max_allowed_packet` of 16 MB or more is recommended; large changes can be refused below that.
* A persistent object cache such as Redis is supported.
* OAuth apps (claude.ai, ChatGPT, Claude Desktop remote) need your site on a public HTTPS address.

The **Doctor** in the Connections tab (Run checks) tests these requirements on your server and tells you what to ask your host for.

= Tested apps =

Tested end to end with Claude Code, Codex, OpenCode, claude.ai, ChatGPT, Claude Desktop (cloud connector), Cursor (access key) and Antigravity CLI. Recipes are included, and testing is under way, for Cursor sign-in and the Claude Desktop local bridge. If a recipe does not work for you, please tell us in the support forum.

= Admin screens =

One Llamly menu with five tabs: Connections (connected apps, recipes and the Doctor), Activity (change history with undo and redo and approval requests), Logs (filterable audit log with CSV export), Settings and About (getting started and example requests). The interface is in English and can be translated on translate.wordpress.org; wp-admin and the OAuth sign-in page follow each user's language.

= Credits and licenses =

Llamly is GPLv2 or later. Its connection recipes, OAuth flow and transport were modelled on Cowboy MCP (GPL-2.0-or-later); interface icons come from Lucide (ISC) and client marks from Simple Icons (CC0) and OpenCode (MIT). Details are in THIRD-PARTY-NOTICES.txt inside the plugin.

== Installation ==

1. Install Llamly MCP from Plugins > Add New, or upload the ZIP in Plugins > Add New > Upload Plugin.
2. Activate it. Llamly creates its own database tables on activation.
3. Open **Llamly** in the admin menu. The Connections tab lists the supported apps and shows the recipe for each one with your site address already filled in.

Your MCP endpoint is `https://your-site.example/wp-json/llamly/v1/endpoint`. OAuth discovery is published at `/.well-known/oauth-protected-resource` and `/.well-known/oauth-authorization-server` on your site root.

**Apps that sign in with OAuth (claude.ai, Claude Desktop, ChatGPT, Cursor)**

OAuth needs your site on a public HTTPS address. New OAuth clients can only register while the registration window is open: the recipe opens it for 30 minutes when you start. Closing the window never disconnects existing apps, and an app that is already registered can reconnect at any time when an administrator approves the sign-in on your site. Other users can approve a sign-in only while the window is open.

* **claude.ai**: click "Add to Claude" in the recipe (or, in claude.ai, go to Customize > Connectors > + Add > Add custom connector and paste the endpoint). Claude opens a login page on your site; review the request, choose the access level and click Approve access. On Team and Enterprise plans an organization owner adds the connector first.
* **Claude Desktop (remote)**: same connector as claude.ai; it shows up in the Desktop app on the same account.
* **ChatGPT**: on chatgpt.com, enable Developer mode in Settings > Security and login, then add a custom MCP server with the endpoint as server URL and OAuth authentication, and approve access on your site. Requires a ChatGPT plan with developer mode (Plus, Pro, Business, Enterprise or Edu); on Business and Enterprise an administrator enables it first.
* **Cursor (OAuth)**: add `{ "mcpServers": { "my-site": { "url": "<endpoint>" } } }` to `~/.cursor/mcp.json` and approve access on your site when Cursor asks.

**Apps that use an access key (Claude Code, Codex, Cursor, Antigravity CLI, OpenCode, Claude Desktop local bridge)**

In the recipe, create a key: give it a name and choose full access, read only or chosen tool categories. The key acts as the WordPress user who created it, with that user's content permissions and never administrator ones. It is shown once.

* **Claude Code**: `claude mcp add --transport http my-site <endpoint> --header "Authorization: Bearer <key>"`, then run `/mcp` in a new session.
* **Codex**: `export LLAMLY_API_KEY="<key>"` (also in your shell profile), then `codex mcp add my-site --url <endpoint> --bearer-token-env-var LLAMLY_API_KEY`, then `/mcp`.
* **Antigravity CLI**: `agy mcp add --header "Authorization: Bearer <key>" my-site <endpoint>`. To remove it: `agy mcp remove my-site`.
* **OpenCode**: add a remote server with `"type": "remote"`, `"url": "<endpoint>"`, `"oauth": false` and an `Authorization: Bearer <key>` header to `~/.config/opencode/opencode.json` (or `opencode.json` in a project), then `opencode mcp list`.
* **Cursor (key)**: add the server with `"url"` and an `Authorization: Bearer <key>` header to `~/.cursor/mcp.json` and restart Cursor.
* **Claude Desktop with a local site**: cloud connectors cannot reach a site that runs on your computer. The recipe gives a `claude_desktop_config.json` entry that starts the open source `mcp-remote` bridge through `npx` (Node.js required) with your key. The key is stored in plain text in that file, so prefer a read-only key if you do not need edits.

After the first request, the Connections tab marks the app as connected and the request appears in Logs.

== Frequently Asked Questions ==

= What is a WordPress MCP server? =

A plugin that lets AI apps act on your site through the Model Context Protocol (MCP), the open standard that ChatGPT, Claude, Cursor and other agents use to call external tools. Llamly MCP turns your WordPress site into one of those tools, so your agent can publish, edit and organize things directly instead of telling you what to click.

= How do I connect ChatGPT to my WordPress site? =

Install Llamly MCP, open Llamly > Connections and pick ChatGPT. On chatgpt.com, turn on Developer mode in Settings > Security and login, add a custom MCP server with your Llamly endpoint and OAuth authentication, and approve access on your site. It needs a Plus, Pro, Business, Enterprise or Edu plan and a public HTTPS site.

= How do I connect Claude to my WordPress site? =

For claude.ai and Claude Desktop, click "Add to Claude" in the Llamly recipe and approve the sign-in on your site, with no terminal and no key. For Claude Code, create a key in the recipe and run the one-line command shown there.

= Do I need an API key from OpenAI or Anthropic? =

No. Llamly does not call any AI model. You use the AI app you already have, on its own plan, and that app connects to your site. Llamly only needs its own access key or an OAuth approval, both created inside your site.

= Is it free? =

Yes. This plugin is free and licensed GPLv2 or later. Llamly does not meter requests; the only limit is the rate limit per connection you set in Settings.

Llamly Pro is an optional, paid add-on with extra modules for your agent. The first one, Llamly Builder, builds pages in Elementor and in the block editor. Nothing in this plugin is locked or needs Llamly Pro.

= Do I need a Llamly account? =

No. There is no account and no sign-up. Everything runs inside your WordPress site.

= Do I need to be a developer? =

No. Apps like ChatGPT and claude.ai connect with a click and an approval on your site, and from then on you just ask in plain language. Terminal apps such as Claude Code and Codex get a ready-to-paste command.

= Which AI apps work with it? =

Any MCP client over Streamable HTTP. Recipes are included for ChatGPT, claude.ai, Claude Desktop, Claude Code, Codex, Cursor, OpenCode and Antigravity CLI.

= Is it safe? Can the AI break my site? =

Almost every change is journaled and can be undone, safe mode makes the agent confirm before deleting or undoing, and the optional approval queue holds publishing for you. The agent works with content permissions only, never administrator ones. Keys can be read only, and every request is logged. See "Safe by design" above.

= Does it work with Elementor? =

Yes, read only: the agent can read Elementor pages, templates, widgets and global styles, but Llamly never writes Elementor data.

= Does an outside service administer my site? =

No. There is no Llamly server or relay. The AI app you connect (ChatGPT, Claude, Codex, Cursor and others) sends requests straight to your site with an access key or an OAuth grant that a user of your site created, and you can revoke it at any time in Connections. Each request runs as that user with content permissions only: an administrator's key cannot change site settings, users, plugins, themes or code, and cannot write unfiltered HTML.

= Does Llamly send my content anywhere? =

No. Your posts, pages, media, connections, keys, logs and change journal are never sent anywhere. Llamly makes no outbound calls at runtime, except the optional usage statistics described in External services, which send nothing unless an administrator opts in. The AI app you connect sends requests to your site and receives the answers; what that app does with them is governed by its own terms. The connection diagnostics in Connections (Run checks) only call your own site (loopback) to check that the REST API, the MCP endpoint and the OAuth discovery URLs answer.

= Can the agent publish or delete things without me? =

By default the agent can create, edit and publish without waiting for you, and every change is journaled, so almost everything can be undone in Activity. With safe mode on (the default) the agent must first show you what it will do and send `confirm: true` before it deletes or trashes something, before it undoes a change, before a redo that is destructive or irreversible, and before changes that cannot be undone. Llamly cannot create, change or delete users. Llamly cannot change site settings either. If you want to approve things yourself, turn on "Require my approval to publish" in Settings: those requests then wait for you in wp-admin.

= Why doesn't the history show "changed outside Llamly"? =

The Activity tab does not read the current state of every item, so it stays fast with a large history. If you edited an item in WordPress after the agent changed it, Llamly checks the real state when you click Undo and, if the item changed, warns you instead of overwriting your edit.

= How long do approval requests last? =

Approval requests expire after 7 days. The agent must send a new request after that.

= How do I disconnect an app? =

In Connections, open the app's menu under Connected apps and click Revoke access. The next request with that token or key is rejected. Deactivating the plugin also stops every connection: the endpoint no longer exists while Llamly is inactive.

= The Doctor says the server can't reach its own address. =

If the browser checks pass, this is shown as a warning, not a failure: AI apps connect from outside. Security and cache plugins (Wordfence, Solid Security, All In One WP Security, Sucuri, WP Rocket, W3 Total Cache, LiteSpeed Cache and others) are listed as a heads-up, since they sometimes restrict or cache REST requests.

= Which upload size can the agent send? =

Media uploads go through MCP as base64 in a single request that is limited to 1 MiB, so one `wp_upload_media` call carries a file of up to about 693 KB (or less if your PHP upload limit is lower). Text fields such as the caption travel in the same request; for long texts upload the file first and then call `wp_update_media`. Larger files are refused with a clear message.

= Does it work with a local WordPress site? =

Yes for apps that run on your computer and use a key (Claude Code, Codex, Cursor, Antigravity CLI, OpenCode) and for Claude Desktop through the local bridge. claude.ai, ChatGPT and the Claude cloud connector need a public HTTPS address.

= Does it work on multisite? =

Connections, history and settings are kept per site.

= What happens to my data when I delete the plugin? =

By default nothing is removed, so you can reinstall without losing connections, history and settings. If you turn on "Delete data when deleting the plugin" in Settings (it asks for confirmation), deleting the plugin removes Llamly's own tables, options, transients, user meta and the `uploads/llamly-mcp` folder. Posts, pages, media, users and any other WordPress content are never removed by uninstall.

Before an update that rewrites or removes existing data or columns in Llamly's tables, the previous tables are first copied to backup tables named `<table>_bak_v<N>`. Purely additive updates (new columns or tables) make no copy. The latest backups stay in the database as a safety net, and older ones are removed after a later verified update; deleting the plugin with "Delete data when deleting the plugin" turned on also removes them.

== Privacy ==

Llamly stores, in your WordPress database:

* **Connections**: name, scope, owner user, creation and last-use time of each key and OAuth client. Keys and tokens are stored only as hashes.
* **Audit log**: one row per MCP request with time, connection, user, tool, result and the request IP address. Kept for 30 days by default (configurable from 1 to 365 days); you can clear or export it from Logs.
* **Change journal**: before and after snapshots of each object an agent changed, used for undo and redo. Snapshots contain the fields of the changed object, which can include personal data such as a comment author's name, email, IP address and user agent; passwords, sessions and application passwords are never stored. Kept for 7 days by default (configurable from 1 to 90 days). Snapshots of deleted media files are kept in `wp-content/uploads/llamly-mcp/` for the same period.

There is no Llamly server: the AI app you connect sends its requests straight to your site, and each request acts with the content permissions of the user who created the key or approved the OAuth sign-in, never administrator ones.

Expired rows are removed by a daily WordPress cron event. Llamly sets no cookies on the public site and adds no tracking to it. Llamly itself makes no external requests at runtime; the only exceptions, described in External services, send nothing unless an administrator asks. Links in the admin that open claude.ai or chatgpt.com only open those sites in your browser when you click them.

== External services ==

Llamly includes the Freemius SDK (https://freemius.com), which Llamly uses for optional usage statistics and to offer the Llamly Pro add-on. It connects to `api.freemius.com` and `wp.freemius.com`.

* **Before you choose, and if you skip:** the Llamly screen shows an opt-in screen once, with "Allow & Continue" and "Skip". Nothing is sent to Freemius before you click "Allow & Continue". If you click "Skip", nothing is sent and every feature of Llamly keeps working exactly the same; you can opt in later from the plugin's row on the Plugins screen.
* **After you click "Allow & Continue":** your browser sends the opt-in to `wp.freemius.com`, and from then on the site reports to `api.freemius.com`: your first and last name and email address as an administrator, the site URL, title and language, the WordPress, PHP, Llamly and SDK versions, Llamly events (activation, deactivation, uninstall, version updates) and the names, slugs and versions of the installed plugins and themes. The opt-in screen lists these before you agree. Freemius may then email you security and feature updates, educational content and occasional offers, as the opt-in screen says. After opting in, Llamly > Account shows what is shared and lets you opt out.
* **Deactivation feedback:** when you deactivate Llamly, an optional form asks why. If you send it, the reason you chose (and any text you wrote) is sent to Freemius, anonymously if you did not opt in. You can skip the form.
* **Llamly Pro plans:** Llamly > Llamly Pro asks `api.freemius.com` for the plans (sending the site URL) only after you click "Show plans and prices", or while you are opted in. "Buy" opens `checkout.freemius.com` in a new tab.
* Nothing from your posts, pages, media, connections, keys, logs or the change journal is ever sent.

Freemius terms of service: https://freemius.com/terms/ · Freemius privacy policy: https://freemius.com/privacy/

== Source code ==

Every JavaScript and CSS file in this plugin is its own human-readable source, not minified and not generated: there is no build step. `assets/free/js/admin.js` (the admin screens), `assets/free/js/editor-guard.js` (block editor guard) and `assets/free/js/icons.js` (the SVG markup of the icons, from Lucide and Simple Icons, see THIRD-PARTY-NOTICES.txt) are loaded as they are, and the CSS in `assets/free/css/` likewise. The PHP code is in `src/Free/`.

== Limitations ==

* **One undo and redo cycle.** A change can be undone once and redone once. A repeated undo returns `already_undone`.
* Undo works while the snapshot of a change is retained (7 days by default). After that the Activity screen shows why a change can no longer be undone.
* Private meta fields of other plugins and meta derived from other data (for example values a plugin recalculates on save) are left as they are; undo does not rewrite them.
* **Editors and other REST clients.** After the agent has edited a post, page, custom post type, navigation menu, template or template part, a save over the REST API is refused with 409 until the client reads the item with `context=edit` and sends its `llamly_edit_token` in the `X-Llamly-Edit-Token` header. The block editor does this for you. The mobile app and other REST clients that send no token get the error until they reload the item. Items the agent never changed need no token. Not guarded: XML-RPC, the quick edit and bulk edit of the posts list, and plugin-specific REST APIs that write the same post (for example WooCommerce products). You can disable XML-RPC.
* **Upload size.** One `wp_upload_media` call carries a file of up to about 693 KB, because the whole request is limited to 1 MiB. Over the limit the agent gets a clear error and a request larger than 1 MiB is refused with HTTP 413.
* Elementor support is read only.
* Llamly coordinates with the block editor, but a person saving the same post at the same moment as an agent may get a conflict that must be resolved by reloading.
* The agent can delete to the trash only; permanent deletion of posts is not offered.

== Screenshots ==

1. Connections: pick an AI app and follow its step-by-step recipe. Key-based apps get a ready-to-paste command; the key is shown only once.
2. Activity: a request waiting for approval, shown with a before and after of the change, and the history of every change made by an agent with undo and redo.
3. Logs: audit trail of every MCP request, including refused ones, with filters by connection, result and date.
4. Settings: server switch, safe mode, the optional approval queue, rate limit, OAuth rules and retention.
5. About: what Llamly does, first steps and example requests to try with your agent.

== Changelog ==

= 1.1.1 =
* New Llamly > Llamly Pro page while the optional Llamly Pro add-on is not active: what it is, its plans and prices from Freemius, and a link to the Freemius checkout. The page asks Freemius for the plans only after you click, or after you opted in. A "Get Llamly Pro" link in the Plugins row and the About card point to it.
* Readme: the third tutorial video, and a short note on Llamly Pro.
* While the optional Llamly Pro add-on is not active, the connected agent learns what Llamly Pro adds, so it can mention it once when a request needs it and offer what Llamly can do instead. A refusal Llamly Pro covers (for example editing an Elementor page) says so in one line. Nothing is shown in wp-admin and no feature of Llamly is locked.
* When the connected agent cannot change the site settings (site title, homepage and the other Settings > General and Settings > Reading options), it now tells you so and gives you the direct links to those screens (wp_site_info returns them).
* Fix: connecting Claude and other OAuth apps failed on sites with plain permalinks (the WordPress default), because the OAuth discovery addresses under /.well-known/ answered with the home page. They now answer in every permalink mode, with or without a trailing slash. The Doctor explains when the web server does not pass these addresses to WordPress.
* Connections: a "Didn't work? Try another way" line under "Add to Claude", with the steps for Claude Team and Enterprise accounts (the organization owner adds the connector first) and the option to connect from Claude Code or Codex with an access key. After the click, the page shows how far the app got and says so when it has not reached the site after 3 minutes. An attempt that did not finish shows in Logs with the step where it stopped. All of this stays on your site.

= 1.1.0 =
* Optional, opt-in usage and diagnostic statistics through Freemius. Nothing is sent without an administrator's consent ("Allow & Continue" on the Llamly screen). "Skip" keeps every feature working exactly the same. See External services.
* About: a Llamly Pro card. Llamly Pro will be a separate add-on and is coming soon; nothing in Llamly is locked.
* Uninstall keeps the same rules, tells the Freemius SDK about the uninstall (sent only if you opted in or sent deactivation feedback), and also removes what the Freemius SDK stored about Llamly.
* Readme: tutorial videos.

= 1.0.3 =
* The MCP endpoint checks the request origin and the caller's credential (Llamly API key or OAuth access token) in its REST permission callback, before the request reaches the MCP handler. Responses to clients are unchanged.
* Translations are no longer bundled: they come from translate.wordpress.org language packs.

= 1.0.2 =
* Agent requests run under a capability ceiling: whatever the role of the connected user, MCP and Abilities requests never use administrator capabilities (site settings, users, plugins, themes, code files, import and export, network administration, unfiltered HTML).
* The content policy applies to every user, administrators included: scripts, styles, iframes, inline event handlers, `javascript:` links and Custom HTML blocks are refused or removed in posts, patterns, block edits and templates.
* Removed the tools that changed site options, read the PHP error log and ran Site Health. Option changes made with 1.0.1 can still be undone in Activity.
* The key creation step and the OAuth sign-in page say what the agent can and cannot do.
* The admin JavaScript, CSS and icons are formatted as readable source; new "Source code" section in this readme.

= 1.0.1 =
* Users are read only: the tools that created, changed or deleted users were removed.
* Global styles: custom CSS is no longer accepted. Edit it in the Site Editor; Llamly keeps the CSS saved there as it is.
* Database restore points were removed. Existing restore points and their tables are deleted on update; use your host's backups to take the whole site back to an earlier moment.
* Plugin paths, request input and database queries follow the WordPress.org guidelines (plugin directory functions, sanitized input, prepared queries).

= 1.0.0 =
First public release on WordPress.org. It summarizes the changes since the 0.1.0 preview, which was never published here.

* New approval policy. By default nothing waits for approval and every change is journaled, almost all of it with undo. With safe mode on, the agent needs `confirm: true` to delete, trash, delete a restore point, undo, redo destructive or irreversible changes and for irreversible actions. An optional approval queue in Settings holds publishing or changing anything visitors see (content, comments, menus, templates, styles, media in use), users and settings for a human. A restore requested by an agent always needs a human.
* Hosting checks. Llamly verifies InnoDB, transaction isolation, durable commits, Galera and `db.php` drop-ins, `GET_LOCK` and PHP time limits before it writes, and the Doctor reports each one with what to ask your host.
* Restore points. Size limit of about 200 MB, a clearer confirmation (size, time estimate, what is kept), options and tables with secrets of other plugins are kept as they are now, and an automatic "Before restoring" point.
* Activity. The history is paginated in the database and approval requests show a before and after diff.
* Safer editing. The block editor and REST clients are protected from overwriting a change made by an agent.
* MCP. The server now sends usage instructions to the agent, tool annotations (a hint that only pure creations are non-destructive), English-only responses and a documented upload size limit.
* Admin. English tab URLs (old Portuguese links still redirect), a Doctor with security and cache plugin hints, and a Brazilian Portuguese translation.
* Keys can have full access, read only access or chosen tool categories; OAuth grants are read only or read and write.

== Upgrade Notice ==

= 1.1.1 =
Fixes connecting Claude and other apps on sites with plain permalinks. Adds a Llamly Pro page with the optional add-on's plans; nothing is sent to Freemius until you click there or opt in.

= 1.1.0 =
Optional, opt-in usage statistics through Freemius: nothing is sent without your consent, and Skip keeps everything working. Llamly Pro add-on coming soon.

= 1.0.3 =
The MCP endpoint authenticates in its REST permission callback. Translations now come from translate.wordpress.org.

= 1.0.2 =
Agents now work with content permissions only, for every user role: no site settings, PHP error log or Site Health tools, and no unfiltered HTML.

= 1.0.1 =
Users are read only, global styles no longer accept custom CSS, and database restore points were removed (existing ones are deleted on update).

= 1.0.0 =
First public release. Writes no longer wait for approval by default; turn on the approval queue in Settings if you want that. Safe mode now asks the agent for `confirm: true` before deleting, undoing and irreversible actions.
