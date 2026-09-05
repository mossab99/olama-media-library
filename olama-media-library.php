<?php
/**
 * Plugin Name: Olama Media Library
 * Plugin URI: https://olama.online
 * Description: Standalone media library and Google Drive upload module for Olama School curriculum lessons.
 * Version: 2.2.2
 * Author: Olama
 * Text Domain: olama-media-library
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('OLAMA_MEDIA_LIBRARY_VERSION', '2.2.2');
define('OLAMA_MEDIA_LIBRARY_DB_VERSION', '2.2.0');
define('OLAMA_MEDIA_LIBRARY_FILE', __FILE__);
define('OLAMA_MEDIA_LIBRARY_PATH', plugin_dir_path(__FILE__));
define('OLAMA_MEDIA_LIBRARY_URL', plugin_dir_url(__FILE__));

// Phase 0 safety freeze. These may be defined before the plugin loads, but
// production should keep all four disabled until their rollout gates pass.
if (!defined('OLAMA_MEDIA_DRIVE_UPLOAD_ENABLED')) {
    define('OLAMA_MEDIA_DRIVE_UPLOAD_ENABLED', false);
}
if (!defined('OLAMA_MEDIA_DRIVE_SYNC_ENABLED')) {
    define('OLAMA_MEDIA_DRIVE_SYNC_ENABLED', false);
}
if (!defined('OLAMA_MEDIA_DRIVE_FOLDER_CREATION_ENABLED')) {
    define('OLAMA_MEDIA_DRIVE_FOLDER_CREATION_ENABLED', false);
}
if (!defined('OLAMA_MEDIA_LEGACY_SYNC_ENABLED')) {
    define('OLAMA_MEDIA_LEGACY_SYNC_ENABLED', false);
}

$olama_school_autoload = WP_PLUGIN_DIR . '/olama-school/vendor/autoload.php';
if (file_exists($olama_school_autoload)) {
    require_once $olama_school_autoload;
}

require_once OLAMA_MEDIA_LIBRARY_PATH . 'includes/class-olama-media-logger.php';
require_once OLAMA_MEDIA_LIBRARY_PATH . 'includes/class-olama-media-db.php';
require_once OLAMA_MEDIA_LIBRARY_PATH . 'includes/class-olama-media-feature-flags.php';
require_once OLAMA_MEDIA_LIBRARY_PATH . 'includes/class-olama-media-drive.php';
require_once OLAMA_MEDIA_LIBRARY_PATH . 'includes/class-olama-media-curriculum-adapter.php';
require_once OLAMA_MEDIA_LIBRARY_PATH . 'includes/class-olama-media-normalizer.php';
require_once OLAMA_MEDIA_LIBRARY_PATH . 'includes/class-olama-media-v2-repository.php';
require_once OLAMA_MEDIA_LIBRARY_PATH . 'includes/class-olama-media-guardian-library.php';
require_once OLAMA_MEDIA_LIBRARY_PATH . 'includes/class-olama-media-drive-indexer.php';
require_once OLAMA_MEDIA_LIBRARY_PATH . 'includes/class-olama-media-drive-inventory-repository.php';
require_once OLAMA_MEDIA_LIBRARY_PATH . 'includes/class-olama-media-drive-discovery.php';
require_once OLAMA_MEDIA_LIBRARY_PATH . 'includes/class-olama-media-drive-mapping.php';
require_once OLAMA_MEDIA_LIBRARY_PATH . 'includes/class-olama-media-matcher.php';
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
