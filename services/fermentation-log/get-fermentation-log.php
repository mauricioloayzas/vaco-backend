<?php
require __DIR__ . '/../../vendor/autoload.php';

return function (array $event) {

    $id = $event['pathParameters']['id'] ?? null;

    if (empty($id)) {
        return [
            'statusCode' => 400,
            'body' => json_encode([
                'error' => 'Missing required parameter: id'
            ])
        ];
    }

    try {

        $repository = new App\Common\Repositories\FermentationLogRepository();
        $log = $repository->getFermentationLog($id);

        if ($log === null) {
            return [
                'statusCode' => 404,
                'body' => json_encode([
                    'error' => 'Fermentation log not found'
                ])
            ];
        }

        return [
            'statusCode' => 200,
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
