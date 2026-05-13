# Section Builder

A WordPress plugin that turns **Gutenberg Reusable Blocks** into reusable section templates, exposes them via REST API, and lets a headless dashboard (e.g. Next.js) compose pages by ordering sections and overriding text content.

> Pattern-based page builder — no ACF, no custom PHP per section. Design once in Gutenberg, reuse anywhere.

---

## Requirements

- WordPress **5.9+**
- PHP **7.4+**
- (Optional) A frontend dashboard that consumes the REST API — e.g. Next.js

## Installation

1. Copy the `section-builder` folder into `wp-content/plugins/`
2. Activate **Section Builder** from wp-admin → Plugins
3. Done. No additional configuration required.

## How it works

1. **Design sections** in wp-admin using Gutenberg → save as Reusable Blocks (`wp_block`)
2. **Fetch & arrange** sections from your dashboard via REST API
3. **Save page layout** — order of patterns + per-block text overrides
4. **Render** — the plugin auto-applies the page template and renders the layout via the `[page_builder_sections]` shortcode

When a layout is saved through the API, the plugin automatically:
- Sets the page template to `Section Builder — Full Page`
- Injects the `[page_builder_sections]` shortcode into `post_content` as a fallback

No manual wp-admin setup needed.

## REST API

Base namespace: `/wp-json/builder/v1`

### Patterns
| Method | Endpoint | Purpose |
|--------|----------|---------|
| `GET`  | `/patterns` | List all Reusable Blocks with `editable_fields` |
| `GET`  | `/patterns/{id}` | Single pattern + rendered HTML |
| `POST` | `/patterns/render` | Render a pattern with overrides (preview) |

### Pages
| Method | Endpoint | Purpose |
|--------|----------|---------|
| `GET`  | `/pages/{id}` | Read saved page layout |
| `POST` | `/pages/{id}` | Atomically save layout |
| `POST` | `/pages/{id}/render` | Preview rendered HTML without saving |

### Page layout format

Stored as post meta `_sb_page_layout`:

```json
[
  { "pattern_id": 42, "overrides": { "0": "New heading text" } },
  { "pattern_id": 55, "overrides": {} },
  { "pattern_id": 38, "overrides": { "0.1": "Custom paragraph" } }
]
```

Override keys are **block paths** from `editable_fields` (e.g. `"0.1"` = second block inside the first block).

## Shortcode

Render a saved layout anywhere:

```
[page_builder_sections post_id="123"]
```

`post_id` defaults to the current post.

## CORS

The plugin allows REST requests from these origins by default — edit [section-builder.php](section-builder.php#L78-L81) to add your own:

- `http://localhost:3000`
- `https://dashboard.your-domain.com`

## Project structure

```
section-builder/
├── section-builder.php       # Bootstrap, shortcode, CORS, page template
├── includes/
│   ├── REST_API.php          # All REST endpoints
│   └── Renderer.php          # Layout → HTML
├── templates/
│   └── page-builder.php      # Custom page template
└── assets/css/
    └── section-builder.css   # Minimal wrapper styles
```

## Documentation

See [DOC.md](DOC.md) for architecture details, data model, v1 → v2 migration notes, and instructions for non-developers creating sections.

## Version

**2.2.1** — see [section-builder.php](section-builder.php) for version constants.
