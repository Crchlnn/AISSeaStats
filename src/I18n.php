<?php
declare(strict_types=1);

namespace AISSeaStats;

/** Minimal translation layer backed by lang/<code>.json. */
final class I18n
{
    public const LANGS = ['fr', 'en'];
    private static ?string $lang = null;
    /** @var array<string, string> */
    private static array $dict = [];

    public static function lang(): string
    {
        if (self::$lang !== null) {
            return self::$lang;
        }
        $pref = 'auto';
        try {
            $pref = (string) Settings::get('lang');
        } catch (\Throwable) {
        }
        if (!in_array($pref, self::LANGS, true)) {
            $pref = 'en';
            $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
            foreach (explode(',', $accept) as $part) {
                $code = substr(trim($part), 0, 2);
                if (in_array($code, self::LANGS, true)) {
                    $pref = $code;
                    break;
                }
            }
        }
        self::$lang = $pref;
        return $pref;
    }

    /** @return array<string, string> */
    public static function dict(): array
    {
        if (self::$dict === []) {
            $file = APP_ROOT . '/lang/' . self::lang() . '.json';
            $data = json_decode((string) @file_get_contents($file), true);
            self::$dict = is_array($data) ? $data : [];
        }
        return self::$dict;
    }

    /** @param array<string, string|int|float> $vars */
    public static function t(string $key, array $vars = []): string
    {
        // Singular form "<key>.one" when the count is 1 (same rule as the page script).
        if (isset($vars['n']) && (int) preg_replace('/\D/', '', (string) $vars['n']) === 1 && isset(self::dict()[$key . '.one'])) {
            $key .= '.one';
        }
        $s = self::dict()[$key] ?? $key;
        foreach ($vars as $k => $v) {
            $s = str_replace('{' . $k . '}', (string) $v, $s);
        }
        return $s;
    }
}
