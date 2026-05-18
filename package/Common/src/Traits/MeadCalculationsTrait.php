<?php

namespace App\Common\Traits;

use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\SweetnessProfile;

trait MeadCalculationsTrait
{
    private static float $defaultHoneyBrix       = 80.0;
    private static float $defaultSo2TargetMgPerL = 30.0;

    private static array $strainsData = [
        'ec1118'     => ['dosis' => 0.25, 'tolerancia' => 18, 'atenuacion' => 0.95],
        '71b'        => ['dosis' => 0.25, 'tolerancia' => 14, 'atenuacion' => 0.90],
        'k1v'        => ['dosis' => 0.25, 'tolerancia' => 18, 'atenuacion' => 0.95],
        'd47'        => ['dosis' => 0.25, 'tolerancia' => 15, 'atenuacion' => 0.88],
        'custom_vin' => ['dosis' => 0.25, 'tolerancia' => 14, 'atenuacion' => 0.90],
        'pan_seca'   => ['dosis' => 1.0,  'tolerancia' => 8,  'atenuacion' => 0.75],
        'pan_fresca' => ['dosis' => 3.0,  'tolerancia' => 7,  'atenuacion' => 0.70],
    ];

    private static array $nutrientsDB = [
        'dap'       => 0.5,
        'fermaid_k' => 0.25,
        'fermaid_o' => 0.3,
        'mgso4'     => 0.2,
        'go_ferm'   => 0.3,
    ];

    public static function calculateMeadDetails(
        float   $honeyKg,
        float   $initialBrix,
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
        $honeyBrix    = self::$defaultHoneyBrix;
        $finalBrixDes = max(1.0, $finalBrixDesired);

        // Mosto base
        $mielG        = $honeyKg * 1000;
        $solidosMiel  = ($honeyBrix / 100) * $mielG;
        $aguaEnMiel   = $mielG - $solidosMiel;
        $totalMezclaG = $solidosMiel / ($initialBrix / 100);
        $waterLiters  = round(($totalMezclaG - $solidosMiel - $aguaEnMiel) / 1000, 2);
        $totalL       = round($totalMezclaG / 1000, 2);

        // Levadura
        $cepa       = self::$strainsData[$yeastStrain];
        $dosisGperL = $cepa['dosis'];
        $yeastGrams = round($dosisGperL * $totalL, 2);

        // Brix finales y ABV
        $brixNatural      = max(3.5, $initialBrix - min($initialBrix * $cepa['atenuacion'], $cepa['tolerancia'] / (0.59 * 0.51)));
        $brixFinalEffect  = max($finalBrixDes, $brixNatural);
        $abvFinal         = round(min(($initialBrix - $brixFinalEffect) * 0.59 * 0.51, $cepa['tolerancia']), 1);
        $needsStabilizers = $finalBrixDes > $brixNatural;
        $sweetnessProfile = SweetnessProfile::fromBrix($finalBrixDes)->value;

        // Nutrientes (solo levadura panadera)
        $nutrientPrimaryGrams   = null;
        $nutrientSecondaryGrams = null;
        if ($yeastType === 'panifera') {
            if ($nutrientPrimary !== null && isset(self::$nutrientsDB[$nutrientPrimary])) {
                $nutrientPrimaryGrams = round(self::$nutrientsDB[$nutrientPrimary] * $totalL, 2);
            }
            if ($nutrientSecondary !== null && isset(self::$nutrientsDB[$nutrientSecondary])) {
                $nutrientSecondaryGrams = round(self::$nutrientsDB[$nutrientSecondary] * $totalL, 2);
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

        // Agua de dilución por aditivo (en ml)
        $yeastDilutionWaterMl             = round($yeastGrams * 10, 1);
        $nutrientPrimaryDilutionWaterMl   = $nutrientPrimaryGrams   !== null ? round($nutrientPrimaryGrams   * 10, 1) : null;
        $nutrientSecondaryDilutionWaterMl = $nutrientSecondaryGrams !== null ? round($nutrientSecondaryGrams * 10, 1) : null;
        $sorbateDilutionWaterMlMin        = $sorbateGramsMin    !== null ? round($sorbateGramsMin    * 10, 1) : null;
        $sorbateDilutionWaterMlMax        = $sorbateGramsMax    !== null ? round($sorbateGramsMax    * 10, 1) : null;
        $benzoateDilutionWaterMlMin       = $benzoateGramsMin   !== null ? round($benzoateGramsMin   * 10, 1) : null;
        $benzoateDilutionWaterMlMax       = $benzoateGramsMax   !== null ? round($benzoateGramsMax   * 10, 1) : null;
        $metabisulfiteDilutionWaterMl     = $metabisulfiteGrams !== null ? round($metabisulfiteGrams * 10, 1) : null;
        $bentoniteDilutionWaterMlMin      = $bentoniteGramsMin  !== null ? round($bentoniteGramsMin  * 15, 1) : null;
        $bentoniteDilutionWaterMlMax      = $bentoniteGramsMax  !== null ? round($bentoniteGramsMax  * 15, 1) : null;
        $albuminDilutionWaterMlMin        = $albuminGramsMin    !== null ? round($albuminGramsMin    * 10, 1) : null;
        $albuminDilutionWaterMlMax        = $albuminGramsMax    !== null ? round($albuminGramsMax    * 10, 1) : null;

        return [
            'honey_brix'               => $honeyBrix,
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
            'albumin_grams_min'                     => $albuminGramsMin,
            'albumin_grams_max'                     => $albuminGramsMax,
            'yeast_dilution_water_ml'               => $yeastDilutionWaterMl,
            'nutrient_primary_dilution_water_ml'    => $nutrientPrimaryDilutionWaterMl,
            'nutrient_secondary_dilution_water_ml'  => $nutrientSecondaryDilutionWaterMl,
            'sorbate_dilution_water_ml_min'         => $sorbateDilutionWaterMlMin,
            'sorbate_dilution_water_ml_max'         => $sorbateDilutionWaterMlMax,
            'benzoate_dilution_water_ml_min'        => $benzoateDilutionWaterMlMin,
            'benzoate_dilution_water_ml_max'        => $benzoateDilutionWaterMlMax,
            'metabisulfite_dilution_water_ml'       => $metabisulfiteDilutionWaterMl,
            'bentonite_dilution_water_ml_min'       => $bentoniteDilutionWaterMlMin,
            'bentonite_dilution_water_ml_max'       => $bentoniteDilutionWaterMlMax,
            'albumin_dilution_water_ml_min'         => $albuminDilutionWaterMlMin,
            'albumin_dilution_water_ml_max'         => $albuminDilutionWaterMlMax,
        ];
    }
}
