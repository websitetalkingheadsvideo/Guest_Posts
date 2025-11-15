<?php
/**
 * Keyword Filter
 *
 * Handles keyword matching with OR logic
 *
 * @package GuestPosts
 */

declare(strict_types=1);

namespace GuestPosts;

if (!defined('ABSPATH')) {
    exit;
}

class Keyword_Filter {
    
    /**
     * Check if post matches keywords (OR logic)
     *
     * @param array<string> $keywords Keywords to match
     * @param array<string, mixed> $post_data Post data (title, content, tags, categories)
     * @return bool True if any keyword matches
     */
    public function matches_keywords(array $keywords, array $post_data): bool {
        if (empty($keywords)) {
            return false;
        }
        
        // Normalize keywords to lowercase
        $keywords = array_map('strtolower', array_map('trim', $keywords));
        $keywords = array_filter($keywords);
        
        if (empty($keywords)) {
            return false;
        }
        
        // Get searchable text from post data
        $search_text = $this->get_searchable_text($post_data);
        $search_text_lower = strtolower($search_text);
        
        // Check if any keyword matches (OR logic)
        foreach ($keywords as $keyword) {
            if (empty($keyword)) {
                continue;
            }
            
            if (strpos($search_text_lower, $keyword) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get searchable text from post data
     *
     * @param array<string, mixed> $post_data Post data
     * @return string Combined searchable text
     */
    private function get_searchable_text(array $post_data): string {
        $text_parts = [];
        
        // Title
        if (isset($post_data['title']) && is_string($post_data['title'])) {
            $text_parts[] = $post_data['title'];
        }
        
        // Content
        if (isset($post_data['content']) && is_string($post_data['content'])) {
            // Strip HTML tags for better matching
            $text_parts[] = wp_strip_all_tags($post_data['content']);
        }
        
        // Tags
        if (isset($post_data['tags']) && is_array($post_data['tags'])) {
            foreach ($post_data['tags'] as $tag) {
                if (is_string($tag)) {
                    $text_parts[] = $tag;
                } elseif (is_array($tag) && isset($tag['name'])) {
                    $text_parts[] = $tag['name'];
                }
            }
        }
        
        // Categories
        if (isset($post_data['categories']) && is_array($post_data['categories'])) {
            foreach ($post_data['categories'] as $category) {
                if (is_string($category)) {
                    $text_parts[] = $category;
                } elseif (is_array($category) && isset($category['name'])) {
                    $text_parts[] = $category['name'];
                }
            }
        }
        
        return implode(' ', $text_parts);
    }
}

