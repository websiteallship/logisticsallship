# 05 - WP CLEANUP & PERFORMANCE

> Loại bỏ bloat mặc định của WordPress, tối ưu tải trang.

---

## 1. inc/wp-cleanup.php

```php
<?php
/**
 * WordPress cleanup and performance optimizations
 *
 * @package Allship_Logistics
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Remove WordPress Head Bloat ──
remove_action( 'wp_head', 'wp_generator' );                    // WP version
remove_action( 'wp_head', 'wlwmanifest_link' );                // Windows Live Writer
remove_action( 'wp_head', 'rsd_link' );                        // Really Simple Discovery
remove_action( 'wp_head', 'wp_shortlink_wp_head' );            // Shortlink
remove_action( 'wp_head', 'rest_output_link_wp_head' );        // REST API link
remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );   // oEmbed
remove_action( 'wp_head', 'print_emoji_detection_script', 7 ); // Emoji JS
remove_action( 'wp_print_styles', 'print_emoji_styles' );      // Emoji CSS
remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
remove_action( 'admin_print_styles', 'print_emoji_styles' );

// ── Disable XML-RPC ──
add_filter( 'xmlrpc_enabled', '__return_false' );

// ── Dequeue Block Library CSS (Gutenberg) — Not using blocks ──
function allship_dequeue_block_styles() {
    wp_dequeue_style( 'wp-block-library' );
    wp_dequeue_style( 'wp-block-library-theme' );
    wp_dequeue_style( 'wc-blocks-style' );           // WooCommerce blocks (if present)
    wp_dequeue_style( 'global-styles' );              // FSE global styles
    wp_dequeue_style( 'classic-theme-styles' );       // Classic theme compat
}
add_action( 'wp_enqueue_scripts', 'allship_dequeue_block_styles', 100 );

// ── Dequeue jQuery Migrate ──
function allship_dequeue_jquery_migrate( $scripts ) {
    if ( ! is_admin() && isset( $scripts->registered['jquery'] ) ) {
        $scripts->registered['jquery']->deps = array_diff(
            $scripts->registered['jquery']->deps,
            array( 'jquery-migrate' )
        );
    }
}
add_action( 'wp_default_scripts', 'allship_dequeue_jquery_migrate' );

// ── Disable Gutenberg on Page Templates ──
function allship_disable_gutenberg( $use_block_editor, $post ) {
    if ( $post->post_type === 'page' ) {
        return false; // Force Classic Editor for pages
    }
    return $use_block_editor;
}
add_filter( 'use_block_editor_for_post', 'allship_disable_gutenberg', 10, 2 );

// ── Disable Self-Pingbacks ──
function allship_disable_self_pingback( &$links ) {
    $home = get_option( 'home' );
    foreach ( $links as $l => $link ) {
        if ( 0 === strpos( $link, $home ) ) {
            unset( $links[$l] );
        }
    }
}
add_action( 'pre_ping', 'allship_disable_self_pingback' );

// ── Remove Query Strings from Static Resources ──
function allship_remove_query_strings( $src ) {
    if ( strpos( $src, '?ver=' ) ) {
        $src = remove_query_arg( 'ver', $src );
    }
    return $src;
}
// Uncomment for production:
// add_filter( 'style_loader_src', 'allship_remove_query_strings', 10, 2 );
// add_filter( 'script_loader_src', 'allship_remove_query_strings', 10, 2 );
```

---

## 2. Performance Targets (from UX_GUIDELINES.md)

| Metric | Target | Notes |
|--------|--------|-------|
| LCP | < 2.5s | Hero image preload, `fetchpriority="high"` |
| CLS | < 0.1 | All `<img>` have `width` + `height` |
| INP | < 200ms | Defer non-critical JS |
| First Byte | < 600ms | WP caching plugin |
| Page Weight | < 2MB | Optimize images |

---

## 3. Image Optimization Rules

### Hero Images (above-the-fold)
```html
<img 
  src="<?php echo esc_url(get_template_directory_uri()); ?>/assets/images/hero.jpg"
  alt="Description"
  width="1200" height="800"
  loading="eager"
  fetchpriority="high"
>
```

### Below-the-fold Images
```html
<img 
  src="<?php echo esc_url(get_template_directory_uri()); ?>/assets/images/feature.jpg"
  alt="Description"
  width="600" height="400"
  loading="lazy"
>
```

### Testimonial Avatars
```html
<img 
  src="<?php echo esc_url(get_template_directory_uri()); ?>/assets/images/testimonial-1.jpg"
  alt="Name"
  class="w-10 h-10 rounded-full object-cover"
  width="40" height="40"
  loading="lazy"
>
```

---

## 4. Preload Critical Assets

Thêm vào `header.php` (trong `<head>`, trước `wp_head()`):

```html
<!-- Preload hero font -->
<link rel="preload" href="<?php echo esc_url(get_template_directory_uri()); ?>/assets/fonts/manrope-variable.woff2" as="font" type="font/woff2" crossorigin>

<!-- Preconnect CDNs -->
<link rel="preconnect" href="https://unpkg.com" crossorigin>
<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
<link rel="preconnect" href="https://fastly.jsdelivr.net" crossorigin>
```

---

## 5. Recommended Plugins

| Plugin | Purpose | Priority |
|--------|---------|----------|
| **ACF Pro** | Theme Options fields | ✅ Đã cài |
| **FluentForm + Pro** | Contact form, Quote modal | ✅ Đã cài |
| **Classic Editor** | Force classic editor | ✅ Đã cài |
| **WP Rocket / LiteSpeed Cache** | Page caching, CSS/JS optimization | 🔴 CẦN CÀI |
| **RankMath / Yoast SEO** | Meta titles, descriptions, schema | 🔴 CẦN CÀI |
| **ShortPixel / Imagify** | Image compression on upload | 🟡 Khuyến nghị |
