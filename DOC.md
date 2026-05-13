# Section Builder v2 — Gutenberg Pattern-based Page Builder

## Architecture change (v2)

**v1 (deprecated):** ACF Flexible Content + PHP class per section type + custom templates
**v2 (current):** Gutenberg Reusable Blocks (`wp_block` post type) as section templates

## How it works

1. **Create patterns** in wp-admin → Gutenberg editor (Reusable Blocks)
   - Design each section visually using Gutenberg blocks
   - Save as Reusable Block → becomes `wp_block` post type
   - Each Reusable Block = one section template

2. **Next.js dashboard** fetches patterns via REST API
   - `GET /builder/v1/patterns` → list all available section templates
   - Each pattern includes `editable_fields` — extracted headings, paragraphs, buttons, images
   - User selects patterns, reorders with drag-and-drop, edits content overrides

3. **Save page layout** via REST API
   - `POST /builder/v1/pages/{id}` → saves ordered array of `{ pattern_id, overrides }`
   - Stored as `_sb_page_layout` post meta
   - Overrides keyed by block path (e.g., `"0.1"` = second block inside first block)

4. **Frontend renders** patterns in order
   - Renderer reads `_sb_page_layout` → loads each `wp_block` → applies overrides → renders HTML
   - Gutenberg's own CSS handles styling (no custom templates needed)

## Auto-setup on save (v2.1 fix)

When `POST /pages/{id}` saves a layout, the plugin automatically:
1. Sets the page template to `sb-page-builder.php` (if not already set)
2. Injects `[page_builder_sections]` shortcode into `post_content` as fallback

This means: **no manual wp-admin setup needed.** Just save sections from Next.js → public page renders immediately.

Previously this was a manual step (see `page-builder-public-render-fix.md`). Now it's automatic.

## File structure

```
section-builder/
├── section-builder.php          # Bootstrap, shortcode, CORS, page template
├── includes/
│   ├── REST_API.php             # All endpoints (patterns + pages + render)
│   └── Renderer.php             # Render patterns from layout array
├── templates/
│   └── page-builder.php         # Custom page template
└── assets/css/
    └── section-builder.css      # Minimal wrapper CSS (Gutenberg handles block styles)
```

## REST API endpoints

### Patterns
- `GET /builder/v1/patterns` — list all Reusable Blocks with editable_fields
- `GET /builder/v1/patterns/{id}` — single pattern + rendered HTML
- `POST /builder/v1/patterns/render` — render pattern with overrides (preview)

### Pages
- `GET /builder/v1/pages/{id}` — page layout (pattern order + overrides)
- `POST /builder/v1/pages/{id}` — atomic save layout
- `POST /builder/v1/pages/{id}/render` — preview rendered HTML without saving

## Data model

### Page layout (post meta: `_sb_page_layout`)
```json
[
  { "pattern_id": 42, "overrides": { "0": "New heading text" } },
  { "pattern_id": 55, "overrides": {} },
  { "pattern_id": 38, "overrides": { "0.1": "Custom paragraph" } }
]
```

### Editable fields (extracted from block content)
```json
[
  { "path": "0",   "type": "heading", "level": "h2", "value": "Original heading", "block": "core/heading" },
  { "path": "1",   "type": "text",    "value": "Original paragraph",              "block": "core/paragraph" },
  { "path": "2.0", "type": "button",  "value": "Click me",                        "block": "core/button" }
]
```

### Override format
Key = block path from editable_fields, value = new text content.
The override replaces text inside the block HTML while preserving tags and attributes.

## Key differences from v1

| Aspect | v1 (ACF) | v2 (Patterns) |
|--------|----------|---------------|
| Section design | PHP code + ACF fields | Gutenberg visual editor |
| Add new section | Write PHP class + template + CSS | Just create Reusable Block |
| Storage | ACF Flexible Content (wp_postmeta complex) | Simple post meta array |
| Rendering | Custom PHP templates | Gutenberg do_blocks() |
| CSS | Custom section-builder.css (429 lines) | Gutenberg block styles (built-in) |
| Content editing | ACF field schema → dynamic form | Override text in existing blocks |
| Dependencies | ACF Pro required | No dependencies |

## Creating a section (for non-developers)

1. wp-admin → Add New → Reusable Block (or Appearance → Patterns)
2. Design section using Gutenberg blocks (Cover, Columns, Heading, Image, Button, etc.)
3. Style using Gutenberg block settings (colors, spacing, typography)
4. Publish → section immediately available in Next.js dashboard

## Tech stack

- WordPress 6.x + Gutenberg (no ACF dependency)
- Elementor Pro still handles theme header/footer if needed
- Next.js dashboard (ADDTOCRAFT/SimplyBuild monorepo)
- Jotai for state, @dnd-kit for drag-and-drop
