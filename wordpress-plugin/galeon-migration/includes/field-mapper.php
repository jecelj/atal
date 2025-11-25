<?php
/**
 * ACF Field Mapper
 * Maps galeonadriatic.com ACF fields to Filament master fields
 */

class Galeon_Field_Mapper
{
    private $slider_extractor;

    public function __construct()
    {
        $this->slider_extractor = new Galeon_Smart_Slider_Extractor();
    }

    /**
     * Map all yacht fields
     * 
     * @param WP_Post $post WordPress post object
     * @param array $acf_fields ACF fields
     * @return array Mapped data for Filament
     */
    public function map_yacht_fields($post, $acf_fields)
    {
        $data = [
            'source_post_id' => $post->ID,
            'name' => $post->post_title,
            'slug' => $post->post_name,
            'state' => 'new',
            'brand' => 'Galeon',
            'model' => $this->get_model_from_category($post->ID),
            'fields' => [],
            'media' => [],
            'skipped_fields' => [],
        ];

        // Map content fields
        $data['fields']['sub_titile'] = $this->map_subtitle($acf_fields['yacht_short_desc'] ?? '');
        $data['fields']['full_description'] = $acf_fields['yacht_long_description'] ?? '';
        $data['fields']['specifications'] = $acf_fields['specifications'] ?? '';

        // Map length
        $data['fields']['lenght'] = $this->parse_length($acf_fields['yacht_length_grid_info'] ?? '');

        // Map single media
        $data['media']['cover_image'] = $this->get_image_url($acf_fields['cover_image_post_view'] ?? null);
        $data['media']['grid_image'] = $this->get_image_url($acf_fields['grid_image'] ?? null);
        $data['media']['grid_image_hover'] = $acf_fields['grid_hover_image'] ?? null;
        $data['media']['pdf_brochure'] = $this->get_file_url($acf_fields['download_specification_file'] ?? null);

        // Map galleries
        $data['media']['gallery_exterior'] = $this->map_gallery($acf_fields['gallery_shortcode_2'] ?? '', 'Exterior');
        $data['media']['gallery_interrior'] = $this->map_gallery($acf_fields['smartslider3_slider=xx'] ?? '', 'Interior');
        $data['media']['gallery_cockpit'] = $this->map_gallery($acf_fields['gallery_shortcode_3'] ?? '', 'Cockpit');

        // Map video URL
        $data['media']['video_url'] = $this->map_video_gallery($acf_fields['gallery_shortcode_1'] ?? '');

        // Map layout images
        $data['media']['gallery_layout'] = $this->map_layout_images($acf_fields);

        // Track skipped fields
        $this->track_skipped_fields($data, $acf_fields);

        return $data;
    }

    /**
     * Get model name from post category
     */
    private function get_model_from_category($post_id)
    {
        $categories = get_the_category($post_id);

        $model_map = [
            'flybridge' => 'Flybridge',
            'gto' => 'GTO',
            'hardtop' => 'Hardtop',
            'skydeck' => 'Skydeck',
            'explorer' => 'Explorer',
        ];

        foreach ($categories as $category) {
            $slug = $category->slug;
            if (isset($model_map[$slug])) {
                return $model_map[$slug];
            }
        }

        return null;
    }

    /**
     * Map subtitle - wrap in paragraph tags
     */
    private function map_subtitle($text)
    {
        if (empty($text)) {
            return '';
        }

        // If already has HTML tags, return as is
        if (strip_tags($text) !== $text) {
            return $text;
        }

        // Wrap in paragraph
        return '<p>' . esc_html($text) . '</p>';
    }

    /**
     * Parse length from text
     * Input: "Length overall [m] 13,97"
     * Output: 13.97
     */
    private function parse_length($text)
    {
        if (empty($text)) {
            return null;
        }

        // Extract number with comma or dot
        if (preg_match('/(\d+[,.]?\d*)/', $text, $matches)) {
            $number = str_replace(',', '.', $matches[1]);
            return (float) $number;
        }

        return null;
    }

    /**
     * Get image URL from ACF image field
     */
    private function get_image_url($image_field)
    {
        if (empty($image_field)) {
            return null;
        }

        // If it's already a URL string
        if (is_string($image_field) && filter_var($image_field, FILTER_VALIDATE_URL)) {
            return $image_field;
        }

        // If it's an array with 'url' key
        if (is_array($image_field) && isset($image_field['url'])) {
            return $image_field['url'];
        }

        return null;
    }

    /**
     * Get file URL from ACF file field
     */
    private function get_file_url($file_field)
    {
        if (empty($file_field)) {
            return null;
        }

        // If it's already a URL string
        if (is_string($file_field) && filter_var($file_field, FILTER_VALIDATE_URL)) {
            return $file_field;
        }

        // If it's an array with 'url' key
        if (is_array($file_field) && isset($file_field['url'])) {
            return $file_field['url'];
        }

        return null;
    }

    /**
     * Map gallery from Smart Slider shortcode
     */
    private function map_gallery($shortcode, $gallery_name)
    {
        if (empty($shortcode)) {
            $this->log("Skipping empty gallery: {$gallery_name}");
            return [];
        }

        $slider_id = $this->slider_extractor->extract_slider_id($shortcode);
        if (!$slider_id) {
            $this->log("Could not extract slider ID from: {$shortcode}");
            return [];
        }

        $images = $this->slider_extractor->get_slider_images($slider_id);
        $this->log("Extracted " . count($images) . " images from {$gallery_name} (slider {$slider_id})");

        return $images;
    }

    /**
     * Map video gallery - extract YouTube URL
     */
    private function map_video_gallery($shortcode)
    {
        if (empty($shortcode)) {
            return null;
        }

        $slider_id = $this->slider_extractor->extract_slider_id($shortcode);
        if (!$slider_id) {
            return null;
        }

        $youtube_url = $this->slider_extractor->extract_youtube_url($slider_id);
        if ($youtube_url) {
            $this->log("Extracted YouTube URL: {$youtube_url}");
        }

        return $youtube_url;
    }

    /**
     * Map layout images (layout_1 to layout_5)
     */
    private function map_layout_images($acf_fields)
    {
        $layouts = [];

        for ($i = 1; $i <= 5; $i++) {
            $key = 'layout_' . $i;
            $url = $acf_fields[$key] ?? null;

            if (!empty($url)) {
                $layouts[] = $url;
            }
        }

        $this->log("Mapped " . count($layouts) . " layout images");
        return $layouts;
    }

    /**
     * Track skipped fields for logging
     */
    private function track_skipped_fields(&$data, $acf_fields)
    {
        $mapped_keys = [
            'yacht_short_desc',
            'yacht_long_description',
            'specifications',
            'yacht_length_grid_info',
            'cover_image_post_view',
            'grid_image',
            'grid_hover_image',
            'download_specification_file',
            'gallery_shortcode_1',
            'gallery_shortcode_2',
            'gallery_shortcode_3',
            'smartslider3_slider=xx',
            'gallery_shortcode_5',
            'layout_1',
            'layout_2',
            'layout_3',
            'layout_4',
            'layout_5',
        ];

        foreach ($acf_fields as $key => $value) {
            if (!in_array($key, $mapped_keys) && !empty($value)) {
                $data['skipped_fields'][$key] = $value;
            }
        }

        if (!empty($data['skipped_fields'])) {
            $this->log("Skipped fields: " . implode(', ', array_keys($data['skipped_fields'])));
        }
    }

    /**
     * Log message
     */
    private function log($message)
    {
        error_log('[Galeon Field Mapper] ' . $message);
    }
}
