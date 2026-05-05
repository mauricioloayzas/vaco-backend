<?php
require_once file_exists('/opt/vendor/autoload.php') ? '/opt/vendor/autoload.php' : __DIR__ . '/../../vendor/autoload.php';

return function (array $event) {

    $batchId = $event['pathParameters']['batch_id'] ?? null;

    if (empty($batchId)) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'Missing required parameter: batch_id'])];
    }

    try {

        $repository = new App\Common\Repositories\BatchProductionCostRepository();
        $costs      = $repository->getBatchProductionCostsByBatchId($batchId);

        return [
            'statusCode' => 200,
            'headers'    => ['Content-Type' => 'application/json'],
            'body'       => json_encode(['data' => array_map(fn($c) => $c->toArray(), $costs)]),
        ];

    } catch (Exception $e) {

        return ['statusCode' => 500, 'body' => json_encode(['error' => $e->getMessage()])];
    }
};
