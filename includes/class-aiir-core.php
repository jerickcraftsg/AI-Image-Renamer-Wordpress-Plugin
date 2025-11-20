<?php
if (!defined('ABSPATH')) exit;

class AIIR_Core {
    public static function init() {
        if (is_admin()) {
            require_once AIIR_PLUGIN_DIR . 'includes/class-aiir-admin.php';
            AIIR_Admin::init();
        }
    }
}