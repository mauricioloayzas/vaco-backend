<?php
require __DIR__ . '/../../vendor/autoload.php';

return function (array $event) {

    $profile_id = $event['pathParameters']['profile_id'] ?? null;
    $body = json_decode($event['body'] ?? '', true);

    $requiredFields = ['code', 'name'];
    foreach ($requiredFields as $field) {
        if (!isset($body[$field])) {
            return [
                'statusCode' => 400,
                'body' => json_encode([
                    'error' => "Missing required field: $field",
                    'payload' => $body
                ])
            ];
        }
    }

    if (empty($profile_id)) {
        return [
            'statusCode' => 400,
            'body' => json_encode(['error' => 'Missing required profile_id'])
        ];
    }

    try {

        $repository = new App\Common\Repositories\BatchRepository();
        $batch = $repository->createBatch($profile_id, $body);

        return [
            'statusCode' => 201,
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
