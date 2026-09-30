<?php
declare(strict_types=1);

namespace AISSeaStats;

/**
 * Minimal SMTP client for alert e-mails: implicit TLS (465), STARTTLS (587) or plain,
 * AUTH PLAIN / LOGIN, UTF-8 plain-text body. No dependency.
 */
final class Smtp
{
    public const SECURITY = ['starttls', 'ssl', 'none'];
    private const TIMEOUT = 15;

    /** @var resource|null */
    private $fp = null;

    /**
     * @param array{host: string, port: int, security: string, user: string, pass: string, from: string, to: string} $cfg
     * @throws \RuntimeException
     */
    public static function send(array $cfg, string $subject, string $body): void
    {
        $to = self::addresses($cfg['to']);
        $from = trim($cfg['from']);
        if ($to === [] || !self::validAddress($from)) {
            throw new \RuntimeException('invalid sender or recipient address');
        }
        $smtp = new self();
        try {
            $smtp->open($cfg['host'], (int) $cfg['port'], $cfg['security']);
            $ext = $smtp->hello();
            if ($cfg['security'] === 'starttls') {
                if (!str_contains($ext, 'STARTTLS')) {
                    throw new \RuntimeException('server does not offer STARTTLS');
                }
                $smtp->cmd('STARTTLS', [220]);
                if (!@stream_socket_enable_crypto($smtp->fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('TLS negotiation failed' . self::sslError());
                }
                $ext = $smtp->hello();
            }
            if ($cfg['user'] !== '') {
                if (preg_match('/AUTH[ =][^\r\n]*PLAIN/i', $ext) || !preg_match('/AUTH[ =][^\r\n]*LOGIN/i', $ext)) {
                    $smtp->cmd('AUTH PLAIN ' . base64_encode("\0" . $cfg['user'] . "\0" . $cfg['pass']), [235], true);
                } else {
                    $smtp->cmd('AUTH LOGIN', [334]);
                    $smtp->cmd(base64_encode($cfg['user']), [334], true);
                    $smtp->cmd(base64_encode($cfg['pass']), [235], true);
                }
            }
            $smtp->cmd('MAIL FROM:<' . $from . '>', [250]);
            foreach ($to as $addr) {
                $smtp->cmd('RCPT TO:<' . $addr . '>', [250, 251]);
            }
            $smtp->cmd('DATA', [354]);
            $smtp->cmd(self::message($from, $to, $subject, $body) . "\r\n.", [250]);
            $smtp->cmd('QUIT', [221]);
        } finally {
            $smtp->close();
        }
    }

    /** @return array<int, string> */
    public static function addresses(string $list): array
    {
        $out = [];
        foreach (preg_split('/[,;\s]+/', $list) ?: [] as $a) {
            if ($a !== '' && self::validAddress($a)) {
                $out[] = $a;
            }
        }
        return array_slice(array_values(array_unique($out)), 0, 10);
    }

    public static function validAddress(string $a): bool
    {
        return !preg_match('/[\r\n<>]/', $a) && filter_var($a, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** @param array<int, string> $to */
    public static function message(string $from, array $to, string $subject, string $body): string
    {
        $headers = [
            'Date: ' . date('r'),
            'From: AISSeaStats <' . $from . '>',
            'To: ' . implode(', ', $to),
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@aisseastats>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'X-Mailer: AISSeaStats',
        ];
        // Base64 lines never start with a dot: no dot-stuffing needed.
        return implode("\r\n", $headers) . "\r\n\r\n" . rtrim(chunk_split(base64_encode($body), 76, "\r\n"));
    }

    private function open(string $host, int $port, string $security): void
    {
        if (!preg_match('/^[A-Za-z0-9.\-]+$/', $host) || $port < 1 || $port > 65535) {
            throw new \RuntimeException('invalid SMTP server');
        }
        $ctx = stream_context_create(['ssl' => ['peer_name' => $host, 'verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        $fp = @stream_socket_client(($security === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, self::TIMEOUT,
            STREAM_CLIENT_CONNECT, $ctx);
        if ($fp === false) {
            throw new \RuntimeException('cannot connect to ' . $host . ':' . $port . ($errstr !== '' ? ' (' . $errstr . ')' : self::sslError()));
        }
        stream_set_timeout($fp, self::TIMEOUT);
        $this->fp = $fp;
        $this->expect([220]);
    }

    private static function sslError(): string
    {
        $m = (string) (error_get_last()['message'] ?? '');
        if (str_contains($m, 'certificate verify failed')) {
            return ' (invalid server certificate)';
        }
        return stripos($m, 'ssl') !== false || stripos($m, 'crypto') !== false ? ' (TLS or certificate error)' : '';
    }

    private function hello(): string
    {
        return $this->cmd('EHLO aisseastats', [250]);
    }

    /** @param array<int, int> $codes */
    private function cmd(string $line, array $codes, bool $secret = false): string
    {
        if (fwrite($this->fp, $line . "\r\n") === false) {
            throw new \RuntimeException('connection lost');
        }
        try {
            return $this->expect($codes);
        } catch (\RuntimeException $e) {
            if ($secret) {
                throw new \RuntimeException('authentication refused: ' . $e->getMessage());
            }
            throw new \RuntimeException((strlen($line) > 80 ? 'message' : strtok($line, ':')) . ': ' . $e->getMessage());
        }
    }

    /** @param array<int, int> $codes */
    private function expect(array $codes): string
    {
        $reply = '';
        do {
            $line = fgets($this->fp, 1024);
            if ($line === false) {
                throw new \RuntimeException('no answer from server');
            }
            $reply .= $line;
        } while (isset($line[3]) && $line[3] === '-');
        $code = (int) substr($line, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new \RuntimeException(trim(mb_substr($reply, 0, 200)));
        }
        return $reply;
    }

    private function close(): void
    {
        if (is_resource($this->fp)) {
            fclose($this->fp);
        }
        $this->fp = null;
    }
}
