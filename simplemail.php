<?php
/**
 * Plugin Name:       SimpleMail
 * Description:       Envoie les e-mails WordPress via un serveur SMTP externe (Symfony Mailer) au lieu de la fonction mail() de PHP.
 * Version:           1.1.5
 * Requires at least: 5.7
 * Requires PHP:      8.1
 * Author:            Jean Roussie
 * License:           GPL-2.0-or-later
 * Text Domain:       simplemail
 * Update URI:        https://github.com/johnburnout/SimpleMail
 */

defined('ABSPATH') || exit;

define('SIMPLEMAIL_VERSION', '1.1.5');
define('SIMPLEMAIL_FILE', __FILE__);

// Dépôt GitHub (owner/repo) utilisé pour la vérification des mises à jour.
if (!defined('SIMPLEMAIL_GITHUB_REPO')) {
    define('SIMPLEMAIL_GITHUB_REPO', 'johnburnout/SimpleMail');
}

if (version_compare(PHP_VERSION, '8.1', '<')) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>SimpleMail nécessite PHP 8.1 ou plus.</p></div>';
    });
    return;
}

$simplemail_autoload = __DIR__ . '/vendor/autoload.php';
if (!is_readable($simplemail_autoload)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>SimpleMail : dépendances manquantes. Lancez <code>composer install --no-dev</code> dans le dossier du plugin.</p></div>';
    });
    return;
}
require_once $simplemail_autoload;

register_activation_hook(__FILE__, [\SimpleMail\MuPlugin::class, 'install']);
register_deactivation_hook(__FILE__, [\SimpleMail\MuPlugin::class, 'uninstall']);

\SimpleMail\Plugin::boot();
