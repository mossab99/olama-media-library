<?php
/**
 * Plugin Name: Olama Media Library
 * Plugin URI: https://olama.online
 * Description: Standalone media library and Google Drive upload module for Olama School curriculum lessons.
 * Version: 1.2.1
 * Author: Olama
 * Text Domain: olama-media-library
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('OLAMA_MEDIA_LIBRARY_VERSION', '1.2.1');
define('OLAMA_MEDIA_LIBRARY_FILE', __FILE__);
define('OLAMA_MEDIA_LIBRARY_PATH', plugin_dir_path(__FILE__));
define('OLAMA_MEDIA_LIBRARY_URL', plugin_dir_url(__FILE__));

$olama_school_autoload = WP_PLUGIN_DIR . '/olama-school/vendor/autoload.php';
if (file_exists($olama_school_autoload)) {
    require_once $olama_school_autoload;
}

require_once OLAMA_MEDIA_LIBRARY_PATH . 'includes/class-olama-media-logger.php';
require_once OLAMA_MEDIA_LIBRARY_PATH . 'includes/class-olama-media-db.php';
require_once OLAMA_MEDIA_LIBRARY_PATH . 'includes/class-olama-media-drive.php';
require_once OLAMA_MEDIA_LIBRARY_PATH . 'includes/class-olama-media-curriculum-adapter.php';
require_once OLAMA_MEDIA_LIBRARY_PATH . 'includes/class-olama-media-ajax.php';
require_once OLAMA_MEDIA_LIBRARY_PATH . 'includes/class-olama-media-admin.php';
require_once OLAMA_MEDIA_LIBRARY_PATH . 'includes/class-olama-media-library-plugin.php';

function olama_media_library_activate()
{
    $db = new Olama_Media_DB();
    $db->create_tables();
}
register_activation_hook(__FILE__, 'olama_media_library_activate');

function olama_media_library()
{
    return Olama_Media_Library_Plugin::instance();
}

add_action('plugins_loaded', 'olama_media_library');
