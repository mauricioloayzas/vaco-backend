<?php
require_once file_exists('/opt/vendor/autoload.php') ? '/opt/vendor/autoload.php' : __DIR__ . '/../../vendor/autoload.php';

return function (array $event) {

    $id   = $event['pathParameters']['id'] ?? null;
    $body = json_decode($event['body'] ?? '', true);

    if (empty($id)) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'Missing required parameter: id'])];
    }

    if (empty($body)) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'Request body is empty'])];
    }

    try {

        $repository = new App\Common\Repositories\BatchProductionCostRepository();
        $cost       = $repository->getBatchProductionCost($id);

        if ($cost === null) {
            return ['statusCode' => 404, 'body' => json_encode(['error' => 'Production cost not found'])];
        }

        $updated = $repository->updateBatchProductionCost($id, $body);

        return [
            'statusCode' => 200,
            'headers'    => ['Content-Type' => 'application/json'],
            'body'       => json_encode(['data' => $updated->toArray()]),
        ];

    } catch (Exception $e) {

        return ['statusCode' => 500, 'body' => json_encode(['error' => $e->getMessage()])];
    }
};
