<?php
/**
 * Network Manager
 *
 * Handles sending posts to network sites
 *
 * @package GuestPosts
 */

declare(strict_types=1);

namespace GuestPosts;

if (!defined('ABSPATH')) {
    exit;
}

class Network_Manager {
    
    private Settings_Manager $settings_manager;
    private API_Client $api_client;
    private Elementor_Handler $elementor_handler;
    
    public function __construct() {
        $this->settings_manager = new Settings_Manager();
        $this->api_client = new API_Client();
        $this->elementor_handler = new Elementor_Handler();
        
        // Hook into post publishing
        add_action('publish_post', [$this, 'on_post_published'], 10, 2);
        add_action('transition_post_status', [$this, 'on_post_status_transition'], 10, 3);
        
        // Hook into post updates
        add_action('post_updated', [$this, 'on_post_updated'], 10, 3);
        
        // Add meta box for manual send
        add_action('add_meta_boxes', [$this, 'add_meta_box']);
        add_action('wp_ajax_guest_posts_send_manual', [$this, 'handle_manual_send']);
    }
    
    /**
     * Handle post published
     *
     * @param int $post_id Post ID
     * @param \WP_Post $post Post object
     * @return void
     */
    public function on_post_published(int $post_id, \WP_Post $post): void {
        try {
            // Skip revisions and autosaves
            if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
                return;
            }
            
            // Check if already sent
            $already_sent = get_post_meta($post_id, '_guest_posts_sent', true);
            if ($already_sent) {
                return;
            }
            
            $this->send_to_network($post_id);
        } catch (Throwable $e) {
            // Log error but don't break site - use multiple methods to ensure it's logged
            $error_message = 'Guest Posts: Error in on_post_published - ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine();
            
            // Try WordPress debug log
            if (defined('WP_DEBUG') && WP_DEBUG) {
                if (function_exists('error_log')) {
                    error_log($error_message);
                }
                // Also try writing directly to debug.log if it exists
                $debug_log = WP_CONTENT_DIR . '/debug.log';
                if (is_writable(WP_CONTENT_DIR) || (file_exists($debug_log) && is_writable($debug_log))) {
                    @file_put_contents($debug_log, '[' . current_time('mysql') . '] ' . $error_message . PHP_EOL, FILE_APPEND);
                }
            }
        }
    }
    
    /**
     * Handle post status transition
     *
     * @param string $new_status New status
     * @param string $old_status Old status
     * @param \WP_Post $post Post object
     * @return void
     */
    public function on_post_status_transition(string $new_status, string $old_status, \WP_Post $post): void {
        try {
            // Only send when transitioning to publish
            if ($new_status !== 'publish' || $old_status === 'publish') {
                return;
            }
            
            // Skip revisions and autosaves
            if (wp_is_post_revision($post->ID) || wp_is_post_autosave($post->ID)) {
                return;
            }
            
            // Check if already sent
            $already_sent = get_post_meta($post->ID, '_guest_posts_sent', true);
            if ($already_sent) {
                return;
            }
            
            $this->send_to_network($post->ID);
        } catch (Throwable $e) {
            // Log error but don't break site
            if (defined('WP_DEBUG') && WP_DEBUG && function_exists('error_log')) {
                error_log('Guest Posts: Error in on_post_status_transition - ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
            }
        }
    }
    
    /**
     * Handle post updated
     *
     * @param int $post_id Post ID
     * @param \WP_Post $post_after Post object after update
     * @param \WP_Post $post_before Post object before update
     * @return void
     */
    public function on_post_updated(int $post_id, \WP_Post $post_after, \WP_Post $post_before): void {
        try {
            // Only update if post is published
            if ($post_after->post_status !== 'publish') {
                return;
            }
            
            // Skip revisions and autosaves
            if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
                return;
            }
            
            // Only update if content actually changed
            if ($post_after->post_content === $post_before->post_content &&
                $post_after->post_title === $post_before->post_title) {
                return;
            }
            
            $this->update_network_posts($post_id);
        } catch (Throwable $e) {
            // Log error but don't break site
            if (defined('WP_DEBUG') && WP_DEBUG && function_exists('error_log')) {
                error_log('Guest Posts: Error in on_post_updated - ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
            }
        }
    }
    
    /**
     * Send post to all network sites
     *
     * @param int $post_id Post ID
     * @return void
     */
    public function send_to_network(int $post_id): void {
        try {
            $post = get_post($post_id);
            if (!$post) {
                return;
            }
            
            // Check exclusions
            if ($this->is_excluded($post_id)) {
                return;
            }
            
            // Prepare post data
            $post_data = $this->prepare_post_data($post_id);
            if (empty($post_data)) {
                return;
            }
            
            // Get network sites
            $sites = $this->settings_manager->get_network_sites();
            if (empty($sites)) {
                return;
            }
            
            $sent_sites = [];
            
            foreach ($sites as $site) {
                try {
                    if (!isset($site['id'])) {
                        continue;
                    }
                    
                    $site_obj = $this->settings_manager->get_network_site($site['id']);
                    if (!$site_obj || !isset($site_obj['url']) || !isset($site_obj['api_key'])) {
                        continue;
                    }
                    
                    $api_key = $site_obj['api_key'];
                    if (empty($api_key)) {
                        continue;
                    }
                    
                    $response = $this->api_client->send_post($site_obj['url'], $api_key, $post_data);
                    
                    if (isset($response['success']) && $response['success']) {
                        $sent_sites[] = $site['id'];
                    }
                } catch (Throwable $e) {
                    // Log error for this site but continue with others
                    if (defined('WP_DEBUG') && WP_DEBUG && function_exists('error_log')) {
                        error_log('Guest Posts: Error sending to site ' . ($site['id'] ?? 'unknown') . ' - ' . $e->getMessage());
                    }
                    continue;
                }
            }
            
            // Mark as sent
            if (!empty($sent_sites)) {
                update_post_meta($post_id, '_guest_posts_sent', true);
                update_post_meta($post_id, '_guest_posts_sent_sites', $sent_sites);
            }
        } catch (Throwable $e) {
            // Log error but don't break site - use multiple methods to ensure it's logged
            $error_message = 'Guest Posts: Error in send_to_network - ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine();
            
            // Try WordPress debug log
            if (defined('WP_DEBUG') && WP_DEBUG) {
                if (function_exists('error_log')) {
                    error_log($error_message);
                }
                // Also try writing directly to debug.log if it exists or can be created
                $debug_log = WP_CONTENT_DIR . '/debug.log';
                if (is_writable(WP_CONTENT_DIR) || (file_exists($debug_log) && is_writable($debug_log))) {
                    @file_put_contents($debug_log, '[' . current_time('mysql') . '] ' . $error_message . PHP_EOL, FILE_APPEND);
                }
            }
        }
    }
    
    /**
     * Update existing cross-posts
     *
     * @param int $post_id Post ID
     * @return void
     */
    private function update_network_posts(int $post_id): void {
        try {
            $post = get_post($post_id);
            if (!$post) {
                return;
            }
            
            // Get sites this was sent to
            $sent_sites = get_post_meta($post_id, '_guest_posts_sent_sites', true);
            if (empty($sent_sites) || !is_array($sent_sites)) {
                return;
            }
            
            // Prepare post data
            $post_data = $this->prepare_post_data($post_id);
            if (empty($post_data)) {
                return;
            }
            
            $post_data['update'] = true;
            $post_data['original_post_id'] = $post_id;
            
            // Get network sites
            $sites = $this->settings_manager->get_network_sites();
            
            foreach ($sites as $site) {
                try {
                    if (!isset($site['id']) || !in_array($site['id'], $sent_sites, true)) {
                        continue;
                    }
                    
                    $site_obj = $this->settings_manager->get_network_site($site['id']);
                    if (!$site_obj || !isset($site_obj['url']) || !isset($site_obj['api_key'])) {
                        continue;
                    }
                    
                    $api_key = $site_obj['api_key'];
                    if (empty($api_key)) {
                        continue;
                    }
                    
                    $this->api_client->send_post($site_obj['url'], $api_key, $post_data);
                } catch (Throwable $e) {
                    // Log error for this site but continue with others
                    if (defined('WP_DEBUG') && WP_DEBUG && function_exists('error_log')) {
                        error_log('Guest Posts: Error updating site ' . ($site['id'] ?? 'unknown') . ' - ' . $e->getMessage());
                    }
                    continue;
                }
            }
        } catch (Throwable $e) {
            // Log error but don't break site
            if (defined('WP_DEBUG') && WP_DEBUG && function_exists('error_log')) {
                error_log('Guest Posts: Error in update_network_posts - ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
            }
        }
    }
    
    /**
     * Prepare post data for sending
     *
     * @param int $post_id Post ID
     * @return array<string, mixed> Post data
     */
    private function prepare_post_data(int $post_id): array {
        try {
            $post = get_post($post_id);
            if (!$post) {
                return [];
            }
            
            // Get content (Elementor or standard)
            $content = '';
            try {
                $content = $this->elementor_handler->get_post_content($post_id);
            } catch (Throwable $e) {
                // Fallback to standard content if Elementor handler fails
                $content = $post->post_content ?? '';
                if (defined('WP_DEBUG') && WP_DEBUG && function_exists('error_log')) {
                    error_log('Guest Posts: Elementor handler failed, using standard content - ' . $e->getMessage());
                }
            }
            
            // Generate excerpt
            $excerpt = $this->generate_excerpt($content, $post->post_excerpt ?? '');
            
            // Get tags
            $tags = [];
            try {
                $tags = wp_get_post_tags($post_id, ['fields' => 'names']);
                if (!is_array($tags)) {
                    $tags = [];
                }
            } catch (Throwable $e) {
                $tags = [];
            }
            
            // Get categories
            $categories = [];
            try {
                $categories = wp_get_post_categories($post_id, ['fields' => 'names']);
                if (!is_array($categories)) {
                    $categories = [];
                }
            } catch (Throwable $e) {
                $categories = [];
            }
            
            // Get featured image
            $featured_image_url = '';
            try {
                $thumbnail_id = get_post_thumbnail_id($post_id);
                if ($thumbnail_id) {
                    $featured_image_url = wp_get_attachment_image_url($thumbnail_id, 'full');
                    if (!is_string($featured_image_url)) {
                        $featured_image_url = '';
                    }
                }
            } catch (Throwable $e) {
                $featured_image_url = '';
            }
            
            // Get permalink
            $permalink = '';
            try {
                $permalink = get_permalink($post_id);
                if (!is_string($permalink)) {
                    $permalink = '';
                }
            } catch (Throwable $e) {
                $permalink = '';
            }
            
            // Get author
            $author = '';
            try {
                $author = get_the_author_meta('display_name', $post->post_author ?? 0);
                if (!is_string($author)) {
                    $author = '';
                }
            } catch (Throwable $e) {
                $author = '';
            }
            
            return [
                'title' => $post->post_title ?? '',
                'content' => $content,
                'excerpt' => $excerpt,
                'permalink' => $permalink,
                'post_id' => $post_id,
                'site_url' => home_url(),
                'tags' => $tags,
                'categories' => $categories,
                'featured_image_url' => $featured_image_url,
                'author' => $author,
                'date' => $post->post_date ?? '',
            ];
        } catch (Throwable $e) {
            // Log error and return empty array
            if (defined('WP_DEBUG') && WP_DEBUG && function_exists('error_log')) {
                error_log('Guest Posts: Error in prepare_post_data - ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
            }
            return [];
        }
    }
    
    /**
     * Generate excerpt from content
     *
     * @param string $content Post content
     * @param string $existing_excerpt Existing excerpt
     * @return string Excerpt
     */
    private function generate_excerpt(string $content, string $existing_excerpt = ''): string {
        if (!empty($existing_excerpt)) {
            return $existing_excerpt;
        }
        
        // Strip HTML and generate excerpt
        $text = wp_strip_all_tags($content);
        return wp_trim_words($text, 55, '...');
    }
    
    /**
     * Check if post is excluded
     *
     * @param int $post_id Post ID
     * @return bool True if excluded
     */
    private function is_excluded(int $post_id): bool {
        try {
            $exclusions = $this->settings_manager->get_exclusions();
            if (!is_array($exclusions)) {
                return false;
            }
            
            // Check categories
            try {
                $post_categories = wp_get_post_categories($post_id);
                if (is_array($post_categories) && isset($exclusions['categories']) && is_array($exclusions['categories'])) {
                    foreach ($post_categories as $cat_id) {
                        if (in_array($cat_id, $exclusions['categories'], true)) {
                            return true;
                        }
                    }
                }
            } catch (Throwable $e) {
                // Continue to check tags
            }
            
            // Check tags
            try {
                $post_tags = wp_get_post_tags($post_id, ['fields' => 'ids']);
                if (is_array($post_tags) && isset($exclusions['tags']) && is_array($exclusions['tags'])) {
                    foreach ($post_tags as $tag_id) {
                        if (in_array($tag_id, $exclusions['tags'], true)) {
                            return true;
                        }
                    }
                }
            } catch (Throwable $e) {
                // Continue
            }
            
            return false;
        } catch (Throwable $e) {
            // If exclusion check fails, don't exclude the post
            if (defined('WP_DEBUG') && WP_DEBUG && function_exists('error_log')) {
                error_log('Guest Posts: Error in is_excluded - ' . $e->getMessage());
            }
            return false;
        }
    }
    
    /**
     * Add meta box to post editor
     *
     * @return void
     */
    public function add_meta_box(): void {
        add_meta_box(
            'guest-posts-manual-send',
            __('Guest Posts Network', 'guest-posts'),
            [$this, 'render_meta_box'],
            'post',
            'side',
            'default'
        );
    }
    
    /**
     * Render meta box
     *
     * @param \WP_Post $post Post object
     * @return void
     */
    public function render_meta_box(\WP_Post $post): void {
        wp_nonce_field('guest_posts_manual_send', 'guest_posts_nonce');
        
        $sent = get_post_meta($post->ID, '_guest_posts_sent', true);
        ?>
        <p>
            <button type="button" class="button button-primary" id="guest-posts-send-btn" data-post-id="<?php echo esc_attr($post->ID); ?>">
                <?php esc_html_e('Send to Network', 'guest-posts'); ?>
            </button>
        </p>
        <?php if ($sent): ?>
            <p class="description">
                <?php esc_html_e('This post has been sent to the network.', 'guest-posts'); ?>
            </p>
        <?php endif; ?>
        <div id="guest-posts-result"></div>
        <?php
    }
    
    /**
     * Handle manual send AJAX request
     *
     * @return void
     */
    public function handle_manual_send(): void {
        try {
            check_ajax_referer('guest_posts_manual_send', 'nonce');
            
            if (!current_user_can('publish_posts')) {
                wp_send_json_error(['message' => __('Insufficient permissions', 'guest-posts')]);
            }
            
            $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
            if (!$post_id) {
                wp_send_json_error(['message' => __('Invalid post ID', 'guest-posts')]);
            }
            
            // Clear sent flag to allow resending
            delete_post_meta($post_id, '_guest_posts_sent');
            delete_post_meta($post_id, '_guest_posts_sent_sites');
            
            $this->send_to_network($post_id);
            
            wp_send_json_success(['message' => __('Post sent to network', 'guest-posts')]);
        } catch (Throwable $e) {
            // Log error and send error response
            if (defined('WP_DEBUG') && WP_DEBUG && function_exists('error_log')) {
                error_log('Guest Posts: Error in handle_manual_send - ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
            }
            wp_send_json_error(['message' => __('Error sending post: ', 'guest-posts') . $e->getMessage()]);
        }
    }
}

