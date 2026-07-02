<?php
/**
 * Fired when the plugin is deleted.
 *
 * @package TranslationApi
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Stored API keys (hashes) created in the plugin's settings.
delete_option( 'translation_api_keys' );
