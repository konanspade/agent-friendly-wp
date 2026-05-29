<p align="center">
  <img src="assets/logo.svg" width="80" height="80" alt="Agent Friendly">
</p>

<h1 align="center">Agent Friendly</h1>

<p align="center">
  <strong>Make your WordPress site readable by AI agents.</strong><br>
  LLMs.txt &bull; Markdown Mirror &bull; WebMCP &bull; Fluent Forms &bull; WooCommerce &bull; AI Discovery
</p>

<p align="center">
  <a href="https://wordpress.org/plugins/agent-friendly-wp/"><img src="https://img.shields.io/badge/WordPress-v1.0.0-21759b?logo=wordpress" alt="WordPress"></a>
  <a href="https://www.gnu.org/licenses/gpl-2.0.html"><img src="https://img.shields.io/badge/License-GPL--2.0--or--later-blue" alt="License"></a>
  <img src="https://img.shields.io/badge/PHP-7.4+-8892BF?logo=php&logoColor=white" alt="PHP 7.4+">
  <img src="https://img.shields.io/badge/WordPress-6.0+-21759b" alt="WP 6.0+">
</p>

---

Agent Friendly is the most complete toolkit for making your WordPress site accessible to AI agents and large language models. Six modules provide structured content access, form interaction, commerce tools, and standards-based discovery.

## Modules

### Content Layer

| Module | What it does |
|--------|-------------|
| **LLMs.txt** | Serves `llms.txt` and `llms-full.txt` at your site root per the [llms.txt spec](https://llmstxt.org/). Auto-generates from published pages or accepts custom content. |
| **Markdown Mirror** | Every published post/page available as clean Markdown at `/md/{slug}/`. Converts rendered HTML (Gutenberg, shortcodes, page builders) on the fly with YAML front matter. |

### Tool Layer

| Module | What it does |
|--------|-------------|
| **WebMCP** | Registers structured tools via [`navigator.modelContext`](https://webmcp.dev/) for AI-powered browsers (Chrome 149+, Edge). Falls back to `window.afwpTools` public API. |
| **Tool Registry** | Central coordination for all tools. Unified `POST /tools/execute` endpoint with per-IP rate limiting, nonce protection, and optional Turnstile captcha. Developer API via `afwp_register_tools` hook. |
| **Fluent Forms** | When [Fluent Forms](https://wordpress.org/plugins/fluentform/) is active: `list_forms`, `get_form_fields`, `submit_form` tools with optional [Cloudflare Turnstile](https://www.cloudflare.com/products/turnstile/) captcha. |
| **WooCommerce** | When [WooCommerce](https://wordpress.org/plugins/woocommerce/) is active: product search, product details, categories (public) + cart management and coupons (nonce-protected). |

### Discovery Layer

| Endpoint | Standard |
|----------|----------|
| `/.well-known/webmcp.json` | WebMCP manifest |
| `/.well-known/mcp/server-card.json` | MCP Server Card (SEP-1649) |
| `/.well-known/api-catalog` | RFC 9727 API Catalog |
| `/.well-known/agent-skills/index.json` | Agent Skills Index |

Plus RFC 8288 `Link` HTTP headers and `<link>` HTML tags on every frontend page, and `robots.txt` directives for LLMs.txt and WebMCP.

## Quick Start

```bash
# Install via WP-CLI
wp plugin install agent-friendly-wp --activate

# Or upload the folder to /wp-content/plugins/ and activate in wp-admin
```

1. Activate the plugin
2. Visit **Agent Friendly** in your admin sidebar
3. LLMs.txt, Markdown Mirror, and WebMCP work immediately — zero config
4. Install Fluent Forms or WooCommerce to unlock form and commerce tools

## Registered Tools

### Content (public, read-only)

| Tool | Description |
|------|-------------|
| `search_site` | Full-text search across published content |
| `get_page` | Page/post content by URL path or ID |
| `get_site_info` | Site name, description, language, content types |
| `get_navigation` | Menu structure with nested links |
| `list_posts` | Recent posts filtered by category or tag |
| `get_contact_info` | Site name, URL, and email |

### Forms (requires Fluent Forms)

| Tool | Access | Description |
|------|--------|-------------|
| `list_forms` | Public | List published forms with IDs and field counts |
| `get_form_fields` | Public | Field schema for a specific form |
| `submit_form` | Protected + Turnstile | Submit form data with validation |

### WooCommerce (requires WooCommerce)

| Tool | Access | Description |
|------|--------|-------------|
| `woo_search_products` | Public | Search by keyword, category, price range |
| `woo_get_product` | Public | Full product details by ID or slug |
| `woo_get_product_categories` | Public | List categories with counts |
| `woo_add_to_cart` | Protected | Add product to cart |
| `woo_get_cart` | Protected | Current cart contents and totals |
| `woo_remove_from_cart` | Protected | Remove item by cart key |
| `woo_apply_coupon` | Protected | Apply a coupon code |

## REST API

All endpoints under the `agent-friendly/v1` namespace.

```
# List all tools
GET  /wp-json/agent-friendly/v1/tools

# Execute any tool
POST /wp-json/agent-friendly/v1/tools/execute
     {"tool": "search_site", "input": {"query": "hello"}}

# Get a nonce for protected tools (requires login)
GET  /wp-json/agent-friendly/v1/tools/nonce
```

Individual backward-compatible endpoints also available at `/webmcp/search`, `/webmcp/page`, etc.

## Developer API

Register custom tools from any plugin or theme:

```php
add_action( 'afwp_register_tools', function( $registry ) {
    $registry->register( 'my_custom_tool', [
        'description' => 'Does something useful.',
        'group'       => 'custom',
        'inputSchema' => [
            'type'       => 'object',
            'properties' => [
                'text' => [
                    'type'        => 'string',
                    'description' => 'Input text to process.',
                ],
            ],
            'required' => [ 'text' ],
        ],
        'callback' => function( $input ) {
            return [ 'result' => strtoupper( $input['text'] ) ];
        },
        'protected' => false,
        'turnstile' => false,
    ] );
} );
```

Your tool automatically appears in the WebMCP manifest, browser registration, discovery endpoints, and the unified execute endpoint.

### Filters

| Filter | Description |
|--------|-------------|
| `afwp_protected_tools` | Array of tool names that require nonce authentication |
| `afwp_rate_limiter_ip` | Override the client IP used for rate limiting (for trusted proxy setups) |

## Security

- **Read-only tools** are publicly accessible (same data as your public site)
- **Write tools** (cart, form submission) require a valid WordPress REST nonce
- **Turnstile captcha** optionally protects form submissions via Cloudflare's managed challenge
- **Rate limiting** enforces per-IP request limits on the execute endpoint (default: 120 req / 60s)
- Coexistence guard prevents conflicts if Konan & Spade Helper is active

## Architecture

```
agent-friendly-wp.php          Bootstrap, constants, module registration
includes/
  class-plugin.php             Singleton container, module lifecycle
  class-tool-registry.php      Central tool registry + execute/nonce/settings REST
  class-rate-limiter.php       Per-IP rate limiting with transient counters
  class-admin-page.php         Dedicated admin page with vertical tab nav
  class-module.php             Abstract module base class
  class-html-to-markdown.php   DOMDocument-based HTML to Markdown converter
  modules/
    class-llms-txt-module.php
    class-markdown-mirror-module.php
    class-webmcp-module.php
    class-fluent-forms-module.php
    class-woocommerce-module.php
    class-discovery-module.php
uninstall.php                  Clean removal of all options and transients
```

## Requirements

- WordPress 6.0+
- PHP 7.4+
- **Optional:** Fluent Forms (for form tools)
- **Optional:** WooCommerce 7.0+ (for commerce tools)

## License

GPL-2.0-or-later. See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).

---

<p align="center">
  Built by <a href="https://konanspade.com">Konan & Spade</a>
</p>
