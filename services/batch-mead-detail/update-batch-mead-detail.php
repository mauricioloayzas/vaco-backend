<?php
require __DIR__ . '/../../vendor/autoload.php';

use App\Common\Mead\MeadCalculator;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\MetabisulfiteType;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\NutrientType;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\YeastStrain;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\YeastType;

const RECALC_TRIGGER_FIELDS = [
    'honey_kg', 'initial_brix', 'final_brix_desired',
    'yeast_type', 'yeast_strain',
    'nutrient_primary', 'nutrient_secondary',
    'use_sorbate', 'use_benzoate', 'use_metabisulfite', 'metabisulfite_type',
    'use_bentonite', 'use_albumin',
];

return function (array $event) {

    $id   = $event['pathParameters']['id'] ?? null;
    $body = json_decode($event['body'] ?? '', true);

    if (empty($id)) {
        return [
            'statusCode' => 400,
            'body' => json_encode(['error' => 'Missing required parameter: id'])
        ];
    }

    if (empty($body)) {
        return [
            'statusCode' => 400,
            'body' => json_encode(['error' => 'Request body is empty'])
        ];
    }

    try {

        $repository = new App\Common\Repositories\BatchMeadDetailRepository();

        $existing = $repository->getBatchMeadDetail($id);
        if ($existing === null) {
            return [
                'statusCode' => 404,
                'body' => json_encode(['error' => 'Batch mead detail not found'])
            ];
        }

        if (!empty(array_intersect(array_keys($body), RECALC_TRIGGER_FIELDS))) {
            $honeyKg           = (float)($body['honey_kg'] ?? $existing->honey_kg);
            $initialBrix       = (float)($body['initial_brix'] ?? $existing->initial_brix);
            $finalBrixDesired  = (float)($body['final_brix_desired'] ?? $existing->final_brix_desired);
            $yeastType         = $body['yeast_type'] ?? $existing->yeast_type->value;
            $yeastStrain       = $body['yeast_strain'] ?? $existing->yeast_strain->value;
            $nutrientPrimary   = array_key_exists('nutrient_primary', $body)   ? $body['nutrient_primary']   : $existing->nutrient_primary?->value;
            $nutrientSecondary = array_key_exists('nutrient_secondary', $body) ? $body['nutrient_secondary'] : $existing->nutrient_secondary?->value;
            $useSorbate        = (bool)($body['use_sorbate'] ?? $existing->use_sorbate);
            $useBenzoate       = (bool)($body['use_benzoate'] ?? $existing->use_benzoate);
            $useMetabisulfite  = (bool)($body['use_metabisulfite'] ?? $existing->use_metabisulfite);
            $metabisulfiteType = array_key_exists('metabisulfite_type', $body) ? $body['metabisulfite_type'] : $existing->metabisulfite_type?->value;
            $useBentonite      = (bool)($body['use_bentonite'] ?? $existing->use_bentonite);
            $useAlbumin        = (bool)($body['use_albumin'] ?? $existing->use_albumin);

            if (isset($body['yeast_type']) && YeastType::tryFrom($yeastType) === null) {
                return ['statusCode' => 400, 'body' => json_encode(['error' => 'Invalid yeast_type'])];
            }
            if (isset($body['yeast_strain']) && YeastStrain::tryFrom($yeastStrain) === null) {
                return ['statusCode' => 400, 'body' => json_encode(['error' => 'Invalid yeast_strain'])];
            }
            if (isset($body['nutrient_primary']) && $nutrientPrimary !== null && NutrientType::tryFrom($nutrientPrimary) === null) {
                return ['statusCode' => 400, 'body' => json_encode(['error' => 'Invalid nutrient_primary'])];
            }
            if (isset($body['nutrient_secondary']) && $nutrientSecondary !== null && NutrientType::tryFrom($nutrientSecondary) === null) {
                return ['statusCode' => 400, 'body' => json_encode(['error' => 'Invalid nutrient_secondary'])];
            }
            if ($useMetabisulfite && $metabisulfiteType !== null && MetabisulfiteType::tryFrom($metabisulfiteType) === null) {
                return ['statusCode' => 400, 'body' => json_encode(['error' => 'Invalid metabisulfite_type'])];
            }

            $calc = MeadCalculator::calculateMeadDetails(
                $honeyKg,
                $initialBrix,
                $finalBrixDesired,
                $yeastStrain,
                $yeastType,
                $nutrientPrimary,
                $nutrientSecondary,
                $useSorbate,
                $useBenzoate,
                $useMetabisulfite,
                $metabisulfiteType,
                $useBentonite,
                $useAlbumin
            );

            $body = array_merge($body, $calc);
        }

        $usedLimits = [
            'sorbate_grams_used' => [
                'enabled' => $body['use_sorbate'] ?? $existing->use_sorbate,
                'min'     => $body['sorbate_grams_min'] ?? $existing->sorbate_grams_min,
                'max'     => $body['sorbate_grams_max'] ?? $existing->sorbate_grams_max,
            ],
            'benzoate_grams_used' => [
                'enabled' => $body['use_benzoate'] ?? $existing->use_benzoate,
                'min'     => $body['benzoate_grams_min'] ?? $existing->benzoate_grams_min,
                'max'     => $body['benzoate_grams_max'] ?? $existing->benzoate_grams_max,
            ],
            'metabisulfite_grams_used' => [
                'enabled' => $body['use_metabisulfite'] ?? $existing->use_metabisulfite,
                'min'     => 0.0,
                'max'     => $body['metabisulfite_grams'] ?? $existing->metabisulfite_grams,
            ],
            'bentonite_grams_used' => [
                'enabled' => $body['use_bentonite'] ?? $existing->use_bentonite,
                'min'     => $body['bentonite_grams_min'] ?? $existing->bentonite_grams_min,
                'max'     => $body['bentonite_grams_max'] ?? $existing->bentonite_grams_max,
            ],
            'albumin_grams_used' => [
                'enabled' => $body['use_albumin'] ?? $existing->use_albumin,
                'min'     => $body['albumin_grams_min'] ?? $existing->albumin_grams_min,
                'max'     => $body['albumin_grams_max'] ?? $existing->albumin_grams_max,
            ],
        ];

        foreach ($usedLimits as $field => $limits) {
            if (!array_key_exists($field, $body)) {
                continue;
            }

            if (!$limits['enabled']) {
                return [
                    'statusCode' => 400,
                    'body' => json_encode(['error' => "{$field} cannot be set: compound was not used in this batch"])
                ];
            }

            $used = (float)$body[$field];

            if ($limits['min'] !== null && $used < $limits['min']) {
                return [
                    'statusCode' => 400,
                    'body' => json_encode(['error' => "{$field} ({$used}g) is below the minimum allowed ({$limits['min']}g)"])
                ];
            }

            if ($limits['max'] !== null && $used > $limits['max']) {
                return [
                    'statusCode' => 400,
                    'body' => json_encode(['error' => "{$field} ({$used}g) exceeds the maximum allowed ({$limits['max']}g)"])
                ];
            }
        }

        $detail = $repository->updateBatchMeadDetail($id, $body);

        return [
            'statusCode' => 200,
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
