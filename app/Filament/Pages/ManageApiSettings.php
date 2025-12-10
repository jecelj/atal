<?php

namespace App\Filament\Pages;

use App\Settings\ApiSettings;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Pages\Page;

class ManageApiSettings extends Page
{
    use \BezhanSalleh\FilamentShield\Traits\HasPageShield;

    protected static ?string $navigationIcon = 'heroicon-o-cog';

    protected static string $settings = ApiSettings::class;

    protected static ?string $navigationGroup = 'Configuration';

    protected static ?string $title = 'API Settings';

    protected static ?int $navigationSort = 3;

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('WordPress Sync API')
                    ->description('Configure API key for WordPress synchronization endpoints.')
                    ->schema([
                        Forms\Components\TextInput::make('sync_api_key')
                            ->label('API Key')
                            ->required()
                            ->maxLength(255)
                            ->helperText('This key is used to authenticate requests from WordPress sites.')
                            ->suffixAction(
                                Forms\Components\Actions\Action::make('generate')
                                    ->icon('heroicon-m-arrow-path')
                                    ->action(function (Forms\Set $set) {
                                        $set('sync_api_key', bin2hex(random_bytes(32)));
                                    })
                            ),

                        Forms\Components\Placeholder::make('api_endpoints')
                            ->label('API Endpoints')
                            ->content(function () {
                                $baseUrl = config('app.url');
                                return "
                                    **Yachts**: {$baseUrl}/api/sync/yachts
                                    **Brands**: {$baseUrl}/api/sync/brands
                                    **Models**: {$baseUrl}/api/sync/models
                                    **Fields**: {$baseUrl}/api/sync/fields
                                ";
                            }),

                        Forms\Components\Placeholder::make('usage_example')
                            ->label('Usage Example')
                            ->content(function () {
                                $baseUrl = config('app.url');
                                return "
                                    **Query Parameter**:
                                    `{$baseUrl}/api/sync/yachts?api_key=YOUR_API_KEY`
                                    
                                    **Bearer Token** (recommended):
                                    `curl -H \"Authorization: Bearer YOUR_API_KEY\" {$baseUrl}/api/sync/yachts`
                                ";
                            }),
                    ]),
            ]);
    }
}
