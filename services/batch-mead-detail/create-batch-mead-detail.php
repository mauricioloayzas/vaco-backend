<?php
require __DIR__ . '/../../vendor/autoload.php';

use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\MetabisulfiteType;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\NutrientType;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\SweetnessProfile;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\YeastStrain;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\YeastType;

const DEFAULT_HONEY_BRIX       = 80.0;
const DEFAULT_SO2_TARGET_MG_PER_L = 30.0;

return function (array $event) {

    $batchId = $event['pathParameters']['batch_id'] ?? null;
    $body    = json_decode($event['body'] ?? '', true);

    if (empty($batchId)) {
        return [
            'statusCode' => 400,
            'body' => json_encode(['error' => 'Missing required parameter: batch_id'])
        ];
    }

    $required = ['honey_kg', 'initial_brix', 'final_brix_desired', 'yeast_type', 'yeast_strain'];
    foreach ($required as $field) {
        if (!isset($body[$field]) || $body[$field] === '') {
            return [
                'statusCode' => 400,
                'body' => json_encode(['error' => "Missing required field: {$field}"])
            ];
        }
    }

    $honeyKg        = (float)$body['honey_kg'];
    $honeyBrix      = DEFAULT_HONEY_BRIX;
    $initialBrix    = (float)$body['initial_brix'];
    $finalBrixDes   = max(1.0, (float)$body['final_brix_desired']);
    $yeastTypeStr   = $body['yeast_type'];
    $yeastStrainStr = $body['yeast_strain'];

    if (YeastType::tryFrom($yeastTypeStr) === null) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'Invalid yeast_type'])];
    }
    if (YeastStrain::tryFrom($yeastStrainStr) === null) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'Invalid yeast_strain'])];
    }
    if ($initialBrix >= $honeyBrix) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'initial_brix must be less than honey_brix'])];
    }

    // --- Mosto base ---
    $mielG        = $honeyKg * 1000;
    $solidosMiel  = ($honeyBrix / 100) * $mielG;
    $aguaEnMiel   = $mielG - $solidosMiel;
    $totalMezclaG = $solidosMiel / ($initialBrix / 100);
    $waterLiters  = round(($totalMezclaG - $solidosMiel - $aguaEnMiel) / 1000, 2);
    $totalL       = round($totalMezclaG / 1000, 2);

    // --- Levadura ---
    $strainsData = [
        'ec1118'     => ['dosis' => 0.25, 'tolerancia' => 18, 'atenuacion' => 0.95],
        '71b'        => ['dosis' => 0.25, 'tolerancia' => 14, 'atenuacion' => 0.90],
        'k1v'        => ['dosis' => 0.25, 'tolerancia' => 18, 'atenuacion' => 0.95],
        'd47'        => ['dosis' => 0.25, 'tolerancia' => 15, 'atenuacion' => 0.88],
        'custom_vin' => ['dosis' => 0.25, 'tolerancia' => 14, 'atenuacion' => 0.90],
        'pan_seca'   => ['dosis' => 1.0,  'tolerancia' => 8,  'atenuacion' => 0.75],
        'pan_fresca' => ['dosis' => 3.0,  'tolerancia' => 7,  'atenuacion' => 0.70],
    ];

    $cepa        = $strainsData[$yeastStrainStr];
    $dosisGperL  = $cepa['dosis'];
    $yeastGrams  = round($dosisGperL * $totalL, 2);

    // --- Brix finales y ABV ---
    $brixNatural      = max(3.5, $initialBrix - min($initialBrix * $cepa['atenuacion'], $cepa['tolerancia'] / (0.59 * 0.51)));
    $brixFinalEffect  = max($finalBrixDes, $brixNatural);
    $abvFinal         = round(min(($initialBrix - $brixFinalEffect) * 0.59 * 0.51, $cepa['tolerancia']), 1);
    $needsStabilizers = $finalBrixDes > $brixNatural;

    // --- Perfil de dulzor ---
    $sweetnessProfile = SweetnessProfile::fromBrix($finalBrixDes)->value;

    // --- Nutrientes (solo panadera) ---
    $nutrientsDB = [
        'dap'       => 0.5,
        'fermaid_k' => 0.25,
        'fermaid_o' => 0.3,
        'mgso4'     => 0.2,
        'go_ferm'   => 0.3,
    ];

    $nutrientPrimary        = null;
    $nutrientPrimaryGrams   = null;
    $nutrientSecondary      = null;
    $nutrientSecondaryGrams = null;

    if ($yeastTypeStr === 'panifera') {
        if (!empty($body['nutrient_primary'])) {
            if (NutrientType::tryFrom($body['nutrient_primary']) === null) {
                return ['statusCode' => 400, 'body' => json_encode(['error' => 'Invalid nutrient_primary'])];
            }
            $nutrientPrimary      = $body['nutrient_primary'];
            $nutrientPrimaryGrams = round($nutrientsDB[$nutrientPrimary] * $totalL, 2);
        }
        if (!empty($body['nutrient_secondary'])) {
            if (NutrientType::tryFrom($body['nutrient_secondary']) === null) {
                return ['statusCode' => 400, 'body' => json_encode(['error' => 'Invalid nutrient_secondary'])];
            }
            $nutrientSecondary      = $body['nutrient_secondary'];
            $nutrientSecondaryGrams = round($nutrientsDB[$nutrientSecondary] * $totalL, 2);
        }
    }

    // --- Estabilizantes ---
    $useSorbate       = (bool)($body['use_sorbate'] ?? false);
    $useBenzoate      = (bool)($body['use_benzoate'] ?? false);
    $useMetabisulfite = (bool)($body['use_metabisulfite'] ?? false);

    $metabisulfiteType  = null;
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
    if ($useMetabisulfite) {
        if (empty($body['metabisulfite_type'])) {
            return ['statusCode' => 400, 'body' => json_encode(['error' => 'metabisulfite_type is required when use_metabisulfite is true'])];
        }
        if (MetabisulfiteType::tryFrom($body['metabisulfite_type']) === null) {
            return ['statusCode' => 400, 'body' => json_encode(['error' => 'Invalid metabisulfite_type'])];
        }
        $metabisulfiteType = $body['metabisulfite_type'];
        $so2TargetMgPerL   = DEFAULT_SO2_TARGET_MG_PER_L;
        $so2Pct            = $metabisulfiteType === 'potasio' ? 0.576 : 0.674;
        $metabisulfiteGrams = round(($so2TargetMgPerL * $totalL) / ($so2Pct * 1000), 2);
    }

    // --- Clarificantes ---
    $useBentonite     = (bool)($body['use_bentonite'] ?? false);
    $useAlbumin       = (bool)($body['use_albumin'] ?? false);
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

    try {

        $repository = new App\Common\Repositories\BatchMeadDetailRepository();
        $detail = $repository->createBatchMeadDetail([
            'honey_kg'                 => $honeyKg,
            'honey_brix'               => $honeyBrix,
            'initial_brix'             => $initialBrix,
            'water_liters'             => $waterLiters,
            'total_must_liters'        => $totalL,
            'final_brix_desired'       => $finalBrixDes,
            'yeast_type'               => $yeastTypeStr,
            'yeast_strain'             => $yeastStrainStr,
            'yeast_dose_g_per_l'       => $dosisGperL,
            'yeast_grams'              => $yeastGrams,
            'nutrient_primary'         => $nutrientPrimary,
            'nutrient_primary_grams'   => $nutrientPrimaryGrams,
            'nutrient_secondary'       => $nutrientSecondary,
            'nutrient_secondary_grams' => $nutrientSecondaryGrams,
            'use_sorbate'              => $useSorbate,
            'use_benzoate'             => $useBenzoate,
            'use_metabisulfite'        => $useMetabisulfite,
            'metabisulfite_type'       => $metabisulfiteType,
            'so2_target_mg_per_l'      => $so2TargetMgPerL,
            'use_bentonite'            => $useBentonite,
            'use_albumin'              => $useAlbumin,
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
        ], $batchId);

        return [
            'statusCode' => 201,
            'headers'    => ['Content-Type' => 'application/json'],
            'body'       => json_encode(['data' => $detail->toArray()])
        ];

    } catch (\InvalidArgumentException $e) {

        return [
            'statusCode' => 400,
            'body' => json_encode(['error' => $e->getMessage()])
        ];

    } catch (Exception $e) {

        return [
            'statusCode' => 500,
            'body' => json_encode(['error' => $e->getMessage()])
        ];
    }
};
