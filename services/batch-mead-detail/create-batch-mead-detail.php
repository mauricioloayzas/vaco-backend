<?php
require __DIR__ . '/../../vendor/autoload.php';

use App\Common\Mead\MeadCalculator;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\MetabisulfiteType;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\NutrientType;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\YeastStrain;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\YeastType;

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
    $initialBrix    = (float)$body['initial_brix'];
    $finalBrixDes   = (float)$body['final_brix_desired'];
    $yeastTypeStr   = $body['yeast_type'];
    $yeastStrainStr = $body['yeast_strain'];

    if (YeastType::tryFrom($yeastTypeStr) === null) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'Invalid yeast_type'])];
    }
    if (YeastStrain::tryFrom($yeastStrainStr) === null) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'Invalid yeast_strain'])];
    }
    if ($initialBrix >= 80.0) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'initial_brix must be less than honey_brix'])];
    }

    $useSorbate       = (bool)($body['use_sorbate'] ?? false);
    $useBenzoate      = (bool)($body['use_benzoate'] ?? false);
    $useMetabisulfite = (bool)($body['use_metabisulfite'] ?? false);
    $useBentonite     = (bool)($body['use_bentonite'] ?? false);
    $useAlbumin       = (bool)($body['use_albumin'] ?? false);

    $nutrientPrimary   = null;
    $nutrientSecondary = null;
    $metabisulfiteType = null;

    if ($yeastTypeStr === 'panifera') {
        if (!empty($body['nutrient_primary'])) {
            if (NutrientType::tryFrom($body['nutrient_primary']) === null) {
                return ['statusCode' => 400, 'body' => json_encode(['error' => 'Invalid nutrient_primary'])];
            }
            $nutrientPrimary = $body['nutrient_primary'];
        }
        if (!empty($body['nutrient_secondary'])) {
            if (NutrientType::tryFrom($body['nutrient_secondary']) === null) {
                return ['statusCode' => 400, 'body' => json_encode(['error' => 'Invalid nutrient_secondary'])];
            }
            $nutrientSecondary = $body['nutrient_secondary'];
        }
    }

    if ($useMetabisulfite) {
        if (empty($body['metabisulfite_type'])) {
            return ['statusCode' => 400, 'body' => json_encode(['error' => 'metabisulfite_type is required when use_metabisulfite is true'])];
        }
        if (MetabisulfiteType::tryFrom($body['metabisulfite_type']) === null) {
            return ['statusCode' => 400, 'body' => json_encode(['error' => 'Invalid metabisulfite_type'])];
        }
        $metabisulfiteType = $body['metabisulfite_type'];
    }

    $calc = MeadCalculator::calculateMeadDetails(
        $honeyKg,
        $initialBrix,
        $finalBrixDes,
        $yeastStrainStr,
        $yeastTypeStr,
        $nutrientPrimary,
        $nutrientSecondary,
        $useSorbate,
        $useBenzoate,
        $useMetabisulfite,
        $metabisulfiteType,
        $useBentonite,
        $useAlbumin
    );

    try {

        $repository = new App\Common\Repositories\BatchMeadDetailRepository();
        $detail = $repository->createBatchMeadDetail(array_merge([
            'honey_kg'           => $honeyKg,
            'initial_brix'       => $initialBrix,
            'final_brix_desired' => max(1.0, $finalBrixDes),
            'yeast_type'         => $yeastTypeStr,
            'yeast_strain'       => $yeastStrainStr,
            'nutrient_primary'   => $nutrientPrimary,
            'nutrient_secondary' => $nutrientSecondary,
            'use_sorbate'        => $useSorbate,
            'use_benzoate'       => $useBenzoate,
            'use_metabisulfite'  => $useMetabisulfite,
            'metabisulfite_type' => $metabisulfiteType,
            'use_bentonite'      => $useBentonite,
            'use_albumin'        => $useAlbumin,
        ], $calc), $batchId);

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
