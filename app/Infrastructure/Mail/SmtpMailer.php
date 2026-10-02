<?php

declare(strict_types=1);

namespace App\Infrastructure\Mail;

/**
 * Minimal SMTP client over a raw socket — no PHPMailer/Symfony Mailer
 * dependency, consistent with the rest of this app staying
 * Composer-free at runtime (Phase 0 hosting constraints: no SSH means
 * no `composer install` on the server). Supports AUTH LOGIN, which is
 * what virtually every cPanel-created mailbox uses.
 *
 * Two connection styles, controlled by `encryption`:
 *  - 'ssl'  : implicit TLS from the first byte (typically port 465)
 *  - 'tls'  : plaintext connect, then STARTTLS (typically port 587)
 *  - 'none' : plaintext, no encryption (only for a trusted local relay)
 */
final class SmtpMailer implements MailerInterface
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly string $fromAddress,
        private readonly string $fromName,
        private readonly string $encryption = 'ssl',
    ) {
    }

    public function send(string $to, string $subject, string $body): void
    {
        $socket = $this->connect();

        try {
            $this->readResponse($socket, 220);

            $localName = $this->fromAddress !== '' ? substr(strrchr($this->fromAddress, '@') ?: '', 1) : 'localhost';
            $this->command($socket, 'EHLO ' . ($localName !== '' ? $localName : 'localhost'), 250);

            if ($this->encryption === 'tls') {
                $this->command($socket, 'STARTTLS', 220);

                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('SMTP STARTTLS negotiation failed.');
                }

                $this->command($socket, 'EHLO ' . ($localName !== '' ? $localName : 'localhost'), 250);
            }

            $this->command($socket, 'AUTH LOGIN', 334);
            $this->command($socket, base64_encode($this->username), 334);
            $this->command($socket, base64_encode($this->password), 235);

            $this->command($socket, "MAIL FROM:<{$this->fromAddress}>", 250);
            $this->command($socket, "RCPT TO:<{$to}>", 250);
            $this->command($socket, 'DATA', 354);

            $headers = implode("\r\n", [
                'From: ' . $this->fromName . ' <' . $this->fromAddress . '>',
                'To: <' . $to . '>',
                'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
            ]) . "\r\n\r\n";

            // Per RFC 5321: a line starting with '.' must be escaped
            // to '..' inside the DATA block, or the server reads it as
            // the end-of-message marker.
            $escapedBody = preg_replace('/^\./m', '..', $body);

            fwrite($socket, $headers . $escapedBody . "\r\n.\r\n");
            $this->readResponse($socket, 250);

            fwrite($socket, "QUIT\r\n");
        } finally {
            fclose($socket);
        }
    }

    /**
     * @return resource
     */
    private function connect()
    {
        $transport = $this->encryption === 'ssl' ? 'ssl://' : 'tcp://';

        $context = stream_context_create([
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);

        $socket = @stream_socket_client(
            $transport . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            15,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            throw new \RuntimeException("SMTP connection to {$this->host}:{$this->port} failed: {$errstr} ({$errno})");
        }

        return $socket;
    }

    /**
     * @param resource $socket
     */
    private function command($socket, string $line, int $expectedCode): string
    {
        fwrite($socket, $line . "\r\n");

        return $this->readResponse($socket, $expectedCode);
    }

    /**
     * @param resource $socket
     */
    private function readResponse($socket, int $expectedCode): string
    {
        $response = '';

        do {
            $line = fgets($socket, 515);

            if ($line === false) {
                throw new \RuntimeException('SMTP connection closed unexpectedly while reading a response.');
            }

            $response .= $line;
            // A space (not a hyphen) after the 3-digit code marks the
            // final line of a possibly multi-line SMTP response.
        } while (isset($line[3]) && $line[3] === '-');

        $code = (int) substr($response, 0, 3);

        if ($code !== $expectedCode) {
            throw new \RuntimeException("Unexpected SMTP response (expected {$expectedCode}): " . trim($response));
        }

        return $response;
    }
}
