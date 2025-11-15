# Guest Posts WordPress Plugin

A WordPress plugin that automatically cross-posts blog articles across a network of WordPress sites with keyword-based filtering.

## Features

- **Automatic Cross-Posting**: Posts are automatically sent to all configured network sites when published
- **Receiver-Side Keyword Filtering**: Each site filters incoming posts using OR logic - any keyword match creates a cross-post
- **Elementor Support**: Automatically extracts text content from Elementor posts
- **Post Updates**: Cross-posts are automatically updated when the original post is modified
- **SEO-Friendly**: Includes canonical links pointing to the original post
- **Manual Send**: Option to manually send posts to the network from the post editor
- **Exclusions**: Exclude posts by category or tag from being cross-posted
- **Secure API**: Custom API key authentication for secure communication

## Requirements

- WordPress 5.0+
- PHP 7.4+
- OpenSSL extension (recommended for API key encryption)

## Installation

1. Upload the plugin files to `/wp-content/plugins/guest-posts/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings → Guest Posts to configure

## Configuration

### Setting Up Your Network

1. **Get Your API Key**: 
   - Go to Settings → Guest Posts
   - Copy your site's API key (or generate one if needed)

2. **Add Network Sites**:
   - Enter the site URL
   - Paste the API key from that site
   - Optionally add a name for easy identification
   - Click "Add Site"

3. **Configure Keywords** (on receiving sites):
   - Click "Keywords" next to each site
   - Enter keywords (one per line or comma-separated)
   - Posts matching any keyword will be cross-posted (OR logic)

4. **Set Exclusions** (on publishing sites):
   - Select categories or tags to exclude
   - Posts in excluded categories/tags won't be sent

## How It Works

### Publishing Site
- When a post is published, it's automatically sent to all configured network sites
- The plugin extracts content (handling Elementor if present)
- Post data includes: title, excerpt, content, tags, categories, featured image URL, and permalink

### Receiving Site
- Receives post via REST API endpoint
- Checks post against configured keywords (OR logic)
- If match: Creates cross-post with excerpt and "Read more" link
- If no match: Silently discards
- Adds canonical link to original post

### Post Updates
- When original post is updated, cross-posts are automatically updated
- Canonical links are preserved

## Security

- API keys are encrypted in the database
- REST API requires valid API key authentication
- All inputs are sanitized and outputs are escaped
- Nonce verification for all admin forms

## Development

### File Structure
```
guest-posts/
├── guest-posts.php              (main plugin file)
├── includes/
│   ├── class-network-manager.php
│   ├── class-post-receiver.php
│   ├── class-keyword-filter.php
│   ├── class-elementor-handler.php
│   ├── class-api-client.php
│   └── class-settings-manager.php
├── admin/
│   ├── class-admin-settings.php
│   └── views/
│       └── settings-page.php
└── assets/
    ├── css/
    │   └── admin.css
    └── js/
        └── admin.js
```

### Coding Standards
- PHP 8.4 with strict types
- WordPress coding standards
- OOP architecture with namespaced classes
- WordPress hooks and filters

## License

GPL v2 or later

## Support

For issues and feature requests, please contact the plugin author.

