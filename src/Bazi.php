<?php

namespace Dackou\Bazi;

use com\nlf\calendar\EightChar;
use com\nlf\calendar\util\LunarUtil as U;
use Dackou\Bazi\Internal\Birth;
use Dackou\Bazi\Internal\ChartTranslation;
use Dackou\Bazi\Internal\Language;
use Dackou\Bazi\Internal\Sky;
use Dackou\Bazi\Internal\Luck;
use Dackou\Bazi\Internal\Pillars;
use Dackou\Bazi\Internal\Reference;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

class Bazi
{
    /** Resolve a domestic administrative area to its representative point. */
    public static function coordinates(string $birthPlace): array
    {
        return Birth::coordinates($birthPlace);
    }

    /** Convert local Gregorian civil time using the longitude and UTC offset at birth. */
    public static function trueSolarTime(string $birthTime, float $longitude, string $utcOffset = '+08:00'): array
    {
        $civil = Birth::civilTime($birthTime, $utcOffset);
        return Birth::solarTimeFields(Birth::apparentTime($civil, $longitude));
    }

    /** Labels and select options for rendering the input form before a chart exists. */
    public static function formOptions(string $language = 'zh-CN'): array
    {
        return Language::form(Language::normalize($language));
    }

    /** Build the form, basic information, natal chart and professional timeline. */
    public static function chart(array $input): array
    {
        $language = Language::normalize($input['language'] ?? 'zh-CN');
        list($form, $civil, $clock) = Birth::parse($input);
        $form['language'] = $language;
        $beijing = new DateTimeZone('+08:00');
        // 节气比较的是同一瞬间。地方太阳时仅决定当地日、时柱。
        $instant = Birth::solar($civil->setTimezone($beijing))->getLunar();
        $calendar = Birth::solar($civil)->getLunar();
        $local = Birth::solar($clock)->getLunar()->getEightChar();
        $local->setSect(1); // 子初（23:00）换干支日。
        $base = $instant->getEightChar();
        $natal = ['year' => $base->getYear(), 'month' => $base->getMonth(), 'day' => $local->getDay(), 'hour' => $local->getTime()];
        if ($form['mode'] === 'pillars') {
            $given = $input['pillars'] ?? null;
            if (!is_array($given) || count($given) !== 4) {
                throw new InvalidArgumentException('四柱模式须提供年、月、日、时四柱');
            }
            $given = array_keys($given) === [0, 1, 2, 3] ? array_combine(array_keys($natal), $given) : $given;
            foreach ($natal as $k => $v) {
                if (($given[$k] ?? null) !== $v) {
                    throw new InvalidArgumentException('出生时间与所填四柱不符：'.$k);
                }
            }
            $form['pillars'] = $natal;
        }
        $year = $input['referenceYear'] ?? max((int)(new DateTimeImmutable('now', $civil->getTimezone()))->format('Y'), $instant->getSolar()->getYear());
        if (!is_int($year) || $year < $instant->getSolar()->getYear() || $year > 2300) {
            throw new InvalidArgumentException('referenceYear 应为出生年份至 2300 之间的整数');
        }
        $rows = [];
        foreach ($natal as $key => $gz) {
            $rows[$key] = Pillars::row($gz, $natal, $key === 'day');
        }
        $prev = $instant->getPrevJie();
        $next = $instant->getNextJie();
        $terms = [];
        $hours = [];
        foreach (['previous' => $prev, 'next' => $next] as $key => $term) {
            $time = new DateTimeImmutable($term->getSolar()->toYmdHms(), $beijing);
            $hours[$key] = intdiv(abs($civil->getTimestamp() - $time->getTimestamp()), 3600);
            $terms[$key] = ['name' => $term->getName(), 'time' => $time->setTimezone($civil->getTimezone())->format('Y-m-d H:i:s'), 'days' => intdiv($hours[$key], 24), 'hours' => $hours[$key] % 24];
        }
        $command = Reference::command($rows['month']['branch'], $terms['previous']['days']);
        $star = $calendar->getSolar()->getXingZuo();
        $signs = ['白羊' => 'Aries', '金牛' => 'Taurus', '双子' => 'Gemini', '巨蟹' => 'Cancer', '狮子' => 'Leo', '处女' => 'Virgo', '天秤' => 'Libra', '天蝎' => 'Scorpio', '射手' => 'Sagittarius', '摩羯' => 'Capricorn', '水瓶' => 'Aquarius', '双鱼' => 'Pisces'];
        $jieYear = $instant->getSolar()->getYear();
        if ($instant->getYearInGanZhiExact() !== U::$JIA_ZI[($jieYear - 4) % 60]) {
            $jieYear--;
        }
        $info = ['lunar' => $calendar->getYear().'年'.$calendar->getMonthInChinese().'月'.$calendar->getDayInChinese().' '.$rows['hour']['branch'].'时',
            'dayMaster' => $rows['day']['stem'].$rows['day']['stemElement'], 'polarity' => array_search($rows['day']['stem'], U::$GAN) % 2 ? '阳' : '阴',
            'chartType' => $form['gender'] === 'male' ? '乾造' : '坤造', 'solar' => $civil->format('Y-m-d H:i'), 'trueSolarTime' => $form['trueSolarTime'],
            'birthPlace' => $form['address'], 'commander' => $command.U::$WU_XING_GAN[$command].'用事', 'jie' => $terms,
            'birthJie' => sprintf('出生于%s后%d天%d小时，%s前%d天%d小时', $prev->getName(), $terms['previous']['days'], $terms['previous']['hours'], $next->getName(), $terms['next']['days'], $terms['next']['hours']),
            'zodiac' => $star.'座（'.$signs[$star].'）', 'lodge' => $calendar->getXiu().'宿'.$calendar->getGong().'方'.($calendar->getShou() === '青龙' ? '苍龙' : $calendar->getShou()),
            'void' => ['year' => $rows['year']['void'], 'day' => $rows['day']['void']], 'lifeGua' => Reference::gua($jieYear, $form['gender']),
            'bones' => Reference::bones($calendar, $rows['hour']['branch'], $form['gender']), 'sky' => Sky::positions($civil)];
        foreach (self::palaces($base, $local, $rows) as $key => $gz) {
            $info[$key] = ['ganZhi' => $gz, 'naYin' => U::$NAYIN[$gz]];
        }
        $result = ['input' => $form, 'basic' => $info + Reference::elements($rows),
            'chart' => ['pillars' => $rows, 'relations' => Pillars::relations($natal)] + Reference::chartNotes($rows),
            'professional' => Luck::build($base, $natal, $rows, $form['gender'], $year, $civil->getTimezone(), $command),
            'display' => Language::display($language, $form, $info, $rows, $calendar)];
        return ChartTranslation::apply($result, $calendar);
    }

    /** Beijing standard-time candidates, with a half-open interval at minute precision. */
    public static function findBirthTimes(array $pillars, int $fromYear, int $toYear): array
    {
        if ($fromYear < 1900 || $toYear > 2100 || $fromYear > $toYear) {
            throw new InvalidArgumentException('反查年份范围为 1900 至 2100');
        }
        $fields = ['year', 'month', 'day', 'hour'];
        if (array_keys($pillars) === [0, 1, 2, 3]) {
            $pillars = array_combine($fields, $pillars);
        }
        foreach ($fields as $key) {
            if (!in_array($pillars[$key] ?? null, U::$JIA_ZI, true)) {
                throw new InvalidArgumentException('无效干支 '.$key);
            }
        }
        $zone = new DateTimeZone('+08:00');
        $from = new DateTimeImmutable($fromYear.'-01-01', $zone);
        $to = new DateTimeImmutable(($toYear + 1).'-01-01', $zone);
        $month = Pillars::chars($pillars['month'])[1];
        $hourIndex = array_search(Pillars::chars($pillars['hour'])[1], U::$ZHI) - 1;
        $jie = ['立春', '惊蛰', '清明', '立夏', '芒种', '小暑', '立秋', '白露', '寒露', '立冬', '大雪', 'XIAO_HAN', 'LI_CHUN'];
        $offset = array_search($month, EightChar::$MONTH_ZHI) - 1;
        $results = [];
        for ($y = $fromYear - 1;$y <= $toYear;$y++) {
            if (U::$JIA_ZI[($y - 4) % 60] !== $pillars['year']) {
                continue;
            }
            $terms = \com\nlf\calendar\Solar::fromYmd($y, 6, 1)->getLunar()->getJieQiTable();
            $start = new DateTimeImmutable($terms[$jie[$offset]]->toYmdHms(), $zone);
            $end = new DateTimeImmutable($terms[$jie[$offset + 1]]->toYmdHms(), $zone);
            $midnight = $start->setTime(0, 0);
            $firstDay = Birth::solar($midnight)->getLunar()->getDayInGanZhiExact2();
            $days = (U::getJiaZiIndex($pillars['day']) - U::getJiaZiIndex($firstDay) + 60) % 60;
            $hourStart = $midnight->modify('+'.$days.' days')->modify(sprintf('%+d hours', $hourIndex * 2 - 1));
            $lower = max($start, $from, $hourStart);
            $upper = min($end, $to, $hourStart->modify('+2 hours'));
            // Only civil minutes that can be sent back to chart() are offered.
            $lower = $lower->setTimestamp((int)ceil($lower->getTimestamp() / 60) * 60);
            $upper = $upper->setTimestamp((int)ceil($upper->getTimestamp() / 60) * 60);
            if ($lower >= $upper) {
                continue;
            }
            $eight = Birth::solar($lower)->getLunar()->getEightChar();
            $eight->setSect(1);
            if ($eight->getMonth() === $pillars['month'] && $eight->getTime() === $pillars['hour']) {
                $results[] = ['from' => $lower->format('Y-m-d H:i'), 'until' => $upper->format('Y-m-d H:i'), 'utcOffset' => '+08:00'];
            }
        }
        return $results;
    }

    private static function palaces(EightChar $base, EightChar $local, array $rows): array
    {
        $month = array_search($rows['month']['branch'], EightChar::$MONTH_ZHI);
        $hour = $rows['hour']['branch'];
        $sum = $month + array_search($hour, EightChar::$MONTH_ZHI);
        $life = ($sum >= 14 ? 26 : 14) - $sum;
        $body = ($month + array_search($hour, U::$ZHI) - 1) % 12 + 1;
        $yearStem = array_search($rows['year']['stem'], U::$GAN);
        $palace = function ($index) use ($yearStem) {
            return U::$GAN[($yearStem * 2 + $index - 1) % 10 + 1].EightChar::$MONTH_ZHI[$index];
        };
        return ['fetalOrigin' => $base->getTaiYuan(), 'fetalBreath' => $local->getTaiXi(), 'lifePalace' => $palace($life), 'bodyPalace' => $palace($body)];
    }
}
