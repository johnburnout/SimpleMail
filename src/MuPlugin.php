<?php

namespace SimpleMail;

/**
 * Installe / retire le mu-plugin `simplemail-updater.php`.
 *
 * Certains outils (ex. WP Workbench de Virtualmin) lancent WP-CLI sans charger les extensions
 * (--skip-plugins) : le code de SimpleMail ne tourne pas, ses mises à jour GitHub restent
 * invisibles. Les mu-plugins, eux, sont toujours chargés : celui-ci ne charge que
 * GithubUpdater, et seulement si SimpleMail n'a pas été chargé normalement.
 */
final class MuPlugin
{
    public const FILENAME = 'simplemail-updater.php';
    private const VERSION_OPTION = 'simplemail_mu_version';
    private const ERROR_OPTION = 'simplemail_mu_error';

    /**
     * (Ré)installe le mu-plugin s'il manque ou date d'une autre version du plugin.
     * Limité à l'admin, au cron et à WP-CLI : rien à faire sur les pages publiques.
     */
    public static function maybeInstall(): void
    {
        if (!is_admin() && !wp_doing_cron() && !(defined('WP_CLI') && WP_CLI)) {
            return;
        }
        if (get_option(self::VERSION_OPTION) === SIMPLEMAIL_VERSION && is_file(self::path())) {
            return;
        }
        self::install();
    }

    public static function install(): bool
    {
        $dir = self::dir();
        $ok = (is_dir($dir) || wp_mkdir_p($dir))
            && file_put_contents(self::path(), self::contents(), LOCK_EX) !== false;

        if ($ok) {
            update_option(self::VERSION_OPTION, SIMPLEMAIL_VERSION, true);
            delete_option(self::ERROR_OPTION);
        } else {
            update_option(self::ERROR_OPTION, sprintf('Impossible d’écrire %s (droits sur le dossier mu-plugins ?).', self::path()), false);
        }

        return $ok;
    }

    public static function uninstall(): void
    {
        if (is_file(self::path())) {
            @unlink(self::path());
        }
        delete_option(self::VERSION_OPTION);
        delete_option(self::ERROR_OPTION);
    }

    public static function lastError(): ?string
    {
        $error = get_option(self::ERROR_OPTION);

        return is_string($error) && $error !== '' ? $error : null;
    }

    public static function path(): string
    {
        return self::dir() . '/' . self::FILENAME;
    }

    private static function dir(): string
    {
        return defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
    }

    private static function contents(): string
    {
        // Chemin du plugin relatif à WP_PLUGIN_DIR (le dossier peut s'appeler autrement que "simplemail").
        $basename = var_export(plugin_basename(SIMPLEMAIL_FILE), true);
        $version = SIMPLEMAIL_VERSION;

        return <<<PHP
<?php
/**
 * Plugin Name: SimpleMail – mises à jour
 * Description: Installé automatiquement par SimpleMail ({$version}). Permet de détecter ses mises à jour même quand les extensions ne sont pas chargées (WP-CLI --skip-plugins, WP Workbench). Retiré à la désactivation de SimpleMail.
 */

defined('ABSPATH') || exit;

add_action('plugins_loaded', static function (): void {
    if (class_exists('SimpleMail\\\\Plugin', false)) {
        return; // SimpleMail est chargé normalement : il gère lui-même ses mises à jour.
    }

    \$file = WP_PLUGIN_DIR . '/' . {$basename};
    \$updater = dirname(\$file) . '/src/GithubUpdater.php';
    if (!is_readable(\$file) || !is_readable(\$updater)) {
        return; // plugin supprimé
    }

    \$data = get_file_data(\$file, ['version' => 'Version', 'update_uri' => 'Update URI']);
    if (defined('SIMPLEMAIL_GITHUB_REPO')) {
        \$repo = SIMPLEMAIL_GITHUB_REPO;
    } else {
        \$repo = trim((string) wp_parse_url(\$data['update_uri'], PHP_URL_PATH), '/');
    }
    if (\$data['version'] === '' || !preg_match('#^[\\w.-]+/[\\w.-]+\$#', \$repo)) {
        return;
    }

    require_once \$updater;
    (new \\SimpleMail\\GithubUpdater(\$file, \$repo, \$data['version']))->register();
});

PHP;
    }
}
