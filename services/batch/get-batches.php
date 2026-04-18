<?php
require __DIR__ . '/../../vendor/autoload.php';

return function (array $event) {

    $profile_id = $event['pathParameters']['profile_id'] ?? null;

    if (empty($profile_id)) {
        return [
            'statusCode' => 400,
            'body' => json_encode(['error' => 'Missing required profile_id'])
        ];
    }

    try {

        $repository = new App\Common\Repositories\BatchRepository();
        $batches = $repository->getBatches($profile_id);

        return [
            'statusCode' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode([
                'data' => $batches ? array_map(fn($b) => $b->toArray(), $batches) : []
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
