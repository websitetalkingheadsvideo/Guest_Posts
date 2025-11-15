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
        
        // Get keywords AJAX handler (for settings page)
        if (typeof ajaxurl !== 'undefined') {
            // This will be handled by WordPress admin-ajax.php
        }
    });
    
})(jQuery);

