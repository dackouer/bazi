<?php

namespace Dackou\Bazi\Internal;

use com\nlf\calendar\EightChar;
use com\nlf\calendar\Solar;
use com\nlf\calendar\util\LunarUtil as U;
use DateTimeImmutable;
use DateTimeZone;

final class Luck
{
    /** A timeline year labels the solar-term year starting at Li Chun. */
    public static function build(EightChar $birth, array $natal, array $rows, string $gender, int $year, DateTimeZone $zone, string $command): array
    {
        $yun = $birth->getYun($gender === 'male' ? 1 : 0, 2);
        $start = $yun->getStartSolar();
        $beijing = new DateTimeZone('+08:00');
        $handover = (new DateTimeImmutable($start->toYmdHms(), $beijing))->setTimezone($zone);
        $firstYear = $birth->getLunar()->getSolar()->getYear();
        $cycles = [];
        $years = [];
        $active = ['annual' => U::$JIA_ZI[(($year - 4) % 60 + 60) % 60]];
        $cycleStars = [];
        $annualStars = [];
        foreach ($yun->getDaYunBy(max(13, 2 + intdiv($year - $firstYear, 10))) as $cycle) {
            $from = $cycle->getStartYear();
            $to = $cycle->getEndYear();
            if ($from > $to) {
                continue;
            }
            $gz = $cycle->getGanZhi();
            $pillar = $gz === '' ? null : Pillars::row($gz, $natal);
            $cycles[] = ['index' => $cycle->getIndex(), 'startYear' => $from, 'endYear' => $to, 'startAge' => $cycle->getStartAge(),
                'endAge' => $cycle->getEndAge(), 'pillar' => $pillar, 'handover' => $pillar ? $handover->modify('+'.(10 * ($cycle->getIndex() - 1)).' years')->format('Y-m-d H:i') : null];
            if ($pillar) {
                $cycleStars[] = ['startYear' => $from, 'ganZhi' => $gz, 'stars' => $pillar['shenSha']];
            }
            if ($year < $from || $year > $to) {
                continue;
            }
            if ($pillar) {
                $active['decade'] = $gz;
            }
            for ($y = $from;$y <= $to;$y++) {
                $annual = U::$JIA_ZI[(($y - 4) % 60 + 60) % 60];
                $row = Pillars::row($annual, $natal);
                $years[] = ['year' => $y, 'age' => $y - $firstYear + 1, 'pillar' => $row];
                $annualStars[] = ['year' => $y, 'ganZhi' => $annual, 'stars' => $row['shenSha']];
            }
        }
        $terms = Solar::fromYmd($year, 6, 1)->getLunar()->getJieQiTable();
        $monthNames = ['立春', '惊蛰', '清明', '立夏', '芒种', '小暑', '立秋', '白露', '寒露', '立冬', '大雪', 'XIAO_HAN'];
        $months = [];
        foreach ($monthNames as $key) {
            $term = $terms[$key];
            $local = (new DateTimeImmutable($term->toYmdHms(), $beijing))->setTimezone($zone);
            $months[] = ['jie' => $key === 'XIAO_HAN' ? '小寒' : $key, 'startsAt' => $local->format('Y-m-d H:i:s'),
                'pillar' => Pillars::row($term->getLunar()->getMonthInGanZhiExact(), $natal)];
        }
        return ['startOffset' => ['years' => $yun->getStartYear(), 'months' => $yun->getStartMonth(), 'days' => $yun->getStartDay(), 'hours' => $yun->getStartHour()],
            'handover' => $handover->format('Y-m-d H:i'), 'direction' => $yun->isForward() ? '顺' : '逆', 'referenceYear' => $year,
            'age' => $year - $firstYear + 1, 'ageBasis' => '流年年份减出生公历年加一', 'commander' => $command, 'decades' => $cycles, 'years' => $years, 'months' => $months,
            'active' => $active, 'relations' => Pillars::relations($natal + $active, array_keys($active)),
            'natalShenSha' => array_map(function ($r) {
                return $r['shenSha'];
            }, $rows), 'decadeShenSha' => $cycleStars, 'annualShenSha' => $annualStars];
    }
}
