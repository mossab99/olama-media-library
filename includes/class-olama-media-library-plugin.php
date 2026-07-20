<?php
if (!defined('ABSPATH')) {
    exit;
}

class Olama_Media_Library_Plugin
{
    private static $instance = null;
    private $db;
    private $logger;
    private $curriculum;

    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->db = new Olama_Media_DB();
        $this->logger = new Olama_Media_Logger($this->db);
        $this->curriculum = new Olama_Media_Curriculum_Adapter();

        add_action('init', array($this, 'load_textdomain'));
        add_action('admin_init', array($this, 'maybe_update_schema'));
        add_action('olama_users_register_modules', array($this, 'register_access_module'));

        if (is_admin()) {
            new Olama_Media_Admin($this->db, $this->curriculum);
            new Olama_Media_Ajax($this->db, $this->curriculum, $this->logger);
        }
    }

    public function load_textdomain()
    {
        load_plugin_textdomain('olama-media-library', false, dirname(plugin_basename(OLAMA_MEDIA_LIBRARY_FILE)) . '/languages');
    }

    public function maybe_update_schema()
    {
        if (get_option('olama_media_library_db_version') !== OLAMA_MEDIA_LIBRARY_VERSION) {
            $this->db->create_tables();
            update_option('olama_media_library_db_version', OLAMA_MEDIA_LIBRARY_VERSION);
        }
    }

    public function register_access_module()
    {
        if (!class_exists('Olama_Users_Registry')) {
            return;
        }

        Olama_Users_Registry::register(array(
            'id' => 'olama_media_library',
            'plugin' => 'olama-media-library',
            'label' => __('Media Library', 'olama-media-library'),
            'capability' => 'olama_access_media_library',
            'items' => array(
                array('id' => 'olama_media_library.upload', 'type' => 'action', 'label' => __('Upload videos', 'olama-media-library'), 'capability' => 'olama_media_upload_video'),
                array('id' => 'olama_media_library.approve', 'type' => 'action', 'label' => __('Approve videos', 'olama-media-library'), 'capability' => 'olama_media_approve_video'),
                array('id' => 'olama_media_library.drive', 'type' => 'action', 'label' => __('Manage Drive settings', 'olama-media-library'), 'capability' => 'olama_media_drive_settings'),
                array('id' => 'olama_media_library.logs', 'type' => 'action', 'label' => __('View upload logs', 'olama-media-library'), 'capability' => 'olama_media_view_logs'),
            ),
        ));
    }
}
