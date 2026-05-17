<?php

namespace App\Common\Traits;

use App\Common\Repositories\BatchProductionCostRepository;
use App\Common\Repositories\BatchRepository;
use App\Common\Repositories\RawMaterialMovementRepository;
use App\Common\Repositories\RawMaterialRepository;
use App\Common\Repositories\ToolRepository;
use Mauloasan\BobConstruye\DynamoDB\Entities\Vaco\RawMaterialEntity;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\ClarifierType;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\RawMaterialCategory;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\RawMaterialUnit;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\StabilizerType;

trait BatchInventoryTrait
{
    /**
     * Verifies stock for all ingredients used in the recipe and applies tool depreciation.
     *
     * @param  string   $batchId
     * @param  callable $baseIngredientMatcher  fn(RawMaterialEntity): bool  — matches the base ingredient
     * @param  string   $baseIngredientLabel    key used in $foundMaterials (e.g. 'honey', 'sugarcane_juice')
     * @param  string   $yeastTypeStr
     * @param  string   $yeastStrainStr
     * @param  bool     $useSorbate
     * @param  bool     $useMetabisulfite
     * @param  bool     $useBentonite
     * @param  bool     $useAlbumin
     * @param  string|null $nutrientPrimary
     * @param  string|null $nutrientSecondary
     * @param  array    $toolIds
     * @return array    Error response (has 'statusCode') OR success ['profile_id', 'found_materials', 'found_tools']
     */
    public static function checkInventory(
        string   $batchId,
        callable $baseIngredientMatcher,
        string   $baseIngredientLabel,
        string   $yeastTypeStr,
        string   $yeastStrainStr,
        bool     $useSorbate,
        bool     $useMetabisulfite,
        bool     $useBentonite,
        bool     $useAlbumin,
        ?string  $nutrientPrimary,
        ?string  $nutrientSecondary,
        array    $toolIds,
        float    $waterLiters = 0.0,
        array    $requiredQuantities = [],
        ?string  $sweetenerType = null
    ): array {
        $batch = (new BatchRepository())->getBatchById($batchId);

        if ($batch === null) {
            return ['statusCode' => 404, 'body' => json_encode(['error' => 'Batch not found'])];
        }

        $profileId    = $batch->profile_id;
        $allMaterials = [];
        $lastKey      = null;

        do {
            $page         = (new RawMaterialRepository())->getRawMaterials($profileId, 100, $lastKey);
            $allMaterials = array_merge($allMaterials, $page['items']);
            $lastKey      = $page['last_evaluated_key'];
        } while ($lastKey !== null);

        $foundMaterials = [];

        $findAndCheck = function (string $label, callable $matcher) use ($allMaterials, &$foundMaterials, $requiredQuantities): ?array {
            foreach ($allMaterials as $mat) {
                if ($matcher($mat)) {
                    if ($mat->stock_quantity <= 0) {
                        return ['statusCode' => 400, 'body' => json_encode(['error' => "Insufficient stock for: {$label}"])];
                    }
                    if (isset($requiredQuantities[$label])) {
                        [$reqQty, $fromUnit] = $requiredQuantities[$label];
                        $needed = self::convertToUnit($reqQty, $fromUnit, $mat->unit);
                        if ($mat->stock_quantity < $needed) {
                            return ['statusCode' => 400, 'body' => json_encode([
                                'error' => "Insufficient stock for: {$label}. Required: {$needed} {$mat->unit->value}, available: {$mat->stock_quantity} {$mat->unit->value}",
                            ])];
                        }
                    }
                    $foundMaterials[$label] = $mat;
                    return null;
                }
            }
            return ['statusCode' => 404, 'body' => json_encode(['error' => "Raw material not registered in inventory: {$label}"])];
        };

        $checks = [
            $baseIngredientLabel => $baseIngredientMatcher,
            'yeast'              => fn($m) => $m->category === RawMaterialCategory::LEVADURA
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
        if ($waterLiters > 0) {
            $checks['water'] = fn($m) => $m->category === RawMaterialCategory::INGREDIENTE_BASE
                && $m->is_water === true;
        }
        if ($sweetenerType !== null) {
            $checks['sweetener'] = fn($m) => $m->category === RawMaterialCategory::EDULCORANTE
                && $m->sweetener_type?->value === $sweetenerType;
        }

        foreach ($checks as $label => $matcher) {
            $err = $findAndCheck($label, $matcher);
            if ($err !== null) {
                return $err;
            }
        }

        $foundTools = [];
        $toolRepo   = new ToolRepository();

        foreach ($toolIds as $toolId) {
            $tool = $toolRepo->getToolById($toolId);
            if ($tool === null) {
                return ['statusCode' => 404, 'body' => json_encode(['error' => "Tool not found: {$toolId}"])];
            }
            $depreciation        = $tool->depreciationPerBatch();
            $newPrice            = max($tool->residual_value, $tool->purchase_price - $depreciation);
            $foundTools[$toolId] = ['entity' => $tool, 'depreciation' => $depreciation];
            $toolRepo->updateTool($tool->profile_id, $toolId, ['purchase_price' => $newPrice]);
        }

        return [
            'profile_id'      => $profileId,
            'found_materials' => $foundMaterials,
            'found_tools'     => $foundTools,
        ];
    }

    /**
     * Adjusts stock for quantity differences when a batch detail is updated.
     * Creates EXIT movements for increases and PURCHASE movements for decreases.
     * Updates the existing production cost record for the batch.
     *
     * @param  string   $batchId
     * @param  string   $profileId
     * @param  array    $foundMaterials          From checkInventory (new ingredient state)
     * @param  string   $baseIngredientLabel
     * @param  float    $oldBaseQty              Old quantity (before update)
     * @param  float    $newBaseQty              New quantity (after update)
     * @param  callable $baseQtyConverter        fn(float $qty, RawMaterialUnit $unit): float
     * @param  array    $oldGramValues           ['yeast_grams' => x, 'sorbate_grams_max' => x, ...]
     * @param  array    $newCalc                 Output of FermentFormula::calculate* after recalc
     * @param  string   $recipeNote
     */
    public static function settleInventoryUpdate(
        string   $batchId,
        string   $profileId,
        array    $foundMaterials,
        string   $baseIngredientLabel,
        float    $oldBaseQty,
        float    $newBaseQty,
        callable $baseQtyConverter,
        array    $oldGramValues,
        array    $newCalc,
        string   $recipeNote,
        array    $foundTools = []
    ): void {
        $gramsToUnit = fn(float $g, RawMaterialUnit $u): float => match ($u) {
            RawMaterialUnit::KG => round($g / 1000, 6),
            default             => $g,
        };

        $gramEntries = [
            'yeast'              => [$oldGramValues['yeast_grams'],              $newCalc['yeast_grams']],
            'sorbate'            => [$oldGramValues['sorbate_grams_max'],        $newCalc['sorbate_grams_max']],
            'metabisulfite'      => [$oldGramValues['metabisulfite_grams'],      $newCalc['metabisulfite_grams']],
            'bentonite'          => [$oldGramValues['bentonite_grams_max'],      $newCalc['bentonite_grams_max']],
            'albumin'            => [$oldGramValues['albumin_grams_max'],        $newCalc['albumin_grams_max']],
            'nutrient_primary'   => [$oldGramValues['nutrient_primary_grams'],  $newCalc['nutrient_primary_grams']],
            'nutrient_secondary' => [$oldGramValues['nutrient_secondary_grams'], $newCalc['nutrient_secondary_grams']],
        ];

        $movementRepo    = new RawMaterialMovementRepository();
        $rawMaterialRepo = new RawMaterialRepository();

        $applyDiff = function (RawMaterialEntity $mat, float $diff) use (
            $movementRepo, $rawMaterialRepo, $profileId, $batchId, $recipeNote
        ): void {
            if (abs($diff) < 0.000001) {
                return;
            }

            $movementData = [
                'raw_material_id' => $mat->id,
                'movement_type'   => $diff > 0 ? 'exit' : 'purchase',
                'quantity'        => abs($diff),
                'unit'            => $mat->unit->value,
                'notes'           => "Batch {$batchId} - {$recipeNote} adjustment",
            ];

            if ($diff < 0) {
                $movementData['price_per_unit'] = $mat->price_per_unit;
            }

            $movementRepo->createMovement($profileId, $movementData);
            $rawMaterialRepo->updateRawMaterial($profileId, $mat->id, [
                'stock_quantity' => $diff > 0
                    ? max(0.0, $mat->stock_quantity - abs($diff))
                    : $mat->stock_quantity + abs($diff),
            ]);
        };

        // Base ingredient diff
        if (isset($foundMaterials[$baseIngredientLabel])) {
            $mat  = $foundMaterials[$baseIngredientLabel];
            $diff = $baseQtyConverter($newBaseQty, $mat->unit) - $baseQtyConverter($oldBaseQty, $mat->unit);
            $applyDiff($mat, $diff);
        }

        // Gram ingredients diff
        foreach ($gramEntries as $label => [$oldGrams, $newGrams]) {
            if (!isset($foundMaterials[$label])) {
                continue;
            }
            $mat  = $foundMaterials[$label];
            $diff = $gramsToUnit($newGrams ?? 0.0, $mat->unit) - $gramsToUnit($oldGrams ?? 0.0, $mat->unit);
            $applyDiff($mat, $diff);
        }

        // Water diff (already in liters)
        if (isset($foundMaterials['water'])) {
            $oldWater = (float)($oldGramValues['water_liters'] ?? 0.0);
            $newWater = (float)($newCalc['water_liters'] ?? 0.0);
            $applyDiff($foundMaterials['water'], $newWater - $oldWater);
        }

        // Sweetener diff (in KG, converted to material unit)
        if (isset($foundMaterials['sweetener'])) {
            $mat      = $foundMaterials['sweetener'];
            $oldSugar = self::convertToUnit((float)($oldGramValues['sweetener_kg'] ?? 0.0), RawMaterialUnit::KG, $mat->unit);
            $newSugar = self::convertToUnit((float)($newCalc['sweetener_kg'] ?? 0.0), RawMaterialUnit::KG, $mat->unit);
            $applyDiff($mat, $newSugar - $oldSugar);
        }

        // Update existing production cost with new quantities
        $costRepo  = new BatchProductionCostRepository();
        $costItems = $costRepo->getBatchProductionCostsByBatchId($batchId);

        if (!empty($costItems)) {
            $rawMaterialCosts = [];

            if (isset($foundMaterials[$baseIngredientLabel])) {
                $mat = $foundMaterials[$baseIngredientLabel];
                $qty = $baseQtyConverter($newBaseQty, $mat->unit);
                $rawMaterialCosts[] = [
                    'raw_material_id' => $mat->id,
                    'name'            => $mat->name,
                    'quantity'        => $qty,
                    'unit'            => $mat->unit->value,
                    'price_per_unit'  => $mat->price_per_unit,
                    'total'           => round($qty * $mat->price_per_unit, 4),
                ];
            }

            foreach ($gramEntries as $label => [$oldGrams, $newGrams]) {
                if (!isset($foundMaterials[$label]) || $newGrams === null) {
                    continue;
                }
                $mat = $foundMaterials[$label];
                $qty = $gramsToUnit($newGrams, $mat->unit);
                $rawMaterialCosts[] = [
                    'raw_material_id' => $mat->id,
                    'name'            => $mat->name,
                    'quantity'        => $qty,
                    'unit'            => $mat->unit->value,
                    'price_per_unit'  => $mat->price_per_unit,
                    'total'           => round($qty * $mat->price_per_unit, 4),
                ];
            }

            if (isset($foundMaterials['water']) && ($newCalc['water_liters'] ?? 0) > 0) {
                $mat = $foundMaterials['water'];
                $qty = (float)$newCalc['water_liters'];
                $rawMaterialCosts[] = [
                    'raw_material_id' => $mat->id,
                    'name'            => $mat->name,
                    'quantity'        => $qty,
                    'unit'            => $mat->unit->value,
                    'price_per_unit'  => $mat->price_per_unit,
                    'total'           => round($qty * $mat->price_per_unit, 4),
                ];
            }

            if (isset($foundMaterials['sweetener']) && ($newCalc['sweetener_kg'] ?? 0) > 0) {
                $mat = $foundMaterials['sweetener'];
                $qty = self::convertToUnit((float)$newCalc['sweetener_kg'], RawMaterialUnit::KG, $mat->unit);
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

            $updateData = [
                'raw_material_costs' => $rawMaterialCosts,
                'total_must_liters'  => $newCalc['total_must_liters'],
            ];
            if (!empty($toolCosts)) {
                $updateData['tool_costs'] = $toolCosts;
            }

            $costRepo->updateBatchProductionCost($costItems[0]->id, $updateData);
        }
    }

    /**
     * Creates the batch production cost record and registers EXIT movements for each ingredient.
     *
     * @param  string   $batchId
     * @param  string   $profileId
     * @param  array    $foundMaterials          Result from checkInventory
     * @param  array    $foundTools              Result from checkInventory
     * @param  string   $baseIngredientLabel     Same label used in checkInventory
     * @param  float    $baseIngredientQty       Raw quantity (kg, liters, etc.)
     * @param  callable $baseQtyConverter        fn(float $qty, RawMaterialUnit $unit): float
     * @param  array    $calc                    Output of FermentFormula::calculate*
     * @param  string   $recipeNote              Appended to movement notes (e.g. 'mead recipe')
     */
    public static function settleInventory(
        string   $batchId,
        string   $profileId,
        array    $foundMaterials,
        array    $foundTools,
        string   $baseIngredientLabel,
        float    $baseIngredientQty,
        callable $baseQtyConverter,
        array    $calc,
        string   $recipeNote
    ): void {
        $gramsToUnit = fn(float $g, RawMaterialUnit $u): float => match ($u) {
            RawMaterialUnit::KG => round($g / 1000, 6),
            default             => $g,
        };

        $gramEntries = [
            'yeast'              => $calc['yeast_grams'],
            'sorbate'            => $calc['sorbate_grams_max'],
            'metabisulfite'      => $calc['metabisulfite_grams'],
            'bentonite'          => $calc['bentonite_grams_max'],
            'albumin'            => $calc['albumin_grams_max'],
            'nutrient_primary'   => $calc['nutrient_primary_grams'],
            'nutrient_secondary' => $calc['nutrient_secondary_grams'],
        ];

        // --- Build production cost arrays ---

        $rawMaterialCosts = [];

        if (isset($foundMaterials[$baseIngredientLabel])) {
            $mat = $foundMaterials[$baseIngredientLabel];
            $qty = $baseQtyConverter($baseIngredientQty, $mat->unit);
            $rawMaterialCosts[] = [
                'raw_material_id' => $mat->id,
                'name'            => $mat->name,
                'quantity'        => $qty,
                'unit'            => $mat->unit->value,
                'price_per_unit'  => $mat->price_per_unit,
                'total'           => round($qty * $mat->price_per_unit, 4),
            ];
        }

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

        if (isset($foundMaterials['water']) && ($calc['water_liters'] ?? 0) > 0) {
            $mat = $foundMaterials['water'];
            $qty = (float)$calc['water_liters'];
            $rawMaterialCosts[] = [
                'raw_material_id' => $mat->id,
                'name'            => $mat->name,
                'quantity'        => $qty,
                'unit'            => $mat->unit->value,
                'price_per_unit'  => $mat->price_per_unit,
                'total'           => round($qty * $mat->price_per_unit, 4),
            ];
        }

        if (isset($foundMaterials['sweetener']) && ($calc['sweetener_kg'] ?? 0) > 0) {
            $mat = $foundMaterials['sweetener'];
            $qty = self::convertToUnit((float)$calc['sweetener_kg'], RawMaterialUnit::KG, $mat->unit);
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

        (new BatchProductionCostRepository())->createBatchProductionCost(
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

        // --- Register EXIT movements and update stock ---

        $movementRepo    = new RawMaterialMovementRepository();
        $rawMaterialRepo = new RawMaterialRepository();

        $registerExit = function (RawMaterialEntity $mat, float $qty) use (
            $movementRepo,
            $rawMaterialRepo,
            $profileId,
            $batchId,
            $recipeNote
        ): void {
            $movementRepo->createMovement($profileId, [
                'raw_material_id' => $mat->id,
                'movement_type'   => 'exit',
                'quantity'        => $qty,
                'unit'            => $mat->unit->value,
                'notes'           => "Batch {$batchId} - {$recipeNote}",
            ]);
            $rawMaterialRepo->updateRawMaterial($profileId, $mat->id, [
                'stock_quantity' => max(0.0, $mat->stock_quantity - $qty),
            ]);
        };

        if (isset($foundMaterials[$baseIngredientLabel])) {
            $mat = $foundMaterials[$baseIngredientLabel];
            $registerExit($mat, $baseQtyConverter($baseIngredientQty, $mat->unit));
        }

        foreach ($gramEntries as $label => $grams) {
            if (!isset($foundMaterials[$label]) || $grams === null) {
                continue;
            }
            $mat = $foundMaterials[$label];
            $registerExit($mat, $gramsToUnit($grams, $mat->unit));
        }

        if (isset($foundMaterials['water']) && ($calc['water_liters'] ?? 0) > 0) {
            $registerExit($foundMaterials['water'], (float)$calc['water_liters']);
        }

        if (isset($foundMaterials['sweetener']) && ($calc['sweetener_kg'] ?? 0) > 0) {
            $mat = $foundMaterials['sweetener'];
            $registerExit($mat, self::convertToUnit((float)$calc['sweetener_kg'], RawMaterialUnit::KG, $mat->unit));
        }
    }

    /**
     * Builds the required-quantities map from a recipe calc array.
     * Each entry: [float $qty, RawMaterialUnit $fromUnit]
     */
    public static function buildRequiredQuantities(
        string          $baseLabel,
        float           $baseQty,
        RawMaterialUnit $baseUnit,
        array           $calc
    ): array {
        $qtys = [
            $baseLabel => [$baseQty, $baseUnit],
            'yeast'    => [$calc['yeast_grams'], RawMaterialUnit::G],
        ];

        $gramFields = [
            'sorbate'            => 'sorbate_grams_max',
            'metabisulfite'      => 'metabisulfite_grams',
            'bentonite'          => 'bentonite_grams_max',
            'albumin'            => 'albumin_grams_max',
            'nutrient_primary'   => 'nutrient_primary_grams',
            'nutrient_secondary' => 'nutrient_secondary_grams',
        ];

        foreach ($gramFields as $label => $calcKey) {
            if (!empty($calc[$calcKey])) {
                $qtys[$label] = [$calc[$calcKey], RawMaterialUnit::G];
            }
        }

        if (!empty($calc['water_liters'])) {
            $qtys['water'] = [$calc['water_liters'], RawMaterialUnit::L];
        }
        if (!empty($calc['sweetener_kg'])) {
            $qtys['sweetener'] = [$calc['sweetener_kg'], RawMaterialUnit::KG];
        }

        return $qtys;
    }

    private static function convertToUnit(float $qty, RawMaterialUnit $from, RawMaterialUnit $to): float
    {
        if ($from === $to) {
            return $qty;
        }
        if ($from === RawMaterialUnit::G  && $to === RawMaterialUnit::KG) return round($qty / 1000, 6);
        if ($from === RawMaterialUnit::KG && $to === RawMaterialUnit::G)  return round($qty * 1000, 2);
        if ($from === RawMaterialUnit::L  && $to === RawMaterialUnit::ML) return round($qty * 1000, 2);
        if ($from === RawMaterialUnit::ML && $to === RawMaterialUnit::L)  return round($qty / 1000, 6);
        return $qty;
    }
}
