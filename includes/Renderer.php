<?php

namespace SectionBuilder;

/**
 * Renderer — Pattern-based
 *
 * อ่าน _sb_page_layout จาก post meta → render แต่ละ pattern ตาม order
 * รองรับ content overrides per section
 */
class Renderer
{
    /**
     * Render page จาก post meta
     */
    public static function render(int $post_id): string
    {
        $layout = get_post_meta($post_id, '_sb_page_layout', true);

        if (!$layout || !is_array($layout)) {
            return defined('WP_DEBUG') && WP_DEBUG
                ? sprintf('<!-- SB: no layout for post_id=%d -->', $post_id)
                : '';
        }

        return self::render_from_layout($layout);
    }

    /**
     * Render จาก layout array โดยตรง (ใช้ทั้ง frontend และ preview API)
     *
     * @param array $layout [{ pattern_id: int|string, overrides: array }, ...]
     *   pattern_id = numeric (wp_block post ID) หรือ string (theme pattern name)
     */
    public static function render_from_layout(array $layout): string
    {
        if (empty($layout)) return '';

        $output = '';

        foreach ($layout as $index => $item) {
            $pattern_id = $item['pattern_id'] ?? 0;
            $overrides  = $item['overrides'] ?? [];

            // Resolve pattern content — support ทั้ง synced (ID) และ theme (name)
            $content = null;
            $slug    = '';

            if (is_numeric($pattern_id)) {
                // wp_block post
                $pattern = get_post((int) $pattern_id);
                if ($pattern && $pattern->post_type === 'wp_block') {
                    $content = $pattern->post_content;
                    $slug    = sanitize_title($pattern->post_title);
                }
            } else {
                // Theme/plugin registered pattern
                if (class_exists('\WP_Block_Patterns_Registry')) {
                    $registry = \WP_Block_Patterns_Registry::get_instance();
                    $pattern  = $registry->get_registered((string) $pattern_id);
                    if ($pattern) {
                        $content = $pattern['content'] ?? '';
                        $slug    = sanitize_title($pattern['title'] ?? $pattern_id);
                    }
                }
            }

            if ($content === null) {
                $output .= sprintf('<!-- SB: pattern %s not found -->', esc_html($pattern_id));
                continue;
            }

            // Apply overrides
            if (!empty($overrides)) {
                $content = self::apply_overrides($content, $overrides);
            }

            // Render blocks → HTML
            $html = do_blocks($content);
            $html = apply_filters('the_content', $html);

            // Wrap in section container
            $output .= sprintf(
                '<section class="sb-section sb-section--%s" data-section-index="%d" data-pattern-id="%s">%s</section>',
                esc_attr($slug),
                $index,
                esc_attr($pattern_id),
                $html
            );
        }

        return $output;
    }

    /**
     * Apply text/content overrides to block content
     */
    private static function apply_overrides(string $content, array $overrides): string
    {
        if (empty($overrides)) return $content;

        $blocks = parse_blocks($content);
        self::apply_overrides_walk($blocks, $overrides, '');
        return serialize_blocks($blocks);
    }

    private static function apply_overrides_walk(array &$blocks, array $overrides, string $path): void
    {
        foreach ($blocks as $i => &$block) {
            $current_path = $path ? "{$path}.{$i}" : (string) $i;
            $name = $block['blockName'] ?? '';

            if (isset($overrides[$current_path])) {
                $new_value = $overrides[$current_path];

                if (in_array($name, ['core/heading', 'core/paragraph'])) {
                    $block['innerHTML'] = preg_replace(
                        '/>(.*?)</s',
                        '>' . esc_html($new_value) . '<',
                        $block['innerHTML'],
                        1
                    );
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
                self::apply_overrides_walk($block['innerBlocks'], $overrides, $current_path);
            }
        }
    }
}
