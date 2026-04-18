<?php
require __DIR__ . '/../../vendor/autoload.php';

return function (array $event) {

    $body = json_decode($event['body'] ?? '', true);

    if (empty($body['code'])) {
        return [
            'statusCode' => 400,
            'body' => json_encode([
                'error' => 'Missing required field: code'
            ])
        ];
    }

    if (empty($body['name'])) {
        return [
            'statusCode' => 400,
            'body' => json_encode([
                'error' => 'Missing required field: name'
            ])
        ];
    }

    try {

        $repository = new App\Common\Repositories\BatchRepository();
        $batch = $repository->createBatch($body);

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
