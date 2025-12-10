<?php

namespace App\Filament\Pages;

use App\Models\FormFieldConfiguration;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Filament\Notifications\Notification;

class ManageNewYachtFields extends Page
{
    use \BezhanSalleh\FilamentShield\Traits\HasPageShield;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'New Yacht Fields';

    protected static ?string $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.manage-new-yacht-fields';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'fields' => FormFieldConfiguration::forNewYachts()->ordered()->get()->toArray(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Repeater::make('fields')
                    ->schema([
                        Forms\Components\TextInput::make('field_key')
                            ->label('Field Key')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->helperText('Unique identifier for this field (e.g., engine_hours, condition)'),
                        Forms\Components\TextInput::make('group')
                            ->label('Group')
                            ->helperText('Section name (e.g., Basic Information, Technical Information, Media)')
                            ->placeholder('Additional Information'),
                        Forms\Components\Select::make('field_type')
                            ->label('Field Type')
                            ->options([
                                'text' => 'Text',
                                'textarea' => 'Textarea',
                                'richtext' => 'Rich Text',
                                'number' => 'Number',
                                'date' => 'Date',
                                'select' => 'Select',
                                'checkbox' => 'Checkbox',
                                'image' => 'Image',
                                'gallery' => 'Gallery',
                                'repeater' => 'Repeater',
                                'brand' => 'Brand (Relationship)',
                                'model' => 'Model (Relationship)',
                                'file' => 'File Upload',
                            ])
                            ->required()
                            ->live(),
                        Forms\Components\TextInput::make('label')
                            ->label('Label')
                            ->required(),
                        Forms\Components\Toggle::make('is_required')
                            ->label('Required')
                            ->default(false),
                        Forms\Components\Toggle::make('is_multilingual')
                            ->label('Multilingual')
                            ->helperText('Enable translations for this field')
                            ->default(false),
                        Forms\Components\Toggle::make('sync_as_taxonomy')
                            ->label('Sync as Taxonomy')
                            ->helperText('Sync options as WordPress Taxonomy terms (enables translation via Falang)')
                            ->default(false)
                            ->visible(fn(Forms\Get $get) => in_array($get('field_type'), ['select', 'checkbox'])),
                        Forms\Components\Repeater::make('options')
                            ->label('Select Options')
                            ->schema(function () {
                                $schema = [
                                    Forms\Components\TextInput::make('value')
                                        ->required(),
                                    Forms\Components\TextInput::make('label')
                                        ->label('Label (Default)')
                                        ->required(),
                                ];

                                // Add fields for other languages
                                try {
                                    $languages = \App\Models\Language::where('is_default', false)->get();
                                    foreach ($languages as $language) {
                                        $schema[] = Forms\Components\TextInput::make('label_' . $language->code)
                                            ->label("Label ({$language->name})");
                                    }
                                } catch (\Exception $e) {
                                    // Fallback if migration/table issue
                                }

                                return $schema;
                            })
                            ->visible(fn(Forms\Get $get) => in_array($get('field_type'), ['select', 'checkbox']))
                            ->columns(2),
                        Forms\Components\TagsInput::make('validation_rules')
                            ->label('Validation Rules')
                            ->helperText('e.g., max:255, min:0, email'),
                    ])
                    ->reorderable()
                    ->collapsible()
                    ->itemLabel(fn(array $state): ?string => $state['label'] ?? null)
                    ->addActionLabel('Add Field')
                    ->defaultItems(0)
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        // Delete existing fields for new yachts
        FormFieldConfiguration::forNewYachts()->delete();

        // Create new fields
        foreach ($data['fields'] as $index => $field) {
            FormFieldConfiguration::create([
                'entity_type' => 'new_yacht',
                'group' => $field['group'] ?? null,
                'field_key' => $field['field_key'],
                'field_type' => $field['field_type'],
                'label' => $field['label'],
                'is_required' => $field['is_required'] ?? false,
                'is_multilingual' => $field['is_multilingual'] ?? false,
                'sync_as_taxonomy' => $field['sync_as_taxonomy'] ?? false,
                'order' => $index,
                'options' => $field['options'] ?? null,
                'validation_rules' => $field['validation_rules'] ?? null,
            ]);
        }

        Notification::make()
            ->title('Saved successfully')
            ->success()
            ->send();
    }
}
