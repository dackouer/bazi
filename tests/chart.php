<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use Dackou\Bazi\Bazi;

set_error_handler(function ($level, $message, $file, $line) {
    if ($level & (E_WARNING | E_NOTICE)) {
        throw new ErrorException($message, 0, $level, $file, $line);
    }
    return false;
});
function same($expected, $actual, $label)
{
    if ($expected !== $actual) {
        throw new RuntimeException($label.' '.json_encode($actual, JSON_UNESCAPED_UNICODE));
    }
}
function invalid(callable $fn)
{
    try {
        $fn();
    } catch (InvalidArgumentException $e) {
        return;
    }
    throw new RuntimeException('无效输入未被拒绝');
}
$input = ['name' => '示例', 'gender' => '男', 'birth' => '1982-09-20 07:35', 'address' => '湖南省 邵阳市 武冈市', 'referenceYear' => 2026];
$result = Bazi::chart($input);
$b = $result['basic'];
$chart = $result['chart'];
$p = $result['professional'];
$gz = ['壬戌', '己酉', '丙午', '壬辰'];
same($gz, array_column($chart['pillars'], 'ganZhi'), '四柱');
same('1982年八月初四 辰时', $b['lunar'], '农历');
same('1982-09-20 07:04', $b['trueSolarTime'], '真太阳时');
same('1982-09-08 07:31:43', $b['jie']['previous']['time'], '白露');
same('1982-10-08 23:02:09', $b['jie']['next']['time'], '寒露');
same('离卦（东四命）', $b['lifeGua'], '命卦');
same(49, $b['bones']['qian'], '称骨');
same('甲辰', $b['lifePalace']['ganZhi'], '命宫');
same('壬寅', $b['bodyPalace']['ganZhi'], '身宫');
same(['木' => 0, '火' => 2, '土' => 3, '金' => 1, '水' => 2], $b['elementCounts'], '五行本数');
same(7, count($b['sky']['longitude']), '七曜');
same(true, abs(array_sum($b['elementEnergy']['percent']) - 100) < 0.03, '能量分布');
same('辛金用事', $b['commander'], '人元分野');
same(true, in_array('半合', array_column($chart['relations']['branches'], 'type'), true), '原局半合');
same(['annual' => '丙午', 'decade' => '癸丑'], $p['active'], '选中岁运');
same(45, $p['age'], '虚岁');
same(['years' => 6, 'months' => 2, 'days' => 17, 'hours' => 6], $p['startOffset'], '起运折算');
same(10, count($p['years']), '流年数');
same(12, count($p['months']), '流月数');
same('庚寅', $p['months'][0]['pillar']['ganZhi'], '立春月');
same('小寒', $p['months'][11]['jie'], '跨年小寒');
same('2027', substr($p['months'][11]['startsAt'], 0, 4), '流月交界年');
same($chart, Bazi::chart(array_replace($input, ['mode' => '农历', 'birth' => '1982-08-04 07:35']))['chart'], '农历等价');
same($chart, Bazi::chart($input + ['mode' => '四柱', 'pillars' => $gz])['chart'], '四柱等价');
same($chart, Bazi::chart($input + ['save' => true, 'group' => '家人'])['chart'], '记录选项独立');
$reverse = Bazi::findBirthTimes($gz, 1982, 1982);
same('1982-09-20 07:00', $reverse[0]['from'], '四柱反查起点');
same('1982-09-20 09:00', $reverse[0]['until'], '四柱反查终点');
$plain = ['name' => '测试', 'gender' => '女', 'referenceYear' => 2026];
$leap = Bazi::chart($plain + ['mode' => 'lunar', 'birth' => '2023-02-01 10:30', 'leapMonth' => true]);
same('2023-03-22 10:30', $leap['basic']['solar'], '闰二月');
same(null, $leap['input']['trueSolarTime'], '缺省地点');
same('逆', Bazi::chart(array_replace($input, ['gender' => '女']))['professional']['direction'], '阴阳顺逆');
$early = Bazi::chart(array_replace($input, ['referenceYear' => 1982]));
same(false, isset($early['professional']['active']['decade']), '未交运');
// 2024 立春北京时间 16:27:07。新疆地方时仍早于 16 时，年、月柱应换。
$west = $plain + ['coordinates' => ['longitude' => 87.6, 'latitude' => 43.8]];
$justBefore = Bazi::chart($west + ['birth' => '2024-02-04 16:27']);
$justAfter = Bazi::chart($west + ['birth' => '2024-02-04 16:28']);
same('癸卯', $justBefore['chart']['pillars']['year']['ganZhi'], '立春前');
same('甲辰', $justAfter['chart']['pillars']['year']['ganZhi'], '立春后');
same('丙寅', $justAfter['chart']['pillars']['month']['ganZhi'], '立春换月');
$sameInstant = Bazi::chart($west + ['birth' => '2024-02-04 08:28', 'utcOffset' => '+00:00']);
same($justAfter['chart'], $sameInstant['chart'], '同一瞬间和地点');
$beforeZi = Bazi::chart($plain + ['birth' => '2024-01-01 22:59']);
$atZi = Bazi::chart($plain + ['birth' => '2024-01-01 23:00']);
$midnight = Bazi::chart($plain + ['birth' => '2024-01-02 00:00']);
same(false, $beforeZi['chart']['pillars']['day'] === $atZi['chart']['pillars']['day'], '子初换日');
same($atZi['chart']['pillars'], $midnight['chart']['pillars'], '子时跨午夜');
foreach ([['gender' => null], ['name' => ' '], ['birth' => '2025-02-29 07:00'], ['birth' => '2025-03-01 25:30'], ['mode' => '农历', 'leapMonth' => true], ['mode' => '四柱', 'pillars' => array_reverse($gz)], ['address' => '朝阳区'], ['coordinates' => ['longitude' => 200, 'latitude' => 10]]] as $bad) {
    invalid(function () use ($input,$bad) {
        Bazi::chart(array_replace($input,$bad));
    });
}
$point = Bazi::coordinates('湖南省 邵阳市 武冈市');
same($result['input']['coordinates'], $point, '独立地址接口与排盘一致');
$solarTime = Bazi::trueSolarTime('1982-09-20 07:35', $point['longitude']);
same('1982-09-20 07:03:50', $solarTime['trueSolarTimeExact'], '独立时间接口精确到秒');
same($result['basic']['trueSolarTime'], $solarTime['trueSolarTime'], '两种入口取整一致');
same($solarTime, Bazi::trueSolarTime('1982-09-19 23:35', 110.63, '+00:00'), 'UTC 偏移和跨日');
invalid(function () { Bazi::coordinates('朝阳区'); });
invalid(function () { Bazi::coordinates(''); });
invalid(function () { Bazi::trueSolarTime('2024-02-30 12:00', 0); });
invalid(function () { Bazi::trueSolarTime('2024-02-29 12:00', NAN); });
invalid(function () { Bazi::trueSolarTime('2024-02-29 12:00', 0, '+14:30'); });
same('zh-CN', $result['input']['language'], '默认语言');
foreach ([['zh-Hans', 'zh-CN', '出生时间'], ['zh_Hant_TW', 'zh-TW', '出生時間'], ['en-US', 'en', 'Birth date and time'], ['ja-JP', 'ja', '出生日時'], ['ko_KR', 'ko', '출생 일시']] as list($tag, $language, $label)) {
    $localized = Bazi::chart($input + ['language' => $tag]);
    same($language, $localized['input']['language'], '规范语言标记');
    same($label, $localized['display']['labels']['birth'], '本地化表单标签');
    same($localized['display']['options'], Bazi::formOptions($tag)['options'], '独立表单选项');
    $expected = [
        'zh-CN' => ['阳', '七杀', '顺', '立春'],
        'zh-TW' => ['陽', '七殺', '順', '立春'],
        'en' => ['Yang', 'Seven Killings', 'Forward', 'Start of Spring'],
        'ja' => ['陽', '偏官', '順行', '立春'],
        'ko' => ['양', '편관', '순행', '입춘'],
    ];
    same($expected[$language], [$localized['basic']['polarity'], $localized['chart']['pillars']['hour']['mainStar'],
        $localized['professional']['direction'], $localized['professional']['months'][0]['jie']], '主要分区按语言返回');
    same($result['professional']['handover'], $localized['professional']['handover'], '交运时刻');
    same($result['professional']['startOffset'], $localized['professional']['startOffset'], '起运数据');
    same($result['basic']['birthPlace'], $localized['basic']['birthPlace'], '出生地址原文');
}
same('임술', $localized['display']['pillars']['year']['ganZhi'], '韩文四柱');
invalid(function () use ($input) { Bazi::chart($input + ['language' => 'fr']); });
foreach ([1, '1', 'male', 2, '2', 'female'] as $gender) {
    $canonical = in_array($gender, [1, '1', 'male'], true) ? 'male' : 'female';
    same(Bazi::chart(array_replace($input, ['gender' => $canonical])),
        Bazi::chart(array_replace($input, ['gender' => $gender])), '性别编码等价');
}
foreach (['solar' => [1, '1'], 'lunar' => [2, '2'], 'pillars' => [3, '3', '农历、pillars']] as $mode => $aliases) {
    $form = array_replace($input, ['mode' => $mode]);
    if ($mode === 'lunar') { $form['birth'] = '1982-08-04 07:35'; }
    if ($mode === 'pillars') { $form['pillars'] = $gz; }
    $expected = Bazi::chart($form);
    foreach ($aliases as $alias) {
        same($expected, Bazi::chart(array_replace($form, ['mode' => $alias])), '排盘方式编码等价');
    }
}
invalid(function () use ($input) { Bazi::chart(array_replace($input, ['gender' => 0])); });
same($input['birth'], $result['input']['birth'], '出生时间字段');
same($input['address'], $result['input']['address'], '出生地址字段');
$legacy = $input;
$legacy['birthTime'] = $legacy['birth'];
$legacy['birthPlace'] = $legacy['address'];
unset($legacy['birth'], $legacy['address']);
same($result, Bazi::chart($legacy), '旧字段兼容');
echo "排盘回归检查通过\n";
