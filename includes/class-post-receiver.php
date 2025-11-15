<?php
/**
 * Post Receiver
 *
 * Handles receiving posts via REST API
 *
 * @package GuestPosts
 */

declare(strict_types=1);

namespace GuestPosts;

if (!defined('ABSPATH')) {
    exit;
}

class Post_Receiver {
    
    private Settings_Manager $settings_manager;
    private Keyword_Filter $keyword_filter;
    
    public function __construct() {
        $this->settings_manager = new Settings_Manager();
        $this->keyword_filter = new Keyword_Filter();
        
        // Register REST API endpoint
        add_action('rest_api_init', [$this, 'register_routes']);
        
        // Add canonical links to cross-posts
        add_action('wp_head', [$this, 'add_canonical_to_head'], 1);
    }
    
    /**
     * Register REST API routes
     *
     * @return void
     */
    public function register_routes(): void {
        register_rest_route('guest-posts/v1', '/receive', [
            'methods' => 'POST',
            'callback' => [$this, 'receive_post'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }
    
    /**
     * Check API key permission
     *
     * @param \WP_REST_Request $request Request object
     * @return bool|\WP_Error Permission result
     */
    public function check_permission(\WP_REST_Request $request) {
        $api_key = $request->get_header('X-Guest-Posts-API-Key');
        
        if (empty($api_key)) {
            return new \WP_Error(
                'missing_api_key',
                __('API key is required', 'guest-posts'),
                ['status' => 401]
            );
        }
        
        $stored_key = $this->settings_manager->get_api_key();
        
        if (empty($stored_key) || $api_key !== $stored_key) {
            return new \WP_Error(
                'invalid_api_key',
                __('Invalid API key', 'guest-posts'),
                ['status' => 403]
            );
        }
        
        return true;
    }
    
    /**
     * Receive post from remote site
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response|\WP_Error Response
     */
    public function receive_post(\WP_REST_Request $request) {
        // Handle test requests
        $data = $request->get_json_params();
        if (isset($data['test']) && $data['test'] === true) {
            return new \WP_Error(
                'test_request',
                __('Test request received', 'guest-posts'),
                ['status' => 400]
            );
        }
        
        // Get site ID from request (to identify which site's keywords to use)
        $source_site_url = isset($data['site_url']) ? esc_url_raw($data['site_url']) : '';
        
        // Find matching site in network to get keywords
        $network_sites = $this->settings_manager->get_network_sites();
        $matching_site = null;
        
        foreach ($network_sites as $site) {
            if (trailingslashit($site['url']) === trailingslashit($source_site_url)) {
                $matching_site = $site;
                break;
            }
        }
        
        // If no matching site found, use first site's keywords (fallback)
        if (!$matching_site && !empty($network_sites)) {
            $matching_site = $network_sites[0];
        }
        
        // Get keywords for this site
        $keywords = [];
        if ($matching_site) {
            $keywords = $this->settings_manager->get_keywords($matching_site['id']);
        }
        
        // Prepare post data for keyword matching
        $post_data = [
            'title' => isset($data['title']) ? sanitize_text_field($data['title']) : '',
            'content' => isset($data['content']) ? wp_kses_post($data['content']) : '',
            'tags' => isset($data['tags']) && is_array($data['tags']) ? $data['tags'] : [],
            'categories' => isset($data['categories']) && is_array($data['categories']) ? $data['categories'] : [],
        ];
        
        // Check if post matches keywords (OR logic)
        if (!empty($keywords) && !$this->keyword_filter->matches_keywords($keywords, $post_data)) {
            // No match, discard silently
            return new \WP_REST_Response([
                'success' => true,
                'message' => 'Post received but discarded (no keyword match)',
                'matched' => false,
            ], 200);
        }
        
        // Check if this is an update
        $is_update = isset($data['update']) && $data['update'] === true;
        $original_post_id = isset($data['original_post_id']) ? (int) $data['original_post_id'] : 0;
        
        if ($is_update && $original_post_id > 0) {
            // Find existing cross-post
            $existing_post = $this->find_existing_cross_post($original_post_id, $source_site_url);
            
            if ($existing_post) {
                // Update existing post
                $post_id = $this->update_cross_post($existing_post->ID, $data);
            } else {
                // Create new post if not found
                $post_id = $this->create_cross_post($data);
            }
        } else {
            // Create new post
            $post_id = $this->create_cross_post($data);
        }
        
        if (is_wp_error($post_id)) {
            return $post_id;
        }
        
        return new \WP_REST_Response([
            'success' => true,
            'post_id' => $post_id,
            'matched' => true,
        ], 200);
    }
    
    /**
     * Create cross-post
     *
     * @param array<string, mixed> $data Post data
     * @return int|\WP_Error Post ID or error
     */
    private function create_cross_post(array $data) {
        $post_data = [
            'post_title' => isset($data['title']) ? sanitize_text_field($data['title']) : '',
            'post_content' => $this->format_cross_post_content($data),
            'post_excerpt' => isset($data['excerpt']) ? sanitize_textarea_field($data['excerpt']) : '',
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_author' => $this->get_default_author_id(),
        ];
        
        $post_id = wp_insert_post($post_data, true);
        
        if (is_wp_error($post_id)) {
            return $post_id;
        }
        
        // Store original post information
        if (isset($data['post_id'])) {
            update_post_meta($post_id, '_guest_post_original_id', (int) $data['post_id']);
        }
        if (isset($data['site_url'])) {
            update_post_meta($post_id, '_guest_post_original_site', esc_url_raw($data['site_url']));
        }
        if (isset($data['permalink'])) {
            update_post_meta($post_id, '_guest_post_original_url', esc_url_raw($data['permalink']));
        }
        
        // Set featured image if provided
        if (!empty($data['featured_image_url'])) {
            $this->set_featured_image($post_id, $data['featured_image_url']);
        }
        
        // Add canonical link
        $this->add_canonical_link($post_id, $data['permalink'] ?? '');
        
        return $post_id;
    }
    
    /**
     * Update existing cross-post
     *
     * @param int $post_id Post ID
     * @param array<string, mixed> $data Post data
     * @return int|\WP_Error Post ID or error
     */
    private function update_cross_post(int $post_id, array $data) {
        $post_data = [
            'ID' => $post_id,
            'post_title' => isset($data['title']) ? sanitize_text_field($data['title']) : '',
            'post_content' => $this->format_cross_post_content($data),
            'post_excerpt' => isset($data['excerpt']) ? sanitize_textarea_field($data['excerpt']) : '',
        ];
        
        $result = wp_update_post($post_data, true);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        // Update canonical link if permalink changed
        if (isset($data['permalink'])) {
            $this->add_canonical_link($post_id, $data['permalink']);
        }
        
        return $post_id;
    }
    
    /**
     * Find existing cross-post by original post ID and site URL
     *
     * @param int $original_post_id Original post ID
     * @param string $source_site_url Source site URL
     * @return \WP_Post|null Post object or null
     */
    private function find_existing_cross_post(int $original_post_id, string $source_site_url): ?\WP_Post {
        $posts = get_posts([
            'post_type' => 'post',
            'post_status' => 'any',
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => '_guest_post_original_id',
                    'value' => $original_post_id,
                    'compare' => '=',
                ],
                [
                    'key' => '_guest_post_original_site',
                    'value' => $source_site_url,
                    'compare' => '=',
                ],
            ],
            'posts_per_page' => 1,
        ]);
        
        return !empty($posts) ? $posts[0] : null;
    }
    
    /**
     * Format cross-post content with excerpt and link
     *
     * @param array<string, mixed> $data Post data
     * @return string Formatted content
     */
    private function format_cross_post_content(array $data): string {
        $excerpt = isset($data['excerpt']) ? wp_kses_post($data['excerpt']) : '';
        $permalink = isset($data['permalink']) ? esc_url_raw($data['permalink']) : '';
        $original_site = isset($data['site_url']) ? esc_url_raw($data['site_url']) : '';
        
        $content = '<p>' . $excerpt . '</p>';
        
        if (!empty($permalink)) {
            $content .= '<p><a href="' . esc_url($permalink) . '" rel="canonical">' . 
                       esc_html__('Read more', 'guest-posts') . '</a></p>';
        }
        
        return $content;
    }
    
    /**
     * Add canonical link to post
     *
     * @param int $post_id Post ID
     * @param string $canonical_url Canonical URL
     * @return void
     */
    private function add_canonical_link(int $post_id, string $canonical_url): void {
        if (empty($canonical_url)) {
            return;
        }
        
        update_post_meta($post_id, '_guest_post_canonical', esc_url_raw($canonical_url));
    }
    
    /**
     * Add canonical link to head for cross-posts
     *
     * @return void
     */
    public function add_canonical_to_head(): void {
        if (!is_single()) {
            return;
        }
        
        $post_id = get_the_ID();
        if (!$post_id) {
            return;
        }
        
        $canonical_url = get_post_meta($post_id, '_guest_post_canonical', true);
        if (!empty($canonical_url)) {
            echo '<link rel="canonical" href="' . esc_url($canonical_url) . '" />' . "\n";
        }
    }
    
    /**
     * Set featured image from URL
     *
     * @param int $post_id Post ID
     * @param string $image_url Image URL
     * @return void
     */
    private function set_featured_image(int $post_id, string $image_url): void {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        
        $attachment_id = media_sideload_image($image_url, $post_id, null, 'id');
        
        if (!is_wp_error($attachment_id)) {
            set_post_thumbnail($post_id, $attachment_id);
        }
    }
    
    /**
     * Get default author ID for cross-posts
     *
     * @return int Author ID
     */
    private function get_default_author_id(): int {
        // Use first admin user, or current user if admin
        $admins = get_users(['role' => 'administrator', 'number' => 1]);
        if (!empty($admins)) {
            return $admins[0]->ID;
        }
        
        return get_current_user_id() ?: 1;
    }
}

