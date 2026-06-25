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
}
