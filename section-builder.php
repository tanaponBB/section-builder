<?php
/**
 * Plugin Name: Section Builder
 * Description: Pattern-based page builder — ใช้ Gutenberg Reusable Blocks เป็น section templates แล้วจัดเรียงผ่าน Next.js
 * Version: 2.2.1
 * Requires PHP: 7.4
 * Requires at least: 5.9
 */

defined('ABSPATH') || exit;

define('SB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SB_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SB_VERSION', '2.2.1');

// ============================================================
//  Version check — ป้องกัน fatal error
// ============================================================
if (version_compare(PHP_VERSION, '7.4', '<')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>Section Builder requires PHP 7.4+. Current: ' . PHP_VERSION . '</p></div>';
    });
    return;
}

if (version_compare(get_bloginfo('version'), '5.9', '<')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>Section Builder requires WordPress 5.9+</p></div>';
    });
    return;
}

// Autoload
spl_autoload_register(function ($class) {
    $prefix = 'SectionBuilder\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
    $relative = substr($class, strlen($prefix));
    $file = SB_PLUGIN_DIR . 'includes/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) require $file;
});

// ============================================================
//  Boot
// ============================================================

add_action('init', function () {
    // Register shortcode เร็ว — ไม่ต้องรอ plugin อื่น
    add_shortcode('page_builder_sections', function ($atts) {
        $atts    = shortcode_atts(['post_id' => get_the_ID()], $atts);
        $post_id = (int) $atts['post_id'];
        if (!$post_id) return '<!-- SB: no post ID -->';
        return SectionBuilder\Renderer::render($post_id);
    });

    // Add custom pattern category (WP 5.5+)
    if (function_exists('register_block_pattern_category')) {
        register_block_pattern_category('section-builder', [
            'label' => 'Section Builder',
        ]);
    }
});

add_action('plugins_loaded', function () {
    SectionBuilder\REST_API::instance();
});

// ============================================================
//  Enqueue CSS
// ============================================================
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('section-builder', SB_PLUGIN_URL . 'assets/css/section-builder.css', [], SB_VERSION);
});

// ============================================================
//  CORS
// ============================================================
add_action('rest_api_init', function () {
    $allowed_origins = [
        'http://localhost:3000',
        'https://dashboard.your-domain.com',
    ];

    add_filter('rest_pre_serve_request', function ($served) use ($allowed_origins) {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (in_array($origin, $allowed_origins, true)) {
            header("Access-Control-Allow-Origin: {$origin}");
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce');
            header('Access-Control-Allow-Credentials: true');
        }
        return $served;
    });
}, 1);

// ============================================================
//  Page Template (วิธี C — ไม่ผ่าน Elementor)
// ============================================================
add_filter('theme_page_templates', function ($templates) {
    $templates['sb-page-builder.php'] = 'Section Builder — Full Page';
    return $templates;
});
add_filter('template_include', function ($template) {
    if (get_page_template_slug() === 'sb-page-builder.php') {
        $custom = SB_PLUGIN_DIR . 'templates/page-builder.php';
        if (file_exists($custom)) return $custom;
    }
    return $template;
});
