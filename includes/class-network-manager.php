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
    }
    
    /**
     * Send post to all network sites
     *
     * @param int $post_id Post ID
     * @return void
     */
    public function send_to_network(int $post_id): void {
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
        
        // Get network sites
        $sites = $this->settings_manager->get_network_sites();
        
        $sent_sites = [];
        
        foreach ($sites as $site) {
            $site_obj = $this->settings_manager->get_network_site($site['id']);
            if (!$site_obj) {
                continue;
            }
            $api_key = $site_obj['api_key'];
            if (empty($api_key)) {
                continue;
            }
            
            $response = $this->api_client->send_post($site_obj['url'], $api_key, $post_data);
            
            if ($response['success']) {
                $sent_sites[] = $site['id'];
            }
        }
        
        // Mark as sent
        if (!empty($sent_sites)) {
            update_post_meta($post_id, '_guest_posts_sent', true);
            update_post_meta($post_id, '_guest_posts_sent_sites', $sent_sites);
        }
    }
    
    /**
     * Update existing cross-posts
     *
     * @param int $post_id Post ID
     * @return void
     */
    private function update_network_posts(int $post_id): void {
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
        $post_data['update'] = true;
        $post_data['original_post_id'] = $post_id;
        
        // Get network sites
        $sites = $this->settings_manager->get_network_sites();
        
        foreach ($sites as $site) {
            if (!in_array($site['id'], $sent_sites, true)) {
                continue;
            }
            
            $site_obj = $this->settings_manager->get_network_site($site['id']);
            if (!$site_obj) {
                continue;
            }
            $api_key = $site_obj['api_key'];
            if (empty($api_key)) {
                continue;
            }
            
            $this->api_client->send_post($site_obj['url'], $api_key, $post_data);
        }
    }
    
    /**
     * Prepare post data for sending
     *
     * @param int $post_id Post ID
     * @return array<string, mixed> Post data
     */
    private function prepare_post_data(int $post_id): array {
        $post = get_post($post_id);
        if (!$post) {
            return [];
        }
        
        // Get content (Elementor or standard)
        $content = $this->elementor_handler->get_post_content($post_id);
        
        // Generate excerpt
        $excerpt = $this->generate_excerpt($content, $post->post_excerpt);
        
        // Get tags
        $tags = wp_get_post_tags($post_id, ['fields' => 'names']);
        
        // Get categories
        $categories = wp_get_post_categories($post_id, ['fields' => 'names']);
        
        // Get featured image
        $featured_image_url = '';
        $thumbnail_id = get_post_thumbnail_id($post_id);
        if ($thumbnail_id) {
            $featured_image_url = wp_get_attachment_image_url($thumbnail_id, 'full');
        }
        
        return [
            'title' => $post->post_title,
            'content' => $content,
            'excerpt' => $excerpt,
            'permalink' => get_permalink($post_id),
            'post_id' => $post_id,
            'site_url' => home_url(),
            'tags' => $tags,
            'categories' => $categories,
            'featured_image_url' => $featured_image_url,
            'author' => get_the_author_meta('display_name', $post->post_author),
            'date' => $post->post_date,
        ];
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
        $exclusions = $this->settings_manager->get_exclusions();
        
        // Check categories
        $post_categories = wp_get_post_categories($post_id);
        foreach ($post_categories as $cat_id) {
            if (in_array($cat_id, $exclusions['categories'], true)) {
                return true;
            }
        }
        
        // Check tags
        $post_tags = wp_get_post_tags($post_id, ['fields' => 'ids']);
        foreach ($post_tags as $tag_id) {
            if (in_array($tag_id, $exclusions['tags'], true)) {
                return true;
            }
        }
        
        return false;
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
    }
}

