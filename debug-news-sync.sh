#!/bin/bash

# Debug script for News sync troubleshooting

echo "=== News Sync Debug ==="
echo ""

echo "1. Checking if News table exists..."
php artisan tinker --execute="echo \\App\\Models\\News::count() . ' news items found';"

echo ""
echo "2. Checking News with sync sites..."
php artisan tinker --execute="
\$news = \\App\\Models\\News::with('syncSites')->get();
foreach (\$news as \$item) {
    echo 'News: ' . \$item->slug . ' - Synced to: ' . \$item->syncSites->pluck('name')->join(', ') . PHP_EOL;
}
"

echo ""
echo "3. Testing sync for first news item..."
php artisan tinker --execute="
\$news = \\App\\Models\\News::first();
if (\$news) {
    \$service = app(\\App\\Services\\WordPressSyncService::class);
    \$results = \$service->syncNews(\$news);
    print_r(\$results);
} else {
    echo 'No news items found';
}
"

echo ""
echo "=== End Debug ==="
