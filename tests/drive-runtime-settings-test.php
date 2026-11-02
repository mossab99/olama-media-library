<?php
define('ABSPATH', __DIR__ . '/');
function get_option($key, $default = array()) { return $GLOBALS['runtime_settings'] ?? $default; }
require dirname(__DIR__) . '/includes/class-olama-media-feature-flags.php';
function runtime_assert($condition, $message) { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }
$flags = Olama_Media_Feature_Flags::class;
runtime_assert($flags::enabled($flags::DRIVE_UPLOAD), 'Existing sites keep uploads enabled by default.');
runtime_assert(!$flags::enabled($flags::DRIVE_SYNC), 'A site without saved sync consent remains disabled.');
runtime_assert(!$flags::is_overridden($flags::DRIVE_SYNC), 'An undefined deployment constant must leave the checkbox editable.');
$GLOBALS['runtime_settings'] = $flags::settings_from_request(array('drive_features_present'=>'1', 'drive_upload_enabled'=>'1', 'drive_sync_enabled'=>'1'), array());
runtime_assert($flags::enabled($flags::DRIVE_SYNC), 'Saving the visible toggle enables the server-side gate.');
runtime_assert($flags::enabled($flags::DRIVE_UPLOAD), 'Saving sync preserves the selected upload setting.');
$GLOBALS['runtime_settings'] = $flags::settings_from_request(array('drive_features_present'=>'1'), $GLOBALS['runtime_settings']);
runtime_assert(!$flags::enabled($flags::DRIVE_SYNC) && !$flags::enabled($flags::DRIVE_UPLOAD), 'Unchecked toggles disable both server-side operations.');
$saved = array('drive_sync_enabled'=>1, 'drive_upload_enabled'=>0);
runtime_assert($flags::settings_from_request(array(), $saved) === array('drive_upload_enabled'=>0, 'drive_sync_enabled'=>1), 'An older settings client must preserve existing runtime flags.');
$GLOBALS['runtime_settings'] = array('drive_sync_enabled'=>'false', 'drive_upload_enabled'=>'true');
runtime_assert(!$flags::enabled($flags::DRIVE_SYNC) && !$flags::enabled($flags::DRIVE_UPLOAD), 'Arbitrary truthy strings must not enable a feature.');
define('OLAMA_MEDIA_DRIVE_SYNC_ENABLED', false);
$GLOBALS['runtime_settings'] = array('drive_sync_enabled'=>1);
runtime_assert($flags::is_overridden($flags::DRIVE_SYNC) && !$flags::enabled($flags::DRIVE_SYNC), 'An explicit deployment freeze remains authoritative and visible.');
runtime_assert($flags::settings_from_request(array('drive_features_present'=>'1'), array('drive_sync_enabled'=>1))['drive_sync_enabled'] === 1, 'A disabled control must not erase the saved setting behind a deployment override.');
define('OLAMA_MEDIA_DRIVE_UPLOAD_ENABLED', true);
$GLOBALS['runtime_settings']['drive_upload_enabled'] = 0;
runtime_assert($flags::enabled($flags::DRIVE_UPLOAD), 'An explicit deployment enable remains authoritative.');
runtime_assert(!$flags::enabled($flags::DRIVE_FOLDER_CREATION) && !$flags::enabled($flags::LEGACY_SYNC), 'Runtime toggles never enable legacy sync or unrestricted folder creation.');
echo "Drive runtime settings tests passed.\n";
