<?php

namespace SimpleMail;

use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\RfcComplianceException;

/**
 * Remplace l'envoi de wp_mail() via le filtre `pre_wp_mail`.
 *
 * Reprend la logique de wp_mail() (parsing des en-têtes, filtres
 * wp_mail_from / wp_mail_from_name / wp_mail_content_type / wp_mail_charset,
 * actions wp_mail_succeeded / wp_mail_failed) pour rester compatible
 * avec les autres plugins.
 */
final class WpMailHandler
{
    public const LAST_ERROR_OPTION = 'simplemail_last_error';

    private const SKIPPED_HEADERS = ['mime-version', 'content-transfer-encoding', 'x-mailer', 'date', 'message-id'];

    /** Transport réutilisé pour tous les e-mails d'une même requête (une seule connexion SMTP). */
    private ?EsmtpTransport $transport = null;

    public function __construct(
        private readonly Settings $settings,
        private readonly TransportFactory $transportFactory,
    ) {
    }

    /**
     * @param null|bool $return Valeur courte-circuit (null = pas encore géré).
     * @param array     $atts   to, subject, message, headers, attachments.
     */
    public function handle(mixed $return, array $atts): mixed
    {
        if ($return !== null) {
            return $return; // un autre plugin a déjà géré l'envoi
        }

        $mailData = [
            'to'          => $atts['to'] ?? [],
            'subject'     => (string) ($atts['subject'] ?? ''),
            'message'     => (string) ($atts['message'] ?? ''),
            'headers'     => $atts['headers'] ?? [],
            'attachments' => $atts['attachments'] ?? [],
        ];

        try {
            $email = $this->buildEmail($mailData);
            $email = apply_filters('simplemail_email', $email, $mailData);

            $this->transport ??= $this->transportFactory->create();
            $this->transport->send($email);
        } catch (\Throwable $e) {
            $this->resetTransport();
            $mailData['phpmailer_exception_code'] = $e->getCode();
            update_option(self::LAST_ERROR_OPTION, [
                'time'    => time(),
                'message' => $e->getMessage(),
                'to'      => $this->stringifyRecipients($mailData['to']),
                'subject' => $mailData['subject'],
            ], false);

            do_action('wp_mail_failed', new \WP_Error('wp_mail_failed', $e->getMessage(), $mailData));

            return false;
        }

        do_action('wp_mail_succeeded', $mailData);

        return true;
    }

    public function buildEmail(array $data): Email
    {
        $parsed = $this->parseHeaders($data['headers']);
        $email = new Email();

        // --- Expéditeur -------------------------------------------------
        $force = (bool) $this->settings->get('force_from');
        $fromEmail = $parsed['from_email'];
        $fromName = $parsed['from_name'];

        if ($force || $fromEmail === '') {
            $fromEmail = (string) $this->settings->get('from_email') ?: $this->defaultFromEmail();
        }
        if ($force || $fromName === '') {
            $fromName = (string) $this->settings->get('from_name') ?: 'WordPress';
        }

        $fromEmail = apply_filters('wp_mail_from', $fromEmail);
        $fromName = apply_filters('wp_mail_from_name', $fromName);

        if ($force) {
            $fromEmail = (string) $this->settings->get('from_email') ?: $fromEmail;
            $fromName = (string) $this->settings->get('from_name') ?: $fromName;
        }

        $email->from(new Address($fromEmail, $fromName));

        // --- Destinataires ----------------------------------------------
        foreach ($this->parseAddressList($data['to']) as $address) {
            $email->addTo($address);
        }
        foreach ($parsed['cc'] as $address) {
            $email->addCc($address);
        }
        foreach ($parsed['bcc'] as $address) {
            $email->addBcc($address);
        }
        foreach ($parsed['reply_to'] as $address) {
            $email->addReplyTo($address);
        }
        if ($parsed['return_path']) {
            $email->returnPath($parsed['return_path']);
        }
        if ($parsed['sender']) {
            $email->sender($parsed['sender']);
        }

        // --- Contenu ----------------------------------------------------
        $contentType = apply_filters('wp_mail_content_type', $parsed['content_type'] ?: 'text/plain');
        $charset = apply_filters('wp_mail_charset', $parsed['charset'] ?: get_bloginfo('charset'));

        $email->subject($data['subject']);

        if (stripos((string) $contentType, 'text/html') !== false) {
            $email->html($data['message'], $charset);
        } elseif (stripos((string) $contentType, 'multipart/alternative') !== false) {
            $parts = $parsed['boundary'] !== '' ? $this->splitAlternative($data['message'], $parsed['boundary']) : [];
            if ($parts !== []) {
                // Corps MIME déjà construit par l'appelant (cas prévu par wp_mail() avec boundary).
                if (isset($parts['text'])) {
                    $email->text($parts['text'][0], $parts['text'][1] ?: $charset);
                }
                if (isset($parts['html'])) {
                    $email->html($parts['html'][0], $parts['html'][1] ?: $charset);
                }
            } else {
                // Ex. WooCommerce en mode « multipart » : HTML + version texte générée.
                $email->html($data['message'], $charset);
                $email->text(trim(html_entity_decode(wp_strip_all_tags($data['message']), ENT_QUOTES, $charset)), $charset);
            }
        } else {
            $email->text($data['message'], $charset);
        }

        // --- Pièces jointes ---------------------------------------------
        $attachments = $data['attachments'];
        if (!is_array($attachments)) {
            $attachments = explode("\n", str_replace("\r\n", "\n", (string) $attachments));
        }
        foreach ($attachments as $name => $path) {
            $path = trim((string) $path);
            if ($path === '') {
                continue;
            }
            if (!is_readable($path)) {
                throw new \RuntimeException(sprintf('Pièce jointe introuvable : %s', $path));
            }
            $email->attachFromPath($path, is_string($name) ? $name : null);
        }

        // --- En-têtes personnalisés -------------------------------------
        foreach ($parsed['custom'] as [$name, $value]) {
            $email->getHeaders()->addTextHeader($name, $value);
        }

        return $email;
    }

    /**
     * @return array{from_email:string, from_name:string, content_type:string, charset:string, boundary:string, cc:Address[], bcc:Address[], reply_to:Address[], return_path:?Address, sender:?Address, custom:array<int, array{0:string,1:string}>}
     */
    private function parseHeaders(mixed $headers): array
    {
        $out = [
            'from_email' => '', 'from_name' => '', 'content_type' => '', 'charset' => '', 'boundary' => '',
            'cc' => [], 'bcc' => [], 'reply_to' => [], 'return_path' => null, 'sender' => null, 'custom' => [],
        ];

        if (empty($headers)) {
            return $out;
        }
        if (!is_array($headers)) {
            $headers = explode("\n", str_replace("\r\n", "\n", (string) $headers));
        }

        foreach ($headers as $header) {
            if (!is_string($header) || !str_contains($header, ':')) {
                continue;
            }
            [$name, $value] = explode(':', trim($header), 2);
            $name = trim($name);
            $value = trim($value);
            if ($value === '') {
                continue;
            }

            switch (strtolower($name)) {
                case 'from':
                    $address = $this->parseAddress($value);
                    if ($address) {
                        $out['from_email'] = $address->getAddress();
                        $out['from_name'] = $address->getName();
                    }
                    break;

                case 'content-type':
                    $parts = array_map('trim', explode(';', $value));
                    $out['content_type'] = strtolower(array_shift($parts));
                    foreach ($parts as $part) {
                        if (stripos($part, 'charset=') === 0) {
                            $out['charset'] = trim(substr($part, 8), " \"'");
                        } elseif (stripos($part, 'boundary=') === 0) {
                            $out['boundary'] = trim(substr($part, 9), " \"'");
                        }
                    }
                    break;

                case 'cc':
                    array_push($out['cc'], ...$this->parseAddressList($value));
                    break;

                case 'bcc':
                    array_push($out['bcc'], ...$this->parseAddressList($value));
                    break;

                case 'reply-to':
                    array_push($out['reply_to'], ...$this->parseAddressList($value));
                    break;

                // Symfony impose un type d'en-tête précis pour ceux-ci :
                // addTextHeader() lèverait une LogicException et ferait échouer l'envoi.
                case 'return-path':
                    $out['return_path'] = $this->parseAddress($value);
                    break;

                case 'sender':
                    $out['sender'] = $this->parseAddress($value);
                    break;

                case 'to':
                    // Les destinataires viennent du paramètre $to de wp_mail().
                    break;

                default:
                    if (!in_array(strtolower($name), self::SKIPPED_HEADERS, true)) {
                        $out['custom'][] = [$name, $value];
                    }
            }
        }

        return $out;
    }

    /** @return Address[] */
    private function parseAddressList(mixed $list): array
    {
        if (!is_array($list)) {
            $list = explode(',', (string) $list);
        }

        $addresses = [];
        foreach ($list as $item) {
            $address = $this->parseAddress((string) $item);
            if ($address) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }

    /** Accepte "email@domaine" ou "Nom <email@domaine>" (même logique que wp_mail()). */
    private function parseAddress(string $value): ?Address
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $name = '';
        if (preg_match('/(.*)<(.+)>/', $value, $m) && count($m) === 3) {
            $name = trim(str_replace('"', '', $m[1]));
            $value = trim($m[2]);
        }

        try {
            return new Address($value, $name);
        } catch (RfcComplianceException) {
            // Comme PHPMailer : une adresse invalide est ignorée au lieu de bloquer tout l'envoi.
            return null;
        }
    }

    /**
     * Découpe un corps multipart/alternative brut en parties texte et HTML.
     *
     * @return array{text?: array{0:string,1:string}, html?: array{0:string,1:string}}
     */
    private function splitAlternative(string $body, string $boundary): array
    {
        $body = str_replace("\r\n", "\n", $body);
        $chunks = explode('--' . $boundary, $body);
        array_shift($chunks); // préambule

        $out = [];
        foreach ($chunks as $chunk) {
            if (str_starts_with($chunk, '--')) {
                break; // délimiteur de fin
            }
            $chunk = ltrim($chunk, "\n");
            [$rawHeaders, $content] = array_pad(explode("\n\n", $chunk, 2), 2, '');

            $type = 'text/plain';
            $charset = '';
            $encoding = '';
            foreach (explode("\n", $rawHeaders) as $line) {
                if (!str_contains($line, ':')) {
                    continue;
                }
                [$name, $value] = array_map('trim', explode(':', $line, 2));
                if (strcasecmp($name, 'Content-Type') === 0) {
                    $params = array_map('trim', explode(';', $value));
                    $type = strtolower(array_shift($params));
                    foreach ($params as $param) {
                        if (stripos($param, 'charset=') === 0) {
                            $charset = trim(substr($param, 8), " \"'");
                        }
                    }
                } elseif (strcasecmp($name, 'Content-Transfer-Encoding') === 0) {
                    $encoding = strtolower($value);
                }
            }

            $content = rtrim($content, "\n");
            if ($encoding === 'base64') {
                $content = (string) base64_decode($content);
            } elseif ($encoding === 'quoted-printable') {
                $content = quoted_printable_decode($content);
            }

            if ($type === 'text/plain') {
                $out['text'] = [$content, $charset];
            } elseif ($type === 'text/html') {
                $out['html'] = [$content, $charset];
            }
        }

        return $out;
    }

    private function resetTransport(): void
    {
        try {
            $this->transport?->stop();
        } catch (\Throwable) {
        }
        $this->transport = null;
    }

    private function defaultFromEmail(): string
    {
        $host = wp_parse_url(network_home_url(), PHP_URL_HOST) ?: 'localhost';
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return 'wordpress@' . $host;
    }

    private function stringifyRecipients(mixed $to): string
    {
        return is_array($to) ? implode(', ', array_map('strval', $to)) : (string) $to;
    }
}
