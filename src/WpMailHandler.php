<?php

namespace SimpleMail;

use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

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

            $this->transportFactory->create()->send($email);
        } catch (\Throwable $e) {
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

        // --- Contenu ----------------------------------------------------
        $contentType = apply_filters('wp_mail_content_type', $parsed['content_type'] ?: 'text/plain');
        $charset = apply_filters('wp_mail_charset', $parsed['charset'] ?: get_bloginfo('charset'));

        $email->subject($data['subject']);

        if (stripos((string) $contentType, 'text/html') !== false) {
            $email->html($data['message'], $charset);
        } elseif (stripos((string) $contentType, 'multipart/alternative') !== false) {
            // Ex. WooCommerce en mode « multipart » : HTML + version texte générée.
            $email->html($data['message'], $charset);
            $email->text(trim(html_entity_decode(wp_strip_all_tags($data['message']), ENT_QUOTES, $charset)), $charset);
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
     * @return array{from_email:string, from_name:string, content_type:string, charset:string, cc:Address[], bcc:Address[], reply_to:Address[], custom:array<int, array{0:string,1:string}>}
     */
    private function parseHeaders(mixed $headers): array
    {
        $out = [
            'from_email' => '', 'from_name' => '', 'content_type' => '', 'charset' => '',
            'cc' => [], 'bcc' => [], 'reply_to' => [], 'custom' => [],
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

        return new Address($value, $name);
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
