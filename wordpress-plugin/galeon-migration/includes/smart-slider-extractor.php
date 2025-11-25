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

        // Patterns: youtube.com/watch?v=, youtu.be/, youtube.com/embed/
        $patterns = [
            '/youtube\.com\/watch\?v=([a-zA-Z0-9_-]+)/',
            '/youtu\.be\/([a-zA-Z0-9_-]+)/',
            '/youtube\.com\/embed\/([a-zA-Z0-9_-]+)/',
        ];

        foreach ($slides as $slide) {
            $slide_data = $slide->slide;

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $slide_data, $matches)) {
                    $url = 'https://www.youtube.com/watch?v=' . $matches[1];
                    // Avoid duplicates
                    if (!in_array($url, $urls)) {
                        $urls[] = $url;
                    }
                    // Found a URL in this slide, move to next slide
                    break;
                }
            }
        }

        return $urls;
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
