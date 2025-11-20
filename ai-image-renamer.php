<?php
/**
 * Plugin Name: AI Image Renamer
 * Description: Suggests 3 AI-generated filenames for uploaded images using Google Vision API.
 * Version: 1.0.0
 * Author: Jerick
 */

if (!defined('ABSPATH')) exit;

define('AIIR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AIIR_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include core files
require_once AIIR_PLUGIN_DIR . 'includes/class-aiir-core.php';

// Initialize plugin
add_action('plugins_loaded', ['AIIR_Core', 'init']);