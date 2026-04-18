<?php
require __DIR__ . '/../../vendor/autoload.php';

return function (array $event) {

    $id = $event['pathParameters']['id'] ?? null;
    $body = json_decode($event['body'] ?? '', true);

    if (empty($id)) {
        return [
            'statusCode' => 400,
            'body' => json_encode([
                'error' => 'Missing required parameter: id'
            ])
        ];
    }

    if (empty($body['status'])) {
        return [
            'statusCode' => 400,
            'body' => json_encode([
                'error' => 'Missing required field: status'
            ])
        ];
    }

    try {

        $repository = new App\Common\Repositories\BatchRepository();
        $batch = $repository->updateBatchStatus($id, $body['status']);

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

    } catch (\InvalidArgumentException $e) {

        return [
            'statusCode' => 400,
            'body' => json_encode([
                'error' => $e->getMessage()
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
