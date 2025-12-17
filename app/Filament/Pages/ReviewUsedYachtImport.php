<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Cache;
use App\Models\UsedYacht;
use App\Models\Brand;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class ReviewUsedYachtImport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static string $view = 'filament.pages.review-used-yacht-import';

    protected static bool $shouldRegisterNavigation = false;

    public ?array $data = [];
    public $importId;

    public function mount()
    {
        $this->importId = request()->query('import_id');

        if (!$this->importId) {
            abort(404, 'Import ID missing');
        }

        $cachedData = Cache::get('openai_import_used_' . $this->importId);

        if (!$cachedData) {
            Notification::make()->title('Import session expired')->danger()->send();
            return redirect()->route('filament.admin.resources.used-yachts.index');
        }

        Log::info('Review Page (Used): Cached Data loaded', ['keys' => array_keys($cachedData)]);

        // Prepare Images for Unified Manager
        // OpenAI Prompt returns: image_1, galerie (array)
        $allImages = [];

        // Image 1 -> Main/Cover
        $image1 = data_get($cachedData, 'image_1') ?? data_get($cachedData, 'custom_fields.image_1');
        if ($image1) {
            $allImages[] = [
                'url' => $image1,
                'category' => 'image_1',
                'original_category' => 'image_1'
            ];
            // Ensure array structure for binding
            data_set($cachedData, 'custom_fields.image_1', [$image1]); // Normalized field
        }

        // Gallery -> Gallery
        $gallery = data_get($cachedData, 'galerie') ?? data_get($cachedData, 'custom_fields.galerie') ?? [];
        if (is_array($gallery)) {
            $gallery = array_reverse($gallery); // Fix reverse order
        }

        foreach ($gallery as $url) {
            if (!empty($url)) {
                $allImages[] = [
                    'url' => $url,
                    'category' => 'galerie', // General gallery
                    'original_category' => 'galerie'
                ];
            }
        }

        data_set($cachedData, 'custom_fields.all_images', $allImages);

        // Ensure other fields are mapped correctly for the form
        // Title -> Name
        if (empty($cachedData['name']) && !empty($cachedData['title'])) {
            $cachedData['name'] = $cachedData['title'];
        }

        // Populate custom_fields from flat cachedData
        $fieldsToMap = [
            'price',
            'tax_price',
            'year',
            'length',
            'beam',
            'draft',
            'engines',
            'engine_hours',
            'fuel',
            'fuel_tank_capacity',
            'water_capacity',
            'berths',
            'cabins',
            'bathrooms',
            'max_persons',
            'short_description',
            'equipment_and_other_information',
            'pdf_b',
            'image_1',
            'galerie',
            'location'
        ];

        foreach ($fieldsToMap as $key) {
            if (isset($cachedData[$key]) && !isset($cachedData['custom_fields'][$key])) {
                $value = $cachedData[$key];
                data_set($cachedData, "custom_fields.{$key}", $value);
            }
        }

        $this->form->fill($cachedData);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Yacht Details')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Yacht Title')
                            ->required(),
                        Forms\Components\Select::make('brand_id')
                            ->label('Brand')
                            ->options(Brand::all()->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                        Forms\Components\TextInput::make('custom_fields.location')
                            ->label('Location (Raw)')
                            ->helperText('Will try to find/create Location entity on save'),
                        Forms\Components\TextInput::make('custom_fields.year')
                            ->label('Year')
                            ->numeric(),
                        Forms\Components\TextInput::make('custom_fields.price')
                            ->label('Price')
                            ->numeric(),
                        Forms\Components\Select::make('custom_fields.tax_price')
                            ->label('Tax Status')
                            ->options([
                                'vat_included' => 'VAT Included',
                                'vat_excluded' => 'VAT Excluded',
                            ]),
                    ])->columns(2),

                Forms\Components\Section::make('Dimensions & Specs')
                    ->schema([
                        Forms\Components\TextInput::make('custom_fields.length')->label('Length (m)')->numeric(),
                        Forms\Components\TextInput::make('custom_fields.beam')->label('Beam (m)')->numeric(),
                        Forms\Components\TextInput::make('custom_fields.draft')->label('Draft (m)')->numeric(),
                        Forms\Components\TextInput::make('custom_fields.weight')->label('Weight (kg)')->numeric(),

                        Forms\Components\TextInput::make('custom_fields.cabins')->label('Cabins')->numeric(),
                        Forms\Components\TextInput::make('custom_fields.berths')->label('Berths')->numeric(),
                        Forms\Components\TextInput::make('custom_fields.bathrooms')->label('Bathrooms')->numeric(),
                        Forms\Components\TextInput::make('custom_fields.max_persons')->label('Max Persons')->numeric(),

                        Forms\Components\TextInput::make('custom_fields.fuel_tank_capacity')->label('Fuel Tank (L)')->numeric(),
                        Forms\Components\TextInput::make('custom_fields.water_capacity')->label('Water Tank (L)')->numeric(),
                    ])->columns(3),

                Forms\Components\Section::make('Engine')
                    ->schema([
                        Forms\Components\TextInput::make('custom_fields.engines')->label('Engine Description'),
                        Forms\Components\TextInput::make('custom_fields.engine_hours')->label('Engine Hours')->numeric(),
                        Forms\Components\Select::make('custom_fields.fuel')
                            ->label('Fuel Type')
                            ->options([
                                'diesel' => 'Diesel',
                                'petrol' => 'Petrol',
                                'electric_hybrid' => 'Electric/Hybrid',
                                'no_engine' => 'No Engine',
                            ]),
                    ])->columns(2),

                Forms\Components\Section::make('Descriptions')
                    ->schema([
                        Forms\Components\Textarea::make('custom_fields.short_description')
                            ->label('Short Description')
                            ->rows(3),
                        \FilamentTiptapEditor\TiptapEditor::make('custom_fields.equipment_and_other_information')
                            ->label('Equipment & Information')
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Media')
                    ->schema([
                        Forms\Components\View::make('filament.components.lightbox'),
                        Forms\Components\ViewField::make('custom_fields.all_images')
                            ->view('filament.forms.components.unified-media-manager')
                            ->viewData([
                                'categories' => [
                                    'image_1' => 'Main Image',
                                    'galerie' => 'Gallery',
                                    'trash' => 'Trash'
                                ],
                                'buttons' => ['image_1']
                            ])
                            ->columnSpanFull(),

                        // Hidden fields to maintain binding for manual overrides if needed
                        Forms\Components\Hidden::make('custom_fields.image_1'),

                        Forms\Components\TextInput::make('custom_fields.pdf_b')
                            ->label('PDF Brochure URL')
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Debug API Info')
                    ->schema([
                        Forms\Components\Textarea::make('custom_fields._debug_prompt')
                            ->label('OpenAI Prompt')
                            ->rows(5)
                            ->readonly(),
                        Forms\Components\Textarea::make('custom_fields._debug_response')
                            ->label('OpenAI Response')
                            ->rows(10)
                            ->readonly(),
                    ])
                    ->collapsed(),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Import Used Yacht')
                ->action('save'),
            Action::make('cancel')
                ->label('Cancel')
                ->color('gray')
                ->url(route('filament.admin.resources.used-yachts.index')),
        ];
    }

    public function save()
    {
        $data = $this->form->getState();
        set_time_limit(600);
        \Illuminate\Support\Facades\DB::beginTransaction();

        try {
            // 1. Process Location
            $locationId = null;
            if (!empty($data['custom_fields']['location'])) {
                $locName = $data['custom_fields']['location'];
                $location = \App\Models\Location::firstOrCreate(
                    ['name' => $locName],
                    ['slug' => \Illuminate\Support\Str::slug($locName)]
                );
                $locationId = $location->id;
            }

            // 2. Prepare Name (Multilingual)
            $activeLanguages = \App\Models\Language::pluck('code')->toArray();
            $nameMultilingual = array_fill_keys($activeLanguages, $data['name']);

            // 3. Slug
            $slug = \Illuminate\Support\Str::slug($data['name']);
            $originalSlug = $slug;
            $count = 1;
            while (UsedYacht::where('slug', $slug)->exists()) {
                $slug = $originalSlug . '-' . $count++;
            }

            // 4. Custom Fields Cleaning
            $customFields = $data['custom_fields'] ?? [];

            // Wrap Text Fields in Multilingual Array (All Active Languages)
            // Use the $activeLanguages array defined above (ensure it's available)
            // If defined inside standard try block, we can reuse it.
            // Check lines 268-269 for definition.
            foreach (['short_description', 'equipment_and_other_information'] as $key) {
                if (!empty($customFields[$key]) && !is_array($customFields[$key])) {
                    $customFields[$key] = array_fill_keys($activeLanguages, $customFields[$key]);
                }
            }
            // Remove huge debug info and temp images from DB storage if desired
            // unset($customFields['all_images']); // Keep for reference or remove? usually remove.

            // Map flat fields to custom_fields structure expected by UsedYacht
            // User prompt extracted 'price' as integer.
            // UsedYacht table columns vs custom_fields? 
            // In UsedYachtResource, 'price' and 'year' are shown in table as 'custom_fields.price'. 
            // So they are JSON fields. Good.

            // 5. Create Record
            $yacht = UsedYacht::create([
                'state' => 'draft',
                'name' => $nameMultilingual,
                'slug' => $slug,
                'brand_id' => $data['brand_id'],
                'location_id' => $locationId,
                'custom_fields' => $customFields,
            ]);

            \Illuminate\Support\Facades\DB::commit();
            Notification::make()->title('Used Yacht Imported Successfully')->success()->send();

            // 6. Media Attachment
            try {
                // Cover Image
                // In unified manager, we might have 'cover_image' category or field.
                $allImages = $customFields['all_images'] ?? [];

                // Collect URLs
                $coverUrls = [];
                $galleryUrls = [];

                foreach ($allImages as $img) {
                    $cat = $img['category'] ?? 'trash';
                    $url = $img['url'];

                    if ($cat === 'image_1') {
                        $coverUrls[] = $url;
                    } elseif ($cat === 'galerie') {
                        $galleryUrls[] = $url;
                    }
                }

                // Fallback: if no cover, use first gallery or explicit cover field
                if (empty($coverUrls) && !empty($customFields['image_1'])) {
                    $coverUrls[] = $customFields['image_1'];
                }

                // Process Attachments
                foreach ($coverUrls as $url) {
                    if (filter_var($url, FILTER_VALIDATE_URL)) {
                        $yacht->addMediaFromUrl($url)->toMediaCollection('image_1');
                    }
                }

                foreach ($galleryUrls as $url) {
                    if (filter_var($url, FILTER_VALIDATE_URL)) {
                        $yacht->addMediaFromUrl($url)->toMediaCollection('galerie');
                    }
                }

                if (!empty($customFields['pdf_b']) && filter_var($customFields['pdf_b'], FILTER_VALIDATE_URL)) {
                    $yacht->addMediaFromUrl($customFields['pdf_b'])->toMediaCollection('pdf_b');
                }

            } catch (\Exception $e) {
                Log::warning('Media Error: ' . $e->getMessage());
                Notification::make()->title('Media Import Failed')->body($e->getMessage())->warning()->send();
            }

            return redirect()->route('filament.admin.resources.used-yachts.index');

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            Log::error('Import Save Error: ' . $e->getMessage());
            Notification::make()->title('Import Failed')->body($e->getMessage())->danger()->send();
        }
    }
}
