<?php
/**
 * Settings Manager
 *
 * Handles plugin options using WordPress Options API
 *
 * @package GuestPosts
 */

declare(strict_types=1);

namespace GuestPosts;

if (!defined('ABSPATH')) {
    exit;
}

class Settings_Manager {
    
    private const OPTION_NETWORK_SITES = 'guest_posts_network_sites';
    private const OPTION_API_KEY = 'guest_posts_api_key';
    private const OPTION_EXCLUSIONS = 'guest_posts_exclusions';
    private const OPTION_KEYWORDS_PREFIX = 'guest_posts_keywords_';
    
    /**
     * Get all network sites
     *
     * @return array<int, array<string, mixed>>
     */
    public function get_network_sites(): array {
        $sites = get_option(self::OPTION_NETWORK_SITES, []);
        return is_array($sites) ? $sites : [];
    }
    
    /**
     * Add a network site
     *
     * @param string $url Site URL
     * @param string $api_key API key for the remote site
     * @param string $name Site name (optional)
     * @return int Site ID
     */
    public function add_network_site(string $url, string $api_key, string $name = ''): int {
        $sites = $this->get_network_sites();
        $site_id = $this->get_next_site_id();
        
        $sites[] = [
            'id' => $site_id,
            'url' => esc_url_raw($url),
            'api_key' => $this->encrypt_api_key($api_key),
            'name' => sanitize_text_field($name),
            'created' => current_time('mysql'),
        ];
        
        update_option(self::OPTION_NETWORK_SITES, $sites);
        return $site_id;
    }
    
    /**
     * Update a network site
     *
     * @param int $site_id Site ID
     * @param array<string, mixed> $data Site data
     * @return bool Success
     */
    public function update_network_site(int $site_id, array $data): bool {
        $sites = $this->get_network_sites();
        
        foreach ($sites as $key => $site) {
            if ((int) $site['id'] === $site_id) {
                if (isset($data['url'])) {
                    $sites[$key]['url'] = esc_url_raw($data['url']);
                }
                if (isset($data['api_key'])) {
                    $sites[$key]['api_key'] = $this->encrypt_api_key($data['api_key']);
                }
                if (isset($data['name'])) {
                    $sites[$key]['name'] = sanitize_text_field($data['name']);
                }
                
                update_option(self::OPTION_NETWORK_SITES, $sites);
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Delete a network site
     *
     * @param int $site_id Site ID
     * @return bool Success
     */
    public function delete_network_site(int $site_id): bool {
        $sites = $this->get_network_sites();
        
        foreach ($sites as $key => $site) {
            if ((int) $site['id'] === $site_id) {
                unset($sites[$key]);
                $sites = array_values($sites); // Reindex array
                update_option(self::OPTION_NETWORK_SITES, $sites);
                
                // Delete associated keywords
                delete_option(self::OPTION_KEYWORDS_PREFIX . $site_id);
                
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get site by ID
     *
     * @param int $site_id Site ID
     * @return array<string, mixed>|null Site data or null
     */
    public function get_network_site(int $site_id): ?array {
        $sites = $this->get_network_sites();
        
        foreach ($sites as $site) {
            if ((int) $site['id'] === $site_id) {
                $site['api_key'] = $this->decrypt_api_key($site['api_key']);
                return $site;
            }
        }
        
        return null;
    }
    
    /**
     * Get API key for this site
     *
     * @return string API key
     */
    public function get_api_key(): string {
        $key = get_option(self::OPTION_API_KEY, '');
        return $key ? $this->decrypt_api_key($key) : '';
    }
    
    /**
     * Generate and store API key for this site
     *
     * @return string Generated API key
     */
    public function generate_api_key(): string {
        $api_key = $this->generate_secure_key();
        update_option(self::OPTION_API_KEY, $this->encrypt_api_key($api_key));
        return $api_key;
    }
    
    /**
     * Get keywords for a site
     *
     * @param int $site_id Site ID
     * @return array<string> Keywords
     */
    public function get_keywords(int $site_id): array {
        $keywords = get_option(self::OPTION_KEYWORDS_PREFIX . $site_id, '');
        
        if (empty($keywords)) {
            return [];
        }
        
        if (is_array($keywords)) {
            return array_map('trim', $keywords);
        }
        
        // Handle comma-separated string
        $keywords_array = explode(',', $keywords);
        return array_map('trim', array_filter($keywords_array));
    }
    
    /**
     * Save keywords for a site
     *
     * @param int $site_id Site ID
     * @param array<string>|string $keywords Keywords (array or comma-separated string)
     * @return bool Success
     */
    public function save_keywords(int $site_id, $keywords): bool {
        if (is_string($keywords)) {
            $keywords = explode(',', $keywords);
        }
        
        $keywords = array_map('trim', array_filter($keywords));
        return update_option(self::OPTION_KEYWORDS_PREFIX . $site_id, $keywords);
    }
    
    /**
     * Get exclusion settings
     *
     * @return array<string, array<int>> Exclusions by type
     */
    public function get_exclusions(): array {
        $exclusions = get_option(self::OPTION_EXCLUSIONS, []);
        return is_array($exclusions) ? $exclusions : ['categories' => [], 'tags' => []];
    }
    
    /**
     * Save exclusion settings
     *
     * @param array<string, array<int>> $exclusions Exclusions by type
     * @return bool Success
     */
    public function save_exclusions(array $exclusions): bool {
        $sanitized = [
            'categories' => isset($exclusions['categories']) ? array_map('intval', $exclusions['categories']) : [],
            'tags' => isset($exclusions['tags']) ? array_map('intval', $exclusions['tags']) : [],
        ];
        
        return update_option(self::OPTION_EXCLUSIONS, $sanitized);
    }
    
    /**
     * Get next available site ID
     *
     * @return int Next site ID
     */
    private function get_next_site_id(): int {
        $sites = $this->get_network_sites();
        if (empty($sites)) {
            return 1;
        }
        
        $max_id = 0;
        foreach ($sites as $site) {
            $id = (int) $site['id'];
            if ($id > $max_id) {
                $max_id = $id;
            }
        }
        
        return $max_id + 1;
    }
    
    /**
     * Generate secure random API key
     *
     * @return string API key
     */
    private function generate_secure_key(): string {
        if (function_exists('wp_generate_password')) {
            return wp_generate_password(32, false);
        }
        
        return bin2hex(random_bytes(16));
    }
    
    /**
     * Encrypt API key for storage
     *
     * @param string $key API key
     * @return string Encrypted key
     */
    private function encrypt_api_key(string $key): string {
        if (function_exists('openssl_encrypt') && defined('AUTH_SALT')) {
            $method = 'AES-256-CBC';
            $iv_length = openssl_cipher_iv_length($method);
            $iv = openssl_random_pseudo_bytes($iv_length);
            $encrypted = openssl_encrypt($key, $method, AUTH_SALT, 0, $iv);
            return base64_encode($encrypted . '::' . $iv);
        }
        
        // Fallback to base64 encoding if OpenSSL not available
        return base64_encode($key);
    }
    
    /**
     * Decrypt API key from storage
     *
     * @param string $encrypted_key Encrypted key
     * @return string Decrypted key
     */
    public function decrypt_api_key(string $encrypted_key): string {
        if (empty($encrypted_key)) {
            return '';
        }
        
        if (function_exists('openssl_decrypt') && defined('AUTH_SALT')) {
            $data = base64_decode($encrypted_key, true);
            if ($data === false) {
                return '';
            }
            
            $parts = explode('::', $data, 2);
            if (count($parts) !== 2) {
                return '';
            }
            
            $method = 'AES-256-CBC';
            $decrypted = openssl_decrypt($parts[0], $method, AUTH_SALT, 0, $parts[1]);
            return $decrypted !== false ? $decrypted : '';
        }
        
        // Fallback to base64 decoding
        $decoded = base64_decode($encrypted_key, true);
        return $decoded !== false ? $decoded : '';
    }
}

