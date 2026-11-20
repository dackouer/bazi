<?php

namespace Dackou\Bazi\Internal;

use com\nlf\calendar\EightChar;
use com\nlf\calendar\util\LunarUtil as U;

final class Pillars
{
    public const ELEMENTS = ['木', '火', '土', '金', '水'];

    public static function chars(string $text): array
    {
        return preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    }

    public static function stage(string $stem, string $branch): string
    {
        $g = array_search($stem, U::$GAN, true) - 1;
        $z = array_search($branch, U::$ZHI, true) - 1;
        $offsets = [1, 6, 10, 9, 10, 9, 7, 0, 4, 3];
        return EightChar::$CHANG_SHENG[($offsets[$g] + ($g % 2 ? -$z : $z) + 12) % 12];
    }

    public static function row(string $gz, array $natal, bool $day = false): array
    {
        list($stem, $branch) = self::chars($gz);
        $master = self::chars($natal['day'])[0];
        $hidden = U::$ZHI_HIDE_GAN[$branch];
        $secondary = array_map(function ($g) use ($master) {
            return U::$SHI_SHEN[$master.$g];
        }, $hidden);
        return ['ganZhi' => $gz, 'stem' => $stem, 'branch' => $branch, 'stemElement' => U::$WU_XING_GAN[$stem],
            'branchElement' => U::$WU_XING_ZHI[$branch], 'mainStar' => $day ? '日主' : U::$SHI_SHEN[$master.$stem],
            'hiddenStems' => $hidden, 'secondaryStars' => $secondary, 'starStage' => self::stage($master, $branch),
            'selfStage' => self::stage($stem, $branch), 'void' => U::getXunKong($gz), 'naYin' => U::$NAYIN[$gz],
            'shenSha' => self::stars($gz, $natal, $day)];
    }

    private static function stars(string $gz, array $natal, bool $isNatalDay): array
    {
        list($stem, $branch) = self::chars($gz);
        list($master, $day) = self::chars($natal['day']);
        list($yearStem, $year) = self::chars($natal['year']);
        $i = array_search($master, U::$GAN, true) - 1;
        $stars = [];
        $noble = ['丑未', '子申', '亥酉', '亥酉', '丑未', '子申', '丑未', '寅午', '卯巳', '卯巳'];
        foreach ([$master, $yearStem] as $s) {
            if (strpos($noble[array_search($s, U::$GAN, true) - 1], $branch) !== false) {
                $stars[] = '天乙贵人';
                break;
            }
        }
        foreach (['文昌' => '巳午申酉申酉亥子寅卯', '禄神' => '寅卯巳午巳午申酉亥子', '金舆' => '辰巳未申未申戌亥丑寅', '国印贵人' => '戌亥丑寅丑寅辰巳未申'] as $name => $lookup) {
            if (self::chars($lookup)[$i] === $branch) {
                $stars[] = $name;
            }
        }
        $taiJi = ['子午', '卯酉', '辰戌丑未', '寅亥', '巳申'];
        $fuXing = ['寅子', '丑亥', '寅子', '亥', '申', '未', '午', '巳', '辰', '卯'];
        foreach ([$master, $yearStem] as $origin) {
            $index = array_search($origin, U::$GAN, true) - 1;
            if (strpos($taiJi[intdiv($index, 2)], $branch) !== false) {
                $stars[] = '太极贵人';
            }
            if (strpos($fuXing[$index], $branch) !== false) {
                $stars[] = '福星贵人';
            }
        }
        if ($isNatalDay) {
            $dayRules = [
                '阴差阳错' => ['丙子', '丁丑', '戊寅', '辛卯', '壬辰', '癸巳', '丙午', '丁未', '戊申', '辛酉', '壬戌', '癸亥'],
                '六秀日' => ['丙午', '丁未', '戊子', '戊午', '己丑', '己未'],
                '孤鸾日' => ['乙巳', '丁巳', '辛亥', '戊申', '甲寅', '壬子', '丙午', '戊午'],
            ];
            foreach ($dayRules as $name => $days) {
                if (in_array($gz, $days, true)) {
                    $stars[] = $name;
                }
            }
        }
        if ($i % 2 === 0 && self::chars('卯午午酉子')[intdiv($i, 2)] === $branch) {
            $stars[] = '羊刃';
        }
        // 年支、日支三合局，顺序为华盖、将星、驿马、桃花。
        foreach (['申子辰' => '辰子寅酉', '寅午戌' => '戌午申卯', '亥卯未' => '未卯巳子', '巳酉丑' => '丑酉亥午'] as $group => $targets) {
            if (strpos($group, $day) === false && strpos($group, $year) === false) {
                continue;
            }
            foreach (self::chars($targets) as $j => $target) {
                if ($target === $branch) {
                    $stars[] = ['华盖', '将星', '驿马', '桃花'][$j];
                }
            }
        }
        $month = self::chars($natal['month'])[1];
        foreach (['寅午戌' => '丙', '申子辰' => '壬', '亥卯未' => '甲', '巳酉丑' => '庚'] as $group => $virtue) {
            if (strpos($group, $month) !== false && $virtue === $stem) {
                $stars[] = '月德贵人';
            }
        }
        if (strpos(U::getXunKong($natal['day']), $branch) !== false) {
            $stars[] = '空亡';
        }
        return array_values(array_unique($stars));
    }

    public static function relations(array $gz, array $focus = []): array
    {
        $out = ['stems' => [], 'branches' => [], 'pillars' => []];
        $keys = array_keys($gz);
        $groups = ['三合' => ['申子辰', '亥卯未', '寅午戌', '巳酉丑'], '三会' => ['寅卯辰', '巳午未', '申酉戌', '亥子丑']];
        $branches = [];
        foreach ($gz as $key => $value) {
            list($stem, $branch) = self::chars($value);
            $branches[$key] = $branch;
            if ($focus && !in_array($key, $focus, true)) {
                continue;
            }
            $gIndex = array_search(U::$WU_XING_GAN[$stem], self::ELEMENTS);
            $zIndex = array_search(U::$WU_XING_ZHI[$branch], self::ELEMENTS);
            $diff = ($zIndex - $gIndex + 5) % 5;
            if ($diff === 2 || $diff === 3) {
                $kind = $diff === 2 ? '盖头' : '截脚';
                $out['pillars'][] = ['positions' => [$key], 'type' => $kind, 'text' => $value.$kind];
            }
        }
        foreach ($keys as $i => $a) {
            list($g, $z) = self::chars($gz[$a]);
            foreach (array_slice($keys, $i + 1) as $b) {
                if ($focus && !array_intersect([$a, $b], $focus)) {
                    continue;
                }
                list($h, $w) = self::chars($gz[$b]);
                $add = function ($section, $type, $text) use (&$out, $a, $b) {
                    $out[$section][] = ['positions' => [$a, $b], 'type' => $type, 'text' => $text];
                };
                $ge = array_search(U::$WU_XING_GAN[$g], self::ELEMENTS);
                $he = array_search(U::$WU_XING_GAN[$h], self::ELEMENTS);
                $diff = ($he - $ge + 5) % 5;
                if ($diff) {
                    $type = in_array($diff, [1, 4]) ? '相生' : '相克';
                    $add('stems', $type, $g.$h.$type);
                }
                if (U::$HE_GAN_5[array_search($g, U::$GAN) - 1] === $h) {
                    $add('stems', '五合', $g.$h.'合');
                }
                $pairs = ['六合' => ['子丑', '寅亥', '卯戌', '辰酉', '巳申', '午未'], '相冲' => ['子午', '丑未', '寅申', '卯酉', '辰戌', '巳亥'],
                    '相害' => ['子未', '丑午', '寅巳', '卯辰', '申亥', '酉戌'], '相破' => ['子酉', '丑辰', '寅亥', '卯午', '巳申', '未戌'],
                    '相刑' => ['子卯', '寅巳', '巳申', '申寅', '丑戌', '戌未', '未丑'], '自刑' => ['辰辰', '午午', '酉酉', '亥亥']];
                foreach ($pairs as $type => $list) {
                    if (in_array($z.$w, $list, true) || in_array($w.$z, $list, true)) {
                        $add('branches', $type, $z.$w.$type);
                    }
                }
                foreach ($groups['三合'] as $group) {
                    $set = self::chars($group);
                    if ($z !== $w && in_array($z, $set, true) && in_array($w, $set, true) && ($z === $set[1] || $w === $set[1])) {
                        $add('branches', '半合', $z.$w.'半合');
                    }
                }
                if ($gz[$a] === $gz[$b]) {
                    $add('pillars', '伏吟', $gz[$a].'伏吟');
                }
                if (in_array($diff, [2, 3]) && in_array($w.$z, array_merge($pairs['相冲'], array_map(function ($v) {
                    return implode('', array_reverse(self::chars($v)));
                }, $pairs['相冲'])), true)) {
                    $add('pillars', '天克地冲', $gz[$a].$gz[$b].'天克地冲');
                }
            }
        }
        foreach ($groups as $type => $sets) {
            foreach ($sets as $s) {
                $parts = self::chars($s);
                $hits = array_keys(array_intersect($branches, $parts));
                if (!array_diff($parts, $branches) && (!$focus || array_intersect($hits, $focus))) {
                    $out['branches'][] = ['positions' => $hits, 'type' => $type, 'text' => $s.$type];
                }
            }
        }
        return $out;
    }
}
