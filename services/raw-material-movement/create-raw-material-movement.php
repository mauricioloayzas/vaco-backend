<?php
require_once file_exists('/opt/vendor/autoload.php') ? '/opt/vendor/autoload.php' : __DIR__ . '/../../vendor/autoload.php';

use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\RawMaterialMovementType;

return function (array $event) {

    $profile_id = $event['pathParameters']['profile_id'] ?? null;
    $body       = json_decode($event['body'] ?? '', true);

    if (empty($profile_id)) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'Missing required profile_id'])];
    }

    $required = ['raw_material_id', 'movement_type', 'quantity', 'unit'];
    foreach ($required as $field) {
        if (!isset($body[$field]) || $body[$field] === '') {
            return ['statusCode' => 400, 'body' => json_encode(['error' => "Missing required field: {$field}"])];
        }
    }

    try {

        $rawMaterialRepository = new App\Common\Repositories\RawMaterialRepository();
        $rawMaterial           = $rawMaterialRepository->getRawMaterial($profile_id, $body['raw_material_id']);

        if ($rawMaterial === null) {
            return ['statusCode' => 404, 'body' => json_encode(['error' => 'Raw material not found'])];
        }

        $movementType = RawMaterialMovementType::from($body['movement_type']);
        $quantity     = (float)$body['quantity'];
        $currentStock = $rawMaterial->stock_quantity;
        $currentPrice = $rawMaterial->price_per_unit;

        if ($movementType === RawMaterialMovementType::PURCHASE) {

            if (!isset($body['price_per_unit']) || $body['price_per_unit'] === '') {
                return ['statusCode' => 400, 'body' => json_encode(['error' => 'Missing required field: price_per_unit'])];
            }

            $purchasePrice = (float)$body['price_per_unit'];
            $newStock      = $currentStock + $quantity;

            // weighted average: (current_stock * current_price + purchase_qty * purchase_price) / new_stock
            $newPrice = $newStock > 0
                ? (($currentStock * $currentPrice) + ($quantity * $purchasePrice)) / $newStock
                : $purchasePrice;

            $stockUpdate = [
                'stock_quantity' => $newStock,
                'price_per_unit' => round($newPrice, 2),
            ];

        } else {

            if ($quantity > $currentStock) {
                return ['statusCode' => 400, 'body' => json_encode(['error' => 'Insufficient stock for this exit movement'])];
            }

            $stockUpdate = [
                'stock_quantity' => $currentStock - $quantity,
            ];
        }

        $movementRepository = new App\Common\Repositories\RawMaterialMovementRepository();
        $movement           = $movementRepository->createMovement($profile_id, $body);

        $rawMaterialRepository->updateRawMaterial($profile_id, $rawMaterial->id, $stockUpdate);

        return [
            'statusCode' => 201,
            'headers'    => ['Content-Type' => 'application/json'],
            'body'       => json_encode(['data' => $movement->toArray()]),
        ];

    } catch (\InvalidArgumentException $e) {

        return ['statusCode' => 400, 'body' => json_encode(['error' => $e->getMessage()])];

    } catch (Exception $e) {

        return ['statusCode' => 500, 'body' => json_encode(['error' => $e->getMessage()])];
    }
};
