<?php

namespace Dackou\Bazi\Internal;

use com\nlf\calendar\Lunar;
use InvalidArgumentException;

final class Language
{
    private const NAMES = ['zh-CN' => '简体中文', 'zh-TW' => '繁體中文', 'en' => 'English', 'ja' => '日本語', 'ko' => '한국어'];

    public static function normalize($language): string
    {
        $tag = is_string($language) ? strtolower(str_replace('_', '-', trim($language))) : '';
        if (preg_match('/^zh-(hans|hant)(?:-[a-z]{2})?$/D', $tag, $match)) {
            return $match[1] === 'hant' ? 'zh-TW' : 'zh-CN';
        }
        if (in_array($tag, ['zh', 'zh-cn', 'zh-sg', 'zh-my'], true)) {
            return 'zh-CN';
        }
        if (in_array($tag, ['zh-tw', 'zh-hk', 'zh-mo'], true)) {
            return 'zh-TW';
        }
        if (preg_match('/^(en|ja|ko)(?:-[a-z]{2})?$/D', $tag, $match)) {
            return $match[1];
        }
        throw new InvalidArgumentException('language 支持 ' . implode(', ', array_keys(self::NAMES)));
    }

    public static function form(string $language): array
    {
        $dictionary = Birth::data('languages/' . $language);
        return [
            'language' => $language,
            'labels' => $dictionary['labels'],
            'options' => ['language' => self::NAMES, 'gender' => $dictionary['gender'], 'mode' => $dictionary['mode']],
        ];
    }

    public static function display(string $language, array $form, array $basic, array $pillars, Lunar $calendar): array
    {
        $dictionary = Birth::data('languages/' . $language);
        $rows = [];
        foreach ($pillars as $key => $pillar) {
            $stem = $dictionary['stems'][$pillar['stem']];
            $branch = $dictionary['branches'][$pillar['branch']];
            $rows[$key] = [
                'ganZhi' => $stem . ($language === 'en' ? '-' : '') . $branch,
                'stem' => $stem, 'branch' => $branch,
                'stemElement' => $dictionary['elements'][$pillar['stemElement']],
                'branchElement' => $dictionary['elements'][$pillar['branchElement']],
                'mainStar' => $dictionary['stars'][$pillar['mainStar']],
                'secondaryStars' => array_map(function ($star) use ($dictionary) {
                    return $dictionary['stars'][$star];
                }, $pillar['secondaryStars']),
            ];
        }
        $lunar = sprintf($dictionary['formats']['lunar'], $calendar->getYear(), abs($calendar->getMonth()), $calendar->getDay(),
            $calendar->getMonth() < 0 ? $dictionary['formats']['leap'] : '', $rows['hour']['branch']);
        $terms = $basic['jie'];
        foreach ($terms as $key => $term) {
            $terms[$key]['name'] = $dictionary['jie'][$term['name']];
        }
        return self::form($language) + [
            'gender' => $dictionary['gender'][$form['gender']],
            'mode' => $dictionary['mode'][$form['mode']],
            'basic' => ['lunar' => $lunar, 'polarity' => $dictionary['polarity'][$basic['polarity']], 'jie' => $terms],
            'pillars' => $rows,
        ];
    }
}
