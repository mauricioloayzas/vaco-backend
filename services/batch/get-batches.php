<?php
require __DIR__ . '/../../vendor/autoload.php';

return function (array $event) {

    try {

        $repository = new App\Common\Repositories\BatchRepository();
        $batches = $repository->getBatches();

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
