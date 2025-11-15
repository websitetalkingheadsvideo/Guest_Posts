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
        $this->settings_manager = new Settings_Manager();
        $this->api_client = new API_Client();
        
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'handle_form_submission']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('admin_post_guest_posts_generate_api_key', [$this, 'handle_generate_api_key']);
        add_action('wp_ajax_guest_posts_get_keywords', [$this, 'handle_get_keywords_ajax']);
    }
    
    /**
     * Add admin menu
     *
     * @return void
     */
    public function add_admin_menu(): void {
        add_options_page(
            __('Guest Posts Settings', 'guest-posts'),
            __('Guest Posts', 'guest-posts'),
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
        if (!isset($_POST['guest_posts_action']) || !check_admin_referer('guest_posts_settings', 'guest_posts_nonce')) {
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
        if (!current_user_can('manage_options')) {
            return;
        }
        
        require_once GUEST_POSTS_PLUGIN_DIR . 'admin/views/settings-page.php';
    }
}

