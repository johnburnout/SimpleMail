<?php

namespace SimpleMail;

final class Plugin
{
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        $settings = new Settings();

        if (is_admin()) {
            (new Admin($settings))->register();
        }

        // Toujours actif (pas seulement dans l'admin) : la vérification des mises à jour tourne
        // aussi via WP-Cron (mises à jour auto) et WP-CLI, où is_admin() vaut false. Sinon le
        // transient update_plugins recalculé hors admin perd l'entrée SimpleMail.
        (new GithubUpdater(SIMPLEMAIL_FILE, SIMPLEMAIL_GITHUB_REPO, SIMPLEMAIL_VERSION))->register();

        // mu-plugin qui prend le relais de la détection des mises à jour quand les extensions
        // ne sont pas chargées (ex. WP Workbench / WP-CLI --skip-plugins).
        MuPlugin::maybeInstall();

        if ($settings->isEnabled()) {
            $handler = new WpMailHandler($settings, new TransportFactory($settings));
            // pre_wp_mail (WP 5.7+) court-circuite PHPMailer / mail().
            add_filter('pre_wp_mail', [$handler, 'handle'], 10, 2);
        }
    }
}
