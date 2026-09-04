<?php
if (!defined('ABSPATH')) {
    exit;
}

/** Hard server-side gates used during the Drive migration rollout. */
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
        return $constant !== '' && defined($constant) && constant($constant) === true;
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
            self::DRIVE_UPLOAD => __('Drive uploads are temporarily disabled during the media-library safety migration.', 'olama-media-library'),
            self::DRIVE_SYNC => __('Drive synchronization is temporarily disabled during the media-library safety migration.', 'olama-media-library'),
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
