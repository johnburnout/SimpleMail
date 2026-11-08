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

    /**
     * STARTTLS accepté sur la connexion courante. SocketStream::isTLS() ne reflète que le TLS
     * implicite (SMTPS) : il reste false après un STARTTLS réussi, d'où ce suivi séparé.
     * Si l'activation du chiffrement échoue, Symfony lève une exception avant toute commande AUTH.
     */
    private bool $startTlsDone = false;

    public function setAllowInsecureAuth(bool $allow): static
    {
        $this->allowInsecureAuth = $allow;

        return $this;
    }

    public function start(): void
    {
        $this->startTlsDone = false;
        parent::start();
    }

    public function stop(): void
    {
        $this->startTlsDone = false;
        parent::stop();
    }

    public function executeCommand(string $command, array $codes): string
    {
        $upper = strtoupper($command);

        if (!$this->allowInsecureAuth && !$this->startTlsDone && str_starts_with($upper, 'AUTH ')) {
            $stream = $this->getStream();
            if ($stream instanceof SocketStream && !$stream->isTLS()) {
                throw new TransportException('Connexion non chiffrée : le serveur ne propose pas STARTTLS, authentification refusée pour ne pas envoyer le mot de passe en clair. Utilisez le chiffrement SSL/TLS implicite (port 465) ou un serveur compatible STARTTLS.');
            }
        }

        $response = parent::executeCommand($command, $codes);

        if ($upper === "STARTTLS\r\n") {
            $this->startTlsDone = true;
        }

        return $response;
    }
}
