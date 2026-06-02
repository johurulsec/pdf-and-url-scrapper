<?php

declare(strict_types=1);

namespace App;

final class NormalizeFields
{
    public static function standardize(array $raw): array
    {
        $g = fn (string ...$keys): string => trim(implode(' ', array_map(
            fn ($k) => (string)($raw[$k] ?? ''),
            $keys
        )));

        $prop = [];
        $typeText = $g('種類', '物件種別', 'タイプ', '戸建て', 'マンション', '1棟マンション', 'アパート', '土地');
        if (str_contains($typeText, '戸建') || str_contains($typeText, '一戸建')) {
            $prop[] = 'detached';
        }
        if (str_contains($typeText, 'マンション') && str_contains($typeText, '1棟')) {
            $prop[] = 'building_mansion';
        } elseif (str_contains($typeText, 'マンション')) {
            $prop[] = 'mansion';
        }
        if (str_contains($typeText, 'アパート')) {
            $prop[] = 'apartment';
        }
        if (str_contains($typeText, '土地')) {
            $prop[] = 'land';
        }

        $price = null;
        foreach (['価格', '販売価格', '値段', 'price'] as $k) {
            $price ??= Normalization::priceJpy((string)($raw[$k] ?? ''));
        }

        $land = null;
        foreach (['土地面積', '敷地面積'] as $k) {
            $land ??= Normalization::areaM2((string)($raw[$k] ?? ''));
        }
        $building = null;
        foreach (['建物面積', '延床面積', '延床', '床面積'] as $k) {
            $building ??= Normalization::areaM2((string)($raw[$k] ?? ''));
        }

        $bcr = null;
        foreach (['建ぺい率', '建蔽率'] as $k) {
            $bcr ??= Normalization::ratioPercent((string)($raw[$k] ?? ''));
        }

        $farText = (string)($raw['容積率'] ?? '');
        $far = Normalization::ratioPercent($farText);
        $effective = preg_match('/（\s*([0-9]{1,3})\s*％\s*）/u', $farText, $m) ? (int)$m[1] : null;
        $notes = $raw['容積率但し書き'] ?? (($effective && trim($farText) === '') ? $effective . '%' : null);

        $rights = [];
        $tenure = Normalization::tenure($g('所有権', '借地権', '敷地権', '権利'));
        if ($tenure) {
            $rights[] = $tenure;
        }

        $landCategory = null;
        foreach (['宅地', '山林', '田', '畑', '雑種地'] as $token) {
            if (str_contains($g('地目', '土地分類', '備考'), $token)) {
                $landCategory = $token;
                break;
            }
        }

        $transport = [];
        $line = $raw['沿線'] ?? $raw['路線'] ?? $raw['line'] ?? null;
        $station = $raw['駅'] ?? $raw['station'] ?? null;
        $walk = Normalization::walkMinutes($g('徒歩', 'アクセス', '交通'));
        if ($line || $station || $walk !== null) {
            $transport[] = ['line' => $line, 'station' => $station, 'walkMinutes' => $walk];
        }

        $structText = $g('構造', '鉄筋コンクリート', '鉄骨鉄筋コンクリート', '鉄骨', '重量鉄骨', '軽量鉄骨', '木造');
        $structText = trim(preg_replace('/\s+/u', ' ', $structText) ?: '');
        $utilSrc = $g('水道', '下水', 'ガス', '都市ガス', '設備');
        $parkingText = $g('駐車場', '車庫', '駐車');
        $parking = ['available' => null, 'count' => null, 'type' => null, 'text' => null];
        if (str_contains($parkingText, '駐車') || str_contains($parkingText, '車庫')) {
            $parking = [
                'available' => !(str_contains($parkingText, '無し') || str_contains($parkingText, 'なし')),
                'count' => null,
                'type' => null,
                'text' => $parkingText ?: null,
            ];
        }

        $rawMentions = [];
        foreach (['土地面積', '建物面積', '延床面積', '延床', '敷地面積', '1階面積', '2階面積', '3階面積', '面積'] as $k) {
            if (!empty($raw[$k])) {
                $rawMentions[] = $k . '/' . (string)$raw[$k];
            }
        }

        return [
            'propertyType' => $prop,
            'priceJPY' => $price,
            'address' => ['full' => $raw['住所'] ?? $raw['所在地'] ?? $raw['所在'] ?? $raw['address'] ?? null],
            'areas' => [
                'landArea_m2' => $land,
                'buildingArea_m2' => $building,
                'floorArea_m2' => $building,
                'siteArea_m2' => $land,
                'rawMentions' => $rawMentions,
            ],
            'rights' => $rights,
            'share' => $raw['共有持分'] ?? null,
            'landCategory' => $landCategory,
            'road' => [
                'width_m' => Normalization::meters($g('幅員', '道路幅員')),
                'frontage_m' => Normalization::meters($g('間口', '接道長さ', '長さ')),
                'direction' => Normalization::direction($g('接道', '道路', '方位', '方向')),
                'type' => str_contains($g('道路', '接道'), '公道') ? 'public' : (str_contains($g('道路', '接道'), '私道') ? 'private' : null),
                'notes' => $g('接道', '道路メモ') ?: null,
            ],
            'ratios' => [
                'buildingCoverage_pct' => $bcr,
                'floorAreaRatio_pct' => $far,
                'floorAreaRatioEffective_pct' => $effective,
                'notes' => $notes,
            ],
            'zoning' => $raw['用途地域'] ?? null,
            'utilities' => [
                'water' => (str_contains($utilSrc, '水道') || str_contains($utilSrc, '公営水道')) ? true : null,
                'sewer' => (str_contains($utilSrc, '下水') || str_contains($utilSrc, '公共下水')) ? true : null,
                'gas' => str_contains($utilSrc, '都市ガス') ? 'city' : null,
                'cityGas' => str_contains($utilSrc, '都市ガス') ? true : null,
                'electricity' => str_contains($utilSrc, '電気') ? true : null,
            ],
            'status' => $raw['現況'] ?? $raw['状態'] ?? null,
            'transport' => $transport,
            'built' => [
                'builtYearMonth' => $raw['築年月'] ?? $raw['建築年月'] ?? null,
                'renovation' => $raw['増改築'] ?? $raw['リノベーション'] ?? null,
            ],
            'floorPlan' => $raw['間取り'] ?? $raw['floorPlan'] ?? null,
            'structure' => [
                'code' => Normalization::structure($structText),
                'text' => $structText !== '' ? $structText : null,
            ],
            'parking' => $parking,
            'notes' => $raw['備考'] ?? null,
        ];
    }
}
