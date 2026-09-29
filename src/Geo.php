<?php
declare(strict_types=1);

namespace AISSeaStats;

/** Great-circle helpers. All distances in nautical miles. */
final class Geo
{
    public const EARTH_RADIUS_NM = 3440.065;
    public const NM_TO_KM = 1.852;

    public static function distanceNm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $dp = $p2 - $p1;
        $dl = deg2rad($lon2 - $lon1);
        $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
        return 2 * self::EARTH_RADIUS_NM * asin(min(1.0, sqrt($a)));
    }

    /** Initial bearing from point 1 to point 2, degrees 0..360. */
    public static function bearing(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $dl = deg2rad($lon2 - $lon1);
        $y = sin($dl) * cos($p2);
        $x = cos($p1) * sin($p2) - sin($p1) * cos($p2) * cos($dl);
        $deg = rad2deg(atan2($y, $x));
        return fmod($deg + 360.0, 360.0);
    }

    public static function validPosition(mixed $lat, mixed $lon): bool
    {
        if (!is_numeric($lat) || !is_numeric($lon)) {
            return false;
        }
        $lat = (float) $lat;
        $lon = (float) $lon;
        if (abs($lat) > 90 || abs($lon) > 180) {
            return false; // includes AIS "not available" sentinels 91 / 181
        }
        return !(abs($lat) < 1e-6 && abs($lon) < 1e-6);
    }

    /** Compass label of the 45° sector containing a bearing. */
    public static function sectorLabel(float $bearing): string
    {
        $labels = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
        return $labels[(int) floor(fmod($bearing + 22.5, 360.0) / 45.0)];
    }
}
