<?php

namespace App\Common\Traits;

use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\SweetnessProfile;

trait SugarCaneWineCalculationsTrait
{
    private static float $defaultSo2TargetMgPerL  = 30.0;

    private static array $sugarCaneStrains = [
        'ec1118'     => ['dosis' => 0.25, 'tolerancia' => 18, 'atenuacion' => 0.95],
        '71b'        => ['dosis' => 0.25, 'tolerancia' => 14, 'atenuacion' => 0.90],
        'k1v'        => ['dosis' => 0.25, 'tolerancia' => 18, 'atenuacion' => 0.95],
        'd47'        => ['dosis' => 0.25, 'tolerancia' => 15, 'atenuacion' => 0.88],
        'custom_vin' => ['dosis' => 0.25, 'tolerancia' => 14, 'atenuacion' => 0.90],
        'pan_seca'   => ['dosis' => 1.0,  'tolerancia' => 8,  'atenuacion' => 0.75],
        'pan_fresca' => ['dosis' => 3.0,  'tolerancia' => 7,  'atenuacion' => 0.70],
    ];

    private static array $sugarCaneNutrientsDB = [
        'dap'       => 0.5,
        'fermaid_k' => 0.25,
        'fermaid_o' => 0.3,
        'mgso4'     => 0.2,
        'go_ferm'   => 0.3,
    ];

    public static function calculateSugarCaneWineDetails(
        float   $juiceLiters,
        float   $sugarcaneBrix,
        float   $finalBrixDesired,
        string  $yeastStrain,
        string  $yeastType,
        ?string $nutrientPrimary,
        ?string $nutrientSecondary,
        bool    $useSorbate,
        bool    $useBenzoate,
        bool    $useMetabisulfite,
        ?string $metabisulfiteType,
        bool    $useBentonite,
        bool    $useAlbumin
    ): array {
        $finalBrixDes = max(1.0, $finalBrixDesired);

        // Mosto base: el guarapo extraído es el mosto directamente
        $waterLiters = 0.0;
        $totalL      = round($juiceLiters, 2);

        // Levadura
        $cepa       = self::$sugarCaneStrains[$yeastStrain];
        $dosisGperL = $cepa['dosis'];
        $yeastGrams = round($dosisGperL * $totalL, 2);

        // Brix finales y ABV
        $brixNatural      = max(3.5, $sugarcaneBrix - min($sugarcaneBrix * $cepa['atenuacion'], $cepa['tolerancia'] / (0.59 * 0.51)));
        $brixFinalEffect  = max($finalBrixDes, $brixNatural);
        $abvFinal         = round(min(($sugarcaneBrix - $brixFinalEffect) * 0.59 * 0.51, $cepa['tolerancia']), 1);
        $needsStabilizers = $finalBrixDes > $brixNatural;
        $sweetnessProfile = SweetnessProfile::fromBrix($finalBrixDes)->value;

        // Nutrientes (solo levadura panadera)
        $nutrientPrimaryGrams   = null;
        $nutrientSecondaryGrams = null;
        if ($yeastType === 'panifera') {
            if ($nutrientPrimary !== null && isset(self::$sugarCaneNutrientsDB[$nutrientPrimary])) {
                $nutrientPrimaryGrams = round(self::$sugarCaneNutrientsDB[$nutrientPrimary] * $totalL, 2);
            }
            if ($nutrientSecondary !== null && isset(self::$sugarCaneNutrientsDB[$nutrientSecondary])) {
                $nutrientSecondaryGrams = round(self::$sugarCaneNutrientsDB[$nutrientSecondary] * $totalL, 2);
            }
        }

        // Estabilizantes
        $so2TargetMgPerL    = null;
        $metabisulfiteGrams = null;
        $sorbateGramsMin    = null;
        $sorbateGramsMax    = null;
        $benzoateGramsMin   = null;
        $benzoateGramsMax   = null;

        if ($useSorbate) {
            $sorbateGramsMin = round(0.2 * $totalL, 2);
            $sorbateGramsMax = round(0.3 * $totalL, 2);
        }
        if ($useBenzoate) {
            $benzoateGramsMin = round(0.1 * $totalL, 2);
            $benzoateGramsMax = round(0.15 * $totalL, 2);
        }
        if ($useMetabisulfite && $metabisulfiteType !== null) {
            $so2TargetMgPerL    = self::$defaultSo2TargetMgPerL;
            $so2Pct             = $metabisulfiteType === 'potasio' ? 0.576 : 0.674;
            $metabisulfiteGrams = round(($so2TargetMgPerL * $totalL) / ($so2Pct * 1000), 2);
        }

        // Clarificantes
        $bentoniteGramsMin = null;
        $bentoniteGramsMax = null;
        $albuminGramsMin   = null;
        $albuminGramsMax   = null;

        if ($useBentonite) {
            $bentoniteGramsMin = round(1.0 * $totalL, 2);
            $bentoniteGramsMax = round(2.0 * $totalL, 2);
        }
        if ($useAlbumin) {
            $albuminGramsMin = round(3 * $totalL / 100, 2);
            $albuminGramsMax = round(5 * $totalL / 100, 2);
        }

        return [
            'sugarcane_brix'           => $sugarcaneBrix,
            'water_liters'             => $waterLiters,
            'total_must_liters'        => $totalL,
            'yeast_dose_g_per_l'       => $dosisGperL,
            'yeast_grams'              => $yeastGrams,
            'nutrient_primary_grams'   => $nutrientPrimaryGrams,
            'nutrient_secondary_grams' => $nutrientSecondaryGrams,
            'so2_target_mg_per_l'      => $so2TargetMgPerL,
            'sweetness_profile'        => $sweetnessProfile,
            'final_brix_estimated'     => round($brixFinalEffect, 2),
            'abv_estimated'            => $abvFinal,
            'needs_stabilizers'        => $needsStabilizers,
            'sorbate_grams_min'        => $sorbateGramsMin,
            'sorbate_grams_max'        => $sorbateGramsMax,
            'benzoate_grams_min'       => $benzoateGramsMin,
            'benzoate_grams_max'       => $benzoateGramsMax,
            'metabisulfite_grams'      => $metabisulfiteGrams,
            'bentonite_grams_min'      => $bentoniteGramsMin,
            'bentonite_grams_max'      => $bentoniteGramsMax,
            'albumin_grams_min'        => $albuminGramsMin,
            'albumin_grams_max'        => $albuminGramsMax,
        ];
    }
}
