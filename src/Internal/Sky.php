<?php

namespace Dackou\Bazi\Internal;

use com\nlf\calendar\util\ShouXingUtil;
use DateTimeImmutable;
use DateTimeZone;

final class Sky
{
    private static function centuries(DateTimeImmutable $instant): float
    {
        $days = $instant->getTimestamp() / 86400 + 2440587.5 - 2451545.0;
        return ($days + ShouXingUtil::dtT($days)) / 36525;
    }

    private static function degrees(float $degrees): float
    {
        return fmod(fmod($degrees, 360) + 360, 360);
    }

    /** Apparent minus mean solar time, in minutes (Meeus, Astronomical Algorithms, ch. 28). */
    public static function equationOfTime(DateTimeImmutable $instant): float
    {
        $t = self::centuries($instant);
        $sun = ShouXingUtil::saLon($t, -1);
        $obliquity = deg2rad(23.439291 - 0.0130042 * $t);
        $rightAscension = rad2deg(atan2(cos($obliquity) * sin($sun), cos($sun)));
        $meanLongitude = 280.4664567 + 36000.76983 * $t + 0.0003032 * $t * $t;
        $nutation = rad2deg(ShouXingUtil::nutationLon2($t)) * cos($obliquity);
        return 4 * (self::degrees($meanLongitude - 0.0057183 - $rightAscension + $nutation + 180) - 180);
    }

    /** JPL Approximate Positions, tables 2a/2b. Heliocentric ecliptic J2000 x,y in AU. */
    private static function orbit(array $table, float $t, array $correction): array
    {
        $elements = [];
        foreach ($table[0] as $i => $base) {
            $elements[] = $base + $t * $table[1][$i];
        }
        list($a, $e, $inc, $mean, $peri, $node) = $elements;
        list($b, $c, $s, $f) = $correction;
        $phase = deg2rad($f * $t);
        $anomaly = deg2rad(self::degrees($mean - $peri + $b * $t * $t + $c * cos($phase) + $s * sin($phase)));
        $eccentric = $anomaly;
        for ($i = 0; $i < 10; $i++) {
            $delta = ($eccentric - $e * sin($eccentric) - $anomaly) / (1 - $e * cos($eccentric));
            $eccentric -= $delta;
            if (abs($delta) < 1e-12) {
                break;
            }
        }
        $x = $a * (cos($eccentric) - $e);
        $y = $a * sqrt(1 - $e * $e) * sin($eccentric);
        $omega = deg2rad($peri - $node);
        $node = deg2rad($node);
        $inc = deg2rad($inc);
        $u = $x * cos($omega) - $y * sin($omega);
        $v = ($x * sin($omega) + $y * cos($omega)) * cos($inc);
        return [$u * cos($node) - $v * sin($node), $u * sin($node) + $v * cos($node)];
    }

    public static function positions(DateTimeImmutable $instant): array
    {
        $t = self::centuries($instant);
        $nutation = ShouXingUtil::nutationLon2($t);
        $positions = ['太阳' => self::degrees(rad2deg(ShouXingUtil::saLon($t, -1))),
            '月亮' => self::degrees(rad2deg(ShouXingUtil::mLon($t, -1) + $nutation))];
        $orbits = Birth::data('orbits');
        $none = [0, 0, 0, 0];
        $earth = self::orbit($orbits['地月质心'], $t, $none);
        unset($orbits['地月质心']);
        $corrections = ['木星' => [-0.00012452, 0.06064060, -0.35635438, 38.35125000],
            '土星' => [0.00025899, -0.13434469, 0.87320147, 38.35125000]];
        foreach ($orbits as $name => $table) {
            list($x, $y) = self::orbit($table, $t, $corrections[$name] ?? $none);
            $j2000 = rad2deg(atan2($y - $earth[1], $x - $earth[0]));
            $positions[$name] = self::degrees($j2000 + 1.397 * $t + 0.00031 * $t * $t);
        }
        return ['utc' => $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'frame' => '地心黄经近似值，历元为出生日期，向东为正，单位度',
            'longitude' => array_map(function ($lon) {
                return round($lon, 4);
            }, $positions)];
    }
}
