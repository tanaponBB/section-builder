<?php

namespace SectionBuilder;

/**
 * REST API — Pattern-based
 *
 * Endpoints:
 * GET    /builder/v1/patterns                  → list ทุก reusable blocks (section templates)
 * GET    /builder/v1/patterns/{id}             → get pattern เดียว + rendered HTML
 * GET    /builder/v1/pages/{id}                → get page layout (pattern order + overrides)
 * POST   /builder/v1/pages/{id}                → save page layout (atomic)
 * POST   /builder/v1/pages/{id}/render         → preview rendered HTML (ไม่ save)
 * POST   /builder/v1/patterns/render           → render pattern เดียว + overrides
 */
class REST_API
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        if (!self::$instance) {
            self::$instance = new self();
            self::$instance->init();
        }
        return self::$instance;
    }

    private function init(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        $ns = 'builder/v1';

        // ── Patterns ──
        register_rest_route($ns, '/patterns', [
            'methods'             => 'GET',
            'callback'            => [$this, 'list_patterns'],
            'permission_callback' => [$this, 'can_read'],
            'args'                => [
                'source'   => ['type' => 'string', 'default' => 'all',  'enum' => ['all', 'synced', 'theme']],
                'category' => ['type' => 'string', 'default' => ''],
                'search'   => ['type' => 'string', 'default' => ''],
            ],
        ]);

        // รับทั้ง numeric (wp_block ID) และ string (theme pattern name)
        register_rest_route($ns, '/patterns/(?P<id>[a-zA-Z0-9\-\_\/]+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_pattern'],
            'permission_callback' => [$this, 'can_read'],
        ]);

        register_rest_route($ns, '/patterns/render', [
            'methods'             => 'POST',
            'callback'            => [$this, 'render_pattern'],
            'permission_callback' => [$this, 'can_read'],
        ]);

        // ── Pages ──

        // GET /pages — list ทุก pages ที่ใช้ section builder
        register_rest_route($ns, '/pages', [
            'methods'             => 'GET',
            'callback'            => [$this, 'list_pages'],
            'permission_callback' => [$this, 'can_read'],
        ]);

        register_rest_route($ns, '/pages/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_page_layout'],
                'permission_callback' => [$this, 'can_read'],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'save_page_layout'],
                'permission_callback' => [$this, 'can_write'],
            ],
        ]);

        register_rest_route($ns, '/pages/(?P<id>\d+)/render', [
            'methods'             => 'POST',
            'callback'            => [$this, 'render_page_preview'],
            'permission_callback' => [$this, 'can_read'],
        ]);
    }

    // ================================================================
    //  Patterns — list, get, render
    // ================================================================

    /**
     * GET /patterns
     * Return ทุก wp_block posts — section templates ที่สร้างใน Gutenberg
     */
    public function list_patterns(\WP_REST_Request $request): \WP_REST_Response
    {
        $source   = $request->get_param('source') ?? 'all'; // all | synced | theme | core
        $category = $request->get_param('category') ?? '';
        $search   = $request->get_param('search') ?? '';

        $all_patterns = [];

        // ── 1. Synced Patterns (wp_block post type — สร้างเองใน wp-admin) ──
        if ($source === 'all' || $source === 'synced') {
            $args = [
                'post_type'      => 'wp_block',
                'posts_per_page' => 100,
                'post_status'    => 'publish',
                'orderby'        => 'title',
                'order'          => 'ASC',
            ];
            if ($search) $args['s'] = $search;

            $blocks = get_posts($args);
            foreach ($blocks as $b) {
                $p = $this->format_pattern($b);
                $p['source'] = 'synced';
                $all_patterns[] = $p;
            }
        }

        // ── 2. Theme + Plugin registered patterns ──
        if ($source === 'all' || $source === 'theme') {
            if (class_exists('\WP_Block_Patterns_Registry')) {
                $registry = \WP_Block_Patterns_Registry::get_instance();
                $registered = $registry->get_all_registered();

            foreach ($registered as $pattern) {
                $name = $pattern['name'] ?? '';
                $cats = $pattern['categories'] ?? [];

                // Filter by category ถ้ามี
                if ($category && !in_array($category, $cats)) continue;

                // Search filter
                if ($search) {
                    $title = $pattern['title'] ?? '';
                    $desc  = $pattern['description'] ?? '';
                    if (stripos($title, $search) === false && stripos($desc, $search) === false) continue;
                }

                $content = $pattern['content'] ?? '';

                // Extract thumbnail จาก first image
                $thumb = '';
                if (preg_match('/src=["\']([^"\']+)/', $content, $m)) {
                    $thumb = $m[1];
                }

                $all_patterns[] = [
                    'id'              => $name, // patterns ใช้ name เป็น ID (string)
                    'title'           => $pattern['title'] ?? $name,
                    'slug'            => sanitize_title($name),
                    'description'     => $pattern['description'] ?? '',
                    'category'        => implode(', ', $cats),
                    'categories'      => $cats,
                    'thumbnail'       => $thumb,
                    'source'          => 'theme',
                    'raw_content'     => $content,
                    'editable_fields' => $this->extract_editable_fields($content),
                    'viewportWidth'   => $pattern['viewportWidth'] ?? null,
                    'blockTypes'      => $pattern['blockTypes'] ?? [],
                ];
            }
            } // end class_exists WP_Block_Patterns_Registry
        }

        // ── Get unique categories ──
        $categories = [];
        if ($source === 'all' || $source === 'theme') {
            // WP_Block_Pattern_Categories_Registry มีตั้งแต่ WP 5.5+
            if (class_exists('\WP_Block_Pattern_Categories_Registry')) {
                $cat_registry = \WP_Block_Pattern_Categories_Registry::get_instance();
                foreach ($cat_registry->get_all_registered() as $cat) {
                    $categories[] = [
                        'name'  => $cat['name'],
                        'label' => $cat['label'],
                    ];
                }
            }
        }

        return new \WP_REST_Response([
            'success' => true,
            'data'    => [
                'patterns'   => $all_patterns,
                'categories' => $categories,
                'total'      => count($all_patterns),
            ],
        ]);
    }

    /**
     * GET /patterns/{id}
     * Return pattern เดียว + rendered HTML
     * id = numeric (wp_block post ID) หรือ string (theme pattern name)
     */
    public function get_pattern(\WP_REST_Request $request): \WP_REST_Response
    {
        $id = $request->get_param('id');
        $result = $this->resolve_pattern($id);

        if (!$result) {
            return new \WP_REST_Response(['success' => false, 'message' => 'Pattern not found'], 404);
        }

        $result['rendered_html'] = $this->render_block_content($result['raw_content']);

        return new \WP_REST_Response(['success' => true, 'data' => $result]);
    }

    /**
     * POST /patterns/render
     * Render pattern ทีละตัว + apply content overrides
     * Body: { pattern_id: 42 or "pattern-name", overrides: { ... } }
     */
    public function render_pattern(\WP_REST_Request $request): \WP_REST_Response
    {
        $body       = $request->get_json_params();
        $pattern_id = $body['pattern_id'] ?? '';
        $overrides  = $body['overrides'] ?? [];

        $result = $this->resolve_pattern($pattern_id);
        if (!$result) {
            return new \WP_REST_Response(['success' => false, 'message' => 'Pattern not found'], 404);
        }

        $content = $result['raw_content'];
        $content = $this->apply_overrides($content, $overrides);
        $html    = $this->render_block_content($content);

        return new \WP_REST_Response([
            'success' => true,
            'data'    => [
                'html'     => $html,
                'document' => $this->wrap_html_doc($html),
            ],
        ]);
    }

    /**
     * Resolve pattern by ID (numeric = wp_block) or name (string = theme pattern)
     */
    private function resolve_pattern($id): ?array
    {
        // Numeric → wp_block post
        if (is_numeric($id)) {
            $post = get_post((int) $id);
            if ($post && $post->post_type === 'wp_block') {
                $data = $this->format_pattern($post);
                $data['source'] = 'synced';
                return $data;
            }
        }

        // String → theme/plugin registered pattern
        if (class_exists('\WP_Block_Patterns_Registry')) {
            $registry = \WP_Block_Patterns_Registry::get_instance();
            $pattern  = $registry->get_registered($id);
        } else {
            $pattern = null;
        }

        if ($pattern) {
            $content = $pattern['content'] ?? '';
            $thumb   = '';
            if (preg_match('/src=["\']([^"\']+)/', $content, $m)) $thumb = $m[1];

            return [
                'id'              => $pattern['name'],
                'title'           => $pattern['title'] ?? $pattern['name'],
                'slug'            => sanitize_title($pattern['name']),
                'description'     => $pattern['description'] ?? '',
                'category'        => implode(', ', $pattern['categories'] ?? []),
                'categories'      => $pattern['categories'] ?? [],
                'thumbnail'       => $thumb,
                'source'          => 'theme',
                'raw_content'     => $content,
                'editable_fields' => $this->extract_editable_fields($content),
            ];
        }

        return null;
    }

    // ================================================================
    //  Pages — list, layout read/write
    // ================================================================

    /**
     * GET /pages
     *
     * List ทุก pages ที่ใช้ section builder (มี _sb_page_layout meta)
     * + ทุก pages ที่มี shortcode [page_builder_sections]
     * + ทุก pages ที่ใช้ template sb-page-builder.php
     *
     * Next.js ใช้แสดงรายการ pages ใน dashboard — ไม่ต้องหา page ID เอง
     */
    public function list_pages(\WP_REST_Request $request): \WP_REST_Response
    {
        // ดึงทุก pages
        $all_pages = get_posts([
            'post_type'      => 'page',
            'posts_per_page' => 200,
            'post_status'    => ['publish', 'draft', 'pending', 'private'],
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);

        $builder_pages = [];
        $other_pages   = [];

        foreach ($all_pages as $page) {
            $layout   = get_post_meta($page->ID, '_sb_page_layout', true);
            $template = get_post_meta($page->ID, '_wp_page_template', true);
            $has_shortcode = strpos($page->post_content, '[page_builder_sections]') !== false;
            $has_layout = !empty($layout) && is_array($layout);

            $is_builder_page = $has_layout || $has_shortcode || $template === 'sb-page-builder.php';

            $page_data = [
                'id'             => $page->ID,
                'title'          => $page->post_title ?: '(no title)',
                'slug'           => $page->post_name,
                'status'         => $page->post_status,
                'permalink'      => get_permalink($page->ID),
                'modified'       => $page->post_modified_gmt,
                'template'       => $template ?: 'default',
                'is_builder_page'=> $is_builder_page,
                'sections_count' => $has_layout ? count($layout) : 0,
                'has_shortcode'  => $has_shortcode,
            ];

            // แสดง featured image ถ้ามี
            $thumb_id = get_post_thumbnail_id($page->ID);
            if ($thumb_id) {
                $page_data['thumbnail'] = wp_get_attachment_image_url($thumb_id, 'medium');
            }

            if ($is_builder_page) {
                $builder_pages[] = $page_data;
            } else {
                $other_pages[] = $page_data;
            }
        }

        return new \WP_REST_Response([
            'success' => true,
            'data'    => [
                'builder_pages' => $builder_pages,
                'other_pages'   => $other_pages,
                'total_builder' => count($builder_pages),
                'total_other'   => count($other_pages),
            ],
        ]);
    }

    /**
     * GET /pages/{id}
     * Return page layout — ordered array of { pattern_id, overrides }
     */
    public function get_page_layout(\WP_REST_Request $request): \WP_REST_Response
    {
        $post_id = (int) $request->get_param('id');
        $post    = get_post($post_id);

        if (!$post || $post->post_type !== 'page') {
            return new \WP_REST_Response(['success' => false, 'message' => 'Page not found'], 404);
        }

        $layout = get_post_meta($post_id, '_sb_page_layout', true);
        if (!$layout || !is_array($layout)) $layout = [];

        // Enrich layout with pattern info
        $sections = [];
        foreach ($layout as $i => $item) {
            $pid = $item['pattern_id'] ?? 0;
            $pattern_data = null;

            // Resolve pattern — support ทั้ง numeric (wp_block) และ string (theme pattern)
            if (is_numeric($pid)) {
                $pattern_post = get_post((int) $pid);
                if ($pattern_post && $pattern_post->post_type === 'wp_block') {
                    $pattern_data = $this->format_pattern($pattern_post);
                    $pattern_data['source'] = 'synced';
                }
            } else {
                $resolved = $this->resolve_pattern($pid);
                if ($resolved) {
                    $pattern_data = $resolved;
                }
            }

            $sections[] = [
                'order'      => $i,
                'pattern_id' => $pid,
                'pattern'    => $pattern_data,
                'overrides'  => $item['overrides'] ?? [],
            ];
        }

        return new \WP_REST_Response([
            'success' => true,
            'data'    => [
                'id'        => $post_id,
                'title'     => $post->post_title,
                'slug'      => $post->post_name,
                'status'    => $post->post_status,
                'permalink' => get_permalink($post_id),
                'sections'  => $sections,
            ],
        ]);
    }

    /**
     * POST /pages/{id}
     * Atomic save — เขียน layout ทั้งหน้า
     *
     * Body:
     * {
     *   "title": "Page Title",
     *   "slug": "page-slug",
     *   "status": "draft|publish",
     *   "sections": [
     *     { "pattern_id": 42, "overrides": { "heading": "custom text" } },
     *     { "pattern_id": 55, "overrides": {} }
     *   ]
     * }
     */
    public function save_page_layout(\WP_REST_Request $request): \WP_REST_Response
    {
        $post_id = (int) $request->get_param('id');
        $post    = get_post($post_id);

        if (!$post || $post->post_type !== 'page') {
            return new \WP_REST_Response(['success' => false, 'message' => 'Page not found'], 404);
        }

        $body     = $request->get_json_params();
        $sections = $body['sections'] ?? [];

        // Validate — pattern_id ต้องเป็น wp_block (numeric) หรือ registered pattern (string)
        $errors = [];
        foreach ($sections as $i => $sec) {
            $pid = $sec['pattern_id'] ?? null;

            if (!$pid) {
                $errors[] = "Section #{$i}: missing pattern_id";
                continue;
            }

            // Numeric → ต้องเป็น wp_block post ที่มีอยู่จริง
            if (is_numeric($pid)) {
                $p = get_post((int) $pid);
                if (!$p || $p->post_type !== 'wp_block') {
                    $errors[] = "Section #{$i}: synced pattern #{$pid} not found";
                }
            } else {
                // String → ต้องเป็น registered pattern
                if (class_exists('\WP_Block_Patterns_Registry')) {
                    $registry = \WP_Block_Patterns_Registry::get_instance();
                    if (!$registry->get_registered((string) $pid)) {
                        $errors[] = "Section #{$i}: theme pattern '{$pid}' not found";
                    }
                }
                // ถ้าไม่มี Registry class → skip validation สำหรับ theme patterns
            }
        }

        if ($errors) {
            return new \WP_REST_Response(['success' => false, 'errors' => $errors], 400);
        }

        // ── Build post update args (ทำครั้งเดียว) ──
        $update = ['ID' => $post_id];
        if (isset($body['title']))  $update['post_title']  = sanitize_text_field($body['title']);
        if (isset($body['slug']))   $update['post_name']   = sanitize_title($body['slug']);
        if (isset($body['status'])) $update['post_status'] = in_array($body['status'], ['draft','publish','pending','private']) ? $body['status'] : 'draft';

        // ── Auto-inject shortcode ถ้ายังไม่มี (รวมใน wp_update_post ครั้งเดียว) ──
        $current_content = $post->post_content ?? '';
        if (strpos($current_content, '[page_builder_sections]') === false) {
            // Prepend shortcode block — ไม่ overwrite content เดิม
            $shortcode_block = '<!-- wp:shortcode -->' . "\n"
                             . '[page_builder_sections]' . "\n"
                             . '<!-- /wp:shortcode -->';

            // ถ้า content ว่าง → ใส่ shortcode อย่างเดียว
            // ถ้ามี content อยู่ → prepend shortcode ไว้ข้างบน
            if (trim($current_content) === '') {
                $update['post_content'] = $shortcode_block;
            } else {
                $update['post_content'] = $shortcode_block . "\n\n" . $current_content;
            }
        }

        // ── Update post (ครั้งเดียว) ──
        wp_update_post($update);

        // ── Auto-set page template ──
        $current_template = get_post_meta($post_id, '_wp_page_template', true);
        if (!$current_template || $current_template === 'default' || $current_template === '') {
            update_post_meta($post_id, '_wp_page_template', 'sb-page-builder.php');
        }

        // ── Save layout as post meta ──
        $layout = [];
        foreach ($sections as $sec) {
            $pid = $sec['pattern_id'];
            $layout[] = [
                'pattern_id' => is_numeric($pid) ? (int) $pid : (string) $pid,
                'overrides'  => $sec['overrides'] ?? [],
            ];
        }
        update_post_meta($post_id, '_sb_page_layout', $layout);

        // Purge cache
        $this->purge_cache($post_id);

        return new \WP_REST_Response([
            'success' => true,
            'data'    => [
                'id'             => $post_id,
                'permalink'      => get_permalink($post_id),
                'sections_count' => count($layout),
                'template'       => get_post_meta($post_id, '_wp_page_template', true),
            ],
        ]);
    }

    /**
     * POST /pages/{id}/render
     * Preview — render ทุก section ตาม order โดยไม่ save
     * Body: { sections: [{ pattern_id, overrides }, ...] }
     */
    public function render_page_preview(\WP_REST_Request $request): \WP_REST_Response
    {
        $body     = $request->get_json_params();
        $sections = $body['sections'] ?? null;
        $post_id  = (int) $request->get_param('id');

        // ถ้าไม่ส่ง sections → ใช้ที่ save ไว้
        if ($sections === null) {
            $html = Renderer::render($post_id);
        } else {
            $html = Renderer::render_from_layout($sections);
        }

        return new \WP_REST_Response([
            'success' => true,
            'data'    => [
                'html'     => $html,
                'document' => $this->wrap_html_doc($html),
            ],
        ]);
    }

    // ================================================================
    //  Helpers
    // ================================================================

    /**
     * Format wp_block post → API response
     */
    private function format_pattern(\WP_Post $post): array
    {
        $content = $post->post_content;

        // Extract ALL images from content (ไม่ใช่แค่ตัวแรก)
        $images = [];
        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/', $content, $matches)) {
            $images = array_unique($matches[1]);
        }

        // Thumbnail = first image หรือ featured image ของ wp_block post
        $thumbnail = '';
        $featured_id = get_post_thumbnail_id($post->ID);
        if ($featured_id) {
            $thumbnail = wp_get_attachment_image_url($featured_id, 'medium');
        } elseif (!empty($images)) {
            $thumbnail = $images[0];
        }

        // Render preview HTML — ใช้แสดง thumbnail ใน Next.js ผ่าน iframe
        $rendered_html = '';
        try {
            $rendered_html = do_blocks($content);
            $rendered_html = apply_filters('the_content', $rendered_html);
        } catch (\Exception $e) {
            $rendered_html = '<!-- render error -->';
        }

        // Extract category from pattern meta
        $category = get_post_meta($post->ID, '_sb_pattern_category', true) ?: 'uncategorized';

        // Extract block pattern categories if original pattern data exists
        $categories = [];
        $meta = $post->post_content;
        if (preg_match('/\"categories\":\[([^\]]+)\]/', $meta, $cm)) {
            $raw = str_replace(['"', "'"], '', $cm[1]);
            $categories = array_map('trim', explode(',', $raw));
        }

        return [
            'id'              => $post->ID,
            'title'           => $post->post_title,
            'slug'            => $post->post_name,
            'category'        => $category,
            'categories'      => $categories,
            'thumbnail'       => $thumbnail,
            'images'          => array_values($images),
            'modified'        => $post->post_modified_gmt,
            'raw_content'     => $content,
            'rendered_html'   => $rendered_html,
            'preview_document'=> $this->wrap_html_doc($rendered_html),
            'editable_fields' => $this->extract_editable_fields($content),
        ];
    }

    /**
     * Extract editable text from Gutenberg block content
     * ค้นหา headings, paragraphs, buttons → return เป็น array ที่ Next.js ใช้สร้าง edit form
     */
    private function extract_editable_fields(string $content): array
    {
        $fields = [];
        $blocks = parse_blocks($content);

        $this->walk_blocks($blocks, $fields);

        return $fields;
    }

    /**
     * Recursively walk blocks and extract editable text
     */
    private function walk_blocks(array $blocks, array &$fields, string $path = ''): void
    {
        foreach ($blocks as $i => $block) {
            $name = $block['blockName'] ?? '';
            $html = $block['innerHTML'] ?? '';
            $current_path = $path ? "{$path}.{$i}" : (string) $i;

            if (in_array($name, ['core/heading', 'core/paragraph', 'core/button'])) {
                $text = wp_strip_all_tags($html);
                $text = trim($text);

                if ($text) {
                    $type_map = [
                        'core/heading'   => 'heading',
                        'core/paragraph' => 'text',
                        'core/button'    => 'button',
                    ];

                    // Extract heading level
                    $level = '';
                    if ($name === 'core/heading' && preg_match('/<h(\d)/', $html, $m)) {
                        $level = 'h' . $m[1];
                    }

                    $fields[] = [
                        'path'  => $current_path,
                        'type'  => $type_map[$name] ?? 'text',
                        'level' => $level,
                        'value' => $text,
                        'block' => $name,
                    ];
                }
            }

            if ($name === 'core/image' || $name === 'core/cover') {
                if (preg_match('/src=["\']([^"\']+)/', $html, $m)) {
                    $fields[] = [
                        'path'  => $current_path,
                        'type'  => 'image',
                        'value' => $m[1],
                        'block' => $name,
                    ];
                }
            }

            // Recurse inner blocks
            if (!empty($block['innerBlocks'])) {
                $this->walk_blocks($block['innerBlocks'], $fields, $current_path);
            }
        }
    }

    /**
     * Apply content overrides to block content
     * overrides = { "0.1": "New heading text", "0.3": "New paragraph" }
     * key = block path, value = new text
     */
    private function apply_overrides(string $content, array $overrides): string
    {
        if (empty($overrides)) return $content;

        $blocks = parse_blocks($content);
        $this->apply_overrides_recursive($blocks, $overrides, '');
        return serialize_blocks($blocks);
    }

    private function apply_overrides_recursive(array &$blocks, array $overrides, string $path): void
    {
        foreach ($blocks as $i => &$block) {
            $current_path = $path ? "{$path}.{$i}" : (string) $i;
            $name = $block['blockName'] ?? '';

            if (isset($overrides[$current_path])) {
                $new_value = $overrides[$current_path];

                if (in_array($name, ['core/heading', 'core/paragraph'])) {
                    // Replace text content while preserving HTML tags
                    $block['innerHTML'] = preg_replace(
                        '/>(.*?)</s',
                        '>' . esc_html($new_value) . '<',
                        $block['innerHTML'],
                        1
                    );
                    // Also update innerContent
                    if (!empty($block['innerContent'])) {
                        $block['innerContent'][0] = $block['innerHTML'];
                    }
                } elseif ($name === 'core/button') {
                    $block['innerHTML'] = preg_replace(
                        '/>(.*?)</a/s',
                        '>' . esc_html($new_value) . '</a',
                        $block['innerHTML'],
                        1
                    );
                    if (!empty($block['innerContent'])) {
                        $block['innerContent'][0] = $block['innerHTML'];
                    }
                }
            }

            if (!empty($block['innerBlocks'])) {
                $this->apply_overrides_recursive($block['innerBlocks'], $overrides, $current_path);
            }
        }
    }

    /**
     * Render Gutenberg block content → HTML
     */
    private function render_block_content(string $content): string
    {
        // do_blocks() parses and renders all Gutenberg blocks
        $html = do_blocks($content);
        // Apply content filters (shortcodes, autop, etc.)
        $html = apply_filters('the_content', $html);
        return $html;
    }

    /**
     * Wrap HTML in full document for iframe srcDoc
     */
    private function wrap_html_doc(string $html): string
    {
        $css_url  = SB_PLUGIN_URL . 'assets/css/section-builder.css';
        $theme_css = get_stylesheet_uri();

        return sprintf(
            '<!DOCTYPE html><html><head>'
            . '<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<link rel="stylesheet" href="%s">'
            . '<link rel="stylesheet" href="%s">'
            . '<style>body{margin:0;padding:0}</style>'
            . '</head><body class="sb-preview">%s</body></html>',
            esc_url($theme_css),
            esc_url($css_url),
            $html
        );
    }

    private function purge_cache(int $post_id): void
    {
        if (function_exists('wp_cache_post_change'))      wp_cache_post_change($post_id);
        if (class_exists('LiteSpeed_Cache_API'))           do_action('litespeed_purge_post', $post_id);
        if (function_exists('rocket_clean_post'))           rocket_clean_post($post_id);
        do_action('sb_purge_page_cache', $post_id);
    }

    public function can_read(): bool  { return is_user_logged_in(); }
    public function can_write(): bool { return current_user_can('edit_pages'); }
}
