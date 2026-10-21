<?php
/**
 * Plugin Name:       SimpleMail
 * Description:       Envoie les e-mails WordPress via un serveur SMTP externe (Symfony Mailer) au lieu de la fonction mail() de PHP.
 * Version:           1.0.0
 * Requires at least: 5.7
 * Requires PHP:      8.1
 * Author:            Jean Roussie
 * License:           GPL-2.0-or-later
 * Text Domain:       simplemail
 */

defined('ABSPATH') || exit;

define('SIMPLEMAIL_VERSION', '1.0.0');
define('SIMPLEMAIL_FILE', __FILE__);

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

\SimpleMail\Plugin::boot();
