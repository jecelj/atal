<?php

namespace App\Filament\Pages;

use App\Settings\OpenAiSettings;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Pages\SettingsPage;

class ManageExternalApiSettings extends SettingsPage
{
    use \BezhanSalleh\FilamentShield\Traits\HasPageShield;

    protected static ?string $navigationIcon = 'heroicon-o-cog'; // Changed from heroicon-o-cloud

    protected static string $settings = OpenAiSettings::class;

    protected static ?string $navigationGroup = 'Configuration';

    protected static ?string $title = 'External APIs';

    protected static ?string $navigationLabel = 'External APIs';

    protected static ?int $navigationSort = 5;

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('OpenAI Configuration')
                    ->description('Configure your OpenAI API credentials and settings.')
                    ->schema([
                        Forms\Components\TextInput::make('openai_secret')
                            ->label('OpenAI API Key')
                            ->password()
                            ->revealable()
                            ->required()
                            ->helperText('Your OpenAI API secret key. Get it from https://platform.openai.com/api-keys'),
                        Forms\Components\Textarea::make('openai_context')
                            ->label('Translation Context')
                            ->rows(5)
                            ->required()
                            ->default('You are a professional translator. Translate the given text accurately while maintaining the tone and context.')
                            ->helperText('System prompt that guides the AI on how to translate. This helps maintain consistency and quality.'),
                        Forms\Components\Textarea::make('openai_prompt')
                            ->label('OpenAI Media Prompt')
                            ->rows(20)
                            ->helperText('System prompt for the Media classification and selection. Input: BRAND, MODEL, MEDIA (json).'),
                        Forms\Components\Textarea::make('openai_prompt_no_images')
                            ->label('OpenAI Yacht Data Extractor')
                            ->rows(20)
                            ->helperText('System prompt for extracting specifications. Input: BRAND, MODEL, RAW_HTML. Do not ask for translations here.'),
                        Forms\Components\Textarea::make('openai_translation_prompt')
                            ->label('OpenAI Translation Prompt (Input -> Output)')
                            ->rows(15)
                            ->helperText('System prompt for translating extracted JSON. Input: JSON (English keys), LANGUAGES (List). Rules: Technical terms, No literal translation.'),
                        Forms\Components\Textarea::make('adventure_boat_prompt')
                            ->label('Adventure Boat Used Yacht OpenAI Prompt')
                            ->rows(15)
                            ->helperText('description: adventurebuat used yacht openAi'),
                        Forms\Components\Textarea::make('yootheme_falang_prompt')
                            ->label('Yootheme Falang Translation Prompt')
                            ->rows(15)
                            ->helperText('System prompt for Yootheme/Falang translator. Use "You are a professional translator..."'),
                    ])
                    ->collapsible(),

                Forms\Components\Section::make('ScrapingBee Configuration')
                    ->description('Configure ScrapingBee for fallback scraping when Browserless returns incomplete media.')
                    ->schema([
                        Forms\Components\Toggle::make('scrapingbee_enabled')
                            ->label('Enable ScrapingBee')
                            ->helperText('When enabled, ScrapingBee can be used as a fallback scraper in the AI import flow.'),
                        Forms\Components\TextInput::make('scrapingbee_api_key')
                            ->label('ScrapingBee API Key')
                            ->password()
                            ->revealable()
                            ->helperText('API key from scrapingbee.com'),
                        Forms\Components\Select::make('scrapingbee_strategy')
                            ->label('Scraping Strategy')
                            ->options([
                                'fallback' => 'Fallback when media is incomplete',
                                'domain_only' => 'Only for listed domains',
                                'always' => 'Always use ScrapingBee',
                            ])
                            ->default('fallback')
                            ->helperText('Fallback keeps Browserless as the primary scraper and uses ScrapingBee only when needed.'),
                        Forms\Components\TextInput::make('scrapingbee_domains')
                            ->label('Domain Allowlist')
                            ->helperText('Comma-separated domains for domain-only mode, e.g. example.com, yacht-builder.com'),
                        Forms\Components\Toggle::make('scrapingbee_premium_proxy')
                            ->label('Use Premium Proxy')
                            ->helperText('Useful for stricter websites, but it costs more ScrapingBee credits.'),
                        Forms\Components\TextInput::make('scrapingbee_wait')
                            ->label('Wait Time')
                            ->numeric()
                            ->suffix('ms')
                            ->default(3000)
                            ->helperText('Additional wait time for JavaScript-rendered galleries and lazy-loaded images.'),
                        Forms\Components\TextInput::make('scrapingbee_wait_browser')
                            ->label('Wait Browser Event')
                            ->default('networkidle2')
                            ->helperText('Default browser wait event for rendered pages.'),
                        Forms\Components\Textarea::make('scrapingbee_js_scenario')
                            ->label('JavaScript Scenario')
                            ->rows(8)
                            ->helperText('Optional ScrapingBee JS scenario JSON for scrolling, clicking galleries, or waiting for selectors.'),
                    ])
                    ->columns(2)
                    ->collapsible(),

                Forms\Components\Section::make('Browserless Configuration')
                    ->description('API Key for Browserless.io service (used for advanced scraping if enabled).')
                    ->schema([
                        Forms\Components\TextInput::make('browserless_api_key')
                            ->label('Browserless API Key')
                            ->password()
                            ->revealable()
                            ->helperText('API Key from browserless.io'),
                        Forms\Components\Textarea::make('browserless_script')
                            ->label('Scrape Script (Node.js)')
                            ->rows(15)
                            ->helperText('Javascript code for Browserless /function endpoint. Receives { page, context }.')
                            ->default("export default async function({ page }) {\n  await page.goto(context.url);\n  const content = await page.content();\n  return { content };\n};"),
                    ])
                    ->collapsible(),
            ]);
    }
}
