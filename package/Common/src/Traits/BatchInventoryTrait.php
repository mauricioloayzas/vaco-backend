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
        array    $toolIds
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
    }
}
