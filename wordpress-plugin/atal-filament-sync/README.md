# Atal Filament Sync

WordPress plugin for syncing yachts from Filament Admin to WordPress.

## Features

- Automatic sync of New and Used Yachts
- Polylang multilingual support (EN, SL)
- SCF (Secure Custom Fields) integration
- Automatic image downloading
- Brand and Model taxonomy management
- REST API endpoint for remote sync

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings → Atal Sync
4. Configure API URL and API Key
5. Click "Sync Now" to test

## Configuration

### API URL
The base URL of your Filament API, e.g.:
```
https://yachts.atal.at/api/sync
```

### API Key
The API key must match the one configured in your Filament `.env` file:
```
WORDPRESS_SYNC_API_KEY=your-secret-key-here
```

## Requirements

- WordPress 5.0+
- PHP 8.0+
- Polylang plugin (for multilingual support)
- SCF (Secure Custom Fields) plugin

## Usage

### Manual Sync
Go to Settings → Atal Sync and click "Sync Now"

### Automatic Sync (via Filament)
The Filament admin panel can trigger sync via REST API:
```
POST https://atal.at/wp-json/atal-sync/v1/import
Headers:
  X-API-Key: your-secret-key-here
```

## Support

For issues or questions, contact Atal Yachts support.
