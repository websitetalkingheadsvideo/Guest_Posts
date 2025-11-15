<?php
/**
 * Plugin Name: Guest Posts
 * Plugin URI: https://talkingheads.com/guest-posts
 * Description: Automatically cross-post blog articles across WordPress sites with keyword filtering
 * Version: 0.3.2
 * Author: Talking Heads
 * Author URI: https://talkingheads.com/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: guest-posts
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
if (!defined('GUEST_POSTS_VERSION')) {
    define('GUEST_POSTS_VERSION', '0.3.2');
}
if (!defined('GUEST_POSTS_PLUGIN_DIR')) {
    define('GUEST_POSTS_PLUGIN_DIR', plugin_dir_path(__FILE__));
}
if (!defined('GUEST_POSTS_PLUGIN_URL')) {
    define('GUEST_POSTS_PLUGIN_URL', plugin_dir_url(__FILE__));
}
if (!defined('GUEST_POSTS_PLUGIN_FILE')) {
    define('GUEST_POSTS_PLUGIN_FILE', __FILE__);
}

// Autoloader
if (function_exists('spl_autoload_register')) {
    spl_autoload_register(function (string $class): void {
        $prefix = 'GuestPosts\\';
        
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }
        
        $relative_class = substr($class, $len);
        
        // Get plugin directory (use constant if available, otherwise calculate)
        $plugin_dir = defined('GUEST_POSTS_PLUGIN_DIR') 
            ? GUEST_POSTS_PLUGIN_DIR 
            : dirname(__FILE__) . DIRECTORY_SEPARATOR;
        
        // Handle Admin namespace
        if (strpos($relative_class, 'Admin\\') === 0) {
            $relative_class = substr($relative_class, 6); // Remove 'Admin\'
            $base_dir = $plugin_dir . 'admin' . DIRECTORY_SEPARATOR;
        } else {
            $base_dir = $plugin_dir . 'includes' . DIRECTORY_SEPARATOR;
        }
        
        // Convert class name to file name
        // Admin_Settings -> admin-settings
        $file_name = strtolower(str_replace('_', '-', $relative_class));
        $file = $base_dir . 'class-' . $file_name . '.php';
        
        if (file_exists($file)) {
            require_once $file;
        }
    });
}

// Initialize admin class early
if (function_exists('is_admin') && is_admin()) {
    add_action('plugins_loaded', function (): void {
        try {
            if (class_exists('GuestPosts\Admin\Admin_Settings')) {
                new GuestPosts\Admin\Admin_Settings();
            }
        } catch (Throwable $e) {
            // Log error but don't break site
            if (defined('WP_DEBUG') && WP_DEBUG && function_exists('error_log')) {
                error_log('Guest Posts Admin Init Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
            }
        }
    }, 5); // Priority 5 to load early
}

// Initialize plugin
function guest_posts_init(): void {
    if (!function_exists('load_plugin_textdomain')) {
        return;
    }
    
    // Load text domain for translations
    load_plugin_textdomain('guest-posts', false, dirname(plugin_basename(__FILE__)) . '/languages');
    
    try {
        // Always initialize core classes
        if (class_exists('GuestPosts\Network_Manager')) {
            new GuestPosts\Network_Manager();
        }
        if (class_exists('GuestPosts\Post_Receiver')) {
            new GuestPosts\Post_Receiver();
        }
    } catch (Throwable $e) {
        // Log error but don't break site
        if (defined('WP_DEBUG') && WP_DEBUG && function_exists('error_log')) {
            error_log('Guest Posts Plugin Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
        }
    }
}

if (function_exists('add_action')) {
    add_action('plugins_loaded', 'guest_posts_init');
}

// Activation hook
if (function_exists('register_activation_hook')) {
    register_activation_hook(__FILE__, 'guest_posts_activate');
}

function guest_posts_activate(): void {
    // Use dirname(__FILE__) directly - more reliable than plugin_dir_path during activation
    $plugin_dir = dirname(__FILE__) . DIRECTORY_SEPARATOR;
    
    // Load Settings Manager class directly
    $settings_file = $plugin_dir . 'includes' . DIRECTORY_SEPARATOR . 'class-settings-manager.php';
    
    if (!file_exists($settings_file)) {
        // Try with forward slashes (Windows/Unix compatibility)
        $settings_file = dirname(__FILE__) . '/includes/class-settings-manager.php';
        if (!file_exists($settings_file)) {
            // Last attempt - use WordPress function if available
            if (function_exists('plugin_dir_path')) {
                $settings_file = plugin_dir_path(__FILE__) . 'includes/class-settings-manager.php';
            }
            
            if (!file_exists($settings_file)) {
                // Don't prevent activation - just return silently
                return;
            }
        }
    }
    
    require_once $settings_file;
    
    // Generate API key for this site if it doesn't exist
    if (class_exists('GuestPosts\Settings_Manager')) {
        try {
            $settings_manager = new GuestPosts\Settings_Manager();
            $api_key = $settings_manager->get_api_key();
            if (empty($api_key)) {
                $settings_manager->generate_api_key();
            }
        } catch (Throwable $e) {
            // Silently fail - don't prevent activation
        }
    }
}

/**
 * Clean up old/incomplete plugin installation
 * Removes files that shouldn't be there from previous failed installations
 */
function guest_posts_cleanup_old_installation(): void {
    $plugin_dir = defined('GUEST_POSTS_PLUGIN_DIR') 
        ? GUEST_POSTS_PLUGIN_DIR 
        : dirname(__FILE__) . DIRECTORY_SEPARATOR;
    
    // List of files that should exist in a proper installation
    $required_files = [
        'guest-posts.php',
        'includes/class-settings-manager.php',
        'includes/class-network-manager.php',
        'includes/class-post-receiver.php',
        'admin/class-admin-settings.php',
    ];
    
    // Check if this is an incomplete installation (missing required files)
    $is_incomplete = false;
    foreach ($required_files as $file) {
        if (!file_exists($plugin_dir . $file)) {
            $is_incomplete = true;
            break;
        }
    }
    
    // If incomplete and we have the main file, try to fix it
    if ($is_incomplete && file_exists($plugin_dir . 'guest-posts.php')) {
        // This is a partial installation - WordPress should handle overwriting
        // But we can at least ensure required directories exist
        $required_dirs = ['includes', 'admin', 'assets', 'languages'];
        foreach ($required_dirs as $dir) {
            $dir_path = $plugin_dir . $dir;
            if (!is_dir($dir_path)) {
                wp_mkdir_p($dir_path);
            }
        }
    }
}

// Deactivation hook
if (function_exists('register_deactivation_hook')) {
    register_deactivation_hook(__FILE__, function (): void {
        // Clean up scheduled events if any
    });
}

