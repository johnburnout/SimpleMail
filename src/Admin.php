<?php

namespace SimpleMail;

final class Admin
{
    private const PAGE = 'simplemail';
    private const GROUP = 'simplemail';
    private const TEST_ACTION = 'simplemail_test';

    public function __construct(private readonly Settings $settings)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'registerSetting']);
        add_action('admin_post_' . self::TEST_ACTION, [$this, 'sendTest']);
        add_filter('plugin_action_links_' . plugin_basename(SIMPLEMAIL_FILE), [$this, 'actionLinks']);
    }

    public function menu(): void
    {
        add_options_page('SimpleMail', 'SimpleMail', 'manage_options', self::PAGE, [$this, 'render']);
    }

    public function registerSetting(): void
    {
        register_setting(self::GROUP, Settings::OPTION, [
            'type'              => 'array',
            'sanitize_callback' => [$this->settings, 'sanitize'],
            'default'           => Settings::DEFAULTS,
        ]);
    }

    public function actionLinks(array $links): array
    {
        array_unshift($links, sprintf('<a href="%s">%s</a>', esc_url($this->pageUrl()), esc_html__('Réglages', 'simplemail')));

        return $links;
    }

    public function sendTest(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Accès refusé.', 'simplemail'), 403);
        }
        check_admin_referer(self::TEST_ACTION);

        $to = sanitize_email(wp_unslash($_POST['simplemail_test_to'] ?? ''));
        if (!is_email($to)) {
            $this->flash('error', __('Adresse de test invalide.', 'simplemail'));
            wp_safe_redirect($this->pageUrl());
            exit;
        }

        // Le test passe toujours par SimpleMail, même si l'envoi n'est pas encore activé.
        $handler = new WpMailHandler($this->settings, new TransportFactory($this->settings));
        $error = null;
        $catch = static function (\WP_Error $e) use (&$error): void {
            $error = $e->get_error_message();
        };
        add_action('wp_mail_failed', $catch);

        $ok = $handler->handle(null, [
            'to'      => $to,
            'subject' => sprintf('[%s] Test SimpleMail', wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)),
            'message' => "<p>Bonjour,</p><p>Cet e-mail de test a été envoyé par <strong>SimpleMail</strong> via le serveur SMTP configuré.</p><p>Date : " . esc_html(wp_date('Y-m-d H:i:s')) . '</p>',
            'headers' => ['Content-Type: text/html; charset=UTF-8'],
        ]);

        remove_action('wp_mail_failed', $catch);

        if ($ok === true) {
            $this->flash('success', sprintf(__('E-mail de test envoyé à %s.', 'simplemail'), $to));
        } else {
            $this->flash('error', sprintf(__('Échec de l’envoi : %s', 'simplemail'), $error ?? 'erreur inconnue'));
        }

        wp_safe_redirect($this->pageUrl());
        exit;
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $s = $this->settings->all();
        $opt = Settings::OPTION;
        $flash = get_transient($this->flashKey());
        if ($flash) {
            delete_transient($this->flashKey());
        }
        $lastError = get_option(WpMailHandler::LAST_ERROR_OPTION);
        ?>
        <div class="wrap">
            <h1>SimpleMail</h1>

            <?php if ($flash) : ?>
                <div class="notice notice-<?php echo esc_attr($flash['type']); ?> is-dismissible"><p><?php echo esc_html($flash['message']); ?></p></div>
            <?php endif; ?>

            <?php if (!$this->settings->isEnabled()) : ?>
                <div class="notice notice-warning"><p><?php esc_html_e('L’envoi via SMTP est désactivé : WordPress utilise encore la fonction mail() de PHP.', 'simplemail'); ?></p></div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields(self::GROUP); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Activer', 'simplemail'); ?></th>
                        <td><label><input type="checkbox" name="<?php echo esc_attr($opt); ?>[enabled]" value="1" <?php checked($s['enabled']); ?> <?php $this->lock('enabled'); ?>>
                            <?php esc_html_e('Envoyer tous les e-mails de WordPress via ce serveur SMTP', 'simplemail'); ?></label></td>
                    </tr>

                    <tr><th colspan="2"><h2><?php esc_html_e('Serveur SMTP', 'simplemail'); ?></h2></th></tr>
                    <?php $this->textRow('host', __('Hôte', 'simplemail'), $s['host'], 'text', 'smtp.exemple.com'); ?>
                    <?php $this->textRow('port', __('Port', 'simplemail'), (string) $s['port'], 'number', '587', __('587 pour STARTTLS, 465 pour SSL.', 'simplemail')); ?>
                    <tr>
                        <th scope="row"><label for="sm-encryption"><?php esc_html_e('Chiffrement', 'simplemail'); ?></label></th>
                        <td>
                            <select id="sm-encryption" name="<?php echo esc_attr($opt); ?>[encryption]" <?php $this->lock('encryption'); ?>>
                                <option value="tls" <?php selected($s['encryption'], 'tls'); ?>>STARTTLS (<?php esc_html_e('automatique si proposé par le serveur', 'simplemail'); ?>)</option>
                                <option value="ssl" <?php selected($s['encryption'], 'ssl'); ?>>SSL/TLS <?php esc_html_e('implicite', 'simplemail'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <?php $this->textRow('timeout', __('Délai (s)', 'simplemail'), (string) $s['timeout'], 'number', '15'); ?>
                    <tr>
                        <th scope="row"><?php esc_html_e('Certificat', 'simplemail'); ?></th>
                        <td><label><input type="checkbox" name="<?php echo esc_attr($opt); ?>[verify_peer]" value="1" <?php checked($s['verify_peer']); ?> <?php $this->lock('verify_peer'); ?>>
                            <?php esc_html_e('Vérifier le certificat SSL du serveur (recommandé)', 'simplemail'); ?></label></td>
                    </tr>

                    <tr><th colspan="2"><h2><?php esc_html_e('Authentification', 'simplemail'); ?></h2></th></tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Authentification', 'simplemail'); ?></th>
                        <td><label><input type="checkbox" name="<?php echo esc_attr($opt); ?>[auth]" value="1" <?php checked($s['auth']); ?> <?php $this->lock('auth'); ?>>
                            <?php esc_html_e('Le serveur exige un identifiant et un mot de passe', 'simplemail'); ?></label></td>
                    </tr>
                    <?php $this->textRow('username', __('Identifiant', 'simplemail'), $s['username'], 'text', '', '', 'off'); ?>
                    <tr>
                        <th scope="row"><label for="sm-password"><?php esc_html_e('Mot de passe', 'simplemail'); ?></label></th>
                        <td>
                            <?php if ($this->settings->isConstant('password')) : ?>
                                <em><?php esc_html_e('Défini dans wp-config.php (SIMPLEMAIL_PASSWORD).', 'simplemail'); ?></em>
                            <?php else : ?>
                                <input type="password" id="sm-password" class="regular-text" name="<?php echo esc_attr($opt); ?>[password]" value="" autocomplete="new-password"
                                       placeholder="<?php echo $s['password'] !== '' ? esc_attr__('•••••••• (enregistré, laisser vide pour conserver)', 'simplemail') : ''; ?>">
                                <?php if ($s['password'] !== '') : ?>
                                    <br><label><input type="checkbox" name="<?php echo esc_attr($opt); ?>[password_clear]" value="1"> <?php esc_html_e('Effacer le mot de passe enregistré', 'simplemail'); ?></label>
                                <?php endif; ?>
                                <p class="description"><?php esc_html_e('Stocké chiffré en base. Vous pouvez aussi le définir dans wp-config.php avec la constante SIMPLEMAIL_PASSWORD.', 'simplemail'); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <tr><th colspan="2"><h2><?php esc_html_e('Expéditeur', 'simplemail'); ?></h2></th></tr>
                    <?php $this->textRow('from_email', __('E-mail', 'simplemail'), $s['from_email'], 'email', 'noreply@exemple.com', __('Utilisé si aucun expéditeur n’est fourni. Doit généralement être autorisé par le serveur SMTP.', 'simplemail')); ?>
                    <?php $this->textRow('from_name', __('Nom', 'simplemail'), $s['from_name'], 'text', get_bloginfo('name')); ?>
                    <tr>
                        <th scope="row"><?php esc_html_e('Forcer', 'simplemail'); ?></th>
                        <td><label><input type="checkbox" name="<?php echo esc_attr($opt); ?>[force_from]" value="1" <?php checked($s['force_from']); ?> <?php $this->lock('force_from'); ?>>
                            <?php esc_html_e('Toujours utiliser cet expéditeur, même si un plugin en définit un autre', 'simplemail'); ?></label></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <hr>
            <h2><?php esc_html_e('Envoyer un e-mail de test', 'simplemail'); ?></h2>
            <p><?php esc_html_e('Enregistrez d’abord vos réglages. Le test utilise le serveur SMTP même si l’envoi n’est pas encore activé.', 'simplemail'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::TEST_ACTION); ?>">
                <?php wp_nonce_field(self::TEST_ACTION); ?>
                <input type="email" name="simplemail_test_to" class="regular-text" required value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>">
                <?php submit_button(__('Envoyer le test', 'simplemail'), 'secondary', 'submit', false); ?>
            </form>

            <?php if (is_array($lastError)) : ?>
                <hr>
                <h2><?php esc_html_e('Dernière erreur', 'simplemail'); ?></h2>
                <p><code><?php echo esc_html(wp_date('Y-m-d H:i:s', (int) $lastError['time'])); ?></code>
                    — <?php echo esc_html($lastError['to']); ?> — <?php echo esc_html($lastError['subject']); ?></p>
                <pre style="white-space:pre-wrap;background:#fff;padding:10px;border:1px solid #ccd0d4;"><?php echo esc_html($lastError['message']); ?></pre>
            <?php endif; ?>
        </div>
        <?php
    }

    private function textRow(string $key, string $label, string $value, string $type = 'text', string $placeholder = '', string $help = '', string $autocomplete = ''): void
    {
        $id = 'sm-' . $key;
        printf('<tr><th scope="row"><label for="%s">%s</label></th><td>', esc_attr($id), esc_html($label));
        printf(
            '<input type="%s" id="%s" class="%s" name="%s[%s]" value="%s" placeholder="%s"%s %s>',
            esc_attr($type),
            esc_attr($id),
            $type === 'number' ? 'small-text' : 'regular-text',
            esc_attr(Settings::OPTION),
            esc_attr($key),
            esc_attr($value),
            esc_attr($placeholder),
            $autocomplete ? ' autocomplete="' . esc_attr($autocomplete) . '"' : '',
            $this->settings->isConstant($key) ? 'readonly' : ''
        );
        if ($this->settings->isConstant($key)) {
            printf('<p class="description">%s</p>', esc_html(sprintf(__('Défini dans wp-config.php (%s).', 'simplemail'), $this->settings->constantName($key))));
        } elseif ($help !== '') {
            printf('<p class="description">%s</p>', esc_html($help));
        }
        echo '</td></tr>';
    }

    private function lock(string $key): void
    {
        if ($this->settings->isConstant($key)) {
            echo 'disabled title="' . esc_attr(sprintf(__('Défini dans wp-config.php (%s)', 'simplemail'), $this->settings->constantName($key))) . '"';
        }
    }

    private function flash(string $type, string $message): void
    {
        set_transient($this->flashKey(), ['type' => $type, 'message' => $message], 60);
    }

    private function flashKey(): string
    {
        return 'simplemail_flash_' . get_current_user_id();
    }

    private function pageUrl(): string
    {
        return admin_url('options-general.php?page=' . self::PAGE);
    }
}
