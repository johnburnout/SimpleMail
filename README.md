# SimpleMail

Plugin WordPress qui envoie tous les e-mails (`wp_mail()`) via un serveur SMTP externe grâce à **Symfony Mailer**, au lieu de la fonction `mail()` de PHP.

## Prérequis

- WordPress 5.7+ (utilise le filtre `pre_wp_mail`)
- PHP 8.1+ avec l'extension `sodium` (incluse par défaut) et `openssl`

## Installation

1. Copier le dossier `simplemail` dans `wp-content/plugins/` (le dossier `vendor/` est déjà inclus).
   Pour régénérer les dépendances : `composer install --no-dev --optimize-autoloader`.
2. Activer le plugin, puis aller dans **Réglages → SimpleMail**.
3. Saisir hôte, port, chiffrement, identifiants et expéditeur, enregistrer, puis envoyer un e-mail de test.
4. Cocher **Activer** une fois le test réussi.

## Configuration via wp-config.php (optionnel)

Chaque réglage peut être forcé par une constante ; le champ devient alors non modifiable dans l'admin :

```php
define('SIMPLEMAIL_ENABLED', true);
define('SIMPLEMAIL_HOST', 'smtp.exemple.com');
define('SIMPLEMAIL_PORT', 587);
define('SIMPLEMAIL_ENCRYPTION', 'tls'); // 'tls' (STARTTLS) ou 'ssl' (SMTPS, port 465)
define('SIMPLEMAIL_AUTH', true);
define('SIMPLEMAIL_USERNAME', 'user@exemple.com');
define('SIMPLEMAIL_PASSWORD', 'secret');
define('SIMPLEMAIL_FROM_EMAIL', 'noreply@exemple.com');
define('SIMPLEMAIL_FROM_NAME', 'Mon site');
define('SIMPLEMAIL_FORCE_FROM', false);
define('SIMPLEMAIL_VERIFY_PEER', true);
define('SIMPLEMAIL_TIMEOUT', 15);
```

Sans constante, le mot de passe est stocké **chiffré** en base (libsodium, clé dérivée des salts WordPress — si les salts changent, il faut le ressaisir).

## Compatibilité

- Respecte les filtres `wp_mail`, `wp_mail_from`, `wp_mail_from_name`, `wp_mail_content_type`, `wp_mail_charset`
  et les actions `wp_mail_succeeded` / `wp_mail_failed`.
- Gère les en-têtes `From`, `Cc`, `Bcc`, `Reply-To`, `Content-Type` et les en-têtes personnalisés, ainsi que les pièces jointes
  (y compris `['nom.pdf' => '/chemin/fichier.pdf']`).
- L'action `phpmailer_init` n'est **pas** déclenchée (PHPMailer n'est plus utilisé).

## Hooks du plugin

- `simplemail_email` (filtre) : modifier l'objet `Symfony\Component\Mime\Email` avant envoi.
- `simplemail_transport` (filtre) : modifier le `EsmtpTransport` (ex. `setLocalDomain()`).

## Structure

```
simplemail.php          Point d'entrée du plugin
src/Plugin.php          Amorçage, branchement sur pre_wp_mail
src/Settings.php        Lecture/validation des réglages (+ constantes wp-config)
src/Crypto.php          Chiffrement du mot de passe
src/TransportFactory.php Création du transport SMTP Symfony
src/WpMailHandler.php   Conversion wp_mail() → Symfony Email et envoi
src/Admin.php           Page de réglages et e-mail de test
uninstall.php           Nettoyage des options à la désinstallation
```
