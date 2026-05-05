<?php
require_once file_exists('/opt/vendor/autoload.php') ? '/opt/vendor/autoload.php' : __DIR__ . '/../../vendor/autoload.php';

return function (array $event) {

    $batchId = $event['pathParameters']['batch_id'] ?? null;

    if (empty($batchId)) {
        return [
            'statusCode' => 400,
            'body' => json_encode([
                'error' => 'Missing required parameter: batch_id'
            ])
        ];
    }

    try {

        $limit            = (int)($event['queryStringParameters']['limit'] ?? 20);
        $lastEvaluatedKey = isset($event['queryStringParameters']['cursor'])
            ? json_decode(base64_decode($event['queryStringParameters']['cursor']), true)
            : null;

        $repository = new App\Common\Repositories\FermentationLogRepository();
        $result     = $repository->getFermentationLogsByBatchId($batchId, $limit, $lastEvaluatedKey);

        $nextCursor = $result['last_evaluated_key']
            ? base64_encode(json_encode($result['last_evaluated_key']))
            : null;

        return [
            'statusCode' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode([
                'data'        => array_map(fn($l) => $l->toArray(), $result['items']),
                'next_cursor' => $nextCursor,
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
