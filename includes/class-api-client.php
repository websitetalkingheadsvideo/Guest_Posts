<?php
/**
 * API Client
 *
 * Handles REST API HTTP communication with remote sites
 *
 * @package GuestPosts
 */

declare(strict_types=1);

namespace GuestPosts;

if (!defined('ABSPATH')) {
    exit;
}

class API_Client {
    
    /**
     * Send post to remote site
     *
     * @param string $remote_url Remote site URL
     * @param string $api_key API key for authentication
     * @param array<string, mixed> $post_data Post data to send
     * @return array<string, mixed> Response data
     */
    public function send_post(string $remote_url, string $api_key, array $post_data): array {
        $endpoint = trailingslashit($remote_url) . 'wp-json/guest-posts/v1/receive';
        
        $response = wp_remote_post($endpoint, [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Guest-Posts-API-Key' => $api_key,
            ],
            'body' => wp_json_encode($post_data),
        ]);
        
        if (is_wp_error($response)) {
            return [
                'success' => false,
                'error' => $response->get_error_message(),
            ];
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded_body = json_decode($body, true);
        
        if ($status_code !== 200) {
            return [
                'success' => false,
                'error' => isset($decoded_body['message']) ? $decoded_body['message'] : 'Unknown error',
                'status_code' => $status_code,
            ];
        }
        
        return [
            'success' => true,
            'data' => $decoded_body,
        ];
    }
    
    /**
     * Test connection to remote site
     *
     * @param string $remote_url Remote site URL
     * @param string $api_key API key for authentication
     * @return array<string, mixed> Response data
     */
    public function test_connection(string $remote_url, string $api_key): array {
        $endpoint = trailingslashit($remote_url) . 'wp-json/guest-posts/v1/receive';
        
        $response = wp_remote_post($endpoint, [
            'timeout' => 10,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Guest-Posts-API-Key' => $api_key,
            ],
            'body' => wp_json_encode([
                'test' => true,
            ]),
        ]);
        
        if (is_wp_error($response)) {
            return [
                'success' => false,
                'error' => $response->get_error_message(),
            ];
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        
        // 200 or 400 (bad request for test) means endpoint exists
        if ($status_code === 200 || $status_code === 400) {
            return [
                'success' => true,
                'message' => 'Connection successful',
            ];
        }
        
        return [
            'success' => false,
            'error' => 'Connection failed',
            'status_code' => $status_code,
        ];
    }
}

