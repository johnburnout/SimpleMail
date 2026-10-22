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
            (new GithubUpdater(SIMPLEMAIL_FILE, SIMPLEMAIL_GITHUB_REPO))->register();
        }

        if ($settings->isEnabled()) {
            $handler = new WpMailHandler($settings, new TransportFactory($settings));
            // pre_wp_mail (WP 5.7+) court-circuite PHPMailer / mail().
            add_filter('pre_wp_mail', [$handler, 'handle'], 10, 2);
        }
    }
}
