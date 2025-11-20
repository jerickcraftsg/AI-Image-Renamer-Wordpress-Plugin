<?php
if (!defined('ABSPATH')) exit;

class AIIR_Admin {

    public static function init() {
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('elementor/editor/after_enqueue_scripts', [__CLASS__, 'enqueue_assets']);

        add_action('elementor/editor/footer', [__CLASS__, 'render_modal']);
        add_action('admin_footer', [__CLASS__, 'render_modal']);

        add_action('wp_ajax_aiir_get_uploaded_images', [__CLASS__, 'get_uploaded_images']);
        add_action('add_attachment', [__CLASS__, 'store_uploaded_image']);

        add_action('wp_ajax_aiir_rename_images', [__CLASS__, 'rename_images']);

        add_action('admin_menu', [__CLASS__, 'register_bulk_page']);
        add_action('wp_ajax_aiir_bulk_scan', [__CLASS__, 'bulk_scan']);

        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_menu', [__CLASS__, 'settings_page']);
    }

    public static function enqueue_assets() {
        wp_enqueue_style('aiir-admin-css', AIIR_PLUGIN_URL . 'assets/css/aiir-admin.css');
        wp_enqueue_script('aiir-admin-js', AIIR_PLUGIN_URL . 'assets/js/aiir-admin.js', ['jquery'], false, true);
        wp_localize_script('aiir-admin-js', 'AIIR_Ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('aiir_nonce'),
        ]);
    }

    // Store uploaded image temporarily (IDs in transient)
    public static function store_uploaded_image($attachment_id) {
        $post = get_post($attachment_id);
        if (strpos($post->post_mime_type, 'image/') !== 0) {
            return;
        }

        $recent = get_transient('aiir_recent_uploads') ?: [];
        $recent[] = $attachment_id;
        set_transient('aiir_recent_uploads', $recent, 60 * 5); // store 5 min
    }

    // Return uploaded images with AI suggestions
    public static function get_uploaded_images() {
        check_ajax_referer('aiir_nonce', 'nonce');

        $recent = get_transient('aiir_recent_uploads') ?: [];
        if (empty($recent)) {
            wp_send_json_success(['images' => []]);
        }

        delete_transient('aiir_recent_uploads');

        $images = [];
        foreach ($recent as $attachment_id) {
            $mime = get_post_mime_type($attachment_id);

            // Only process images
            if (strpos($mime, 'image/') !== 0) {
                continue;
            }

            $file = get_attached_file($attachment_id);
            $url  = wp_get_attachment_url($attachment_id);

            // Skip if file doesn't exist
            if (!$file || !file_exists($file)) continue;

            $suggestions = self::generate_suggestions($file);
            // $images[] = [
            //     'id' => $attachment_id,
            //     'url' => $url,
            //     'suggestions' => $suggestions,
            // ];

            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

            $images[] = [
                'id' => $attachment_id,
                'url' => $url,
                'ext' => $ext,
                'suggestions' => $suggestions,
            ];
        }

        if (empty($images)) {
            wp_send_json_success(['images' => []]); // JS will do nothing
        }

        wp_send_json_success(['images' => $images]);
    }

    private static function generate_suggestions($file_path) {

        $provider = get_option('aiir_ai_provider', 'google');

        if ($provider === 'openai') {
            return self::generate_openai_suggestions($file_path);
        }

        return self::generate_google_suggestions($file_path);
    }

    // Call Google Vision (use your existing helper)
    private static function generate_google_suggestions($file_path) {

        // Get API key from admin settings
        $api_key = get_option('aiir_vision_api_key', '');

        if (empty($api_key)) {
            error_log('AIIR: Google Vision API key missing.');
            return [];
        }

        $url = 'https://vision.googleapis.com/v1/images:annotate?key=' . $api_key;

        $imageData = base64_encode(file_get_contents($file_path));

        $body = [
            'requests' => [[
                'image' => ['content' => $imageData],
                'features' => [['type' => 'LABEL_DETECTION', 'maxResults' => 10]]
            ]]
        ];

        $response = wp_remote_post($url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($body),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) return [];

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($data['responses'][0]['labelAnnotations'])) return [];

        $labels = array_column($data['responses'][0]['labelAnnotations'], 'description');
        $suggestions = [];

        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));

        // Generate SEO-friendly suggestions
        for ($i = 0; $i < min(3, count($labels)); $i++) {

            $words = array_slice($labels, $i, 3);
            $name  = strtolower(implode('-', $words));
            $name  = preg_replace('/[^a-z0-9-]+/', '-', $name);

            $suggestions[] = trim($name, '-') . '.' . $ext;
        }

        return $suggestions;
    }

    private static function generate_openai_suggestions($file_path) {

        $api_key = get_option('aiir_openai_api_key', '');

        if (empty($api_key)) {
            error_log('AIIR: OpenAI API key missing.');
            return [];
        }

        $image_base64 = base64_encode(file_get_contents($file_path));

        $body = [
            "model" => "gpt-4o-mini",
            "messages" => [
                [
                    "role" => "user",
                    "content" => [
                        [
                            "type" => "input_image",
                            "image_url" => "data:image/jpeg;base64," . $image_base64
                        ],
                        [
                            "type" => "text",
                            "text" => "Generate 3 SEO-friendly short image filenames (kebab-case, no extension)."
                        ]
                    ]
                ]
            ]
        ];

        $response = wp_remote_post("https://api.openai.com/v1/chat/completions", [
            "headers" => [
                "Content-Type" => "application/json",
                "Authorization" => "Bearer " . $api_key
            ],
            "body" => json_encode($body),
            "timeout" => 30
        ]);

        if (is_wp_error($response)) return [];

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($data["choices"][0]["message"]["content"])) return [];

        $raw = trim($data["choices"][0]["message"]["content"]);
        $lines = preg_split('/\r\n|\r|\n/', $raw);

        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        $suggestions = [];

        foreach ($lines as $line) {
            $clean = strtolower(trim($line));
            $clean = preg_replace('/[^a-z0-9-]+/', '-', $clean);
            $suggestions[] = trim($clean, '-') . '.' . $ext;
        }

        return array_slice($suggestions, 0, 3);
    }


    // Modal HTML stays same
    public static function render_modal() {
        ?>
        <div id="aiir-modal" class="aiir-modal">
            <div class="aiir-loading" style="display:none;">
                <div class="aiir-spinner"></div>
                <p>Analyzing uploaded images…</p>
            </div>

            <div class="aiir-modal-content">
                <span class="aiir-close">&times;</span>
                <h2>AI Filename Suggestions</h2>
                <div class="aiir-upload-list"></div>
                <button id="aiir-submit" class="button button-primary">Apply Selected Names</button>
            </div>
        </div>
        <?php
    }

    public static function rename_images() {
        check_ajax_referer('aiir_nonce', 'nonce');

        if (empty($_POST['images']) || !is_array($_POST['images'])) {
            wp_send_json_error(['message' => 'No images provided.']);
        }

        $results = [];

        foreach ($_POST['images'] as $img) {
            $id = intval($img['id']);
            $new_name = sanitize_file_name($img['new_name']);

            $file_path = get_attached_file($id);
            if (!$file_path || !file_exists($file_path)) {
                $results[] = ['id' => $id, 'status' => 'error', 'message' => 'File not found'];
                continue;
            }

            $upload_dir = pathinfo($file_path, PATHINFO_DIRNAME);
            $new_path = $upload_dir . '/' . $new_name;

            // Avoid overwrite
            if (file_exists($new_path)) {
                $name_no_ext = pathinfo($new_name, PATHINFO_FILENAME);
                $ext = pathinfo($new_name, PATHINFO_EXTENSION);
                $new_name = $name_no_ext . '-' . uniqid() . '.' . $ext;
                $new_path = $upload_dir . '/' . $new_name;
            }

            // Try rename
            if (rename($file_path, $new_path)) {
                update_attached_file($id, $new_path);

                // Update metadata
                wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $new_path));

                update_post_meta($id, '_aiir_renamed', 1);

                // Update GUID (optional but good practice)
                $new_url = str_replace(wp_get_upload_dir()['basedir'], wp_get_upload_dir()['baseurl'], $new_path);
                wp_update_post([
                    'ID' => $id,
                    'guid' => $new_url,
                    'post_title' => pathinfo($new_name, PATHINFO_FILENAME),
                    'post_name' => pathinfo($new_name, PATHINFO_FILENAME),
                ]);

                $results[] = ['id' => $id, 'status' => 'success', 'new_name' => $new_name];
            } else {
                $results[] = ['id' => $id, 'status' => 'error', 'message' => 'Rename failed'];
            }
        }

        wp_send_json_success(['results' => $results]);
    }

    public static function register_bulk_page() {
        add_media_page(
            'Bulk AI Image Renamer',
            'Bulk AI Image Renamer',
            'manage_options',
            'aiir-bulk-renamer',
            [__CLASS__, 'bulk_page_html']
        );
    }

    public static function bulk_page_html() {
        ?>
        <div class="wrap">
            <h1>Bulk AI Image Renamer</h1>

            <button id="aiir-bulk-scan" class="button button-primary">
                Scan Images
            </button>

            <div id="aiir-bulk-results" style="margin-top:20px;"></div>

            <button id="aiir-bulk-submit" class="button button-primary" style="margin-top:20px; display:none;">
                Apply Selected Names
            </button>
        </div>
        <?php
    }

    public static function bulk_scan() {
        check_ajax_referer('aiir_nonce', 'nonce');

        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $limit  = 10;

        // Query only un-renamed images
        $args = [
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'posts_per_page' => $limit,
            'offset'         => $offset,
            'post_status'    => 'inherit',
            'meta_query'     => [
                [
                    'key'     => '_aiir_renamed',
                    'compare' => 'NOT EXISTS'
                ]
            ]
        ];

        $query = new WP_Query($args);

        // Count ALL remaining (for message + next batch)
        $count_query = new WP_Query([
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'posts_per_page' => -1,
            'post_status'    => 'inherit',
            'meta_query'     => [
                [
                    'key'     => '_aiir_renamed',
                    'compare' => 'NOT EXISTS'
                ]
            ]
        ]);

        $total_remaining = $count_query->found_posts;

        $images = [];

        foreach ($query->posts as $img) {

            $file = get_attached_file($img->ID);
            if (!$file || !file_exists($file)) continue;

            $url  = wp_get_attachment_url($img->ID);
            $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));

            $suggestions = self::generate_suggestions($file);

            $images[] = [
                'id'          => $img->ID,
                'url'         => $url,
                'ext'         => $ext,
                'suggestions' => $suggestions
            ];
        }

        $next_offset = $offset + $limit;
        if ($next_offset >= $total_remaining) {
            $next_offset = null; // No more pages
        }

        wp_send_json_success([
            'images'          => $images,
            'total_remaining' => $total_remaining,
            'next_offset'     => $next_offset
        ]);
    }

    public static function register_settings() {
        register_setting('aiir_settings_group', 'aiir_vision_api_key');
        register_setting('aiir_settings_group', 'aiir_ai_provider');
        register_setting('aiir_settings_group', 'aiir_openai_api_key');

        add_settings_section(
            'aiir_api_section',
            'AI Image Renamer Settings',
            function() {
                echo '<p>Select your AI provider and enter the corresponding API key.</p>';
            },
            'aiir-settings'
        );

        add_settings_field(
            'aiir_ai_provider',
            'AI Provider',
            function() {
                $provider = get_option('aiir_ai_provider', 'google'); ?>

                <label>
                    <input type="radio" name="aiir_ai_provider" value="google" 
                        <?php checked($provider, 'google'); ?>> Google Vision
                </label><br>

                <label>
                    <input type="radio" name="aiir_ai_provider" value="openai"
                        <?php checked($provider, 'openai'); ?>> OpenAI Vision
                </label>

            <?php },
            'aiir-settings',
            'aiir_api_section'
        );

        add_settings_field(
            'aiir_vision_api_key',
            'Google Vision API Key',
            function() {
                $key = esc_attr(get_option('aiir_vision_api_key', ''));
                echo '<input type="text" name="aiir_vision_api_key" value="' . $key . '" class="regular-text" placeholder="Enter Google Vision API Key">';
            },
            'aiir-settings',
            'aiir_api_section'
        );

        add_settings_field(
            'aiir_openai_api_key',
            'OpenAI API Key',
            function() {
                $key = esc_attr(get_option('aiir_openai_api_key', ''));
                echo '<input type="text" name="aiir_openai_api_key" value="' . $key . '" class="regular-text" placeholder="Enter OpenAI API Key">';
            },
            'aiir-settings',
            'aiir_api_section'
        );
    }

    public static function settings_page() {
        add_options_page(
            'AI Image Renamer',
            'AI Image Renamer',
            'manage_options',
            'aiir-settings',
            function() {
                echo '<div class="wrap"><h1>AI Image Renamer Settings</h1>';
                echo '<form method="post" action="options.php">';
                settings_fields('aiir_settings_group');
                do_settings_sections('aiir-settings');
                submit_button();
                echo '</form></div>';
            }
        );
    }
}
