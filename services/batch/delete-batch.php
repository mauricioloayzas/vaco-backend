<?php
require __DIR__ . '/../../vendor/autoload.php';

return function (array $event) {

    $profile_id = $event['pathParameters']['profile_id'] ?? null;
    $id = $event['pathParameters']['id'] ?? null;

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

    try {

        $repository = new App\Common\Repositories\BatchRepository();
        $repository->deleteBatch($profile_id, $id);

        return [
            'statusCode' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode([
                'message' => 'Batch deleted successfully'
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
