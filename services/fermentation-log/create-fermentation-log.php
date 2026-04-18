<?php
require __DIR__ . '/../../vendor/autoload.php';

return function (array $event) {

    $batchId = $event['pathParameters']['batch_id'] ?? null;
    $body = json_decode($event['body'] ?? '', true);

    if (empty($batchId)) {
        return [
            'statusCode' => 400,
            'body' => json_encode([
                'error' => 'Missing required parameter: batch_id'
            ])
        ];
    }

    if (empty($body['recorded_at'])) {
        return [
            'statusCode' => 400,
            'body' => json_encode([
                'error' => 'Missing required field: recorded_at'
            ])
        ];
    }

    try {

        $repository = new App\Common\Repositories\FermentationLogRepository();
        $log = $repository->createFermentationLog($body, $batchId);

        return [
            'statusCode' => 201,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode([
                'data' => $log->toArray()
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
