<?php

namespace SimpleMail;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

/**
 * EsmtpTransport qui refuse d'envoyer les identifiants sur une connexion non chiffrée.
 *
 * En mode STARTTLS, Symfony ne chiffre que si le serveur annonce STARTTLS : si le serveur
 * ne le propose pas (ou si un intermédiaire le retire de la réponse EHLO), la commande AUTH
 * partirait en clair. On l'intercepte ici.
 */
final class SecureEsmtpTransport extends EsmtpTransport
{
    private bool $allowInsecureAuth = false;

    public function setAllowInsecureAuth(bool $allow): static
    {
        $this->allowInsecureAuth = $allow;

        return $this;
    }

    public function executeCommand(string $command, array $codes): string
    {
        if (!$this->allowInsecureAuth && str_starts_with(strtoupper($command), 'AUTH ')) {
            $stream = $this->getStream();
            if ($stream instanceof SocketStream && !$stream->isTLS()) {
                throw new TransportException('Connexion non chiffrée : le serveur ne propose pas STARTTLS, authentification refusée pour ne pas envoyer le mot de passe en clair. Utilisez le chiffrement SSL/TLS implicite (port 465) ou un serveur compatible STARTTLS.');
            }
        }

        return parent::executeCommand($command, $codes);
    }
}
