<?php
/**
 * Admin Settings
 *
 * Handles admin settings page
 *
 * @package GuestPosts
 */

declare(strict_types=1);

namespace GuestPosts\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use GuestPosts\Settings_Manager;
use GuestPosts\API_Client;

class Admin_Settings {
    
    private Settings_Manager $settings_manager;
    private API_Client $api_client;
    
    public function __construct() {
        try {
            $this->settings_manager = new Settings_Manager();
            $this->api_client = new API_Client();
            
            add_action('admin_menu', [$this, 'add_admin_menu']);
            add_action('admin_init', [$this, 'handle_form_submission']);
            add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
            add_action('admin_post_guest_posts_generate_api_key', [$this, 'handle_generate_api_key']);
            add_action('wp_ajax_guest_posts_get_keywords', [$this, 'handle_get_keywords_ajax']);
        } catch (Throwable $e) {
            // Log error but don't break site
            if (defined('WP_DEBUG') && WP_DEBUG && function_exists('error_log')) {
                error_log('Guest Posts Admin Settings Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
            }
        }
    }
    
    /**
     * Add admin menu
     *
     * @return void
     */
    public function add_admin_menu(): void {
        // Ensure user has permission
        if (!current_user_can('manage_options')) {
            return;
        }
        
        add_options_page(
            'Guest Posts Settings',
            'Guest Posts',
            'manage_options',
            'guest-posts',
            [$this, 'render_settings_page']
        );
    }
    
    /**
     * Handle form submission
     *
     * @return void
     */
    public function handle_form_submission(): void {
        if (!isset($_POST['guest_posts_action'])) {
            return;
        }
        
        // Verify nonce
        if (!isset($_POST['guest_posts_nonce']) || !wp_verify_nonce($_POST['guest_posts_nonce'], 'guest_posts_settings')) {
            add_settings_error('guest_posts', 'nonce_error', __('Security check failed. Please try again.', 'guest-posts'));
            return;
        }
        
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $action = sanitize_text_field($_POST['guest_posts_action']);
        
        switch ($action) {
            case 'add_site':
                $this->handle_add_site();
                break;
            case 'update_site':
                $this->handle_update_site();
                break;
            case 'delete_site':
                $this->handle_delete_site();
                break;
            case 'save_keywords':
                $this->handle_save_keywords();
                break;
            case 'save_exclusions':
                $this->handle_save_exclusions();
                break;
            case 'test_connection':
                $this->handle_test_connection();
                break;
            case 'bulk_import':
                $this->handle_bulk_import();
                break;
        }
    }
    
    /**
     * Handle add site
     *
     * @return void
     */
    private function handle_add_site(): void {
        $url = isset($_POST['site_url']) ? esc_url_raw($_POST['site_url']) : '';
        $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
        $name = isset($_POST['site_name']) ? sanitize_text_field($_POST['site_name']) : '';
        
        if (empty($url) || empty($api_key)) {
            add_settings_error('guest_posts', 'missing_fields', __('URL and API key are required', 'guest-posts'));
            return;
        }
        
        $site_id = $this->settings_manager->add_network_site($url, $api_key, $name);
        
        if ($site_id) {
            add_settings_error('guest_posts', 'site_added', __('Site added successfully', 'guest-posts'), 'success');
        } else {
            add_settings_error('guest_posts', 'site_add_error', __('Failed to add site', 'guest-posts'));
        }
    }
    
    /**
     * Handle update site
     *
     * @return void
     */
    private function handle_update_site(): void {
        $site_id = isset($_POST['site_id']) ? (int) $_POST['site_id'] : 0;
        $url = isset($_POST['site_url']) ? esc_url_raw($_POST['site_url']) : '';
        $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
        $name = isset($_POST['site_name']) ? sanitize_text_field($_POST['site_name']) : '';
        
        if ($site_id <= 0) {
            return;
        }
        
        $data = [];
        if (!empty($url)) {
            $data['url'] = $url;
        }
        if (!empty($api_key)) {
            $data['api_key'] = $api_key;
        }
        if (!empty($name)) {
            $data['name'] = $name;
        }
        
        $result = $this->settings_manager->update_network_site($site_id, $data);
        
        if ($result) {
            add_settings_error('guest_posts', 'site_updated', __('Site updated successfully', 'guest-posts'), 'success');
        } else {
            add_settings_error('guest_posts', 'site_update_error', __('Failed to update site', 'guest-posts'));
        }
    }
    
    /**
     * Handle delete site
     *
     * @return void
     */
    private function handle_delete_site(): void {
        $site_id = isset($_POST['site_id']) ? (int) $_POST['site_id'] : 0;
        
        if ($site_id <= 0) {
            return;
        }
        
        $result = $this->settings_manager->delete_network_site($site_id);
        
        if ($result) {
            add_settings_error('guest_posts', 'site_deleted', __('Site deleted successfully', 'guest-posts'), 'success');
        } else {
            add_settings_error('guest_posts', 'site_delete_error', __('Failed to delete site', 'guest-posts'));
        }
    }
    
    /**
     * Handle save keywords
     *
     * @return void
     */
    private function handle_save_keywords(): void {
        $site_id = isset($_POST['site_id']) ? (int) $_POST['site_id'] : 0;
        $keywords = isset($_POST['keywords']) ? sanitize_textarea_field($_POST['keywords']) : '';
        
        if ($site_id <= 0) {
            return;
        }
        
        $result = $this->settings_manager->save_keywords($site_id, $keywords);
        
        if ($result) {
            add_settings_error('guest_posts', 'keywords_saved', __('Keywords saved successfully', 'guest-posts'), 'success');
        } else {
            add_settings_error('guest_posts', 'keywords_save_error', __('Failed to save keywords', 'guest-posts'));
        }
    }
    
    /**
     * Handle save exclusions
     *
     * @return void
     */
    private function handle_save_exclusions(): void {
        $categories = isset($_POST['exclude_categories']) && is_array($_POST['exclude_categories']) 
            ? array_map('intval', $_POST['exclude_categories']) 
            : [];
        $tags = isset($_POST['exclude_tags']) && is_array($_POST['exclude_tags']) 
            ? array_map('intval', $_POST['exclude_tags']) 
            : [];
        
        $result = $this->settings_manager->save_exclusions([
            'categories' => $categories,
            'tags' => $tags,
        ]);
        
        if ($result) {
            add_settings_error('guest_posts', 'exclusions_saved', __('Exclusions saved successfully', 'guest-posts'), 'success');
        } else {
            add_settings_error('guest_posts', 'exclusions_save_error', __('Failed to save exclusions', 'guest-posts'));
        }
    }
    
    /**
     * Handle bulk import
     *
     * @return void
     */
    private function handle_bulk_import(): void {
        // Debug: Log that we got here
        if (defined('WP_DEBUG') && WP_DEBUG && function_exists('error_log')) {
            error_log('Guest Posts: Bulk import handler called. POST data: ' . print_r($_POST, true));
        }
        
        $config_strings = isset($_POST['config_strings']) ? sanitize_textarea_field($_POST['config_strings']) : '';
        
        if (empty($config_strings)) {
            add_settings_error('guest_posts', 'bulk_import_error', __('No configuration strings provided', 'guest-posts'));
            if (defined('WP_DEBUG') && WP_DEBUG && function_exists('error_log')) {
                error_log('Guest Posts: Bulk import - empty config strings');
            }
            return;
        }
        
        $lines = array_filter(array_map('trim', explode("\n", $config_strings)));
        $success_count = 0;
        $error_count = 0;
        
        foreach ($lines as $line) {
            if (empty($line)) {
                continue;
            }
            
            try {
                // Decode base64 and parse JSON
                $decoded = base64_decode($line, true);
                if ($decoded === false) {
                    $error_count++;
                    continue;
                }
                
                $config = json_decode($decoded, true);
                if (!is_array($config) || empty($config['url']) || empty($config['api_key'])) {
                    $error_count++;
                    continue;
                }
                
                // Add site
                $url = esc_url_raw($config['url']);
                $api_key = sanitize_text_field($config['api_key']);
                $name = isset($config['site_name']) ? sanitize_text_field($config['site_name']) : '';
                
                // Check if site already exists
                $existing_sites = $this->settings_manager->get_network_sites();
                $exists = false;
                foreach ($existing_sites as $site) {
                    if (trailingslashit($site['url']) === trailingslashit($url)) {
                        $exists = true;
                        break;
                    }
                }
                
                if (!$exists) {
                    $site_id = $this->settings_manager->add_network_site($url, $api_key, $name);
                    if ($site_id) {
                        $success_count++;
                    } else {
                        $error_count++;
                    }
                } else {
                    // Site already exists - skip but don't count as error
                }
            } catch (Exception $e) {
                $error_count++;
            }
        }
        
        if ($success_count > 0) {
            add_settings_error('guest_posts', 'bulk_import_success', sprintf(__('%d site(s) added successfully', 'guest-posts'), $success_count), 'success');
        }
        if ($error_count > 0) {
            add_settings_error('guest_posts', 'bulk_import_error', sprintf(__('%d configuration string(s) failed to import', 'guest-posts'), $error_count));
        }
        if ($success_count === 0 && $error_count === 0) {
            add_settings_error('guest_posts', 'bulk_import_error', __('No valid configuration strings found', 'guest-posts'));
        }
    }
    
    /**
     * Handle test connection
     *
     * @return void
     */
    private function handle_test_connection(): void {
        $site_id = isset($_POST['site_id']) ? (int) $_POST['site_id'] : 0;
        
        if ($site_id <= 0) {
            return;
        }
        
        $site = $this->settings_manager->get_network_site($site_id);
        if (!$site) {
            add_settings_error('guest_posts', 'test_error', __('Site not found', 'guest-posts'));
            return;
        }
        
        $result = $this->api_client->test_connection($site['url'], $site['api_key']);
        
        if ($result['success']) {
            add_settings_error('guest_posts', 'test_success', __('Connection successful', 'guest-posts'), 'success');
        } else {
            add_settings_error('guest_posts', 'test_error', __('Connection failed: ' . ($result['error'] ?? 'Unknown error'), 'guest-posts'));
        }
    }
    
    /**
     * Enqueue admin scripts and styles
     *
     * @param string $hook Current admin page hook
     * @return void
     */
    public function enqueue_scripts(string $hook): void {
        if ($hook !== 'settings_page_guest-posts') {
            return;
        }
        
        wp_enqueue_style(
            'guest-posts-admin',
            GUEST_POSTS_PLUGIN_URL . 'assets/css/admin.css',
            [],
            GUEST_POSTS_VERSION
        );
        
        wp_enqueue_script(
            'guest-posts-admin',
            GUEST_POSTS_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            GUEST_POSTS_VERSION,
            true
        );
        
        wp_localize_script('guest-posts-admin', 'guestPosts', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('guest_posts_manual_send'),
            'siteUrl' => home_url(),
            'siteName' => get_bloginfo('name'),
            'strings' => [
                'pasteConfig' => __('Please paste configuration string(s).', 'guest-posts'),
                'noValidConfigs' => __('No valid configuration strings found.', 'guest-posts'),
                'willAdd' => __('This will add ', 'guest-posts'),
                'sitesContinue' => __(' site(s). Continue?', 'guest-posts'),
                'submitting' => __('Submitting...', 'guest-posts'),
            ],
        ]);
    }
    
    /**
     * Handle generate API key
     *
     * @return void
     */
    public function handle_generate_api_key(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'guest-posts'));
        }
        
        check_admin_referer('guest_posts_generate_key');
        
        $this->settings_manager->generate_api_key();
        
        wp_redirect(add_query_arg(['settings-updated' => '1'], admin_url('options-general.php?page=guest-posts')));
        exit;
    }
    
    /**
     * Handle get keywords AJAX
     *
     * @return void
     */
    public function handle_get_keywords_ajax(): void {
        check_ajax_referer('guest_posts_get_keywords', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'guest-posts')]);
        }
        
        $site_id = isset($_POST['site_id']) ? (int) $_POST['site_id'] : 0;
        if ($site_id <= 0) {
            wp_send_json_error(['message' => __('Invalid site ID', 'guest-posts')]);
        }
        
        $keywords = $this->settings_manager->get_keywords($site_id);
        
        wp_send_json_success(['keywords' => $keywords]);
    }
    
    /**
     * Render settings page
     *
     * @return void
     */
    public function render_settings_page(): void {
        // Force error display
        @ini_set('display_errors', '1');
        error_reporting(E_ALL);
        
        // Log that we're entering this function
        error_log('Guest Posts: render_settings_page() called');
        
        if (!current_user_can('manage_options')) {
            error_log('Guest Posts: User does not have manage_options capability');
            return;
        }
        
        try {
            // Ensure constant is defined
            if (!defined('GUEST_POSTS_PLUGIN_DIR')) {
                error_log('Guest Posts: GUEST_POSTS_PLUGIN_DIR constant not defined');
                wp_die(
                    '<h1>Guest Posts Error</h1>' .
                    '<p>Plugin directory constant not defined. Please deactivate and reactivate the plugin.</p>' .
                    '<p><strong>Error log:</strong> <code>' . esc_html(ABSPATH . 'wp-content/debug.log') . '</code></p>',
                    'Guest Posts Error',
                    ['response' => 500]
                );
                return;
            }
            
            error_log('Guest Posts: GUEST_POSTS_PLUGIN_DIR = ' . GUEST_POSTS_PLUGIN_DIR);
            
            $view_file = GUEST_POSTS_PLUGIN_DIR . 'admin/views/settings-page.php';
            error_log('Guest Posts: Looking for view file: ' . $view_file);
            error_log('Guest Posts: File exists: ' . (file_exists($view_file) ? 'YES' : 'NO'));
            
            if (!file_exists($view_file)) {
                error_log('Guest Posts: View file not found');
                wp_die(
                    '<h1>Guest Posts Error</h1>' .
                    '<p>Settings page view file not found. Please reinstall the plugin.</p>' .
                    '<p><strong>Error log:</strong> <code>' . esc_html(ABSPATH . 'wp-content/debug.log') . '</code></p>',
                    'Guest Posts Error',
                    ['response' => 500]
                );
                return;
            }
            
            error_log('Guest Posts: About to require view file');
            
            // Use output buffering to catch any fatal errors
            ob_start();
            require_once $view_file;
            $output = ob_get_clean();
            
            if ($output === false) {
                error_log('Guest Posts: Output buffering failed');
                wp_die(
                    '<h1>Guest Posts Error</h1>' .
                    '<p>Failed to render settings page. Check the error log for details.</p>' .
                    '<p><strong>Error log:</strong> <code>' . esc_html(ABSPATH . 'wp-content/debug.log') . '</code></p>',
                    'Guest Posts Error',
                    ['response' => 500]
                );
                return;
            }
            
            error_log('Guest Posts: View file loaded successfully, output length: ' . strlen($output));
            echo $output;
            
        } catch (Throwable $e) {
            // Catch any exceptions or errors - show them on screen
            ob_end_clean();
            
            $trace = $e->getTraceAsString();
            // Limit trace to first 10 lines to avoid overwhelming output
            $trace_lines = explode("\n", $trace);
            $trace = implode("\n", array_slice($trace_lines, 0, 10));
            
            wp_die(
                '<h1>Guest Posts Error</h1>' .
                '<p><strong>ERROR MESSAGE:</strong></p>' .
                '<p style="background: #ffebee; padding: 15px; border-left: 4px solid #c62828; font-family: monospace; font-size: 14px;">' . esc_html($e->getMessage()) . '</p>' .
                '<p><strong>FILE:</strong> ' . esc_html($e->getFile()) . '</p>' .
                '<p><strong>LINE:</strong> ' . esc_html($e->getLine()) . '</p>' .
                '<p><strong>TRACE (first 10 lines):</strong></p>' .
                '<pre style="background: #f5f5f5; padding: 15px; overflow: auto; max-height: 300px;">' . esc_html($trace) . '</pre>' .
                '<p><em>Copy the error message above and send it to support.</em></p>',
                'Guest Posts Error',
                ['response' => 500]
            );
        }
    }
}

