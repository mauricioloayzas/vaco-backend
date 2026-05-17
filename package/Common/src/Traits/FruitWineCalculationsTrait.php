<?php

namespace App\Common\Traits;

use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\BatchSubtype;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\SweetnessProfile;

trait FruitWineCalculationsTrait
{
    private static float $fruitWineSo2TargetMgPerL = 30.0;

    private static array $fruitWineStrains = [
        'ec1118'     => ['dosis' => 0.25, 'tolerancia' => 18, 'atenuacion' => 0.95],
        '71b'        => ['dosis' => 0.25, 'tolerancia' => 14, 'atenuacion' => 0.90],
        'k1v'        => ['dosis' => 0.25, 'tolerancia' => 18, 'atenuacion' => 0.95],
        'd47'        => ['dosis' => 0.25, 'tolerancia' => 15, 'atenuacion' => 0.88],
        'custom_vin' => ['dosis' => 0.25, 'tolerancia' => 14, 'atenuacion' => 0.90],
        'pan_seca'   => ['dosis' => 1.0,  'tolerancia' => 8,  'atenuacion' => 0.75],
        'pan_fresca' => ['dosis' => 3.0,  'tolerancia' => 7,  'atenuacion' => 0.70],
    ];

    private static array $fruitWineNutrientsDB = [
        'dap'       => 0.5,
        'fermaid_k' => 0.25,
        'fermaid_o' => 0.3,
        'mgso4'     => 0.2,
        'go_ferm'   => 0.3,
    ];

    /**
     * Fracción del peso de la fruta que se convierte en jugo (L por kg).
     * Frutas jugosas (uva, manzana, fresa) tienen alto rendimiento.
     * Frutas con pulpa densa (mango, banana) tienen menor rendimiento.
     */
    private static array $fruitJuiceYield = [
        'grape'      => 0.75,
        'apple'      => 0.65,
        'pear'       => 0.65,
        'strawberry' => 0.65,
        'pineapple'  => 0.65,
        'peach'      => 0.60,
        'blackberry' => 0.55,
        'mango'      => 0.55,
        'banana'     => 0.45,
    ];

    /**
     * Agua mínima de maceración requerida por kg de fruta (en litros).
     * Cero para frutas que producen jugo suficiente por prensado.
     * Mayor que cero para frutas con pulpa que necesitan extracción con agua.
     */
    private static array $fruitMinWaterPerKg = [
        'grape'      => 0.0,
        'apple'      => 0.0,
        'pear'       => 0.0,
        'strawberry' => 0.0,
        'pineapple'  => 0.0,
        'peach'      => 0.0,
        'blackberry' => 0.2,
        'mango'      => 0.3,
        'banana'     => 0.5,
    ];

    /**
     * Calcula los parámetros de la receta de vino de frutas.
     *
     * Lógica del mosto:
     *   - Se extrae jugo según el rendimiento propio de cada fruta (juiceYield).
     *   - Frutas con pulpa (banana, mango, blackberry) tienen un mínimo de agua
     *     de maceración (minWaterPerKg) que se aplica antes del cálculo de dilución.
     *   - Si fruit_brix > initial_brix: se agrega agua para diluir al brix objetivo.
     *   - Si fruit_brix < initial_brix: se puede corregir con sugar_kg (chaptalización).
     *   - water_liters es siempre ≥ agua mínima de maceración.
     */
    public static function calculateFruitWineDetails(
        BatchSubtype $fruitType,
        float        $fruitKg,
        float        $fruitBrix,
        float        $initialBrix,
        float        $finalBrixDesired,
        string       $yeastStrain,
        string       $yeastType,
        ?string      $nutrientPrimary,
        ?string      $nutrientSecondary,
        bool         $useSorbate,
        bool         $useBenzoate,
        bool         $useMetabisulfite,
        ?string      $metabisulfiteType,
        bool         $useBentonite,
        bool         $useAlbumin,
        ?float       $sugarKg = null
    ): array {
        $finalBrixDes = max(1.0, $finalBrixDesired);

        // Rendimiento y agua mínima según tipo de fruta
        $juiceYield    = self::$fruitJuiceYield[$fruitType->value]    ?? 0.60;
        $minWaterPerKg = self::$fruitMinWaterPerKg[$fruitType->value] ?? 0.0;
        $minWaterL     = $minWaterPerKg * $fruitKg;

        // Jugo extraído de la fruta
        $juiceG       = $fruitKg * 1000 * $juiceYield;
        $solidosJugo  = $juiceG * ($fruitBrix / 100);
        $aguaEnJugo   = $juiceG - $solidosJugo;

        // Chaptalización automática: si fruit_brix < initial_brix y no se provee sugar_kg,
        // se calcula el azúcar necesario para alcanzar initial_brix con el mínimo de agua de maceración
        if ($sugarKg === null && $fruitBrix < $initialBrix) {
            $baseG       = $juiceG + $minWaterL * 1000;
            $numerator   = $solidosJugo - $baseG * ($initialBrix / 100);
            $denominator = ($initialBrix / 100) - 1;
            $sugCalc     = $numerator / $denominator;
            if ($sugCalc > 0) {
                $sugarKg = round($sugCalc / 1000, 3);
            }
        }

        // Azúcar adicional — chaptalización (100 % sólidos, volumen despreciable)
        $extraSolidosG = ($sugarKg !== null && $sugarKg > 0) ? $sugarKg * 1000 : 0.0;
        $totalSolidosG = $solidosJugo + $extraSolidosG;

        // Volumen de mosto total para alcanzar initial_brix
        $totalMezclaG  = $totalSolidosG / ($initialBrix / 100);

        // Agua a agregar: lo que falta después de jugo y azúcar, nunca menor al mínimo de maceración
        $waterCalculadaG = $totalMezclaG - $juiceG - $extraSolidosG;
        $waterLiters     = round(max($minWaterL, $waterCalculadaG / 1000), 2);
        $totalL          = round(($juiceG + $waterLiters * 1000 + $extraSolidosG) / 1000, 2);

        // Levadura
        $cepa       = self::$fruitWineStrains[$yeastStrain];
        $dosisGperL = $cepa['dosis'];
        $yeastGrams = round($dosisGperL * $totalL, 2);

        // Brix finales estimados y ABV
        $brixNatural      = max(3.5, $initialBrix - min($initialBrix * $cepa['atenuacion'], $cepa['tolerancia'] / (0.59 * 0.51)));
        $brixFinalEffect  = max($finalBrixDes, $brixNatural);
        $abvFinal         = round(min(($initialBrix - $brixFinalEffect) * 0.59 * 0.51, $cepa['tolerancia']), 1);
        $needsStabilizers = $finalBrixDes > $brixNatural;
        $sweetnessProfile = SweetnessProfile::fromBrix($finalBrixDes)->value;

        // Nutrientes (solo levadura panadera)
        $nutrientPrimaryGrams   = null;
        $nutrientSecondaryGrams = null;
        if ($yeastType === 'panifera') {
            if ($nutrientPrimary !== null && isset(self::$fruitWineNutrientsDB[$nutrientPrimary])) {
                $nutrientPrimaryGrams = round(self::$fruitWineNutrientsDB[$nutrientPrimary] * $totalL, 2);
            }
            if ($nutrientSecondary !== null && isset(self::$fruitWineNutrientsDB[$nutrientSecondary])) {
                $nutrientSecondaryGrams = round(self::$fruitWineNutrientsDB[$nutrientSecondary] * $totalL, 2);
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
            $so2TargetMgPerL    = self::$fruitWineSo2TargetMgPerL;
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
            'sweetener_kg'             => $sugarKg,
            'fruit_brix'               => $fruitBrix,
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
