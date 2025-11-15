/**
 * Admin JavaScript
 *
 * @package GuestPosts
 */

(function($) {
    'use strict';
    
    $(document).ready(function() {
        // Manual send button in post editor
        $('#guest-posts-send-btn').on('click', function() {
            var $btn = $(this);
            var postId = $btn.data('post-id');
            var $result = $('#guest-posts-result');
            
            $btn.prop('disabled', true).text('Sending...');
            $result.html('');
            
            $.ajax({
                url: guestPosts.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'guest_posts_send_manual',
                    post_id: postId,
                    nonce: guestPosts.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $result.html('<p class="notice notice-success"><strong>' + response.data.message + '</strong></p>');
                        $btn.text('Sent to Network');
                    } else {
                        $result.html('<p class="notice notice-error"><strong>Error: ' + (response.data.message || 'Unknown error') + '</strong></p>');
                        $btn.prop('disabled', false).text('Send to Network');
                    }
                },
                error: function() {
                    $result.html('<p class="notice notice-error"><strong>Error: Request failed</strong></p>');
                    $btn.prop('disabled', false).text('Send to Network');
                }
            });
        });
        
        // Bulk import form handler (settings page)
        $('#bulk-import-form').on('submit', function(e) {
            console.log('Bulk import form submitted!');
            
            var configStrings = $('#import-config').val().trim();
            if (!configStrings) {
                e.preventDefault();
                alert(guestPosts.strings.pasteConfig);
                return false;
            }
            
            var lines = configStrings.split('\n').map(function(line) { 
                return line.trim(); 
            }).filter(function(line) { 
                return line.length > 0; 
            });
            
            if (lines.length === 0) {
                e.preventDefault();
                alert(guestPosts.strings.noValidConfigs);
                return false;
            }
            
            // Validate all first
            var validCount = 0;
            for (var i = 0; i < lines.length; i++) {
                try {
                    var config = JSON.parse(atob(lines[i]));
                    if (config.url && config.api_key) {
                        validCount++;
                    }
                } catch (e) {
                    // Invalid - skip
                }
            }
            
            if (validCount === 0) {
                e.preventDefault();
                alert(guestPosts.strings.noValidConfigs);
                return false;
            }
            
            if (validCount > 1) {
                if (!confirm(guestPosts.strings.willAdd + validCount + guestPosts.strings.sitesContinue)) {
                    e.preventDefault();
                    return false;
                }
            }
            
            $('#import-status').text(guestPosts.strings.submitting).css('color', 'blue');
            // Form will submit normally - no need to prevent default
        });
        
        // Get keywords AJAX handler (for settings page)
        if (typeof ajaxurl !== 'undefined') {
            // This will be handled by WordPress admin-ajax.php
        }
    });
    
})(jQuery);

