<?php
require_once file_exists('/opt/vendor/autoload.php') ? '/opt/vendor/autoload.php' : __DIR__ . '/../../vendor/autoload.php';

return function (array $event) {

    $batchId    = $event['pathParameters']['batch_id'] ?? null;
    $body       = json_decode($event['body'] ?? '', true);
    $profile_id = $event['queryStringParameters']['profile_id'] ?? ($body['profile_id'] ?? null);

    if (empty($batchId)) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'Missing required parameter: batch_id'])];
    }

    if (empty($profile_id)) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'Missing required field: profile_id'])];
    }

    $batchDurationDays = isset($body['batch_duration_days']) ? (int)$body['batch_duration_days'] : null;
    $totalMustLiters   = isset($body['total_must_liters'])   ? (float)$body['total_must_liters']  : null;
    $laborCost         = (float)($body['labor_cost']       ?? 0);
    $laborHours        = isset($body['labor_hours'])        ? (float)$body['labor_hours']        : null;
    $gasCost           = (float)($body['gas_cost']         ?? 0);
    $electricityCost   = (float)($body['electricity_cost'] ?? 0);
    $otherCosts        = (float)($body['other_costs']      ?? 0);
    $notes             = $body['notes'] ?? null;

    // --- Resolver costos de materias primas ---
    $rawMaterialCosts   = [];
    $rawMaterialRepo    = new App\Common\Repositories\RawMaterialRepository();
    $rawMaterialEntries = $body['raw_material_costs'] ?? [];

    foreach ($rawMaterialEntries as $entry) {
        $quantity = (float)($entry['quantity'] ?? 0);

        if (!empty($entry['raw_material_id'])) {
            $material = $rawMaterialRepo->getRawMaterialById($entry['raw_material_id']);
            if ($material === null) {
                return [
                    'statusCode' => 404,
                    'body'       => json_encode(['error' => "Raw material not found: {$entry['raw_material_id']}"]),
                ];
            }
            $pricePerUnit = isset($entry['price_per_unit']) ? (float)$entry['price_per_unit'] : $material->price_per_unit;
            $rawMaterialCosts[] = [
                'raw_material_id' => $material->id,
                'name'            => $material->name,
                'quantity'        => $quantity,
                'unit'            => $material->unit->value,
                'price_per_unit'  => $pricePerUnit,
                'total'           => round($quantity * $pricePerUnit, 4),
            ];
        } else {
            // Entrada manual sin inventario
            if (empty($entry['name']) || !isset($entry['price_per_unit']) || empty($entry['unit'])) {
                return [
                    'statusCode' => 400,
                    'body'       => json_encode(['error' => 'Manual raw material entry requires: name, unit, price_per_unit']),
                ];
            }
            $pricePerUnit = (float)$entry['price_per_unit'];
            $rawMaterialCosts[] = [
                'raw_material_id' => null,
                'name'            => $entry['name'],
                'quantity'        => $quantity,
                'unit'            => $entry['unit'],
                'price_per_unit'  => $pricePerUnit,
                'total'           => round($quantity * $pricePerUnit, 4),
            ];
        }
    }

    // --- Resolver costos de herramientas (depreciación) ---
    $toolCosts  = [];
    $toolRepo   = new App\Common\Repositories\ToolRepository();
    $toolIds    = $body['tool_ids'] ?? [];

    foreach ($toolIds as $toolId) {
        $tool = $toolRepo->getToolById($toolId);
        if ($tool === null) {
            return [
                'statusCode' => 404,
                'body'       => json_encode(['error' => "Tool not found: {$toolId}"]),
            ];
        }
        $toolCosts[] = [
            'tool_id'             => $tool->id,
            'name'                => $tool->name,
            'depreciation_method' => $tool->depreciation_method->value,
            'depreciation_amount' => $tool->depreciationPerBatch($batchDurationDays),
        ];
    }

    try {

        $repository = new App\Common\Repositories\BatchProductionCostRepository();
        $cost       = $repository->createBatchProductionCost(
            $batchId,
            $profile_id,
            $rawMaterialCosts,
            $toolCosts,
            $laborCost,
            $gasCost,
            $electricityCost,
            $otherCosts,
            $laborHours,
            $totalMustLiters,
            $batchDurationDays,
            $notes
        );

        return [
            'statusCode' => 201,
            'headers'    => ['Content-Type' => 'application/json'],
            'body'       => json_encode(['data' => $cost->toArray()]),
        ];

    } catch (Exception $e) {

        return ['statusCode' => 500, 'body' => json_encode(['error' => $e->getMessage()])];
    }
};
