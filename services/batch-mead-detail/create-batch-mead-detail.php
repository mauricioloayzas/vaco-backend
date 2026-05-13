<?php
require_once file_exists('/opt/vendor/autoload.php') ? '/opt/vendor/autoload.php' : __DIR__ . '/../../vendor/autoload.php';

use App\Common\Helpers\FermentFormula;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\BatchSubtype;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\BatchType;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\ClarifierType;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\MetabisulfiteType;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\NutrientType;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\RawMaterialCategory;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\RawMaterialUnit;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\StabilizerType;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\YeastStrain;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\YeastType;

return function (array $event) {

    $batchId   = $event['pathParameters']['batch_id'] ?? null;
    $body      = json_decode($event['body'] ?? '', true);
    $inventory = (bool)($body['inventory'] ?? false);
    $profileId = $body['profile_id'] ?? ($event['queryStringParameters']['profile_id'] ?? null);

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

    // inventory check: verify stock and collect found entities for later cost calculation
    $foundMaterials = [];
    $foundTools     = [];

    if ($inventory) {
        if (empty($profileId)) {
            return ['statusCode' => 400, 'body' => json_encode(['error' => 'profile_id is required when inventory is true'])];
        }

        try {
            $rawMaterialRepo = new App\Common\Repositories\RawMaterialRepository();
            $allMaterials    = [];
            $lastKey         = null;
            do {
                $page         = $rawMaterialRepo->getRawMaterials($profileId, 100, $lastKey);
                $allMaterials = array_merge($allMaterials, $page['items']);
                $lastKey      = $page['last_evaluated_key'];
            } while ($lastKey !== null);

            $findAndCheck = function (string $label, callable $matcher) use ($allMaterials, &$foundMaterials): ?array {
                foreach ($allMaterials as $mat) {
                    if ($matcher($mat)) {
                        if ($mat->stock_quantity <= 0) {
                            return ['statusCode' => 400, 'body' => json_encode(['error' => "Insufficient stock for: {$label}"])];
                        }
                        $foundMaterials[$label] = $mat;
                        return null;
                    }
                }
                return ['statusCode' => 404, 'body' => json_encode(['error' => "Raw material not registered in inventory: {$label}"])];
            };

            $checks = [
                'honey' => fn($m) => $m->type === BatchType::MEAD && $m->subtype === BatchSubtype::HONEY,
                'yeast' => fn($m) => $m->category === RawMaterialCategory::LEVADURA
                    && $m->yeast_type?->value === $yeastTypeStr
                    && $m->yeast_strain?->value === $yeastStrainStr,
            ];

            if ($useSorbate) {
                $checks['sorbate'] = fn($m) => $m->category === RawMaterialCategory::ESTABILIZADOR
                    && $m->stabilizer_type === StabilizerType::SORBATO_POTASIO;
            }
            if ($useMetabisulfite) {
                $checks['metabisulfite'] = fn($m) => $m->category === RawMaterialCategory::ESTABILIZADOR
                    && $m->stabilizer_type === StabilizerType::METABISULFITO;
            }
            if ($useBentonite) {
                $checks['bentonite'] = fn($m) => $m->category === RawMaterialCategory::CLARIFICANTE
                    && $m->clarifier_type === ClarifierType::BENTONITA;
            }
            if ($useAlbumin) {
                $checks['albumin'] = fn($m) => $m->category === RawMaterialCategory::CLARIFICANTE
                    && $m->clarifier_type === ClarifierType::ALBUMINA;
            }
            if ($nutrientPrimary !== null) {
                $checks['nutrient_primary'] = fn($m) => $m->category === RawMaterialCategory::NUTRIENTE
                    && $m->nutrient_type?->value === $nutrientPrimary;
            }
            if ($nutrientSecondary !== null) {
                $checks['nutrient_secondary'] = fn($m) => $m->category === RawMaterialCategory::NUTRIENTE
                    && $m->nutrient_type?->value === $nutrientSecondary;
            }

            foreach ($checks as $label => $matcher) {
                $err = $findAndCheck($label, $matcher);
                if ($err !== null) {
                    return $err;
                }
            }

            $toolRepo = new App\Common\Repositories\ToolRepository();
            foreach ($body['tool_ids'] ?? [] as $toolId) {
                $tool = $toolRepo->getToolById($toolId);
                if ($tool === null) {
                    return ['statusCode' => 404, 'body' => json_encode(['error' => "Tool not found: {$toolId}"])];
                }
                $depreciation        = $tool->depreciationPerBatch();
                $newPrice            = max($tool->residual_value, $tool->purchase_price - $depreciation);
                $foundTools[$toolId] = ['entity' => $tool, 'depreciation' => $depreciation];
                $toolRepo->updateTool($tool->profile_id, $toolId, ['purchase_price' => $newPrice]);
            }

        } catch (Exception $e) {
            return ['statusCode' => 500, 'body' => json_encode(['error' => $e->getMessage()])];
        }
    }

    $calc = FermentFormula::calculateMeadDetails(
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

        if ($inventory) {
            // Convert a grams quantity to the material's registered unit
            $gramsToUnit = fn(float $g, RawMaterialUnit $u): float => match ($u) {
                RawMaterialUnit::KG => round($g / 1000, 6),
                default             => $g,
            };

            // Honey is already in kg from the body
            $kgToUnit = fn(float $kg, RawMaterialUnit $u): float => match ($u) {
                RawMaterialUnit::G => round($kg * 1000, 2),
                default            => $kg,
            };

            $rawMaterialCosts = [];

            if (isset($foundMaterials['honey'])) {
                $mat = $foundMaterials['honey'];
                $qty = $kgToUnit($honeyKg, $mat->unit);
                $rawMaterialCosts[] = [
                    'raw_material_id' => $mat->id,
                    'name'            => $mat->name,
                    'quantity'        => $qty,
                    'unit'            => $mat->unit->value,
                    'price_per_unit'  => $mat->price_per_unit,
                    'total'           => round($qty * $mat->price_per_unit, 4),
                ];
            }

            // label => grams value from calc (null = not applicable)
            $gramEntries = [
                'yeast'              => $calc['yeast_grams'],
                'sorbate'            => $calc['sorbate_grams_max'],
                'metabisulfite'      => $calc['metabisulfite_grams'],
                'bentonite'          => $calc['bentonite_grams_max'],
                'albumin'            => $calc['albumin_grams_max'],
                'nutrient_primary'   => $calc['nutrient_primary_grams'],
                'nutrient_secondary' => $calc['nutrient_secondary_grams'],
            ];

            foreach ($gramEntries as $label => $grams) {
                if (!isset($foundMaterials[$label]) || $grams === null) {
                    continue;
                }
                $mat = $foundMaterials[$label];
                $qty = $gramsToUnit($grams, $mat->unit);
                $rawMaterialCosts[] = [
                    'raw_material_id' => $mat->id,
                    'name'            => $mat->name,
                    'quantity'        => $qty,
                    'unit'            => $mat->unit->value,
                    'price_per_unit'  => $mat->price_per_unit,
                    'total'           => round($qty * $mat->price_per_unit, 4),
                ];
            }

            $toolCosts = [];
            foreach ($foundTools as $data) {
                $tool        = $data['entity'];
                $toolCosts[] = [
                    'tool_id'             => $tool->id,
                    'name'                => $tool->name,
                    'depreciation_method' => $tool->depreciation_method->value,
                    'depreciation_amount' => $data['depreciation'],
                ];
            }

            $costRepository = new App\Common\Repositories\BatchProductionCostRepository();
            $costRepository->createBatchProductionCost(
                $batchId,
                $profileId,
                $rawMaterialCosts,
                $toolCosts,
                0.0,
                0.0,
                0.0,
                0.0,
                null,
                $calc['total_must_liters'],
                null,
                null
            );
        }

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
