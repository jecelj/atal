<?php

namespace App\Filament\Pages;

use App\Settings\OpenAiSettings;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Pages\SettingsPage;

class ManageOpenAiSettings extends SettingsPage
{
    protected static ?string $navigationIcon = 'heroicon-o-language';

    protected static string $settings = OpenAiSettings::class;

    protected static ?string $navigationGroup = 'Configuration';

    protected static ?string $title = 'OpenAI Translation';

    protected static ?string $navigationLabel = 'OpenAI Translation';

    protected static ?int $navigationSort = 5;

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('OpenAI API Configuration')
                    ->description('Configure your OpenAI API credentials and settings for automatic translations.')
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
                    ])
                    ->columns(1),
            ]);
    }
}
