# Atal Used Yachts Sync

WordPress plugin to sync used yachts from Atal Master system using ACF (Advanced Custom Fields).

## Requirements

- WordPress 5.8+
- PHP 8.0+
- Advanced Custom Fields Pro

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings → Used Yachts Sync
4. Enter your API key from the Master system
5. Configure the default language code

## Configuration

### WordPress Side

1. **Install ACF Pro** - This plugin requires ACF Pro to be installed and activated
2. **Configure API Key** - Go to Settings → Used Yachts Sync and enter the API key
3. **Set Default Language** - Enter the language code for default content (e.g., 'en', 'de', 'sl')

### Master System Side

Configure the following endpoints in your Master system sync settings:

- **Sync Endpoint**: `https://your-site.com/wp-json/atal-used-yachts/v1/sync`
- **Config Endpoint**: `https://your-site.com/wp-json/atal-used-yachts/v1/config`

## Features

- **Dynamic ACF Fields**: Field groups are automatically created based on Master configuration
- **Multilingual Support**: Compatible with WPML and Polylang
- **Media Sync**: Automatically downloads and attaches images
- **Taxonomies**: Creates Brand and Model taxonomies
- **Incremental Sync**: Updates existing yachts, creates new ones

## API Endpoints

### POST `/wp-json/atal-used-yachts/v1/sync`

Receives yacht data from Master system.

**Headers:**
- `X-API-Key`: Your API key

**Body:**
```json
[
  {
    "slug": "yacht-slug",
    "name": {"en": "Yacht Name", "de": "Yacht Name DE"},
    "state": "published",
    "brand": "Brand Name",
    "model": "Model Name",
    "custom_fields": {
      "field_key": "value"
    },
    "media": {
      "cover_image": {"url": "https://..."},
      "gallery_exterior": [
        {"url": "https://..."}
      ]
    }
  }
]
```

### POST `/wp-json/atal-used-yachts/v1/config`

Receives field configuration from Master system.

**Headers:**
- `X-API-Key`: Your API key

**Body:**
```json
[
  {
    "field_key": "engine_hours",
    "field_type": "number",
    "label": "Engine Hours",
    "group": "Technical Information",
    "is_required": false,
    "is_multilingual": false
  }
]
```

## Changelog

### 1.0.0
- Initial release
- ACF field groups
- REST API endpoints
- Media import
- Taxonomy support
