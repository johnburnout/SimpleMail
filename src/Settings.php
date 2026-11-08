<?php

namespace SimpleMail;

/**
 * Réglages stockés dans l'option `simplemail_settings`.
 *
 * Chaque réglage peut être forcé dans wp-config.php via une constante
 * SIMPLEMAIL_<CLÉ> (ex. SIMPLEMAIL_HOST, SIMPLEMAIL_PASSWORD). Une constante
 * a toujours priorité et rend le champ non modifiable dans l'admin.
 */
final class Settings
{
    public const OPTION = 'simplemail_settings';

    public const DEFAULTS = [
        'enabled'     => false,
        'host'        => '',
        'port'        => 587,
        'encryption'  => 'tls',   // tls (STARTTLS si proposé) | ssl (SMTPS implicite)
        'auth'        => true,
        'username'    => '',
        'password'    => '',      // chiffré en base
        'from_email'  => '',
        'from_name'   => '',
        'force_from'  => false,   // écrase le From fourni par les autres plugins
        'verify_peer' => true,
        'timeout'     => 15,
    ];

    /** Valeurs résolues (constantes appliquées), mot de passe encore chiffré. */
    private ?array $cache = null;

    /** Mot de passe déchiffré, calculé une seule fois quand la clé est disponible. */
    private ?string $password = null;

    public function all(): array
    {
        if ($this->cache === null) {
            $stored = get_option(self::OPTION, []);
            $values = array_merge(self::DEFAULTS, is_array($stored) ? $stored : []);
            foreach (array_keys(self::DEFAULTS) as $key) {
                if ($this->isConstant($key)) {
                    $values[$key] = constant($this->constantName($key));
                }
            }
            $this->cache = $values;
        }

        $values = $this->cache;
        if (!$this->isConstant('password')) {
            // Le plugin est chargé avant pluggable.php : wp_salt() (donc la clé) n'existe pas
            // encore au démarrage. On ne déchiffre - et ne mémorise - qu'une fois la clé disponible.
            if ($this->password === null && Crypto::isAvailable()) {
                $this->password = Crypto::decrypt((string) $values['password']);
            }
            $values['password'] = $this->password ?? '';
        }

        return $values;
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    public function isEnabled(): bool
    {
        return (bool) $this->get('enabled') && $this->get('host') !== '';
    }

    public function isConstant(string $key): bool
    {
        return defined($this->constantName($key));
    }

    public function constantName(string $key): string
    {
        return 'SIMPLEMAIL_' . strtoupper($key);
    }

    /** Nettoie les données soumises par le formulaire (callback register_setting). */
    public function sanitize(mixed $input): array
    {
        $input = is_array($input) ? $input : [];
        $current = get_option(self::OPTION, []);
        $current = is_array($current) ? $current : [];

        $out = [
            'enabled'     => !empty($input['enabled']),
            'host'        => sanitize_text_field($input['host'] ?? ''),
            'port'        => max(1, min(65535, (int) ($input['port'] ?? 587))),
            'encryption'  => in_array($input['encryption'] ?? '', ['tls', 'ssl'], true) ? $input['encryption'] : 'tls',
            'auth'        => !empty($input['auth']),
            'username'    => sanitize_text_field($input['username'] ?? ''),
            'from_email'  => sanitize_email($input['from_email'] ?? ''),
            'from_name'   => sanitize_text_field($input['from_name'] ?? ''),
            'force_from'  => !empty($input['force_from']),
            'verify_peer' => !empty($input['verify_peer']),
            'timeout'     => max(1, min(120, (int) ($input['timeout'] ?? 15))),
        ];

        // Mot de passe : vide = on garde l'existant, case "effacer" = on supprime.
        // Les données arrivent déjà dé-slashées par options.php : pas de wp_unslash() ici.
        $password = (string) ($input['password'] ?? '');
        if (!empty($input['password_clear'])) {
            $out['password'] = '';
        } elseif (Crypto::isEncrypted($password)) {
            // Valeur déjà chiffrée : sanitize() peut être appelé deux fois
            // (update_option() -> add_option() au premier enregistrement).
            $out['password'] = $password;
        } elseif ($password !== '') {
            $out['password'] = Crypto::encrypt($password);
        } else {
            $out['password'] = $current['password'] ?? '';
        }

        $this->cache = null;
        $this->password = null;

        return $out;
    }
}
