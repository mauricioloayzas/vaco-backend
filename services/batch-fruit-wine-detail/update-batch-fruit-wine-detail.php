<?php
require_once file_exists('/opt/vendor/autoload.php') ? '/opt/vendor/autoload.php' : __DIR__ . '/../../vendor/autoload.php';

use App\Common\Helpers\BatchInventoryHelper;
use App\Common\Helpers\FermentFormula;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\BatchStatus;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\MetabisulfiteType;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\NutrientType;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\RawMaterialUnit;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\SweetenerType;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\YeastStrain;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\YeastType;

const FRUIT_WINE_STATUS_TRIGGER_FIELDS = [
    'sorbate_grams_used', 'benzoate_grams_used', 'metabisulfite_grams_used',
    'bentonite_grams_used', 'albumin_grams_used',
];

const FRUIT_WINE_RECALC_TRIGGER_FIELDS = [
    'fruit_kg', 'fruit_brix', 'initial_brix', 'final_brix_desired', 'sweetener_kg',
    'yeast_type', 'yeast_strain',
    'nutrient_primary', 'nutrient_secondary',
    'use_sorbate', 'use_benzoate', 'use_metabisulfite', 'metabisulfite_type',
    'use_bentonite', 'use_albumin',
];

return function (array $event) {

    $id        = $event['pathParameters']['id'] ?? null;
    $body      = json_decode($event['body'] ?? '', true);
    $inventory = (bool)($body['inventory'] ?? false);

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

        $repository = new App\Common\Repositories\BatchFruitWineDetailRepository();

        $existing = $repository->getBatchFruitWineDetail($id);
        if ($existing === null) {
            return [
                'statusCode' => 404,
                'body' => json_encode(['error' => 'Batch fruit wine detail not found'])
            ];
        }

        $doSettle            = false;
        $profileId           = null;
        $foundMaterials      = [];
        $foundTools          = [];
        $oldBaseQty          = 0.0;
        $oldGramValues       = [];
        $newBaseQtyForSettle = 0.0;
        $newCalcForSettle    = [];

        $sweetenerType = $body['sweetener_type'] ?? $existing->sweetener_type?->value;
        if ($sweetenerType !== null && SweetenerType::tryFrom($sweetenerType) === null) {
            return ['statusCode' => 400, 'body' => json_encode(['error' => 'Invalid sweetener_type'])];
        }

        if (!empty(array_intersect(array_keys($body), FRUIT_WINE_RECALC_TRIGGER_FIELDS))) {
            $fruitKg           = (float)($body['fruit_kg'] ?? $existing->fruit_kg);
            $fruitBrix         = (float)($body['fruit_brix'] ?? $existing->fruit_brix);
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
            $sugarKg           = array_key_exists('sweetener_kg', $body) ? (isset($body['sweetener_kg']) ? (float)$body['sweetener_kg'] : null) : $existing->sweetener_kg;

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

            $calc = FermentFormula::calculateFruitWineDetails(
                $existing->fruit_type,
                $fruitKg,
                $fruitBrix,
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
                $useAlbumin,
                $sugarKg
            );

            $body = array_merge($body, $calc);

            if ($inventory) {
                if (($calc['sweetener_kg'] ?? 0) > 0 && $sweetenerType === null) {
                    return ['statusCode' => 400, 'body' => json_encode(['error' => 'sweetener_type is required when sugar is added to the recipe'])];
                }

                $oldBaseQty    = $existing->fruit_kg;
                $oldGramValues = [
                    'yeast_grams'              => $existing->yeast_grams,
                    'sorbate_grams_max'        => $existing->sorbate_grams_max,
                    'metabisulfite_grams'      => $existing->metabisulfite_grams,
                    'bentonite_grams_max'      => $existing->bentonite_grams_max,
                    'albumin_grams_max'        => $existing->albumin_grams_max,
                    'nutrient_primary_grams'   => $existing->nutrient_primary_grams,
                    'nutrient_secondary_grams' => $existing->nutrient_secondary_grams,
                    'water_liters'             => $existing->water_liters,
                    'sweetener_kg'             => $existing->sweetener_kg,
                ];

                $result = BatchInventoryHelper::checkInventory(
                    $existing->batch_id,
                    fn($m) => $m->subtype === $existing->fruit_type,
                    $existing->fruit_type->value,
                    $yeastType,
                    $yeastStrain,
                    $useSorbate,
                    $useMetabisulfite,
                    $useBentonite,
                    $useAlbumin,
                    $nutrientPrimary,
                    $nutrientSecondary,
                    $body['tool_ids'] ?? [],
                    $calc['water_liters'],
                    [],
                    $sweetenerType,
                    false
                );

                if (isset($result['statusCode'])) {
                    return $result;
                }

                $profileId           = $result['profile_id'];
                $foundMaterials      = $result['found_materials'];
                $foundTools          = $result['found_tools'];
                $newBaseQtyForSettle = $fruitKg;
                $newCalcForSettle    = $calc;
                $doSettle            = true;
            }
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

        if (!empty(array_intersect(array_keys($body), FRUIT_WINE_STATUS_TRIGGER_FIELDS))) {
            $batchRepository = new App\Common\Repositories\BatchRepository();
            $batch = $batchRepository->getBatchById($existing->batch_id);
            if ($batch !== null) {
                $batchRepository->updateBatchStatus($batch->profile_id, $existing->batch_id, BatchStatus::IN_PROCESS->value);

                $fermentationLogRepository = new App\Common\Repositories\FermentationLogRepository();
                $existingLogs = $fermentationLogRepository->getFermentationLogsByBatchId($existing->batch_id);
                if ($existingLogs === null) {
                    $fermentationLogRepository->createFermentationLog(
                        ['brix' => $existing->initial_brix],
                        $existing->batch_id
                    );
                }
            }
        }

        $detail = $repository->updateBatchFruitWineDetail($id, $body);

        if ($doSettle) {
            BatchInventoryHelper::settleInventoryUpdate(
                $existing->batch_id,
                $profileId,
                $foundMaterials,
                $existing->fruit_type->value,
                $oldBaseQty,
                $newBaseQtyForSettle,
                fn(float $qty, RawMaterialUnit $u) => match ($u) {
                    RawMaterialUnit::G => round($qty * 1000, 2),
                    default            => $qty,
                },
                $oldGramValues,
                $newCalcForSettle,
                $existing->fruit_type->value . ' wine recipe',
                $foundTools
            );
        }

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
