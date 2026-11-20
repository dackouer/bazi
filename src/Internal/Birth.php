<?php

namespace Dackou\Bazi\Internal;

use com\nlf\calendar\Lunar;
use com\nlf\calendar\Solar;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Normalize form values before invoking any chart rules. */
final class Birth
{
    public static function data(string $name): array
    {
        static $tables = [];
        if (!isset($tables[$name])) {
            $tables[$name] = json_decode(file_get_contents(dirname(__DIR__, 2) . '/resources/' . $name . '.json'), true);
            if (!is_array($tables[$name])) {
                throw new \RuntimeException('排盘数据文件无效 ' . $name);
            }
        }
        return $tables[$name];
    }

    public static function solar(DateTimeImmutable $time): Solar
    {
        return Solar::fromYmdHms(...array_map('intval', explode(' ', $time->format('Y n j G i s'))));
    }

    public static function coordinates(string $place): array
    {
        if (trim($place) === '') {
            throw new InvalidArgumentException('请填写出生地点');
        }
        $query = preg_replace('/\s+/u', '', $place);
        $matches = [];
        foreach (self::data('places') as list($path, $lon, $lat)) {
            $parts = explode(' ', $path);
            $short = end($parts);
            $aliases = [implode('', $parts), $short, preg_replace('/[市县区]$/u', '', $short)];
            if (in_array($query, $aliases, true)) {
                $matches[] = ['longitude' => $lon, 'latitude' => $lat, 'source' => 'district', 'place' => $path];
            }
        }
        if (count($matches) !== 1) {
            throw new InvalidArgumentException('地点未匹配或有重名，请提供完整行政区名称或 coordinates');
        }
        return $matches[0];
    }

    private static function coordinate($value, string $name, int $limit): float
    {
        if (!is_numeric($value) || !is_finite((float) $value) || abs($value) > $limit) {
            throw new InvalidArgumentException('无效坐标 ' . $name);
        }
        return (float) $value;
    }

    public static function apparentTime(DateTimeImmutable $civil, float $longitude): DateTimeImmutable
    {
        self::coordinate($longitude, 'longitude', 180);
        $seconds = (int)round(240 * $longitude + 60 * Sky::equationOfTime($civil) - $civil->getOffset());
        return $civil->modify(sprintf('%+d seconds', $seconds));
    }

    public static function solarTimeFields(?DateTimeImmutable $apparent): array
    {
        return [
            'trueSolarTime' => $apparent ? $apparent->modify('+30 seconds')->format('Y-m-d H:i') : null,
            'trueSolarTimeExact' => $apparent ? $apparent->format('Y-m-d H:i:s') : null,
        ];
    }

    public static function civilTime($raw, $offset, string $mode = 'solar', bool $leap = false): DateTimeImmutable
    {
        if (!is_string($raw) || !preg_match('/^(\d{4})-(\d{2})-(\d{2}) ([01]\d|2[0-3]):([0-5]\d)$/D', $raw, $m)) {
            throw new InvalidArgumentException('birth 格式为 YYYY-MM-DD HH:mm');
        }
        list(, $y, $mn, $d, $h, $min) = array_map('intval', $m);
        if ($y < 1900 || $y > 2100) {
            throw new InvalidArgumentException('出生年份范围为 1900 至 2100');
        }
        if (!is_string($offset) || !preg_match('/^[+-](?:(?:0\d|1[0-3]):[0-5]\d|14:00)$/D', $offset)) {
            throw new InvalidArgumentException('utcOffset 使用出生当时的 UTC 偏移，如 +08:00');
        }
        if ($mode === 'lunar') {
            try {
                $rawSolar = Lunar::fromYmdHms($y, $leap ? -$mn : $mn, $d, $h, $min, 0)->getSolar()->toYmdHms();
            } catch (\RuntimeException $e) {
                throw new InvalidArgumentException('农历日期或闰月无效', 0, $e);
            }
        } else {
            if ($leap || !checkdate($mn, $d, $y)) {
                throw new InvalidArgumentException('公历日期无效');
            }
            $rawSolar = $raw . ':00';
        }
        return new DateTimeImmutable($rawSolar, new DateTimeZone($offset));
    }

    public static function parse(array $input): array
    {
        $name = $input['name'] ?? null;
        $gender = $input['gender'] ?? null;
        $mode = $input['mode'] ?? 'solar';
        $genders = ['male' => 'male', 1 => 'male', '男' => 'male', 'female' => 'female', 2 => 'female', '女' => 'female'];
        $modes = [
            'solar' => 'solar', 1 => 'solar', '公历' => 'solar',
            'lunar' => 'lunar', 2 => 'lunar', '农历' => 'lunar',
            'pillars' => 'pillars', 3 => 'pillars', '四柱' => 'pillars', '农历、pillars' => 'pillars',
        ];
        if (!is_string($name) || trim($name) === '') {
            throw new InvalidArgumentException('请填写姓名');
        }
        if ((!is_int($gender) && !is_string($gender)) || !isset($genders[$gender])) {
            throw new InvalidArgumentException('gender 请使用 male / 1 或 female / 2');
        }
        if ((!is_int($mode) && !is_string($mode)) || !isset($modes[$mode])) {
            throw new InvalidArgumentException('无效的排盘方式');
        }
        $mode = $modes[$mode];
        // Older saved forms may still carry the original field names.
        if (!array_key_exists('birth', $input)) {
            $input['birth'] = $input['birthTime'] ?? null;
        }
        if (!array_key_exists('address', $input)) {
            $input['address'] = $input['birthPlace'] ?? null;
        }
        foreach (['save', 'leapMonth'] as $key) {
            if (isset($input[$key]) && !is_bool($input[$key])) {
                throw new InvalidArgumentException($key . ' 需要布尔值');
            }
        }
        foreach (['group', 'address'] as $key) {
            if (isset($input[$key]) && !is_string($input[$key])) {
                throw new InvalidArgumentException($key . ' 需要字符串');
            }
        }
        $offset = $input['utcOffset'] ?? '+08:00';
        $leap = $input['leapMonth'] ?? false;
        $civil = self::civilTime($input['birth'] ?? '', $offset, $mode, $leap);
        $place = trim($input['address'] ?? '');
        $coords = $input['coordinates'] ?? null;
        if ($coords !== null) {
            if (!is_array($coords)) {
                throw new InvalidArgumentException('coordinates 需要经纬度');
            }
            $coords = [
                'longitude' => self::coordinate($coords['longitude'] ?? null, 'longitude', 180),
                'latitude' => self::coordinate($coords['latitude'] ?? null, 'latitude', 90),
                'source' => 'input',
            ];
        } elseif ($place !== '') {
            $coords = self::coordinates($place);
        }
        $apparent = $coords === null ? null : self::apparentTime($civil, $coords['longitude']);
        $form = ['name' => trim($name), 'gender' => $genders[$gender], 'mode' => $mode,
            'birth' => $input['birth'], 'leapMonth' => $leap, 'address' => $coords['place'] ?? $place, 'utcOffset' => $offset,
            'coordinates' => $coords,
            'group' => $input['group'] ?? null, 'save' => $input['save'] ?? false] + self::solarTimeFields($apparent);
        return [$form, $civil, $apparent ?: $civil];
    }
}
