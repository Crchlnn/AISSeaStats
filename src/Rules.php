<?php
declare(strict_types=1);

namespace AISSeaStats;

/**
 * "Interesting vessel" rules. AIS has no reliable "military" or "luxury yacht"
 * code, so every rule is a heuristic the station owner can tune in the admin.
 */
final class Rules
{
    public const TAGS = ['emergency', 'military', 'authority', 'yacht', 'hazmat', 'large', 'rare_flag', 'watchlist'];

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'emergency' => true,
            'military' => true,
            'military_prefixes' => [],
            'authority' => true,
            'yacht' => true,
            'yacht_min_len' => 24,
            'hazmat' => true,
            'large' => true,
            'large_min_len' => 200,
            'rare_flag' => true,
            'rare_flag_max' => 3,
            'rare_flag_min_fleet' => 100,
            'watchlist' => [],
        ];
    }

    /**
     * @param array<string, mixed> $v vessel row
     * @param array<string, mixed> $r rules
     * @param array<string, int> $flagCounts vessels seen per country code
     * @return array<int, string>
     */
    public static function tagsFor(array $v, array $r, array $flagCounts): array
    {
        $tags = [];
        $type = $v['shiptype'] !== null ? (int) $v['shiptype'] : 0;
        $len = $v['length_m'] !== null ? (int) $v['length_m'] : 0;
        $mmsi = str_pad((string) $v['mmsi'], 9, '0', STR_PAD_LEFT);
        $class = (string) $v['vclass'];

        if ($r['emergency'] && $class === 'EMRG') {
            $tags[] = 'emergency';
        }
        if ($r['military']) {
            $hit = $type === 35;
            foreach ((array) $r['military_prefixes'] as $p) {
                $p = preg_replace('/\D/', '', (string) $p) ?? '';
                if ($p !== '' && str_starts_with($mmsi, $p)) {
                    $hit = true;
                }
            }
            if ($hit) {
                $tags[] = 'military';
            }
        }
        if ($r['authority'] && (in_array($type, [51, 55, 58], true) || $class === 'SAR')) {
            $tags[] = 'authority';
        }
        if ($r['yacht'] && in_array($type, [36, 37], true) && $len >= (int) $r['yacht_min_len']) {
            $tags[] = 'yacht';
        }
        if ($r['hazmat'] && (($type >= 71 && $type <= 74) || ($type >= 81 && $type <= 84))) {
            $tags[] = 'hazmat';
        }
        if ($r['large'] && $len >= (int) $r['large_min_len']) {
            $tags[] = 'large';
        }
        $fleet = array_sum($flagCounts);
        if ($r['rare_flag'] && !empty($v['country']) && $fleet >= (int) ($r['rare_flag_min_fleet'] ?? 100)
            && ($flagCounts[$v['country']] ?? 0) <= (int) $r['rare_flag_max']) {
            $tags[] = 'rare_flag';
        }
        foreach ((array) $r['watchlist'] as $w) {
            $w = trim((string) $w);
            if ($w === '') {
                continue;
            }
            if ((ctype_digit($w) && (int) $w === (int) $v['mmsi'])
                || (!ctype_digit($w) && !empty($v['name']) && strcasecmp($w, (string) $v['name']) === 0)) {
                $tags[] = 'watchlist';
                break;
            }
        }
        return $tags;
    }
}
