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
                        Forms\Components\Select::make('openai_model')
                            ->label('OpenAI Model')
                            ->options([
                                'gpt-4o-mini-2024-07-18' => 'GPT-4o Mini (2024-07-18) - Recommended',
                                'gpt-4o-mini' => 'GPT-4o Mini (Latest)',
                                'gpt-4o' => 'GPT-4o (Latest)',
                                'gpt-4o-2024-11-20' => 'GPT-4o (2024-11-20)',
                                'gpt-4-turbo' => 'GPT-4 Turbo (Latest)',
                                'gpt-4-turbo-2024-04-09' => 'GPT-4 Turbo (2024-04-09)',
                                'gpt-4' => 'GPT-4',
                                'gpt-3.5-turbo' => 'GPT-3.5 Turbo',
                            ])
                            ->default('gpt-4o-mini-2024-07-18')
                            ->required()
                            ->helperText('Select the OpenAI model to use for translations. GPT-4o Mini is recommended for cost-effectiveness.'),
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
                            ->helperText('System prompt for extracting specifications and translations. Input: BRAND, MODEL, LANGUAGES, RAW_HTML.'),
                    ])
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
