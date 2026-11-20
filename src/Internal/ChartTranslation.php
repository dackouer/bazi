<?php

namespace Dackou\Bazi\Internal;

use com\nlf\calendar\Lunar;

/** Present a completed chart in its requested language, after all calendar calculations. */
final class ChartTranslation
{
    private $words;
    private $separator;
    private $dictionary;

    private function __construct(string $language)
    {
        $this->dictionary = Birth::data('languages/' . $language);
        $this->separator = $language === 'en' ? '-' : '';
        $this->words = [];
        foreach (['stems', 'branches', 'elements', 'stars', 'polarity', 'jie', 'terms'] as $section) {
            $this->words += $this->dictionary[$section];
        }
    }

    public static function apply(array $result, Lunar $calendar): array
    {
        $language = $result['input']['language'];
        if ($language === 'zh-CN') {
            return $result;
        }
        $translator = new self($language);
        $basic = $result['basic'];
        $result['basic'] = $translator->basic($basic, $result['display'], $calendar);
        $result['chart'] = $translator->translate($result['chart']);
        $result['professional'] = $translator->translate($result['professional']);
        return $result;
    }

    private function basic(array $basic, array $display, Lunar $calendar): array
    {
        $formats = $this->dictionary['resultFormats'];
        $parts = Pillars::chars($basic['dayMaster']);
        $master = $this->words[$parts[0]] . ($this->separator ? ' ' : '') . $this->words[$parts[1]];
        $command = $this->word(substr($basic['commander'], 0, -strlen('用事')));
        $pattern = $this->word($basic['pattern']['tenGod']);
        $previous = $display['basic']['jie']['previous'];
        $next = $display['basic']['jie']['next'];
        preg_match('/（([A-Za-z]+)）$/u', $basic['zodiac'], $sign);
        preg_match('/^(.)卦（(.)四命）$/u', $basic['lifeGua'], $gua);

        $basic = $this->translate($basic);
        $basic = array_replace($basic, $display['basic']);
        $basic['dayMaster'] = $master;
        $basic['commander'] = sprintf($formats['command'], $command);
        $basic['pattern']['reference'] = sprintf($formats['pattern'], $pattern);
        $basic['birthJie'] = sprintf($formats['birthJie'], $previous['name'], $previous['days'], $previous['hours'],
            $next['name'], $next['days'], $next['hours']);
        $basic['zodiac'] = $this->dictionary['signs'][$sign[1]];
        $basic['lifeGua'] = sprintf($formats['gua'], $this->dictionary['gua'][$gua[1]], $this->dictionary['gua'][$gua[2]]);
        $basic['lodge'] = sprintf($formats['lodge'], $this->dictionary['mansions'][$calendar->getXiu()], $this->dictionary['palace'][$calendar->getGong()]);
        $qian = $basic['bones']['qian'];
        $basic['bones']['weight'] = sprintf($formats['weight'], intdiv($qian, 10), $qian % 10);
        return $basic;
    }

    private function word(string $text): string
    {
        if (isset($this->words[$text])) {
            return $this->words[$text];
        }
        // Concatenated stems, branches and elements (pillars, voids, relation participants).
        if (preg_match('/^[甲乙丙丁戊己庚辛壬癸子丑寅卯辰巳午未申酉戌亥木火土金水]+$/uD', $text)) {
            return implode($this->separator, array_map(function ($symbol) {
                return $this->words[$symbol];
            }, Pillars::chars($text)));
        }
        return $text;
    }

    private function translate(array $fields): array
    {
        $translated = [];
        foreach ($fields as $key => $value) {
            // Preserve quoted text and personal data even if they happen to match a term.
            if (in_array($key, ['birthPlace', 'original', 'verse', 'source'], true)) {
                $translated[$key] = $value;
                continue;
            }
            if ($key === 'text' && isset($fields['type'])) {
                $type = $fields['type'];
                $suffix = $type === '五合' ? '合' : $type;
                $symbols = substr($value, 0, -strlen($suffix));
                $value = $this->word($symbols) . ($this->separator ? ' — ' : '') . $this->word($type);
            } elseif (is_array($value)) {
                $value = $this->translate($value);
            } elseif (is_string($value)) {
                $value = $this->word($value);
            }
            // Domain labels also occur as map keys (e.g. element counts and sky positions).
            $translated[$this->words[$key] ?? $key] = $value;
        }
        return $translated;
    }
}
