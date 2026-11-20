<?php

namespace Dackou\Bazi\Internal;

use com\nlf\calendar\Lunar;
use com\nlf\calendar\util\LunarUtil as U;

/** Traditional reference rules, kept separate from calendar conversion. */
final class Reference
{
    public static function command(string $branch, int $days): string
    {
        // 人元分日，交节起算，末段延续至下次交节。
        $segments = ['寅' => ['戊' => 7, '丙' => 7, '甲' => 16], '卯' => ['甲' => 10, '乙' => 20], '辰' => ['乙' => 9, '癸' => 3, '戊' => 18],
            '巳' => ['戊' => 7, '庚' => 7, '丙' => 16], '午' => ['丙' => 10, '己' => 9, '丁' => 11], '未' => ['丁' => 9, '乙' => 3, '己' => 18],
            '申' => ['戊' => 7, '壬' => 7, '庚' => 16], '酉' => ['庚' => 10, '辛' => 20], '戌' => ['辛' => 9, '丁' => 3, '戊' => 18],
            '亥' => ['戊' => 7, '甲' => 5, '壬' => 18], '子' => ['壬' => 10, '癸' => 20], '丑' => ['癸' => 9, '辛' => 3, '己' => 18]];
        foreach ($segments[$branch] as $stem => $duration) {
            if ($days < $duration) {
                break;
            }
            $days -= $duration;
        }
        return $stem;
    }

    public static function elements(array $rows): array
    {
        $counts = array_fill_keys(Pillars::ELEMENTS, 0);
        $hidden = $withHidden = $energy = $counts;
        $yinYang = ['阴' => 0, '阳' => 0];
        $month = $rows['month']['branch'];
        $season = U::$WU_XING_ZHI[$month];
        $index = array_search($season, Pillars::ELEMENTS);
        $state = [];
        foreach (Pillars::ELEMENTS as $i => $element) {
            $state[$element] = ['旺', '相', '死', '囚', '休'][($i - $index + 5) % 5];
        }
        foreach ($rows as $row) {
            $counts[$row['stemElement']]++;
            $counts[$row['branchElement']]++;
            $withHidden[$row['stemElement']]++;
            $energy[$row['stemElement']]++;
            $yinYang[array_search($row['stem'], U::$GAN) % 2 ? '阳' : '阴']++;
            $yinYang[array_search($row['branch'], U::$ZHI) % 2 ? '阳' : '阴']++;
            $weights = [1 => [1], 2 => [0.7, 0.3], 3 => [0.6, 0.3, 0.1]][count($row['hiddenStems'])];
            foreach ($row['hiddenStems'] as $i => $stem) {
                $element = U::$WU_XING_GAN[$stem];
                $hidden[$element]++;
                $withHidden[$element]++;
                $energy[$element] += $weights[$i];
            }
        }
        $factors = ['旺' => 1.5, '相' => 1.25, '休' => 1, '囚' => 0.75, '死' => 0.5];
        foreach ($energy as $element => $score) {
            $energy[$element] = $score * $factors[$state[$element]];
        }
        $total = array_sum($energy);
        foreach ($energy as $element => $score) {
            $energy[$element] = round(100 * $score / $total, 2);
        }
        $master = $rows['day']['stemElement'];
        $resource = Pillars::ELEMENTS[(array_search($master, Pillars::ELEMENTS) + 4) % 5];
        $support = round($energy[$master] + $energy[$resource], 2);
        $root = $rows['month']['hiddenStems'][0];
        $pattern = $rows['month']['secondaryStars'][0];
        return ['yinYang' => $yinYang, 'elementCounts' => $counts, 'hiddenCounts' => $hidden, 'countsWithHidden' => $withHidden,
            'elementEnergy' => ['percent' => $energy, 'method' => '四干等权，四支按藏干分配，乘月令旺相休囚死系数后归一'],
            'strength' => ['season' => $state, 'dayMasterSeason' => $state[$master], 'supportPercent' => $support,
                'reference' => $support > 55 ? '偏强' : ($support < 45 ? '偏弱' : '中和'), 'method' => '印比能量占比，45%～55% 为中和'],
            'pattern' => ['monthMainStem' => $root, 'tenGod' => $pattern, 'reference' => $pattern.'当令', 'method' => '月支本气相对日干的取格参考']];
    }

    public static function bones(Lunar $lunar, string $branch, string $gender): array
    {
        $t = Birth::data('bones');
        $parts = ['year' => $t['year'][U::getJiaZiIndex($lunar->getYearInGanZhi())], 'month' => $t['month'][abs($lunar->getMonth()) - 1],
            'day' => $t['day'][$lunar->getDay() - 1], 'hour' => $t['hour'][array_search($branch, U::$ZHI) - 1]];
        $sum = array_sum($parts);
        $numbers = Pillars::chars('零一二三四五六七八九');
        $label = $numbers[intdiv($sum, 10)].'两'.($sum % 10 ? $numbers[$sum % 10].'钱' : '');
        return ['qian' => $sum, 'weight' => $label, 'parts' => $parts, 'verse' => $t[$gender === 'male' ? 'man' : 'woman'][$label] ?? null];
    }

    public static function gua(int $solarYear, string $gender): string
    {
        $number = $gender === 'male' ? 11 - $solarYear % 9 : $solarYear % 9 + 4;
        $number = (($number - 1) % 9 + 9) % 9 + 1;
        if ($number === 5) {
            $number = $gender === 'male' ? 2 : 8;
        }
        return [1 => '坎', 2 => '坤', 3 => '震', 4 => '巽', 6 => '乾', 7 => '兑', 8 => '艮', 9 => '离'][$number].'卦（'.(in_array($number, [1, 3, 4, 9]) ? '东' : '西').'四命）';
    }

    public static function chartNotes(array $rows): array
    {
        $visible = array_column($rows, 'stem');
        $revealed = [];
        foreach ($rows as $key => $row) {
            foreach ($row['hiddenStems'] as $hidden) {
                $places = array_keys(array_filter(array_map(function ($r) use ($hidden) {
                    return $r['stem'] === $hidden;
                }, $rows)));
                $revealed[] = ['position' => $key, 'hidden' => $hidden, 'visibleAt' => $places];
            }
        }
        $day = $rows['day']['stem'];
        $month = $rows['month']['branch'];
        $base = $rows['month']['hiddenStems'][0];
        $targets = Birth::data('climate')[$day][$month];
        $classic = ['source' => '《子平真诠·论用神》', 'original' => '八字用神，专求月令。以日干配月令地支，而生克不同，格局分焉。',
            'translation' => '先看月令，再将日干与月支相配，考察两者的生克关系以辨格局。',
            'comparison' => ['dayStem' => $day, 'monthBranch' => $month, 'monthMainStem' => $base, 'tenGod' => U::$SHI_SHEN[$day.$base]]];
        return ['revealedStems' => $revealed, 'climate' => ['dayStem' => $day, 'monthBranch' => $month, 'candidates' => $targets, 'visible' => array_values(array_intersect($targets, $visible))], 'classics' => [$classic]];
    }
}
