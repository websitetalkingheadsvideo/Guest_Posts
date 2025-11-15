<?php
/**
 * Settings Page View
 *
 * @package GuestPosts
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

use GuestPosts\Settings_Manager;

$settings_manager = new Settings_Manager();
$network_sites = $settings_manager->get_network_sites();
$exclusions = $settings_manager->get_exclusions();
$api_key = $settings_manager->get_api_key();

// Get all categories and tags for exclusion lists
$all_categories = get_categories(['hide_empty' => false]);
$all_tags = get_tags(['hide_empty' => false]);
?>

<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    
    <?php settings_errors('guest_posts'); ?>
    
    <div class="guest-posts-settings">
        <!-- API Key Section -->
        <div class="card">
            <h2><?php esc_html_e('Your Site API Key', 'guest-posts'); ?></h2>
            <p class="description">
                <?php esc_html_e('Share this API key with other sites to allow them to send posts to this site.', 'guest-posts'); ?>
            </p>
            <p>
                <input type="text" 
                       id="api-key-display" 
                       value="<?php echo esc_attr($api_key); ?>" 
                       readonly 
                       class="large-text code"
                       onclick="this.select();">
                <button type="button" class="button" id="copy-api-key">
                    <?php esc_html_e('Copy', 'guest-posts'); ?>
                </button>
                <?php if (empty($api_key)): ?>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=guest_posts_generate_api_key'), 'guest_posts_generate_key')); ?>" class="button button-primary">
                        <?php esc_html_e('Generate API Key', 'guest-posts'); ?>
                    </a>
                <?php endif; ?>
            </p>
        </div>
        
        <!-- Network Sites Section -->
        <div class="card">
            <h2><?php esc_html_e('Network Sites', 'guest-posts'); ?></h2>
            <p class="description">
                <?php esc_html_e('Add WordPress sites to your network. Posts will be sent to all sites when published.', 'guest-posts'); ?>
            </p>
            
            <!-- Add New Site Form -->
            <form method="post" action="" class="guest-posts-form">
                <?php wp_nonce_field('guest_posts_settings', 'guest_posts_nonce'); ?>
                <input type="hidden" name="guest_posts_action" value="add_site">
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="site_name"><?php esc_html_e('Site Name', 'guest-posts'); ?></label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="site_name" 
                                   name="site_name" 
                                   class="regular-text" 
                                   placeholder="<?php esc_attr_e('e.g., Main Blog', 'guest-posts'); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="site_url"><?php esc_html_e('Site URL', 'guest-posts'); ?></label>
                        </th>
                        <td>
                            <input type="url" 
                                   id="site_url" 
                                   name="site_url" 
                                   class="regular-text" 
                                   required
                                   placeholder="https://example.com">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="api_key"><?php esc_html_e('API Key', 'guest-posts'); ?></label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="api_key" 
                                   name="api_key" 
                                   class="regular-text code" 
                                   required
                                   placeholder="<?php esc_attr_e('Paste API key from remote site', 'guest-posts'); ?>">
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" class="button button-primary" value="<?php esc_attr_e('Add Site', 'guest-posts'); ?>">
                </p>
            </form>
            
            <!-- Existing Sites List -->
            <?php if (!empty($network_sites)): ?>
                <h3><?php esc_html_e('Connected Sites', 'guest-posts'); ?></h3>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Name', 'guest-posts'); ?></th>
                            <th><?php esc_html_e('URL', 'guest-posts'); ?></th>
                            <th><?php esc_html_e('Actions', 'guest-posts'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($network_sites as $site): ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($site['name'] ?: $site['url']); ?></strong>
                                </td>
                                <td>
                                    <code><?php echo esc_html($site['url']); ?></code>
                                </td>
                                <td>
                                    <button type="button" 
                                            class="button button-small edit-site" 
                                            data-site-id="<?php echo esc_attr($site['id']); ?>"
                                            data-site-name="<?php echo esc_attr($site['name']); ?>"
                                            data-site-url="<?php echo esc_attr($site['url']); ?>">
                                        <?php esc_html_e('Edit', 'guest-posts'); ?>
                                    </button>
                                    <button type="button" 
                                            class="button button-small manage-keywords" 
                                            data-site-id="<?php echo esc_attr($site['id']); ?>">
                                        <?php esc_html_e('Keywords', 'guest-posts'); ?>
                                    </button>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('<?php esc_attr_e('Are you sure?', 'guest-posts'); ?>');">
                                        <?php wp_nonce_field('guest_posts_settings', 'guest_posts_nonce'); ?>
                                        <input type="hidden" name="guest_posts_action" value="test_connection">
                                        <input type="hidden" name="site_id" value="<?php echo esc_attr($site['id']); ?>">
                                        <button type="submit" class="button button-small">
                                            <?php esc_html_e('Test', 'guest-posts'); ?>
                                        </button>
                                    </form>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('<?php esc_attr_e('Are you sure you want to delete this site?', 'guest-posts'); ?>');">
                                        <?php wp_nonce_field('guest_posts_settings', 'guest_posts_nonce'); ?>
                                        <input type="hidden" name="guest_posts_action" value="delete_site">
                                        <input type="hidden" name="site_id" value="<?php echo esc_attr($site['id']); ?>">
                                        <button type="submit" class="button button-small button-link-delete">
                                            <?php esc_html_e('Delete', 'guest-posts'); ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        
        <!-- Exclusions Section -->
        <div class="card">
            <h2><?php esc_html_e('Exclusions', 'guest-posts'); ?></h2>
            <p class="description">
                <?php esc_html_e('Posts in these categories or tags will not be sent to the network.', 'guest-posts'); ?>
            </p>
            
            <form method="post" action="">
                <?php wp_nonce_field('guest_posts_settings', 'guest_posts_nonce'); ?>
                <input type="hidden" name="guest_posts_action" value="save_exclusions">
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label><?php esc_html_e('Exclude Categories', 'guest-posts'); ?></label>
                        </th>
                        <td>
                            <select name="exclude_categories[]" multiple size="10" class="large-text">
                                <?php foreach ($all_categories as $category): ?>
                                    <option value="<?php echo esc_attr($category->term_id); ?>" 
                                            <?php selected(in_array($category->term_id, $exclusions['categories'], true)); ?>>
                                        <?php echo esc_html($category->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php esc_html_e('Hold Ctrl/Cmd to select multiple categories.', 'guest-posts'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label><?php esc_html_e('Exclude Tags', 'guest-posts'); ?></label>
                        </th>
                        <td>
                            <select name="exclude_tags[]" multiple size="10" class="large-text">
                                <?php foreach ($all_tags as $tag): ?>
                                    <option value="<?php echo esc_attr($tag->term_id); ?>" 
                                            <?php selected(in_array($tag->term_id, $exclusions['tags'], true)); ?>>
                                        <?php echo esc_html($tag->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php esc_html_e('Hold Ctrl/Cmd to select multiple tags.', 'guest-posts'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" class="button button-primary" value="<?php esc_attr_e('Save Exclusions', 'guest-posts'); ?>">
                </p>
            </form>
        </div>
    </div>
</div>

<!-- Keywords Modal -->
<div id="keywords-modal" style="display:none;">
    <div class="keywords-modal-content">
        <h2><?php esc_html_e('Manage Keywords', 'guest-posts'); ?></h2>
        <p class="description">
            <?php esc_html_e('Enter keywords (one per line or comma-separated). Posts matching any keyword will be cross-posted to this site.', 'guest-posts'); ?>
        </p>
        <form method="post" id="keywords-form">
            <?php wp_nonce_field('guest_posts_settings', 'guest_posts_nonce'); ?>
            <input type="hidden" name="guest_posts_action" value="save_keywords">
            <input type="hidden" name="site_id" id="keywords-site-id">
            <textarea name="keywords" id="keywords-textarea" rows="10" class="large-text" placeholder="<?php esc_attr_e('whiteboard videos, video marketing, animation', 'guest-posts'); ?>"></textarea>
            <p class="submit">
                <button type="button" class="button" id="close-keywords-modal"><?php esc_html_e('Cancel', 'guest-posts'); ?></button>
                <input type="submit" class="button button-primary" value="<?php esc_attr_e('Save Keywords', 'guest-posts'); ?>">
            </p>
        </form>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Copy API key
    $('#copy-api-key').on('click', function() {
        $('#api-key-display').select();
        document.execCommand('copy');
        $(this).text('<?php esc_js_e('Copied!', 'guest-posts'); ?>');
        setTimeout(() => $(this).text('<?php esc_js_e('Copy', 'guest-posts'); ?>'), 2000);
    });
    
    // Keywords modal
    $('.manage-keywords').on('click', function() {
        var siteId = $(this).data('site-id');
        $('#keywords-site-id').val(siteId);
        
        // Load existing keywords
        $.post(ajaxurl, {
            action: 'guest_posts_get_keywords',
            site_id: siteId,
            nonce: '<?php echo wp_create_nonce('guest_posts_get_keywords'); ?>'
        }, function(response) {
            if (response.success) {
                $('#keywords-textarea').val(response.data.keywords.join('\n'));
            }
        });
        
        $('#keywords-modal').show();
    });
    
    $('#close-keywords-modal').on('click', function() {
        $('#keywords-modal').hide();
    });
});

