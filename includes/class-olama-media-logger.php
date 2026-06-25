<?php
if (!defined('ABSPATH')) {
    exit;
}

class Olama_Media_Logger
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function log($event_type, $message = '', $context = array(), $job_id = null, $asset_id = null)
    {
        $this->db->insert_event(array(
            'job_id' => $job_id ? absint($job_id) : null,
            'asset_id' => $asset_id ? absint($asset_id) : null,
            'event_type' => sanitize_key($event_type),
            'message' => sanitize_textarea_field($message),
            'context' => empty($context) ? null : wp_json_encode($this->sanitize_context($context)),
            'created_at' => current_time('mysql'),
        ));
    }

    public function critical($event_type, $message = '', $context = array(), $job_id = null, $asset_id = null)
    {
        $this->log($event_type, $message, $context, $job_id, $asset_id);
        error_log('[Olama Media] ' . sanitize_key($event_type) . ': ' . sanitize_text_field($message));
    }

    private function sanitize_context($context)
    {
        if (!is_array($context)) {
            return sanitize_text_field((string) $context);
        }

        $clean = array();
        foreach ($context as $key => $value) {
            $clean[sanitize_key((string) $key)] = is_array($value) ? $this->sanitize_context($value) : sanitize_text_field((string) $value);
        }
        return $clean;
    }
}
