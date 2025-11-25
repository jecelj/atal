<?php
/**
 * Smart Slider Image Extractor
 * Extracts images from Smart Slider 3 sliders
 */

class Galeon_Smart_Slider_Extractor
{
    private $wpdb;
    private $upload_url;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->upload_url = wp_upload_dir()['baseurl'];
    }

    /**
     * Extract slider ID from shortcode
     * 
     * @param string $shortcode e.g., "[smartslider3 slider="79"]"
     * @return int|null Slider ID or null if not found
     */
    public function extract_slider_id($shortcode)
    {
        if (empty($shortcode)) {
            return null;
        }

        // Match [smartslider3 slider="79"] or [smartslider3 slider='79']
        if (preg_match('/\[smartslider3\s+slider=["\']?(\d+)["\']?\]/', $shortcode, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Get all images from a slider
     * 
     * @param int $slider_id Slider ID
     * @return array Array of image URLs
     */
    public function get_slider_images($slider_id)
    {
        if (empty($slider_id)) {
            return [];
        }

        $table_name = $this->wpdb->prefix . 'nextend2_smartslider3_slides';

        $slides = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT thumbnail, slide FROM {$table_name} WHERE slider = %d ORDER BY ordering ASC",
            $slider_id
        ));

        if (empty($slides)) {
            return [];
        }

        $images = [];
        foreach ($slides as $slide) {
            if (!empty($slide->thumbnail)) {
                $url = $this->convert_upload_path($slide->thumbnail);
                if ($url) {
                    $images[] = $url;
                }
            }
        }

        return $images;
    }

    /**
     * Extract YouTube URLs from video slider
     * 
     * @param int $slider_id Slider ID
     * @return array Array of YouTube URLs
     */
    public function extract_youtube_urls($slider_id)
    {
        if (empty($slider_id)) {
            return [];
        }

        $table_name = $this->wpdb->prefix . 'nextend2_smartslider3_slides';

        $slides = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT slide FROM {$table_name} WHERE slider = %d ORDER BY ordering ASC",
            $slider_id
        ));

        if (empty($slides)) {
            return [];
        }

        $urls = [];

        foreach ($slides as $slide) {
            $slide_data = $slide->slide;

            // Try to decode JSON
            $json = json_decode($slide_data, true);

            if (!$json || !is_array($json)) {
                // Fallback to regex if not valid JSON
                $this->extract_urls_from_text($slide_data, $urls);
                continue;
            }

            // Parse JSON structure to find YouTube URLs
            $this->extract_urls_from_json($json, $urls);
        }

        return $urls;
    }

    /**
     * Extract YouTube URLs from JSON structure
     */
    private function extract_urls_from_json($data, &$urls)
    {
        if (!is_array($data)) {
            return;
        }

        foreach ($data as $key => $value) {
            // Check if this is a YouTube item with youtubeurl field
            if ($key === 'youtubeurl' && is_string($value) && !empty($value)) {
                $url = $this->normalize_youtube_url($value);
                if ($url && !in_array($url, $urls)) {
                    $urls[] = $url;
                }
            }

            // Recursively search nested arrays/objects
            if (is_array($value)) {
                $this->extract_urls_from_json($value, $urls);
            }
        }
    }

    /**
     * Extract YouTube URLs from plain text using regex (fallback)
     */
    private function extract_urls_from_text($text, &$urls)
    {
        $patterns = [
            '/youtube\.com\/watch\?v=([a-zA-Z0-9_-]+)/',
            '/youtu\.be\/([a-zA-Z0-9_-]+)/',
            '/youtube\.com\/embed\/([a-zA-Z0-9_-]+)/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $url = 'https://www.youtube.com/watch?v=' . $matches[1];
                if (!in_array($url, $urls)) {
                    $urls[] = $url;
                }
            }
        }
    }

    /**
     * Normalize YouTube URL to standard watch format
     */
    private function normalize_youtube_url($url)
    {
        if (empty($url)) {
            return null;
        }

        // Extract video ID from various YouTube URL formats
        $patterns = [
            '/youtube\.com\/watch\?v=([a-zA-Z0-9_-]+)/',
            '/youtu\.be\/([a-zA-Z0-9_-]+)/',
            '/youtube\.com\/embed\/([a-zA-Z0-9_-]+)/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches)) {
                return 'https://www.youtube.com/watch?v=' . $matches[1];
            }
        }

        return null;
    }

    /**
     * Convert Smart Slider upload path to full URL
     * 
     * @param string $path e.g., "$upload$/slider/79/IMG_0814.webp"
     * @return string|null Full URL or null
     */
    private function convert_upload_path($path)
    {
        if (empty($path)) {
            return null;
        }

        // Replace $upload$ with actual upload URL
        $url = str_replace('$upload$', $this->upload_url, $path);

        // Ensure it's a valid URL
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }

        return null;
    }

    /**
     * Log message
     */
    private function log($message)
    {
        error_log('[Galeon Migration] ' . $message);
    }
}
