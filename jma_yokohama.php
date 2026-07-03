<?php

/**
 * 気象庁のオープンデータ（XMLフィード）取得実験
 * 
 * 条件:
 * - フィードURL: https://www.data.jma.go.jp/developer/xml/feed/regular.xml
 * - 条件1: <title> が「府県天気予報（Ｒ１）」
 * - 条件2: <author><name> が「横浜地方気象台」
 * - 動作: 上記条件に合致するエントリーの <link type="application/xml"> の href から詳細XMLを取得して表示
 */

if (PHP_SAPI !== 'cli') {
    echo "<!DOCTYPE html><html lang='ja'><head><meta charset='UTF-8'>";
    echo "<meta name='viewport' content='width=device-width, initial-scale=1.0'>";
    echo "<link rel='stylesheet' href='jma_test.css'>";
    echo "<title>気象情報取得実験</title></head><body>";
}

// 1. 定時フィードのURL
$feedUrl = 'https://www.data.jma.go.jp/developer/xml/feed/regular.xml';

// 2. XMLを取得 (Atomフィード)
$context = stream_context_create([
    'http' => ['header' => 'User-Agent: PHP Test Script']
]);
$feedXmlContent = file_get_contents($feedUrl, false, $context);

if (!$feedXmlContent) {
    die("フィードの取得に失敗しました。");
}

// 3. SimpleXMLでパース
$feed = new SimpleXMLElement($feedXmlContent);

// Atom名前空間を登録 (XPathを使用する場合に必要)
$feed->registerXPathNamespace('atom', 'http://www.w3.org/2005/Atom');

$targetUrl = null;
$latestUpdated = null;

// 4. <entry>タグをループして条件に合うものを探す
foreach ($feed->entry as $entry) {
    $title = (string)$entry->title;
    // authorはatom名前空間配下
    $authorName = (string)$entry->author->name;

    if ($title === '府県天気予報（Ｒ１）' && $authorName === '横浜地方気象台') {
        // 条件に一致！ 更新日時を確認
        $updated = strtotime((string)$entry->updated);

        if ($latestUpdated === null || $updated > $latestUpdated) {
            // より新しい（または最初に見つかった）エントリーを記録
            foreach ($entry->link as $link) {
                if ((string)$link['type'] === 'application/xml') {
                    $targetUrl = (string)$link['href'];
                    $latestUpdated = $updated;
                    break;
                }
            }
        }
    }
}

// 5. 対象のURLが見つかった場合、そのXMLを取得して解析
if ($targetUrl) {
    if (PHP_SAPI !== 'cli') {
        echo "<h1 class='forecast-title' style='border-left:none; padding-left:0;'>気象情報取得実験</h1>";
        echo "<p>対象URL: <a href='{$targetUrl}' target='_blank' class='link-original'>{$targetUrl}</a></p>";
    } else {
        echo "対象のURLが見つかりました: {$targetUrl}\n";
    }
    echo "------------------------------------------\n";

    $detailXmlContent = file_get_contents($targetUrl, false, $context);

    if ($detailXmlContent) {
        $report = new SimpleXMLElement($detailXmlContent);

        // 1. 名前空間の定数化
        $bodyNS = 'http://xml.kishou.go.jp/jmaxml1/body/meteorology1/';
        $ebNS = 'http://xml.kishou.go.jp/jmaxml1/elementBasis1/';

        $report->registerXPathNamespace('met', $bodyNS);
        $report->registerXPathNamespace('jmx_eb', $ebNS);
        $report->registerXPathNamespace('r', 'http://xml.kishou.go.jp/jmaxml1/');

        // --- 全データの統合解析 ---

        // 1. 各時間定義のデータを取得する補助関数
        $parseTimeSeries = function ($report, $type, $propertyType, $areaName, $isStation = false) use ($bodyNS, $ebNS) {
            $results = [];
            $xpath = "//met:MeteorologicalInfos[@type='{$type}']/met:TimeSeriesInfo";
            foreach ($report->xpath($xpath) as $ts) {
                $ts->registerXPathNamespace('met', $bodyNS);
                if (!$ts->xpath("met:Item/met:Kind/met:Property[met:Type='{$propertyType}']")) continue;

                // タイムライン定義を取得
                $timeDefines = [];
                foreach ($ts->xpath('met:TimeDefines/met:TimeDefine') as $td) {
                    $id = (string)$td['timeId'];
                    $startStr = (string)$td->children($bodyNS)->DateTime ?: (string)$td->DateTime;
                    $start = strtotime($startStr);
                    $duration = (string)$td->children($bodyNS)->Duration ?: (string)$td->Duration;

                    $end = $start;
                    if ($duration) {
                        if ($duration === 'P1D') {
                            $end = $start + 86400;
                        } else {
                            $hours = (int)preg_replace('/[^0-9]/', '', $duration);
                            $end = $start + ($hours * 3600);
                        }
                    } else {
                        // Durationがない場合のデフォルト処理
                        // 日間予報（天気）の場合は次の日の0時まで、あるいは24時間と仮定
                        if (strpos($propertyType, '天気') !== false && !strpos($propertyType, '３時間') !== false) {
                            $end = $start + 86400;
                        } else {
                            $end = $start + 10800; // デフォルト3時間
                        }
                    }
                    $timeDefines[$id] = [
                        'start' => $start,
                        'end' => $end,
                        'name' => (string)$td->children($bodyNS)->Name ?: (string)$td->Name
                    ];
                }

                // アイテム(地域/地点)をループ
                foreach ($ts->xpath('met:Item') as $item) {
                    $item->registerXPathNamespace('met', $bodyNS);
                    $item->registerXPathNamespace('jmx_eb', $ebNS);
                    $targetName = (string)$item->xpath($isStation ? 'met:Station/met:Name' : 'met:Area/met:Name')[0];

                    if ($targetName === $areaName) {
                        $prop = $item->xpath("met:Kind/met:Property[met:Type='{$propertyType}']")[0];
                        $prop->registerXPathNamespace('met', $bodyNS);
                        $prop->registerXPathNamespace('jmx_eb', $ebNS);

                        // 各種データの抽出
                        if ($propertyType === '天気') {
                            foreach ($prop->xpath('met:WeatherPart/jmx_eb:Weather') as $w) {
                                $ref = (string)$w['refID'];
                                $results[] = array_merge($timeDefines[$ref], ['val' => (string)$w]);
                            }
                        } elseif ($propertyType === '降水確率') {
                            foreach ($prop->xpath('met:ProbabilityOfPrecipitationPart/jmx_eb:ProbabilityOfPrecipitation') as $p) {
                                $ref = (string)$p['refID'];
                                $results[] = array_merge($timeDefines[$ref], ['val' => (string)$p . '%']);
                            }
                        } elseif ($propertyType === '３時間内卓越天気') {
                            foreach ($prop->xpath('met:WeatherPart/jmx_eb:Weather') as $w) {
                                $ref = (string)$w['refID'];
                                $results[] = array_merge($timeDefines[$ref], ['val' => (string)$w]);
                            }
                        } elseif ($propertyType === '３時間内代表風') {
                            $dirs = [];
                            $speeds = [];
                            foreach ($prop->xpath('met:WindDirectionPart/jmx_eb:WindDirection') as $wd) {
                                $dirs[(string)$wd['refID']] = (string)$wd;
                            }
                            foreach ($prop->xpath('met:WindSpeedPart/met:WindSpeedLevel') as $ws) {
                                $speeds[(string)$ws['refID']] = (string)$ws['description'];
                            }
                            foreach ($dirs as $ref => $dir) {
                                if (isset($timeDefines[$ref])) {
                                    $results[] = array_merge($timeDefines[$ref], ['val' => $dir . " (" . ($speeds[$ref] ?? "") . ")"]);
                                }
                            }
                        } elseif ($propertyType === '３時間毎気温' || $propertyType === '日中の最高気温') {
                            foreach ($prop->xpath('met:TemperaturePart/jmx_eb:Temperature') as $t) {
                                $ref = (string)$t['refID'];
                                $results[] = array_merge($timeDefines[$ref], ['val' => (string)$t . '度']);
                            }
                        }
                    }
                }
            }
            return $results;
        };

        // 各種データを収集
        $dailyWeather = $parseTimeSeries($report, '区域予報', '天気', '東部');
        $dailyMaxTemp = $parseTimeSeries($report, '地点予報', '日中の最高気温', '横浜', true);
        $popData = $parseTimeSeries($report, '区域予報', '降水確率', '東部');
        $hourlyWeather = $parseTimeSeries($report, '区域予報', '３時間内卓越天気', '東部');
        $hourlyWind = $parseTimeSeries($report, '区域予報', '３時間内代表風', '東部');
        $hourlyTemp = $parseTimeSeries($report, '地点予報', '３時間毎気温', '横浜', true);

        // 表示用の統合テーブルを構築

        // 1. タイムラインの生成 (3日間 = 24スロット)
        $timeline = [];
        if (!empty($hourlyWeather)) {
            //hourlyWeatherの最初の日時を基点にする
            $baseStart = $hourlyWeather[0]['start'];
            // 基点の日の00:00に揃える
            $baseStart = strtotime(date('Y-m-d 00:00:00', $baseStart));

            for ($i = 0; $i < 24; $i++) {
                $slotStart = $baseStart + ($i * 3 * 3600);
                $slotEnd = $slotStart + (3 * 3600);
                $timeline[] = ['start' => $slotStart, 'end' => $slotEnd];
            }
        }

        if (PHP_SAPI !== 'cli') {
            echo "<div class='weather-forecast-container'>";
            echo "<h2 class='forecast-title'>神奈川県 東部（横浜） 3日間 統合気象予報</h2>";
            echo "<div class='table-wrapper'>";
            echo "<table class='forecast-table'>";

            echo "<tbody>";

            $rowspanTracker = [
                'daily' => 0,
                'pop' => 0
            ];

            $lastLabel = "";

            foreach ($timeline as $index => $slot) {
                $mid = $slot['start'] + 1;

                // 予報区分の変化を検知してヘッダーを挿入
                $currentLabel = "ーー";
                foreach ($dailyWeather as $d) {
                    if ($mid >= $d['start'] && $mid < $d['end']) {
                        $currentLabel = $d['name'];
                        break;
                    }
                }

                if ($currentLabel !== $lastLabel) {
                    echo "<tr class='day-header-row'><th colspan='6'>{$currentLabel}</th></tr>";
                    echo "<tr class='sub-header-row'>";
                    echo "<th>日時</th><th>日間天気／日中の最高気温</th><th style='text-align: center;'>降水確率</th><th style='text-align: center;'>天気(詳細)</th><th style='text-align: center;'>気温</th><th>風（代表）</th>";
                    echo "</tr>";
                    $lastLabel = $currentLabel;
                }

                // 詳細天気の検索
                $hWeatherVal = "ーー";
                foreach ($hourlyWeather as $hw) {
                    if ($slot['start'] == $hw['start']) {
                        $hWeatherVal = $hw['val'];
                        break;
                    }
                }

                // 気温
                $temp = "ーー";
                foreach ($hourlyTemp as $t) {
                    if ($slot['start'] == $t['start']) {
                        $temp = $t['val'];
                        break;
                    }
                }

                // 風
                $wind = "ーー";
                foreach ($hourlyWind as $w) {
                    if ($slot['start'] == $w['start']) {
                        $wind = $w['val'];
                        break;
                    }
                }

                $dateStr = date('m/d H:i', $slot['start']);

                // 日付が変わる行に少しアクセントをつける
                $rowClass = (date('H:i', $slot['start']) === '00:00' && $index > 0) ? " class='row-date-separator'" : "";

                echo "<tr{$rowClass}>";
                echo "<td class='cell-date'>{$dateStr}</td>";

                // 日間天気 (rowspan)
                if ($rowspanTracker['daily'] <= 0) {
                    $dailyVal = "ーー";
                    $maxTemp = "";
                    $rs = 1;
                    foreach ($dailyWeather as $d) {
                        if ($mid >= $d['start'] && $mid < $d['end']) {
                            $dailyVal = $d['val'];
                            // 同じ期間の最高気温を探す
                            foreach ($dailyMaxTemp as $mt) {
                                if ($mid >= $mt['start'] && $mid < $mt['end']) {
                                    $maxTemp = "／" . $mt['val'];
                                    break;
                                }
                            }
                            $diff = $d['end'] - $slot['start'];
                            $rs = max(1, floor($diff / 10800));
                            break;
                        }
                    }
                    $rowspanAttr = ($rs > 1) ? " rowspan='{$rs}'" : "";
                    $rowspanTracker['daily'] = $rs;
                    echo "<td{$rowspanAttr} class='cell-daily'>{$dailyVal}{$maxTemp}</td>";
                }
                $rowspanTracker['daily']--;

                // 降水確率 (rowspan)
                if ($rowspanTracker['pop'] <= 0) {
                    $pop = "ーー";
                    $rs = 1;
                    foreach ($popData as $p) {
                        if ($mid >= $p['start'] && $mid < $p['end']) {
                            $pop = $p['val'];
                            $diff = $p['end'] - $slot['start'];
                            $rs = max(1, floor($diff / 10800));
                            break;
                        }
                    }
                    $rowspanAttr = ($rs > 1) ? " rowspan='{$rs}'" : "";
                    $rowspanTracker['pop'] = $rs;
                    echo "<td{$rowspanAttr} class='cell-pop'>{$pop}</td>";
                }
                $rowspanTracker['pop']--;

                echo "<td class='cell-weather-detail'>{$hWeatherVal}</td>";
                echo "<td class='cell-temp'>{$temp}</td>";
                echo "<td class='cell-wind'>{$wind}</td>";
                echo "</tr>";
            }
            echo "</tbody>";
            echo "</table>";
            echo "</div>"; // table-wrapper
            echo "</div>"; // weather-forecast-container
        } else {
            echo "\n【北東部（銚子） 3日間予報一覧】\n";
            foreach ($timeline as $slot) {
                $mid = $slot['start'] + 1;
                $hWeatherVal = "ーー";
                foreach ($hourlyWeather as $hw) {
                    if ($slot['start'] == $hw['start']) {
                        $hWeatherVal = $hw['val'];
                        break;
                    }
                }
                $pop = "ーー";
                foreach ($popData as $p) {
                    if ($mid >= $p['start'] && $mid < $p['end']) {
                        $pop = $p['val'];
                        break;
                    }
                }
                $daily = "ーー";
                foreach ($dailyWeather as $d) {
                    if ($mid >= $d['start'] && $mid < $d['end']) {
                        $daily = "{$d['name']} ({$d['val']})";
                        break;
                    }
                }
                $temp = "ーー";
                foreach ($hourlyTemp as $t) {
                    if ($slot['start'] == $t['start']) {
                        $temp = $t['val'];
                        break;
                    }
                }
                $wind = "ーー";
                foreach ($hourlyWind as $w) {
                    if ($slot['start'] == $w['start']) {
                        $wind = $w['val'];
                        break;
                    }
                }

                $dateStr = date('m/d H:i', $slot['start']);
                echo "{$dateStr} | 日間:{$daily} | 天気:{$hWeatherVal} | 気温:{$temp} | 降水:{$pop} | 風:{$wind}\n";
            }
        }
    } else {
        echo "詳細XMLの取得に失敗しました。";
    }
} else {
    echo "条件に一致するエントリーが見つかりませんでした。\n";
    echo "（府県天気予報（Ｒ１） かつ 横浜地方気象台）";
}

if (PHP_SAPI !== 'cli') {
    echo "</body></html>";
}
