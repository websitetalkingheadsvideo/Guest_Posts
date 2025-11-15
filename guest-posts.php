<?php
/**
 * Plugin Name: Guest Posts
 * Plugin URI: https://your-site.com/guest-posts
 * Description: Automatically cross-post blog articles across WordPress sites with keyword filtering
 * Version: 0.1.0
 * Author: Your Name
 * Author URI: https://your-site.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: guest-posts
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

declare(strict_types=1);

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('GUEST_POSTS_VERSION', '0.1.0');
define('GUEST_POSTS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GUEST_POSTS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('GUEST_POSTS_PLUGIN_FILE', __FILE__);

// Autoloader
spl_autoload_register(function (string $class): void {
    $prefix = 'GuestPosts\\';
    
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    $relative_class = substr($class, $len);
    
    // Handle Admin namespace
    if (strpos($relative_class, 'Admin\\') === 0) {
        $relative_class = substr($relative_class, 6); // Remove 'Admin\'
        $base_dir = GUEST_POSTS_PLUGIN_DIR . 'admin/';
    } else {
        $base_dir = GUEST_POSTS_PLUGIN_DIR . 'includes/';
    }
    
    $file = $base_dir . 'class-' . str_replace('_', '-', strtolower(str_replace('\\', '-', $relative_class))) . '.php';
    
    if (file_exists($file)) {
        require $file;
    }
});

// Initialize plugin
function guest_posts_init(): void {
    // Load text domain for translations
    load_plugin_textdomain('guest-posts', false, dirname(plugin_basename(__FILE__)) . '/languages');
    
    // Initialize core classes
    if (is_admin()) {
        new GuestPosts\Admin\Admin_Settings();
    }
    
    new GuestPosts\Network_Manager();
    new GuestPosts\Post_Receiver();
}
add_action('plugins_loaded', 'guest_posts_init');

// Activation hook
register_activation_hook(__FILE__, function (): void {
    // Generate API key for this site if it doesn't exist
    $settings_manager = new GuestPosts\Settings_Manager();
    $api_key = $settings_manager->get_api_key();
    if (empty($api_key)) {
        $settings_manager->generate_api_key();
    }
});

// Deactivation hook
register_deactivation_hook(__FILE__, function (): void {
    // Clean up scheduled events if any
});

