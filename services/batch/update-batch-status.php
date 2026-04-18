<?php
require __DIR__ . '/../../vendor/autoload.php';

return function (array $event) {

    $profile_id = $event['pathParameters']['profile_id'] ?? null;
    $id = $event['pathParameters']['id'] ?? null;
    $body = json_decode($event['body'] ?? '', true);

    if (empty($profile_id)) {
        return [
            'statusCode' => 400,
            'body' => json_encode(['error' => 'Missing required profile_id'])
        ];
    }

    if (empty($id)) {
        return [
            'statusCode' => 400,
            'body' => json_encode([
                'error' => 'Missing required parameter: id'
            ])
        ];
    }

    if (!isset($body['status'])) {
        return [
            'statusCode' => 400,
            'body' => json_encode([
                'error' => 'Missing required field: status',
                'payload' => $body
            ])
        ];
    }

    try {

        $repository = new App\Common\Repositories\BatchRepository();
        $batch = $repository->updateBatchStatus($profile_id, $id, $body['status']);

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
