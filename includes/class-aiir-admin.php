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

    // Call Google Vision (use your existing helper)
    private static function generate_suggestions($file_path) {
        $api_key = 'THE_API_KEY';
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

        // Generate up to 3 SEO-friendly suggestions
        for ($i = 0; $i < min(3, count($labels)); $i++) {
            $words = array_slice($labels, $i, 3);
            $name = strtolower(implode('-', $words));
            $name = preg_replace('/[^a-z0-9-]+/', '-', $name);
            $ext = pathinfo($file_path, PATHINFO_EXTENSION);
            $suggestions[] = trim($name, '-') . '.' . strtolower($ext);        }

        return $suggestions;
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
}