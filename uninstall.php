<?php

defined('WP_UNINSTALL_PLUGIN') || exit;

$simplemail_cleanup = static function (): void {
    global $wpdb;

    delete_option('simplemail_settings');
    delete_option('simplemail_last_error');
    delete_transient('simplemail_github_release');

    // Messages flash par utilisateur (simplemail_flash_<id>).
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like('_transient_simplemail_flash_') . '%',
        $wpdb->esc_like('_transient_timeout_simplemail_flash_') . '%'
    ));
};

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $simplemail_site_id) {
        switch_to_blog($simplemail_site_id);
        $simplemail_cleanup();
        restore_current_blog();
    }
    delete_site_transient('update_plugins');
} else {
    $simplemail_cleanup();
}
