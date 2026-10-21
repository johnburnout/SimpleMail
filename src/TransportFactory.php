<?php

namespace SimpleMail;

use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

final class TransportFactory
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function create(?array $override = null): EsmtpTransport
    {
        $s = $override ?? $this->settings->all();

        // tls = true => connexion SSL implicite (SMTPS, port 465 en général).
        // Sinon STARTTLS est utilisé automatiquement si le serveur le propose.
        $transport = new EsmtpTransport((string) $s['host'], (int) $s['port'], $s['encryption'] === 'ssl');

        if (!empty($s['auth'])) {
            $transport->setUsername((string) $s['username']);
            $transport->setPassword((string) $s['password']);
        }

        $stream = $transport->getStream();
        if ($stream instanceof SocketStream) {
            $stream->setTimeout((float) $s['timeout']);
            if (empty($s['verify_peer'])) {
                $stream->setStreamOptions(['ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true,
                ]]);
            }
        }

        /** Permet d'ajuster le transport (ex. local domain, options TLS). */
        return apply_filters('simplemail_transport', $transport, $s);
    }
}
