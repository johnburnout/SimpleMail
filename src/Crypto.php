<?php

namespace SimpleMail;

/**
 * Chiffrement du mot de passe SMTP en base (libsodium), clé dérivée des salts WordPress.
 * Si les salts changent, le mot de passe devra être ressaisi.
 */
final class Crypto
{
    private const PREFIX = 'sm1:';

    /** La clé dépend de wp_salt(), défini dans pluggable.php (chargé après les plugins). */
    public static function isAvailable(): bool
    {
        return function_exists('wp_salt');
    }

    public static function encrypt(string $plain): string
    {
        if ($plain === '' || !function_exists('sodium_crypto_secretbox')) {
            return $plain;
        }
        if (!self::isAvailable()) {
            throw new \LogicException('SimpleMail : chiffrement impossible avant le chargement de pluggable.php.');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plain, $nonce, self::key());

        return self::PREFIX . base64_encode($nonce . $cipher);
    }

    /** Vrai si la valeur est un mot de passe chiffré valide pour la clé actuelle. */
    public static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX) && self::decrypt($value) !== '';
    }

    public static function decrypt(string $stored): string
    {
        if (!str_starts_with($stored, self::PREFIX)) {
            return $stored; // valeur vide ou non chiffrée
        }
        if (!self::isAvailable()) {
            return ''; // clé indisponible : surtout ne pas déchiffrer avec une autre clé
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, self::key());

        return $plain === false ? '' : $plain;
    }

    private static function key(): string
    {
        return sodium_crypto_generichash('simplemail|' . wp_salt('auth'), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
