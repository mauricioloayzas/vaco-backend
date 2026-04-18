<?php
require __DIR__ . '/../../vendor/autoload.php';

return function (array $event) {

    $batchId = $event['pathParameters']['batch_id'] ?? null;

    if (empty($batchId)) {
        return [
            'statusCode' => 400,
            'body' => json_encode([
                'error' => 'Missing required parameter: batch_id'
            ])
        ];
    }

    try {

        $repository = new App\Common\Repositories\FermentationLogRepository();
        $logs = $repository->getFermentationLogsByBatchId($batchId);

        return [
            'statusCode' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode([
                'data' => $logs ? array_map(fn($l) => $l->toArray(), $logs) : []
            ])
        ];

    } catch (Exception $e) {

        return [
            'statusCode' => 500,
            'body' => json_encode([
                'error' => $e->getMessage()
            ])
        ];
    }
};
