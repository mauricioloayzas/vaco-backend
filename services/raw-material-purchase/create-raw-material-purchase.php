<?php
require_once file_exists('/opt/vendor/autoload.php') ? '/opt/vendor/autoload.php' : __DIR__ . '/../../vendor/autoload.php';

return function (array $event) {

    $profile_id = $event['pathParameters']['profile_id'] ?? null;
    $body       = json_decode($event['body'] ?? '', true);

    if (empty($profile_id)) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'Missing required profile_id'])];
    }

    $required = ['raw_material_id', 'quantity', 'price_per_unit', 'unit'];
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

        $purchaseQuantity = (float)$body['quantity'];
        $purchasePrice    = (float)$body['price_per_unit'];
        $currentStock     = $rawMaterial->stock_quantity;
        $currentPrice     = $rawMaterial->price_per_unit;

        $newStock = $currentStock + $purchaseQuantity;

        // weighted average: (current_stock * current_price + purchase_qty * purchase_price) / new_stock
        $newPrice = $newStock > 0
            ? (($currentStock * $currentPrice) + ($purchaseQuantity * $purchasePrice)) / $newStock
            : $purchasePrice;

        $purchaseRepository = new App\Common\Repositories\RawMaterialPurchaseRepository();
        $purchase           = $purchaseRepository->createPurchase($profile_id, $body);

        $rawMaterialRepository->updateRawMaterial($profile_id, $rawMaterial->id, [
            'stock_quantity' => $newStock,
            'price_per_unit' => round($newPrice, 2),
        ]);

        return [
            'statusCode' => 201,
            'headers'    => ['Content-Type' => 'application/json'],
            'body'       => json_encode(['data' => $purchase->toArray()]),
        ];

    } catch (\InvalidArgumentException $e) {

        return ['statusCode' => 400, 'body' => json_encode(['error' => $e->getMessage()])];

    } catch (Exception $e) {

        return ['statusCode' => 500, 'body' => json_encode(['error' => $e->getMessage()])];
    }
};
