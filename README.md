# dackou/bazi

八字排盘 Composer 组件。`Bazi::chart()` 返回可直接编码为 JSON 的 PHP 数组。支持 PHP 7.2–7.4 和 8.x，需要 JSON 扩展。在包目录运行 `composer install` 安装历法依赖。

```php
use Dackou\Bazi\Bazi;

require 'vendor/autoload.php';

$result = Bazi::chart([
    'name'          => '示例',
    'gender'        => 'male',
    'language'      => 'zh-CN',
    'birth'         => '1982-09-20 07:35',
    'address'       => '湖南省 邵阳市 武冈市',
    'referenceYear' => 2026,
]);

$fourPillars = array_column($result['chart']['pillars'], 'ganZhi');
// ['壬戌', '己酉', '丙午', '壬辰']
```

保留原入口 `Dackou\Bazi::chart()`。输入不合法时抛出 `InvalidArgumentException`。

## 表单独立接口

填写出生地后可调用 `coordinates()`。用户输入时间后，将该地点的经度交给 `trueSolarTime()`。这两个方法都可在提交排盘前调用。

```php
$point = Bazi::coordinates('湖南省 邵阳市 武冈市');
// $point['longitude'] 为 110.63，$point['latitude'] 为 26.73

$time = Bazi::trueSolarTime('1982-09-20 07:35', $point['longitude'], '+08:00');
// ['trueSolarTime' => '1982-09-20 07:04', 'trueSolarTimeExact' => '1982-09-20 07:03:50']
```

地址匹配采用内置中国行政区中文名称，返回地区代表点。重名时可传完整省市区，海外地点可直接使用已知经度。时间接口接受公历出生地钟表时间、东正西负的经度，以及出生时的 UTC 偏移（默认 `+08:00`）。返回值包含校正后的日期，可跨日。

## 表单语言

`language` 支持 `zh-CN`（默认，简体中文）、`zh-TW`（繁體中文）、`en`（英语）、`ja`（日语）和 `ko`（韩语）。也接受 `zh-Hant`、`en-US`、`ja-JP`、`ko_KR` 等常用别名。无法识别的语言会抛出 `InvalidArgumentException`。

```php
$form = Bazi::formOptions('ja-JP');
// $form['labels']['birth'] 为「出生日時」
// $form['options']['gender']['female'] 为「女性」，表单提交值为 female
```

排盘的 `input.language` 返回规范语言代码。`display` 提供所选语言的表单标签、选项、农历日期、前后节气、四柱干支、五行和十神。`basic`、`chart` 和 `professional` 直接按所选语言返回结果。例如 `language=en` 时，`chart.pillars.hour.mainStar` 可返回 `Seven Killings`，`professional.direction` 可返回 `Forward`。五行计数等以术语为键的字典也使用当前语言，例如 `basic.elementCounts.Wood`。`input` 保存规范化入参。出生地、古籍出处及引文、称骨歌诀保留原文。

## 输入

| 字段 | 填写方式 |
| --- | --- |
| `name`、`gender` | 必填，姓名为非空字符串。男性填 `male` 或 `1`，女性填 `female` 或 `2`。兼容 `男`、`女`。 |
| `mode` | 默认 `solar`。公历填 `solar` 或 `1`，农历填 `lunar` 或 `2`，四柱填 `pillars`、`3` 或 `农历、pillars`。兼容 `公历`、`农历`、`四柱`。 |
| `birth` | 必填，`YYYY-MM-DD HH:mm`，输入年份 1900–2100。农历模式按农历年月日解析，另两种模式按公历解析。 |
| `leapMonth` | 农历闰月传 `true`。例如 `2023-02-01 10:30` 配合 `true` 对应公历 2023-03-22。 |
| `pillars` | 四柱模式必填。按年、月、日、时顺序传四个干支，或使用 `year/month/day/hour` 四个键。用出生时间校验四柱并确定起运所需的分钟。 |
| `address` | 可选，内置国内地区表，接受唯一的地名或完整行政区路径。重名、未收录地点可传明确坐标。 |
| `coordinates` | 可选，例如 `['longitude'=>110.63,'latitude'=>26.73]`。手工坐标优先；东经、北纬为正。默认地址坐标为地区代表点。 |
| `utcOffset` | 默认 `+08:00`。其他时区或夏令时请传出生当时的实际 UTC 偏移。 |
| `group`、`save` | 分组字符串、是否保存的布尔标记，原样提供给业务层处理记录。 |
| `referenceYear` | 要查看的流年年份，整数，默认取当地当前公历年与出生年中较晚者。以立春为流年起点，支持查询至 2300 年。 |

数字编号也接受表单提交的数字字符串。返回的 `input.gender` 和 `input.mode` 使用英文值，`input.birth` 和 `input.address` 使用新字段名。旧表单可继续提交 `birthTime` 和 `birthPlace`，同时提供时以新字段为准。旧记录中的女性编号 `0` 需改为 `2`。

只有四柱时，可先调用 `Bazi::findBirthTimes(['壬戌','己酉','丙午','壬辰'], 1900, 2000)`。它列出北京时间、未经经度校正的候选区间 `[from, until)`。同一组四柱可以对应多个日期和出生分钟。选定实际时间后调用 `chart()`。

## 返回字段

| 分区 | 字段与含义 |
| --- | --- |
| `input` 表单 | 规范化姓名、性别、方式、出生时间地点、经纬度、分组和保存标记。`trueSolarTime` 四舍五入到分钟，`trueSolarTimeExact` 保留计算到秒的结果。缺少地点和坐标时两者为 `null`，以出生钟表时间定日时。 |
| `basic` 历法 | `lunar` 农历、`solar` 公历、`trueSolarTime` 真太阳时、`birthPlace` 地区、`dayMaster` 日主、`polarity` 阴阳、`chartType` 乾坤、`commander` 司令、`birthJie` 出生节气、`jie.previous/next` 前后交节时刻及间隔、`zodiac` 星座、`lodge` 星宿。 |
| `basic` 宫位与参考 | `fetalOrigin` 胎元、`fetalBreath` 胎息、`lifePalace` 命宫、`bodyPalace` 身宫（各含干支及纳音）、`void.year/day` 年日空亡、`lifeGua` 命卦。`yinYang` 阴阳个数、`strength` 旺衰、`pattern` 格局、`elementEnergy` 能量占比、`elementCounts` 明干明支个数、`hiddenCounts` 仅藏干、`countsWithHidden` 明干加藏干。`bones` 含各项钱数、总重和歌诀，`sky.longitude` 提供出生时七曜的地心黄经，可据此绘图。 |
| `chart` 基本排盘 | `pillars.year/month/day/hour` 各含主星、干支及五行、藏干与对应副星、星运、自坐、空亡、纳音、神煞。`relations.stems/branches/pillars` 给出天干、地支、整柱关系和所在柱位。`revealedStems` 为透藏对照，`climate` 为月令调候提示，`classics` 含典籍来源、原文、译文和本盘对照。 |
| `professional` 专业排盘 | `startOffset` 出生后几岁几月几日几时起运、`handover` 首次交运、`direction` 顺逆、`age` 流年虚岁、`commander` 司令。`decades` 含起运前年段和大运，`years` 为选中运段内的流年，`months` 为所选流年的十二节月及交节时间。`active` 标出岁运，`relations.stems/branches/pillars` 为岁运关系。`natalShenSha/decadeShenSha/annualShenSha` 分别给出四柱、大运、流年神煞。 |

## 计算口径

- 以立春换年、十二节换月。出生钟表时间和交节时间先换算为同一时区。真太阳时采用经度差加均时差，排日、时柱时使用未按分钟取整的值，子初 23:00 换干支日。表单农历依出生地民用日期换算。
- 起运按阳男阴女顺、阴男阳女逆，距顺逆方向相邻节的间隔按分钟折算，三日折一年。一年内的大运列表按交运年份分段，具体交接时刻见各大运的 `handover`。流月从当年立春到次年立春，最后一个月起于次年小寒。
- 截图样例的出生瞬间距白露满 12 天，顺推至寒露折为 6 年 2 月 17 天 6 小时。此处用绝对时间差，真太阳时用于本地日、时柱。
- 五行能量以四个明干和四支藏干加权，再按月令调整，返回百分比和方法。旺衰以印比占比给出参考，格局列出月支本气所对应的十神。神煞采用包内列出的常用规则。调候覆盖十日干与十二月支。古籍当前收录《子平真诠·论用神》的月令条文。称骨用农历正月起年，闰月按同月计，区分男女歌诀。
- 数据随包离线读取。历法由 `6tail/lunar-php` 提供。地区表来自 `jdb110/fast-geocn` 的行政区域轮廓，取最大多边形的近似中心；武冈使用输入示例给出的市区坐标。称骨表来自 `chxb/chenggu`，男命四两九钱歌诀采用题给异文。调候表据 `bazi-ziwei-skill` 的 `TIAO_HOU` 整理。相关许可位于 `resources/licenses`。七曜位置中日月取寿星历，五大行星采用 JPL *Approximate Positions of the Planets* 表 2a、2b，供天体图展示。

运行 `composer test` 检查截图样例、三种输入方式、闰月、时辰边界、交节边界及无效参数。
