=== Agent Friendly ===
Contributors: konanspade
Tags: ai, llm, markdown, webmcp, woocommerce
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Make your WordPress site readable by AI agents. LLMs.txt, Markdown Mirror, WebMCP tools, Fluent Forms submission, WooCommerce, and rich AI discovery.

== Description ==

Agent Friendly is the most complete toolkit for making your WordPress site accessible to AI agents and large language models. Six modules work together to provide structured content access, form interaction, commerce tools, and standards-based discovery.

= Content Layer =

**LLMs.txt** — Serve `llms.txt` and `llms-full.txt` at your site root following the [llms.txt specification](https://llmstxt.org/). Give AI agents a concise overview of your site's content and structure. Generate automatically from published pages or write custom content. Supports physical file detection and editing.

**Markdown Mirror** — Every published post and page is automatically available as clean Markdown at `/md/{slug}/`. No configuration needed. Converts rendered HTML (including Gutenberg blocks, shortcodes, and page builder output) to Markdown on the fly, complete with YAML front matter, customizable footer, and `<link rel="alternate">` discovery headers.

= Tool Layer =

**WebMCP** — Expose your site's content as structured tools for AI agents via the [Web Model Context Protocol](https://webmcp.dev/). Registers tools with `navigator.modelContext.provideContext()` (Chrome 149+ / Edge) so AI-powered browsers can search, navigate, and read your content. Falls back to `registerTool()` and exposes a `window.afwpTools` public API for non-WebMCP agents.

**Tool Registry** — A central registry where all tools are registered and managed. Provides a unified `POST /tools/execute` endpoint with per-IP rate limiting, per-tool nonce protection, and optional Cloudflare Turnstile captcha verification. Third-party developers can register custom tools via the `afwp_register_tools` action hook.

**Fluent Forms** (optional) — When [Fluent Forms](https://wordpress.org/plugins/fluentform/) is installed, three tools are automatically registered: `list_forms` (discover available forms), `get_form_fields` (inspect field schemas), and `submit_form` (submit entries with data). Form submissions support optional Cloudflare Turnstile captcha protection and fire all standard Fluent Forms notification/integration hooks.

**WooCommerce** (optional) — When [WooCommerce](https://wordpress.org/plugins/woocommerce/) is active, seven tools are registered: product search, product details, product categories (public read-only), plus cart management and coupon application (nonce-protected). Handles WC session initialization in REST context automatically.

= Discovery Layer =

**AI Discovery** — Rich, standards-based discovery endpoints so agents can automatically find your site's capabilities:

* **MCP Server Card** at `/.well-known/mcp/server-card.json` (SEP-1649)
* **API Catalog** at `/.well-known/api-catalog` (RFC 9727, linkset+json)
* **Agent Skills Index** at `/.well-known/agent-skills/index.json`
* **WebMCP Manifest** at `/.well-known/webmcp.json`
* RFC 8288 `Link` HTTP headers and `<link>` HTML tags on every page
* `robots.txt` directives for LLMs.txt and WebMCP

= Features =

* **Zero configuration** — content modules work out of the box
* **Unified execute endpoint** — one API for all tools with rate limiting
* **Per-tool security** — public tools need no auth; write tools require WP REST nonce
* **Cloudflare Turnstile** — optional captcha for form submissions via Turnstile managed mode
* **Rate limiting** — configurable per-IP limits (default: 120 requests / 60 seconds)
* **Developer API** — `afwp_register_tools` action + `afwp_protected_tools` filter for custom tools
* **Conditional modules** — Fluent Forms and WooCommerce tabs appear only when their plugins are installed, with helpful install prompts otherwise
* **Dedicated admin page** — vertical tab navigation with Overview, per-module settings, Discovery dashboard, and global Settings
* **Backward-compatible REST** — individual `/webmcp/*` endpoints remain alongside the unified execute endpoint
* **Clean uninstall** — removes all options and transients on deletion
* **No external dependencies** — uses only PHP's built-in DOMDocument
* **Lightweight** — no JavaScript bundles or CSS files to enqueue

= How It Works =

1. Install and activate the plugin
2. Visit **Agent Friendly** in your admin sidebar
3. LLMs.txt, Markdown Mirror, and WebMCP work immediately
4. Install Fluent Forms or WooCommerce to unlock form and commerce tools
5. Optionally configure Turnstile captcha and rate limiting in Settings

= URLs Created =

* `/llms.txt` and `/llms-full.txt` — LLM discovery files
* `/.well-known/llms.txt` — alternative LLMs.txt location
* `/md/{slug}/` — Markdown mirror of any published content
* `/.well-known/webmcp.json` — WebMCP tool manifest
* `/.well-known/mcp/server-card.json` — MCP Server Card
* `/.well-known/api-catalog` — RFC 9727 API Catalog
* `/.well-known/agent-skills/index.json` — Agent Skills Index

= REST API =

All endpoints use the `agent-friendly/v1` namespace:

**Tool Registry (new in 2.0)**

* `GET /tools` — list all registered tools with schemas
* `POST /tools/execute` — invoke any tool by name (rate-limited)
* `GET /tools/nonce` — obtain a WP REST nonce (requires login)
* `GET /settings` — global plugin settings
* `PUT /settings` — update rate limiting configuration

**LLMs.txt**

* `GET /llms-txt` — retrieve content
* `PUT /llms-txt` — update content
* `DELETE /llms-txt` — delete content
* `GET /llms-txt/preview` — markdown-to-HTML preview
* `POST /llms-txt/import` — import from physical files

**Markdown Mirror**

* `GET /markdown/{post_id}` — get markdown for a post (requires edit_posts)
* `GET /markdown-mirror` — mirror settings
* `PUT /markdown-mirror` — update footer

**WebMCP (backward-compatible)**

* `GET /webmcp/site-info` — site metadata
* `GET /webmcp/search?query=...` — search content
* `GET /webmcp/page?path=...` — get page content
* `GET /webmcp/navigation` — menu structure
* `GET /webmcp/posts` — recent posts
* `GET /webmcp/contact-info` — contact information
* `GET /webmcp/tools` — all registered tools
* `GET /webmcp/settings` — WebMCP settings (admin)
* `PUT /webmcp/settings` — update WebMCP settings (admin)

**Fluent Forms** (when active)

* `GET /forms/settings` — forms module settings (admin)
* `PUT /forms/settings` — update forms + Turnstile settings (admin)

**WooCommerce** (when active)

* `GET /woo/settings` — WooCommerce module settings (admin)
* `PUT /woo/settings` — update WooCommerce settings (admin)

= Registered Tools =

**Content** (public, read-only):
`search_site`, `get_page`, `get_site_info`, `get_navigation`, `list_posts`, `get_contact_info`

**Forms** (requires Fluent Forms):
`list_forms` (public), `get_form_fields` (public), `submit_form` (protected + optional Turnstile)

**WooCommerce** (requires WooCommerce):
`woo_search_products` (public), `woo_get_product` (public), `woo_get_product_categories` (public), `woo_add_to_cart` (protected), `woo_get_cart` (protected), `woo_remove_from_cart` (protected), `woo_apply_coupon` (protected)

= Developer API =

Register custom tools from any plugin or theme:

`add_action( 'afwp_register_tools', function( $registry ) {
    $registry->register( 'my_custom_tool', [
        'description' => 'Does something useful.',
        'group'       => 'custom',
        'inputSchema' => [ 'type' => 'object', 'properties' => [] ],
        'callback'    => function( $input ) {
            return [ 'result' => 'Hello!' ];
        },
        'protected'   => false,
        'turnstile'   => false,
    ] );
} );`

Your tool automatically appears in the WebMCP manifest, browser registration, and `/tools/execute` endpoint.

== Installation ==

1. Upload the `agent-friendly-wp` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu
3. Visit **Agent Friendly** in your admin sidebar to configure

Or install directly from the WordPress plugin repository by searching for "Agent Friendly".

= Optional Dependencies =

* **Fluent Forms** — install to enable form listing, field inspection, and submission tools
* **WooCommerce** — install to enable product search, cart, and coupon tools

Neither is required. The plugin works fully without them — the Forms and Commerce tabs will show install prompts.

== Frequently Asked Questions ==

= Does this work with any theme? =

Yes. The plugin works independently of your theme. Markdown Mirror intelligently extracts main content from any theme by trying common container selectors (main, #content, .entry-content, article, etc.).

= Will this slow down my site? =

No. The plugin adds a lightweight inline script for WebMCP (skipped if the browser doesn't support `navigator.modelContext`), a few `<link>` tags, and HTTP headers. Markdown conversion and tool execution happen on-demand, not on every page load.

= Is my content exposed publicly? =

Read-only tools only serve published content that's already publicly accessible. Password-protected posts, drafts, and private content are excluded. Write operations (cart, form submission) require a valid WordPress REST nonce.

= How does rate limiting work? =

The `/tools/execute` endpoint enforces per-IP rate limits using WordPress transients. Default: 120 requests per 60 seconds. Configurable in **Agent Friendly → Settings**. Returns HTTP 429 with `Retry-After` header when exceeded.

= How does Turnstile captcha work? =

When enabled in **Agent Friendly → Forms**, the `submit_form` tool requires a Cloudflare Turnstile token. In browsers with WebMCP support, the bootstrap script automatically renders a managed Turnstile widget (often invisible) and includes the token in the request. Server-side, the token is verified via Cloudflare's siteverify API before the form is processed.

= Can I use this alongside other SEO plugins? =

Yes. Agent Friendly doesn't modify your site's SEO output. It adds new endpoints that don't conflict with any SEO plugin.

= Can I use this alongside Konan & Spade Helper? =

No. If KS Helper is active, Agent Friendly will show a notice and disable itself to avoid duplicate endpoints. Deactivate one before using the other.

= What if /md/ or discovery URLs return 404? =

Visit **Settings → Permalinks** and click **Save Changes** to flush rewrite rules. Alternatively, deactivate and reactivate the plugin.

= Does it work with custom post types? =

Yes. Markdown Mirror and WebMCP tools work with all public post types. WooCommerce tools work with the `product` post type specifically.

= Can third-party plugins add tools? =

Yes. Hook into `afwp_register_tools` to register custom tools. They automatically appear in the WebMCP manifest, browser registration, and the unified execute endpoint. Use the `afwp_protected_tools` filter to mark tools as requiring nonce authentication.

== Screenshots ==

1. Overview page showing all module status and discovery quick links
2. LLMs.txt editor with auto-generation and markdown preview
3. Markdown Mirror settings with live examples and footer editor
4. WebMCP tool configuration with all registered tools grouped by module
5. Fluent Forms tools with Cloudflare Turnstile configuration
6. WooCommerce tool toggles for product and cart operations
7. Discovery tab showing all well-known endpoints and Link headers
8. Settings tab with rate limiting configuration and developer API docs

== Changelog ==

= 1.0.0 =
* Initial release
* LLMs.txt module with editor, auto-generation, physical file support, and robots.txt directive
* Markdown Mirror module with YAML front matter, customizable footer, and alternate link discovery
* WebMCP module with content tools, `navigator.modelContext.provideContext()` registration, and `window.afwpTools` public API
* Tool Registry with central coordination, unified `/tools/execute` endpoint, and developer API (`afwp_register_tools`)
* Per-IP rate limiting with configurable limits (default 120 req/60s)
* Fluent Forms module with `list_forms`, `get_form_fields`, and `submit_form` tools
* Cloudflare Turnstile captcha support for form submissions
* WooCommerce module with 7 tools: product search, product details, categories, cart, and coupons
* Discovery module with MCP Server Card, RFC 9727 API Catalog, and Agent Skills Index
* RFC 8288 Link headers and HTML link tags for all discovery endpoints
* Page builder content fallback (Bricks, Elementor) via rendered HTML extraction
* Dedicated admin settings page with vertical tab navigation
* Clean uninstall support

== Upgrade Notice ==

= 1.0.0 =
Initial release. Install to make your site AI-agent friendly.
