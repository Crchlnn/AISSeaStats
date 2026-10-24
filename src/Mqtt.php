<?php
declare(strict_types=1);

namespace AISSeaStats;

/**
 * Minimal MQTT 3.1.1 client for publishing (1.2.0): CONNECT with optional user/password, TLS and last will,
 * PUBLISH QoS 0 or 1 (waits for PUBACK), PINGREQ, DISCONNECT. No subscription. No dependency.
 * Used by the worker (one connection kept open, so the broker sends the "offline" last will if the worker stops)
 * and by the admin test button (short connection).
 */
final class Mqtt
{
    private const TIMEOUT = 10;
    private const CONNACK = [
        1 => 'protocol version refused',
        2 => 'client identifier refused',
        3 => 'server unavailable',
        4 => 'bad user name or password',
        5 => 'not authorised',
    ];

    /** @var resource|null */
    private $fp = null;
    private int $packetId = 0;
    private int $lastSend = 0;

    /**
     * @param array{host: string, port: int, tls: bool, tls_verify?: bool, user: string, pass: string, client_id: string, keepalive?: int,
     *   will?: array{topic: string, payload: string, retain: bool}|null} $cfg
     * @throws \RuntimeException
     */
    public function connect(array $cfg): void
    {
        $host = $cfg['host'];
        $port = (int) $cfg['port'];
        if (!self::validHost($host) || $port < 1 || $port > 65535) {
            throw new \RuntimeException('invalid MQTT server');
        }
        // TLS checks the server certificate unless the admin turned it off (a broker on the home network with a
        // self-signed certificate): the traffic is then still encrypted, but the server is not authenticated.
        $verify = (bool) ($cfg['tls_verify'] ?? true);
        $ctx = stream_context_create(['ssl' => ['peer_name' => $host, 'verify_peer' => $verify, 'verify_peer_name' => $verify,
            'allow_self_signed' => !$verify, 'SNI_enabled' => true]]);
        $fp = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, self::TIMEOUT, STREAM_CLIENT_CONNECT, $ctx);
        if ($fp === false) {
            throw new \RuntimeException('cannot connect to ' . $host . ':' . $port . ($errstr !== '' ? ' (' . $errstr . ')' : ''));
        }
        // TLS negotiated on the open connection: OpenSSL's reason (untrusted certificate…) is then reported.
        if ($cfg['tls'] && @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
            $last = (string) (error_get_last()['message'] ?? '');
            fclose($fp);
            throw new \RuntimeException('TLS failed with ' . $host . ':' . $port . (str_contains($last, 'certificate verify failed')
                ? ' (certificate not trusted: self-signed? untick "check the certificate")'
                : (str_contains($last, 'wrong version number') ? ' (this port does not speak TLS)' : '')));
        }
        stream_set_timeout($fp, self::TIMEOUT);
        $this->fp = $fp;

        $keepalive = max(10, min(65535, (int) ($cfg['keepalive'] ?? 180)));
        $will = $cfg['will'] ?? null;
        $flags = 0x02; // clean session
        $payload = self::str($cfg['client_id']);
        if ($will !== null) {
            $flags |= 0x04 | (1 << 3) | ($will['retain'] ? 0x20 : 0); // will, QoS 1, retain
            $payload .= self::str($will['topic']) . self::str($will['payload']);
        }
        if ($cfg['user'] !== '') {
            $flags |= 0x80;
            $payload .= self::str($cfg['user']);
            if ($cfg['pass'] !== '') {
                $flags |= 0x40;
                $payload .= self::str($cfg['pass']);
            }
        }
        $var = self::str('MQTT') . chr(4) . chr($flags) . pack('n', $keepalive);
        $this->send(0x10, $var . $payload);
        [$type, $body] = $this->read();
        if ($type !== 0x20 || strlen($body) < 2) {
            $this->close();
            throw new \RuntimeException('no CONNACK from the MQTT server');
        }
        $rc = ord($body[1]);
        if ($rc !== 0) {
            $this->close();
            throw new \RuntimeException('connection refused: ' . (self::CONNACK[$rc] ?? 'code ' . $rc));
        }
    }

    public function connected(): bool
    {
        return is_resource($this->fp) && !feof($this->fp);
    }

    /** @throws \RuntimeException */
    public function publish(string $topic, string $payload, bool $retain = false, int $qos = 0): void
    {
        if (!self::validTopic($topic)) {
            throw new \RuntimeException('invalid MQTT topic');
        }
        $qos = $qos === 1 ? 1 : 0;
        $var = self::str($topic);
        $id = 0;
        if ($qos === 1) {
            $this->packetId = $this->packetId % 65535 + 1;
            $id = $this->packetId;
            $var .= pack('n', $id);
        }
        $this->send(0x30 | ($qos << 1) | ($retain ? 1 : 0), $var . $payload);
        if ($qos === 1) {
            // Wait for our PUBACK; anything else (a late PINGRESP) is skipped.
            for ($i = 0; $i < 5; $i++) {
                [$type, $body] = $this->read();
                if ($type === 0x40 && strlen($body) >= 2 && unpack('n', $body)[1] === $id) {
                    return;
                }
            }
            throw new \RuntimeException('no PUBACK from the MQTT server');
        }
    }

    /** Keeps the connection alive when nothing was sent for a while (the worker publishes every minute anyway). */
    public function ping(): void
    {
        $this->send(0xC0, '');
        [$type] = $this->read();
        if ($type !== 0xD0) {
            throw new \RuntimeException('no PINGRESP from the MQTT server');
        }
    }

    public function idleFor(): int
    {
        return time() - $this->lastSend;
    }

    /** Clean disconnect: the broker does NOT send the last will. */
    public function disconnect(): void
    {
        if (is_resource($this->fp)) {
            @fwrite($this->fp, chr(0xE0) . chr(0));
        }
        $this->close();
    }

    public function close(): void
    {
        if (is_resource($this->fp)) {
            fclose($this->fp);
        }
        $this->fp = null;
    }

    public static function validHost(string $host): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9.\-]{1,253}$/', $host) || filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    /** A topic to publish to: 1 to 200 bytes, no wildcard, no control or NUL character. */
    public static function validTopic(string $topic): bool
    {
        return $topic !== '' && strlen($topic) <= 200 && !preg_match('/[#+\x00-\x1F]/', $topic);
    }

    /** Length-prefixed UTF-8 string. */
    public static function str(string $s): string
    {
        return pack('n', strlen($s)) . $s;
    }

    /** MQTT "remaining length" (1 to 4 bytes). */
    public static function remainingLength(int $n): string
    {
        $out = '';
        do {
            $b = $n % 128;
            $n = intdiv($n, 128);
            $out .= chr($n > 0 ? $b | 0x80 : $b);
        } while ($n > 0);
        return $out;
    }

    private function send(int $header, string $body): void
    {
        if (!is_resource($this->fp)) {
            throw new \RuntimeException('not connected');
        }
        if (strlen($body) > 268435455) {
            throw new \RuntimeException('MQTT message too large');
        }
        $packet = chr($header) . self::remainingLength(strlen($body)) . $body;
        $done = 0;
        while ($done < strlen($packet)) {
            $w = @fwrite($this->fp, substr($packet, $done));
            if ($w === false || $w === 0) {
                $this->close();
                throw new \RuntimeException('connection lost');
            }
            $done += $w;
        }
        $this->lastSend = time();
    }

    /** @return array{0: int, 1: string} packet type (high nibble) and body */
    private function read(): array
    {
        $h = $this->readBytes(1);
        $mult = 1;
        $len = 0;
        for ($i = 0; $i < 4; $i++) {
            $b = ord($this->readBytes(1));
            $len += ($b & 0x7F) * $mult;
            if (($b & 0x80) === 0) {
                break;
            }
            $mult *= 128;
        }
        return [ord($h) & 0xF0, $len > 0 ? $this->readBytes($len) : ''];
    }

    private function readBytes(int $n): string
    {
        $out = '';
        while (strlen($out) < $n) {
            $chunk = is_resource($this->fp) ? @fread($this->fp, $n - strlen($out)) : false;
            if ($chunk === false || $chunk === '') {
                $timedOut = is_resource($this->fp) && (stream_get_meta_data($this->fp)['timed_out'] ?? false);
                $this->close();
                throw new \RuntimeException($timedOut ? 'no answer from the MQTT server' : 'connection closed by the MQTT server');
            }
            $out .= $chunk;
        }
        return $out;
    }
}
