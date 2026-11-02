<?php
if (!defined('ABSPATH')) {
    exit;
}

/** Runtime settings with optional explicit deployment overrides. */
class Olama_Media_Feature_Flags
{
    const DRIVE_UPLOAD = 'drive_upload';
    const DRIVE_SYNC = 'drive_sync';
    const DRIVE_FOLDER_CREATION = 'drive_folder_creation';
    const LEGACY_SYNC = 'legacy_sync';

    private static $constants = array(
        self::DRIVE_UPLOAD => 'OLAMA_MEDIA_DRIVE_UPLOAD_ENABLED',
        self::DRIVE_SYNC => 'OLAMA_MEDIA_DRIVE_SYNC_ENABLED',
        self::DRIVE_FOLDER_CREATION => 'OLAMA_MEDIA_DRIVE_FOLDER_CREATION_ENABLED',
        self::LEGACY_SYNC => 'OLAMA_MEDIA_LEGACY_SYNC_ENABLED',
    );

    public static function enabled($feature)
    {
        $constant = self::$constants[$feature] ?? '';
        if ($constant === '') { return false; }
        if (defined($constant)) { return constant($constant) === true; }
        if (!in_array($feature, array(self::DRIVE_UPLOAD, self::DRIVE_SYNC), true)) { return false; }
        $settings = get_option('academy_media_library_settings', array());
        $key = $feature . '_enabled';
        return array_key_exists($key, $settings) ? in_array($settings[$key], array(true, 1, '1'), true) : $feature === self::DRIVE_UPLOAD;
    }

    public static function is_overridden($feature)
    {
        $constant = self::$constants[$feature] ?? '';
        return $constant !== '' && defined($constant);
    }

    /** A missing form marker means an older client; preserve its saved flags. */
    public static function settings_from_request($request, $current)
    {
        $result = array();
        foreach (array(self::DRIVE_UPLOAD, self::DRIVE_SYNC) as $feature) {
            $key = $feature . '_enabled';
            if (empty($request['drive_features_present']) || self::is_overridden($feature)) {
                if (array_key_exists($key, $current)) { $result[$key] = $current[$key]; }
            } else { $result[$key] = isset($request[$key]) && (string) $request[$key] === '1' ? 1 : 0; }
        }
        return $result;
    }

    public static function phase_zero_active()
    {
        foreach (array_keys(self::$constants) as $feature) {
            if (self::enabled($feature)) {
                return false;
            }
        }
        return true;
    }

    public static function error($feature)
    {
        $messages = array(
            self::DRIVE_UPLOAD => __('رفع الفيديوهات معطل. فعّله من إعدادات Drive، أو راجع مسؤول الموقع إذا كان الإعداد مقفلاً برمجياً.', 'olama-media-library'),
            self::DRIVE_SYNC => __('المزامنة واختيار فيديوهات Drive معطلان. فعّلهما من إعدادات Drive، أو راجع مسؤول الموقع إذا كان الإعداد مقفلاً برمجياً.', 'olama-media-library'),
            self::DRIVE_FOLDER_CREATION => __('Drive folder creation is temporarily disabled during the media-library safety migration.', 'olama-media-library'),
            self::LEGACY_SYNC => __('Legacy Drive synchronization has been disabled and will not be used during migration.', 'olama-media-library'),
        );

        return new WP_Error(
            'olama_media_phase_zero_frozen',
            $messages[$feature] ?? __('This Drive operation is temporarily disabled during the media-library safety migration.', 'olama-media-library'),
            array('feature' => sanitize_key($feature), 'retryable' => false)
        );
    }
}
