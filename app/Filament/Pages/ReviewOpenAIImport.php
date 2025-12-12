<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Cache;
use App\Models\NewYacht;
use App\Models\Brand;
use Filament\Notifications\Notification;
use Filament\Forms\Components\Wizard;
use Illuminate\Support\Facades\Log;

class ReviewOpenAIImport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static string $view = 'filament.pages.review-open-a-i-import';

    protected static bool $shouldRegisterNavigation = false;

    public ?array $data = [];
    public $importId;

    public function mount()
    {
        $this->importId = request()->query('import_id');

        if (!$this->importId) {
            abort(404, 'Import ID missing');
        }

        $cachedData = Cache::get('openai_import_' . $this->importId);

        if (!$cachedData) {
            Notification::make()->title('Import session expired')->danger()->send();
            return redirect()->route('filament.admin.resources.new-yachts.index');
        }

        Log::info('Review Page: Cached Data loaded', ['keys' => array_keys($cachedData)]);

        // Unified Media Manager Preparation
        // Merge all legacy image fields into one 'all_images' collection for the UI
        // CRITICAL FIX: Read from $cachedData directly because form schema no longer has these fields,
        // so form->getState() would return null/empty for them.
        $allImages = [];

        // Helper to safely get nested keys from cachedData (which is just an array)
        $get = fn($key) => data_get($cachedData, $key, []);

        // Define categories with implicit priority (Specific -> Generic)
        // We process them in this order. If an image appears in multiple (e.g. Interior AND Exterior),
        // the FIRST one encountered wins (deduplication logic).
        // So we put specific ones first.
        $categories = [
            'gallery_layout' => $get('custom_fields.gallery_layout_urls'),
            'gallery_cockpit' => $get('custom_fields.gallery_cockpit_urls'),
            'gallery_interior' => array_merge(
                $get('custom_fields.gallery_interior_urls') ?? [],
                $get('custom_fields.gallery_interrior_urls') ?? []
            ),
            'gallery_exterior' => $get('custom_fields.gallery_exterior_urls'), // Bucket often used as "all images"
        ];

        foreach ($categories as $cat => $urls) {
            if (is_array($urls)) {
                foreach ($urls as $url) {
                    if (!empty($url)) {
                        // Prevent duplicates by URL
                        $exists = false;
                        foreach ($allImages as $img) {
                            if ($img['url'] === $url) {
                                $exists = true;
                                break;
                            }
                        }
                        if (!$exists) {
                            $allImages[] = [
                                'url' => $url,
                                'category' => $cat,
                                'original_category' => $cat
                            ];
                        }
                    }
                }
            }
        }

        // Log counts to debug "All Exterior" issue
        Log::info('Review Page: Source Counts', [
            'layout' => count($categories['gallery_layout'] ?? []),
            'cockpit' => count($categories['gallery_cockpit'] ?? []),
            'interior' => count($categories['gallery_interior'] ?? []),
            'exterior' => count($categories['gallery_exterior'] ?? []),
        ]);

        // Push this back into the data structure
        // We must update the cachedData array directly and then fill the form ONCE.

        // SORT images by category priority (Exterior first for display)
        // Order: Exterior, Interior, Cockpit, Layout, Trash
        $order = [
            'gallery_exterior' => 1,
            'gallery_interior' => 2,
            'gallery_cockpit' => 3,
            'gallery_layout' => 4,
            'trash' => 99
        ];

        usort($allImages, function ($a, $b) use ($order) {
            $pA = $order[$a['category']] ?? 50;
            $pB = $order[$b['category']] ?? 50;
            return $pA <=> $pB;
        });

        data_set($cachedData, 'custom_fields.all_images', $allImages);

        // Ensure cover/grid keys exist even if empty (for binding)
        if (!data_get($cachedData, 'custom_fields.cover_image_url'))
            data_set($cachedData, 'custom_fields.cover_image_url', []); // Force array

        if (!data_get($cachedData, 'custom_fields.grid_image_url'))
            data_set($cachedData, 'custom_fields.grid_image_url', []); // Force array

        if (!data_get($cachedData, 'custom_fields.grid_image_hover_url'))
            data_set($cachedData, 'custom_fields.grid_image_hover_url', []); // Force array

        if (!data_get($cachedData, 'custom_fields.grid_image_hover_url'))
            data_set($cachedData, 'custom_fields.grid_image_hover_url', []); // Force array

        Log::info('Review Page: Prepared all_images', [
            'count' => count($allImages),
            'sample' => array_slice($allImages, 0, 5), // Log first 5 to check categories
            'cover' => data_get($cachedData, 'custom_fields.cover_image_url'),
            'grid' => data_get($cachedData, 'custom_fields.grid_image_url'),
        ]);

        $this->form->fill($cachedData);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Yacht Details')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Yacht Name')
                            ->required(),
                        Forms\Components\Select::make('brand_id')
                            ->label('Brand')
                            ->options(Brand::all()->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                        Forms\Components\Select::make('yacht_model_id')
                            ->label('Model')
                            // Ideally dynamic based on brand, but for review page we can list all or rely on pre-filled
                            ->options(\App\Models\YachtModel::all()->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                        Forms\Components\Select::make('custom_fields.type')
                            ->label('Yacht Type')
                            ->options([
                                'yacht' => 'Yacht',
                                'daly_crouser' => 'Daily Crouser',
                                'sport_boat' => 'Sport Boat',
                                'sailling_boat' => 'Sailling boat',
                            ]),

                    ])->columns(2),

                Forms\Components\Section::make('Extracted Data')
                    ->description('Review and edit the data extracted by OpenAI')
                    ->schema(function () {
                        $languages = \App\Models\Language::orderBy('is_default', 'desc')->get();

                        return [
                            Forms\Components\Tabs::make('Short Description (Sub Title)')
                                ->columnSpanFull()
                                ->tabs(function () use ($languages) {
                                    $tabs = [];
                                    foreach ($languages as $language) {
                                        $tabs[] = Forms\Components\Tabs\Tab::make($language->name)
                                            ->schema([
                                                \FilamentTiptapEditor\TiptapEditor::make("custom_fields.sub_title.{$language->code}")
                                                    ->label('Sub Title')
                                                    ->columnSpanFull(),
                                            ]);
                                    }
                                    return $tabs;
                                }),

                            Forms\Components\Tabs::make('Full Description')
                                ->columnSpanFull()
                                ->tabs(function () use ($languages) {
                                    $tabs = [];
                                    foreach ($languages as $language) {
                                        $tabs[] = Forms\Components\Tabs\Tab::make($language->name)
                                            ->schema([
                                                \FilamentTiptapEditor\TiptapEditor::make("custom_fields.full_description.{$language->code}")
                                                    ->label('Full Description')
                                                    ->output(\FilamentTiptapEditor\Enums\TiptapOutput::Html)
                                                    ->columnSpanFull(),
                                            ]);
                                    }
                                    return $tabs;
                                }),

                            Forms\Components\Tabs::make('Technical Specifications')
                                ->columnSpanFull()
                                ->tabs(function () use ($languages) {
                                    $tabs = [];
                                    foreach ($languages as $language) {
                                        $tabs[] = Forms\Components\Tabs\Tab::make($language->name)
                                            ->schema([
                                                \FilamentTiptapEditor\TiptapEditor::make("custom_fields.specifications.{$language->code}")
                                                    ->label('Specifications')
                                                    ->output(\FilamentTiptapEditor\Enums\TiptapOutput::Html)
                                                    ->columnSpanFull(),
                                            ]);
                                    }
                                    return $tabs;
                                }),
                        ];
                    }),

                Forms\Components\Section::make('Specifications')
                    ->schema([
                        Forms\Components\TextInput::make('custom_fields.length')
                            ->label('Length')
                            ->numeric(),
                        Forms\Components\CheckboxList::make('custom_fields.engine_type')
                            ->label('Engine Type')
                            ->options([
                                'petrol' => 'Petrol',
                                'disel' => 'Diesel',
                                'hybrid' => 'Hybrid',
                                'electric' => 'Electric'
                            ])
                            ->columns(2),
                        Forms\Components\Select::make('custom_fields.engine_location')
                            ->label('Engine Location')
                            ->options([
                                'internal' => 'Internal',
                                'external' => 'External'
                            ]),
                        Forms\Components\Select::make('custom_fields.no_cabins')
                            ->label('Number of Cabins')
                            ->options([
                                '0' => '0',
                                '1' => '1',
                                '2' => '2',
                                '3' => '3',
                                '4' => '4 or more'
                            ]),
                        Forms\Components\Select::make('custom_fields.number_of_bathrooms')
                            ->label('Number of Bathrooms')
                            ->options([
                                '0' => '0',
                                '1' => '1',
                                '2' => '2',
                                '3' => '3',
                                '4' => '4 or more'
                            ]),
                    ])->columns(2),

                Forms\Components\Section::make('Media & Documents')
                    ->description('Review images and documents. Use the Media Manager to categorize images and select main visuals.')
                    ->schema([
                        Forms\Components\View::make('filament.components.lightbox'),

                        // Unified Media Manager (Blade View)
                        Forms\Components\ViewField::make('custom_fields.all_images')
                            ->view('filament.forms.components.unified-media-manager')
                            ->columnSpanFull(),

                        // Hidden fields to maintain state for bindings used in the Blade view
                        Forms\Components\Hidden::make('custom_fields.cover_image_url'),
                        Forms\Components\Hidden::make('custom_fields.grid_image_url'),
                        Forms\Components\Hidden::make('custom_fields.grid_image_hover_url'),

                        Forms\Components\TextInput::make('custom_fields.pdf_brochure')
                            ->label('PDF Brochure URL')
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Video Gallery')
                    ->schema([
                        Forms\Components\Placeholder::make('video_preview')
                            ->content(function (Forms\Get $get) {
                                $videos = $get('custom_fields.video_url') ?? [];
                                if (empty($videos))
                                    return 'No videos found';

                                $html = '<div style="display: flex; gap: 1rem; flex-wrap: wrap;">';
                                foreach ($videos as $video) {
                                    $url = $video['url'] ?? '';
                                    if ($url) {
                                        if (preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/', $url, $matches)) {
                                            $embedUrl = "https://www.youtube.com/embed/" . $matches[1];
                                            $html .= "<iframe width='300' height='200' src='{$embedUrl}' frameborder='0' allowfullscreen></iframe>";
                                        }
                                    }
                                }
                                $html .= '</div>';
                                return new \Illuminate\Support\HtmlString($html);
                            })
                            ->columnSpanFull(),

                        Forms\Components\Repeater::make('custom_fields.video_url')
                            ->label('Video URLs')
                            ->schema([
                                Forms\Components\TextInput::make('url')->url()->required()
                            ])
                            ->addActionLabel('Add Video URL')
                    ])
                    ->collapsible(),

                Forms\Components\Section::make('Debug API Info')
                    ->description('Raw Prompt and Response from OpenAI for debugging purposes.')
                    ->schema([
                        Forms\Components\Textarea::make('custom_fields._debug_prompt')
                            ->label('OpenAI Prompt (Input)')
                            ->rows(10)
                            ->readonly(),
                        Forms\Components\Textarea::make('custom_fields._debug_response')
                            ->label('OpenAI Response (Output)')
                            ->rows(20)
                            ->readonly(),
                    ])
                    ->collapsed(), // Collapsed by default
            ])
            ->statePath('data');
    }

    protected function getSingleImageSelectionField($name, $label, $sourceKey)
    {
        return Forms\Components\Radio::make($name)
            ->label($label) // Label might be empty if wrapped in fieldset with same label
            ->options(function (Forms\Get $get) use ($sourceKey) {
                // Get URLs from the shared source (e.g., Exterior Gallery)
                $urls = $get($sourceKey) ?? [];

                // Fallback mechanism if direct get fails
                if (empty($urls) && str_contains($sourceKey, '.')) {
                    $keys = explode('.', $sourceKey);
                    $state = $this->form->getState();
                    $temp = $state;
                    foreach ($keys as $key) {
                        if (isset($temp[$key])) {
                            $temp = $temp[$key];
                        } else {
                            $temp = [];
                            break;
                        }
                    }
                    $urls = $temp;
                }

                $options = [];
                foreach ($urls as $url) {
                    // Display image as option label, wrapped in HtmlString to support HTML rendering
                    // Added @click.prevent to trigger Alpine Lightbox
                    $options[$url] = new \Illuminate\Support\HtmlString("
                        <div style='display:flex; align-items:center; gap:10px;'>
                            <img 
                                src='{$url}' 
                                style='width:300px; height:auto; border-radius:4px; object-fit:cover; cursor: zoom-in;' 
                                @click.prevent=\"\$dispatch('open-lightbox', { url: '{$url}' })\"
                            />
                        </div>
                    ");
                }
                return $options;
            })
            ->columns(3)
            ->gridDirection('row')
            ->extraAttributes(['class' => 'gallery-radio-list']);
    }

    protected function getGalleryField($name, $label, $sourceKey)
    {
        return Forms\Components\CheckboxList::make($name)
            ->label($label)
            ->options(function (Forms\Get $get) use ($sourceKey) {
                $urls = $get($sourceKey) ?? [];

                // Safety check if we got the array directly
                if (empty($urls) && str_contains($sourceKey, '.')) {
                    // manual retrieval if get() fails on deep nest
                    $keys = explode('.', $sourceKey);
                    $state = $this->form->getState(); // keys[0] = custom_fields, keys[1] = key
                    $temp = $state;
                    foreach ($keys as $key) {
                        if (isset($temp[$key])) {
                            $temp = $temp[$key];
                        } else {
                            $temp = [];
                            break;
                        }
                    }
                    $urls = $temp;
                }

                $options = [];
                foreach ($urls as $url) {
                    // Added @click.prevent to trigger Alpine Lightbox
                    $options[$url] = "<div style='display:flex; align-items:center; gap:10px;'><img src='{$url}' style='width:300px; height:auto; border-radius:4px; object-fit:cover; cursor: zoom-in;' @click.prevent=\"\$dispatch('open-lightbox', { url: '{$url}' })\" /></div>";
                }
                return $options;
            })
            ->allowHtml()
            ->columns(3)
            ->gridDirection('row')
            ->extraAttributes(['class' => 'gallery-checkbox-list']);
    }

    protected function getHeaderActions(): array
    {
        return [
            // No header actions needed for production
        ];
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Import Yacht')
                ->action('save'),
            Action::make('cancel')
                ->label('Cancel')
                ->color('gray')
                ->url(route('filament.admin.resources.new-yachts.index')),
        ];
    }

    public function save()
    {
        $data = $this->form->getState();

        // Validation handled by form->getState() basically, but strictly:
        // $this->form->validate();

        set_time_limit(600); // Allow time for image downloads

        \Illuminate\Support\Facades\DB::beginTransaction();

        try {
            // 1. Create Yacht
            // Prepare multilingual name
            $activeLanguages = \App\Models\Language::pluck('code')->toArray();
            $nameMultilingual = array_fill_keys($activeLanguages, $data['name']);

            $yachtName = $data['name'];
            $slug = \Illuminate\Support\Str::slug($yachtName);
            $originalSlug = $slug;
            $count = 1;

            // Handle Slug Uniqueness / Double Submit
            while (NewYacht::where('slug', $slug)->exists()) {
                $existing = NewYacht::where('slug', $slug)->first();
                if ($existing->created_at->diffInMinutes(now()) < 1) {
                    \Illuminate\Support\Facades\DB::rollBack();
                    Notification::make()->title('Yacht Imported Successfully (Duplicate Request Ignored)')->success()->send();
                    return redirect()->route('filament.admin.resources.new-yachts.index');
                }
                $slug = $originalSlug . '-' . $count++;
            }

            // ---------------------------------------------------------
            // 2. Prepare Data & Create Record (Safe Save)
            // ---------------------------------------------------------
            $customFields = $data['custom_fields'] ?? [];

            // MAP TO LEGACY TYPOS (User DB Alignment)
            $customFields['lenght'] = $customFields['length'] ?? null; // Fix 'Lenght' typo
            $customFields['sub_titile'] = $customFields['sub_title'] ?? null; // Fix 'Sub Titile' typo
            $customFields['gallery_interrior_urls'] = $customFields['gallery_interior_urls'] ?? []; // Typo legacy in Yacht.php
            $customFields['gallery_exterrior_urls'] = $customFields['gallery_exterior_urls'] ?? []; // Typo legacy in Yacht.php

            // Media Process preparation
            $allImages = $customFields['all_images'] ?? [];

            // Re-distribute images based on 'category' from Unified Manager
            $mediaFields = [
                'cover_image_url' => $customFields['cover_image_url'] ?? [],
                'grid_image_url' => $customFields['grid_image_url'] ?? [],
                'grid_image_hover_url' => $customFields['grid_image_hover_url'] ?? [],
                'gallery_exterior_urls' => [],
                'gallery_interior_urls' => [],
                'gallery_cockpit_urls' => [],
                'gallery_layout_urls' => [],
                'pdf_brochure' => $customFields['pdf_brochure'] ?? null,
            ];

            foreach ($allImages as $img) {
                if (($img['category'] ?? 'trash') === 'trash')
                    continue;

                $cat = $img['category'];
                // Map category names to field names if they match format 'gallery_TYPE'
                $targetKey = $cat . '_urls';

                if (isset($mediaFields[$targetKey])) {
                    $mediaFields[$targetKey][] = $img['url'];
                }
            }

            // Clean custom fields (remove media placeholders to save space if needed,
            // but keeping them is fine for reference. We DO need to ensure 'lenght' is there.)

            // Log Input Data for Debugging
            Log::info('Review Save: Custom Fields Input', ['keys' => array_keys($customFields), 'sub_title' => $customFields['sub_title'] ?? 'NULL', 'length' => $customFields['length'] ?? 'NULL']);

            // Create Yacht
            $yacht = NewYacht::create([
                'type' => 'new',
                'state' => 'draft',
                'name' => $nameMultilingual, // Defined above
                'slug' => $slug,
                'brand_id' => $data['brand_id'],
                'yacht_model_id' => $data['yacht_model_id'],
                'price' => 0,
                'custom_fields' => $customFields, // SAVE METADATA NOW
            ]);

            \Illuminate\Support\Facades\DB::commit();

            Notification::make()->title('Yacht Imported Successfully')->success()->send();

            // ---------------------------------------------------------
            // Media Processing (Post-Commit)
            // ---------------------------------------------------------
            try {
                $failedAttachments = 0;
                $failedUrls = [];

                // Helper to attach media
                $attachMedia = function ($urls, $collection) use ($yacht, &$failedAttachments, &$failedUrls) {
                    if (empty($urls))
                        return;
                    $urls = is_array($urls) ? $urls : [$urls];

                    foreach ($urls as $url) {
                        if (!empty($url) && filter_var($url, FILTER_VALIDATE_URL)) {
                            try {
                                $yacht->addMediaFromUrl($url)
                                    ->toMediaCollection($collection);
                            } catch (\Exception $e) {
                                Log::warning("Failed to attach media ($url) to $collection: " . $e->getMessage());
                                $failedAttachments++;
                                $failedUrls[] = $url;
                            }
                        }
                    }
                };

                // Single Images
                if (!empty($mediaFields['cover_image_url'])) {
                    $val = $mediaFields['cover_image_url'];
                    $url = is_array($val) ? ($val[0] ?? null) : $val;
                    $attachMedia($url, 'cover_image');
                }

                if (!empty($mediaFields['grid_image_url'])) {
                    $val = $mediaFields['grid_image_url'];
                    $url = is_array($val) ? ($val[0] ?? null) : $val;
                    $attachMedia($url, 'grid_image');
                }

                if (!empty($mediaFields['grid_image_hover_url'])) {
                    $val = $mediaFields['grid_image_hover_url'];
                    $url = is_array($val) ? ($val[0] ?? null) : $val;
                    $attachMedia($url, 'grid_image_hover');
                }

                // Galleries (Use LEGACY/TYPO collection names if needed - checked Yacht.php)
                $attachMedia($mediaFields['gallery_exterior_urls'] ?? [], 'gallery_exterior'); // Standard (Seeder matches this)
                $attachMedia($mediaFields['gallery_interior_urls'] ?? [], 'gallery_interrior'); // Typo legacy (User confirmed this)
                $attachMedia($mediaFields['gallery_cockpit_urls'] ?? [], 'gallery_cockpit');
                $attachMedia($mediaFields['gallery_layout_urls'] ?? [], 'gallery_layout');

                // PDF Brochure
                if (!empty($mediaFields['pdf_brochure'])) {
                    $attachMedia($mediaFields['pdf_brochure'], 'pdf_brochure');
                }

                if ($failedAttachments > 0) {
                    Notification::make()
                        ->title('Yacht Imported with Warnings')
                        ->body("Imported successfully, but $failedAttachments images failed to download. Check logs for details.")
                        ->warning()
                        ->send();
                } else {
                    Notification::make()->title('Yacht Imported Successfully')->success()->send();
                }

            } catch (\Exception $e) {
                Log::error('Media Attachment Error: ' . $e->getMessage());
                Notification::make()
                    ->title('Yacht Created but Media Failed')
                    ->body('Metadata saved. Error downloading images: ' . $e->getMessage())
                    ->warning()
                    ->send();
            }

            return redirect()->route('filament.admin.resources.new-yachts.index');

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            Log::error('Import Save Error: ' . $e->getMessage());
            Notification::make()->title('Import Failed')->body($e->getMessage())->danger()->send();
        }
    }
}
