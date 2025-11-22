<?php

namespace App\Filament\Pages;

use App\Models\FormFieldConfiguration;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Filament\Notifications\Notification;

class ManageUsedYachtFields extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'Used Yacht Fields';

    protected static ?string $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.manage-used-yacht-fields';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'fields' => FormFieldConfiguration::forUsedYachts()->ordered()->get()->toArray(),
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
                                'image' => 'Image',
                                'gallery' => 'Gallery',
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
                        Forms\Components\Repeater::make('options')
                            ->label('Select Options')
                            ->schema([
                                Forms\Components\TextInput::make('value')
                                    ->required(),
                                Forms\Components\TextInput::make('label')
                                    ->required(),
                            ])
                            ->visible(fn(Forms\Get $get) => $get('field_type') === 'select')
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

        // Delete existing fields for used yachts
        FormFieldConfiguration::forUsedYachts()->delete();

        // Create new fields
        foreach ($data['fields'] as $index => $field) {
            FormFieldConfiguration::create([
                'entity_type' => 'used_yacht',
                'group' => $field['group'] ?? null,
                'field_key' => $field['field_key'],
                'field_type' => $field['field_type'],
                'label' => $field['label'],
                'is_required' => $field['is_required'] ?? false,
                'is_multilingual' => $field['is_multilingual'] ?? false,
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
