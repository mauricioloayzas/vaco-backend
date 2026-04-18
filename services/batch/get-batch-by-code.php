<?php
require __DIR__ . '/../../vendor/autoload.php';

return function (array $event) {

    $code = $event['pathParameters']['code'] ?? null;

    if (empty($code)) {
        return [
            'statusCode' => 400,
            'body' => json_encode([
                'error' => 'Missing required parameter: code'
            ])
        ];
    }

    try {

        $repository = new App\Common\Repositories\BatchRepository();
        $batch = $repository->getBatchByCode($code);

        if ($batch === null) {
            return [
                'statusCode' => 404,
                'body' => json_encode([
                    'error' => 'Batch not found'
                ])
            ];
        }

        return [
            'statusCode' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode([
                'data' => $batch->toArray()
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
