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

// Force error display
@ini_set('display_errors', '1');
error_reporting(E_ALL);

// Ensure constant is defined first
if (!defined('GUEST_POSTS_PLUGIN_DIR')) {
    wp_die(
        '<h1>' . esc_html__('Guest Posts Error', 'guest-posts') . '</h1>' .
        '<p>' . esc_html__('Plugin directory constant not defined. Please deactivate and reactivate the plugin.', 'guest-posts') . '</p>',
        esc_html__('Guest Posts Error', 'guest-posts'),
        ['response' => 500]
    );
}

use GuestPosts\Settings_Manager;

// Ensure class file is loaded (autoloader might not have triggered)
if (!class_exists('GuestPosts\Settings_Manager')) {
    // Try to load the class file manually
    $class_file = GUEST_POSTS_PLUGIN_DIR . 'includes/class-settings-manager.php';
    if (file_exists($class_file)) {
        require_once $class_file;
    }
    
    // Check again
    if (!class_exists('GuestPosts\Settings_Manager')) {
        wp_die(
            '<h1>' . esc_html__('Guest Posts Error', 'guest-posts') . '</h1>' .
            '<p>' . esc_html__('Settings Manager class not found. Please deactivate and reactivate the plugin.', 'guest-posts') . '</p>' .
            '<p><strong>Debug:</strong> Class file path: ' . esc_html($class_file) . '</p>' .
            '<p><strong>Debug:</strong> File exists: ' . (file_exists($class_file) ? 'Yes' : 'No') . '</p>',
            esc_html__('Guest Posts Error', 'guest-posts'),
            ['response' => 500]
        );
    }
}

try {
    $settings_manager = new Settings_Manager();
    $network_sites = is_array($settings_manager->get_network_sites()) ? $settings_manager->get_network_sites() : [];
    $api_key = $settings_manager->get_api_key();
} catch (Throwable $e) {
    // If initialization fails, show error and exit early
    if (defined('WP_DEBUG') && WP_DEBUG && function_exists('error_log')) {
        error_log('Guest Posts: Settings Manager initialization failed: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
    }
    wp_die(
        '<h1>' . esc_html__('Guest Posts Error', 'guest-posts') . '</h1>' .
        '<p>' . esc_html__('Failed to initialize settings: ', 'guest-posts') . esc_html($e->getMessage()) . '</p>',
        esc_html__('Guest Posts Error', 'guest-posts'),
        ['response' => 500]
    );
}

// Helper to decrypt API keys for export (temporary - only in this view)
function guest_posts_decrypt_for_export($encrypted_key, $settings_manager) {
    if (empty($encrypted_key)) {
        return '';
    }
    if (!is_object($settings_manager) || !method_exists($settings_manager, 'decrypt_api_key')) {
        return '';
    }
    try {
        $decrypted = $settings_manager->decrypt_api_key($encrypted_key);
        // Make sure we got something back and it's not just the encrypted value
        if (!empty($decrypted) && is_string($decrypted) && $decrypted !== $encrypted_key) {
            return $decrypted;
        }
    } catch (Throwable $e) {
        // Log error but don't break page
        if (defined('WP_DEBUG') && WP_DEBUG && function_exists('error_log')) {
            error_log('Guest Posts: Decrypt error in export: ' . $e->getMessage());
        }
    }
    // Fallback - if decryption failed, return empty (don't expose encrypted key)
    return '';
}
?>

<div class="wrap">
    <h1>
        <?php echo esc_html(get_admin_page_title()); ?>
        <span style="font-size: 0.6em; font-weight: normal; color: #666; margin-left: 10px;">
            Version <?php echo esc_html(defined('GUEST_POSTS_VERSION') ? GUEST_POSTS_VERSION : 'unknown'); ?>
        </span>
    </h1>
    
    <?php settings_errors('guest_posts'); ?>
    
    <div class="guest-posts-settings">
        <!-- STEP 1: Get This Site's Config -->
        <div class="card" style="border-left: 4px solid #00a32a;">
            <h2>STEP 1: Get This Site's Config String</h2>
            <p style="font-size: 16px; font-weight: bold; color: #1d2327;">
                <?php esc_html_e('Copy the config string below. You\'ll paste this into ALL your other sites.', 'guest-posts'); ?>
            </p>
            <?php if (empty($api_key)): ?>
                <p>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=guest_posts_generate_api_key'), 'guest_posts_generate_key')); ?>" class="button button-primary button-large">
                        <?php esc_html_e('Generate API Key First', 'guest-posts'); ?>
                    </a>
                </p>
            <?php else: 
                // Generate config string directly in PHP
                $config = [
                    'url' => home_url(),
                    'api_key' => $api_key,
                    'site_name' => get_bloginfo('name')
                ];
                $config_string = base64_encode(json_encode($config, JSON_UNESCAPED_SLASHES));
                ?>
                <div style="background: #fff; border: 2px solid #00a32a; padding: 15px; border-radius: 4px; margin-top: 15px;">
                    <p style="font-weight: bold; margin-top: 0;">
                        <?php esc_html_e('COPY THIS ENTIRE STRING:', 'guest-posts'); ?>
                    </p>
                    <textarea readonly class="large-text code" rows="3" onclick="this.select();" style="width:100%; font-family: monospace; font-size: 12px; padding: 10px; border: 1px solid #ccc;"><?php echo esc_textarea($config_string); ?></textarea>
                    <p style="margin-bottom: 0; color: #d63638; font-weight: bold;">
                        <?php esc_html_e('✓ Copy this string. You\'ll paste it into all your other sites in Step 2.', 'guest-posts'); ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Network Sites Section -->
        <div class="card">
            <h2><?php esc_html_e('Network Sites', 'guest-posts'); ?></h2>
            <p class="description">
                <?php esc_html_e('Add WordPress sites to receive your posts. When you publish a post here, it will automatically be sent to all sites listed below (if they match the keywords you set for each site).', 'guest-posts'); ?>
            </p>
            <p class="description">
                <strong><?php esc_html_e('Important:', 'guest-posts'); ?></strong>
                <?php esc_html_e('Each receiving site can set keywords to filter which posts they accept. Use "Set Keywords" below to configure what posts each site will accept.', 'guest-posts'); ?>
            </p>
            
            <!-- STEP 2: Import Other Sites -->
            <div style="background: #fff3cd; border-left: 4px solid #ffb900; padding: 15px; margin-bottom: 20px;">
                <h2 style="margin-top: 0;">STEP 2: Add Other Sites to This Site</h2>
                <p style="font-size: 16px; font-weight: bold; color: #1d2327; margin-bottom: 10px;">
                    <?php esc_html_e('Paste config strings from your OTHER sites here (one per line).', 'guest-posts'); ?>
                </p>
                <p style="background: #fff; padding: 10px; border-left: 3px solid #2271b1; margin: 10px 0;">
                    <strong><?php esc_html_e('How to get config strings:', 'guest-posts'); ?></strong><br>
                    <?php esc_html_e('1. Go to each of your OTHER sites', 'guest-posts'); ?><br>
                    <?php esc_html_e('2. Go to Settings → Guest Posts', 'guest-posts'); ?><br>
                    <?php esc_html_e('3. Click "Click Here to Get Config String" (in Step 1)', 'guest-posts'); ?><br>
                    <?php esc_html_e('4. Copy the string that appears', 'guest-posts'); ?><br>
                    <?php esc_html_e('5. Paste it here (one per line if you have multiple)', 'guest-posts'); ?>
                </p>
                <form method="post" action="" class="guest-posts-form" id="bulk-import-form">
                    <?php wp_nonce_field('guest_posts_settings', 'guest_posts_nonce'); ?>
                    <input type="hidden" name="guest_posts_action" value="bulk_import">
                    <textarea name="config_strings" id="import-config" placeholder="<?php esc_attr_e('Paste config strings here, one per line...', 'guest-posts'); ?>" rows="8" class="large-text" style="width:100%; margin-bottom: 10px; font-family: monospace; font-size: 12px; padding: 10px;"><?php echo isset($_POST['config_strings']) ? esc_textarea($_POST['config_strings']) : ''; ?></textarea>
                    <p class="submit">
                        <input type="submit" class="button button-primary" id="import-config-btn" value="<?php esc_attr_e('Add Sites', 'guest-posts'); ?>">
                        <span id="import-status" style="margin-left: 15px; font-weight: bold; font-size: 14px;"></span>
                    </p>
                </form>
            </div>
            
            <!-- Add New Site Form -->
            <h3><?php esc_html_e('Add Site Manually', 'guest-posts'); ?></h3>
            <form method="post" action="" class="guest-posts-form">
                <?php wp_nonce_field('guest_posts_settings', 'guest_posts_nonce'); ?>
                <input type="hidden" name="guest_posts_action" value="add_site">
                
                <table class="form-table">
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
                            <p class="description">
                                <?php esc_html_e('The full URL of the WordPress site where you want to receive posts.', 'guest-posts'); ?>
                            </p>
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
                            <p class="description">
                                <?php esc_html_e('Get this from the receiving site\'s Guest Posts settings page.', 'guest-posts'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="site_name"><?php esc_html_e('Display Name', 'guest-posts'); ?> <span class="description">(<?php esc_html_e('optional', 'guest-posts'); ?>)</span></label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="site_name" 
                                   name="site_name" 
                                   class="regular-text" 
                                   placeholder="<?php esc_attr_e('e.g., Main Blog', 'guest-posts'); ?>">
                            <p class="description">
                                <?php esc_html_e('Optional friendly name to help you identify this site in the list.', 'guest-posts'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" class="button button-primary" value="<?php esc_attr_e('Add Site', 'guest-posts'); ?>">
                </p>
            </form>
            
            <!-- Export Section -->
            <?php if (!empty($network_sites)): ?>
                <div style="background: #e8f4f8; border-left: 4px solid #0073aa; padding: 12px; margin: 20px 0;">
                    <h3 style="margin-top: 0;"><?php esc_html_e('Export All Sites', 'guest-posts'); ?> <span style="font-size: 0.9em; color: #666;">(<?php echo count($network_sites); ?> <?php esc_html_e('site(s)', 'guest-posts'); ?>)</span></h3>
                    <p>
                        <?php esc_html_e('Copy all your connected sites as config strings to paste into other sites:', 'guest-posts'); ?>
                    </p>
                    <?php if (empty($network_sites)): ?>
                        <p class="description" style="color: #d63638;">
                            <?php esc_html_e('No sites to export. Add sites above first.', 'guest-posts'); ?>
                        </p>
                    <?php endif; ?>
                    <textarea id="export-all-sites" readonly class="large-text code" rows="8" style="width:100%; font-family: monospace; margin-bottom: 10px; white-space: pre;" onclick="this.select();"><?php 
                        $configs = [];
                        if (is_array($network_sites)) {
                            foreach ($network_sites as $site) {
                                if (empty($site) || !is_array($site)) {
                                    continue;
                                }
                                if (empty($site['url']) || empty($site['api_key'])) {
                                    continue;
                                }
                                try {
                                    $decrypted_key = guest_posts_decrypt_for_export($site['api_key'], $settings_manager);
                                    if (empty($decrypted_key)) {
                                        continue;
                                    }
                                    $config = [
                                        'url' => (string)$site['url'],
                                        'api_key' => (string)$decrypted_key,
                                        'site_name' => isset($site['name']) ? (string)$site['name'] : ''
                                    ];
                                    $json = json_encode($config, JSON_UNESCAPED_SLASHES);
                                    if ($json !== false) {
                                        $configs[] = base64_encode($json);
                                    }
                                } catch (Exception $e) {
                                    // Skip this site if there's an error
                                    continue;
                                }
                            }
                        }
                        echo esc_textarea(implode("\n", $configs));
                    ?></textarea>
                    <button type="button" class="button" id="copy-all-configs">
                        <?php esc_html_e('Copy All Configs', 'guest-posts'); ?>
                    </button>
                    <p class="description" style="margin-top: 10px;">
                        <?php esc_html_e('Paste this into the "Bulk Import Sites" box on other sites to add all your sites at once.', 'guest-posts'); ?>
                    </p>
                </div>
            <?php endif; ?>
            
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
                                            class="button button-primary manage-keywords" 
                                            data-site-id="<?php echo esc_attr($site['id']); ?>">
                                        <?php esc_html_e('Set Keywords', 'guest-posts'); ?>
                                    </button>
                                    <button type="button" 
                                            class="button button-small edit-site" 
                                            data-site-id="<?php echo esc_attr($site['id']); ?>"
                                            data-site-name="<?php echo esc_attr($site['name']); ?>"
                                            data-site-url="<?php echo esc_attr($site['url']); ?>">
                                        <?php esc_html_e('Edit', 'guest-posts'); ?>
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
        
        <!-- Keywords Info Section -->
        <div class="card">
            <h2><?php esc_html_e('How Keywords Work', 'guest-posts'); ?></h2>
            <div class="notice notice-info inline">
                <p>
                    <strong><?php esc_html_e('On the RECEIVING site:', 'guest-posts'); ?></strong>
                    <?php esc_html_e('Set keywords for each network site using the "Set Keywords" button above. When posts arrive from other sites, they will only be published here if they match your keywords (OR logic - matches any keyword).', 'guest-posts'); ?>
                </p>
                <p>
                    <strong><?php esc_html_e('Empty keywords = Accept all posts:', 'guest-posts'); ?></strong>
                    <?php esc_html_e('If you leave keywords empty for a site, that site will accept ALL posts from you. Only set keywords if you want to filter which posts are accepted.', 'guest-posts'); ?>
                </p>
                <p>
                    <?php esc_html_e('Keywords are matched against the post title, content, tags, and categories. If a post doesn\'t match your keywords, it will be silently discarded.', 'guest-posts'); ?>
                </p>
                <p>
                    <strong><?php esc_html_e('Example:', 'guest-posts'); ?></strong>
                    <?php esc_html_e('If you set keywords: "video marketing, whiteboard animation, explainer videos" - only posts containing at least one of these phrases will be cross-posted to your site.', 'guest-posts'); ?>
                </p>
            </div>
        </div>
        
        <!-- How the Network Works Section -->
        <div class="card">
            <h2><?php esc_html_e('How the Network Works', 'guest-posts'); ?></h2>
            <div class="notice notice-warning inline">
                <p>
                    <strong><?php esc_html_e('Bidirectional Setup Required:', 'guest-posts'); ?></strong>
                </p>
                <p>
                    <?php esc_html_e('To send posts between two sites, you need to add the other site\'s information on BOTH sites:', 'guest-posts'); ?>
                </p>
                <ol style="margin-left: 20px;">
                    <li><?php esc_html_e('On Site A: Add Site B\'s URL and API key (from Site B\'s settings)', 'guest-posts'); ?></li>
                    <li><?php esc_html_e('On Site B: Add Site A\'s URL and API key (from Site A\'s settings)', 'guest-posts'); ?></li>
                </ol>
                <p>
                    <strong><?php esc_html_e('Why?', 'guest-posts'); ?></strong>
                    <?php esc_html_e('Site A needs Site B\'s API key to send posts TO Site B. Site B needs Site A\'s API key to authenticate incoming posts FROM Site A.', 'guest-posts'); ?>
                </p>
                <p>
                    <?php esc_html_e('Once both sites are added to each other, posts published on either site will automatically be sent to the other (if keywords match).', 'guest-posts'); ?>
                </p>
            </div>
        </div>
    </div>
</div>

<!-- Keywords Modal -->
<div id="keywords-modal" style="display:none;">
    <div class="keywords-modal-content">
        <h2><?php esc_html_e('Set Keywords for Incoming Posts', 'guest-posts'); ?></h2>
        <p class="description">
            <strong><?php esc_html_e('Only posts matching these keywords will be published on this site.', 'guest-posts'); ?></strong>
        </p>
        <p class="description" style="color: #d63638; font-weight: bold;">
            <?php esc_html_e('Leave empty to accept ALL posts from this site (no filtering).', 'guest-posts'); ?>
        </p>
        <p class="description">
            <?php esc_html_e('Enter keywords one per line or comma-separated. Keywords are matched against post title, content, tags, and categories. If ANY keyword matches (OR logic), the post will be published. If no keywords match, the post will be silently discarded.', 'guest-posts'); ?>
        </p>
        <form method="post" id="keywords-form">
            <?php wp_nonce_field('guest_posts_settings', 'guest_posts_nonce'); ?>
            <input type="hidden" name="guest_posts_action" value="save_keywords">
            <input type="hidden" name="site_id" id="keywords-site-id">
            <label for="keywords-textarea" class="screen-reader-text"><?php esc_html_e('Keywords', 'guest-posts'); ?></label>
            <textarea name="keywords" id="keywords-textarea" rows="10" class="large-text" placeholder="<?php esc_attr_e('video marketing' . "\n" . 'whiteboard animation' . "\n" . 'explainer videos' . "\n" . 'product demo', 'guest-posts'); ?>"></textarea>
            <p class="description">
                <?php esc_html_e('Example: If you enter "video marketing, animation" - posts containing either "video marketing" OR "animation" will be published.', 'guest-posts'); ?>
            </p>
            <p class="submit">
                <button type="button" class="button" id="close-keywords-modal"><?php esc_html_e('Cancel', 'guest-posts'); ?></button>
                <input type="submit" class="button button-primary" value="<?php esc_attr_e('Save Keywords', 'guest-posts'); ?>">
            </p>
        </form>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    console.log('Guest Posts inline script loaded');
    
    // Copy API key (if element exists)
    $('#copy-api-key').on('click', function() {
        if ($('#api-key-display').length) {
            $('#api-key-display').select();
            document.execCommand('copy');
            $(this).text('<?php esc_js_e('Copied!', 'guest-posts'); ?>');
            setTimeout(() => $(this).text('<?php esc_js_e('Copy', 'guest-posts'); ?>'), 2000);
        }
    });
    
    // Copy all configs
    $('#copy-all-configs').on('click', function() {
        $('#export-all-sites').select();
        document.execCommand('copy');
        $(this).text('<?php esc_js_e('Copied!', 'guest-posts'); ?>');
        setTimeout(() => $(this).text('<?php esc_js_e('Copy All Configs', 'guest-posts'); ?>'), 2000);
    });
    
    // Import configuration - bulk or single
    function importConfig(configString, autoSubmit) {
        try {
            var config = JSON.parse(atob(configString));
            if (config.url && config.api_key) {
                if (autoSubmit) {
                    // Bulk import - submit directly
                    var $form = $('<form method="post" style="display:none;">').appendTo('body');
                    var nonce = '<?php echo wp_create_nonce('guest_posts_settings'); ?>';
                    $form.append($('<input>').attr({type: 'hidden', name: 'guest_posts_nonce', value: nonce}));
                    $form.append($('<input>').attr({type: 'hidden', name: 'guest_posts_action', value: 'add_site'}));
                    $form.append($('<input>').attr({type: 'hidden', name: 'site_url', value: config.url}));
                    $form.append($('<input>').attr({type: 'hidden', name: 'api_key', value: config.api_key}));
                    if (config.site_name) {
                        $form.append($('<input>').attr({type: 'hidden', name: 'site_name', value: config.site_name}));
                    }
                    $form.submit();
                } else {
                    // Single import - fill form
                    $('#site_url').val(config.url);
                    $('#api_key').val(config.api_key);
                    if (config.site_name) {
                        $('#site_name').val(config.site_name);
                    }
                    $('#import-config').val('');
                    // Scroll to form and highlight
                    $('html, body').animate({
                        scrollTop: $('.guest-posts-form').offset().top - 100
                    }, 500);
                    var $form = $('.guest-posts-form');
                    $form.css('background-color', '#fff3cd');
                    setTimeout(function() {
                        $form.css('background-color', '');
                    }, 1000);
                }
                return true;
            } else {
                return false;
            }
        } catch (e) {
            return false;
        }
    }
    
    // Import single site (fill form)
    $('#import-single-btn').on('click', function() {
        var configString = $('#import-config').val().trim();
        if (!configString) {
            alert('<?php esc_js_e('Please paste a configuration string.', 'guest-posts'); ?>');
            return;
        }
        
        // Try first line only for single import
        var firstLine = configString.split('\n')[0].trim();
        if (importConfig(firstLine, false)) {
            $('#import-status').text('✓ Imported - review and click "Add Site"').css('color', 'green');
        } else {
            alert('<?php esc_js_e('Invalid configuration string format.', 'guest-posts'); ?>');
        }
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

