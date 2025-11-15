<?php
/**
 * Elementor Handler
 *
 * Handles detection and extraction of text from Elementor content
 *
 * @package GuestPosts
 */

declare(strict_types=1);

namespace GuestPosts;

if (!defined('ABSPATH')) {
    exit;
}

class Elementor_Handler {
    
    /**
     * Check if post has Elementor content
     *
     * @param int $post_id Post ID
     * @return bool True if Elementor content exists
     */
    public function has_elementor_content(int $post_id): bool {
        $elementor_data = get_post_meta($post_id, '_elementor_data', true);
        return !empty($elementor_data);
    }
    
    /**
     * Extract text content from Elementor JSON
     *
     * @param int $post_id Post ID
     * @return string Extracted text content
     */
    public function extract_text_from_elementor(int $post_id): string {
        $elementor_data = get_post_meta($post_id, '_elementor_data', true);
        
        if (empty($elementor_data)) {
            return '';
        }
        
        // Elementor data might be JSON string or already decoded
        if (is_string($elementor_data)) {
            $decoded = json_decode($elementor_data, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return '';
            }
        } else {
            $decoded = $elementor_data;
        }
        
        if (!is_array($decoded)) {
            return '';
        }
        
        $text_content = $this->extract_text_from_elements($decoded);
        
        // Fallback to post content if extraction failed
        if (empty($text_content)) {
            $post = get_post($post_id);
            return $post ? $post->post_content : '';
        }
        
        return $text_content;
    }
    
    /**
     * Recursively extract text from Elementor elements
     *
     * @param array<mixed> $elements Elementor elements array
     * @return string Extracted text
     */
    private function extract_text_from_elements(array $elements): string {
        $text_parts = [];
        
        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }
            
            // Extract text from widget settings
            if (isset($element['settings']['editor'])) {
                $text_parts[] = wp_strip_all_tags($element['settings']['editor']);
            }
            
            if (isset($element['settings']['text'])) {
                $text_parts[] = wp_strip_all_tags($element['settings']['text']);
            }
            
            if (isset($element['settings']['title'])) {
                $text_parts[] = wp_strip_all_tags($element['settings']['title']);
            }
            
            if (isset($element['settings']['html'])) {
                $text_parts[] = wp_strip_all_tags($element['settings']['html']);
            }
            
            // Recursively process child elements
            if (isset($element['elements']) && is_array($element['elements'])) {
                $child_text = $this->extract_text_from_elements($element['elements']);
                if (!empty($child_text)) {
                    $text_parts[] = $child_text;
                }
            }
        }
        
        return implode(' ', array_filter($text_parts));
    }
    
    /**
     * Get post content (Elementor or standard)
     *
     * @param int $post_id Post ID
     * @return string Post content
     */
    public function get_post_content(int $post_id): string {
        if ($this->has_elementor_content($post_id)) {
            return $this->extract_text_from_elementor($post_id);
        }
        
        $post = get_post($post_id);
        return $post ? $post->post_content : '';
    }
}

