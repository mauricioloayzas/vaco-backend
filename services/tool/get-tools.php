<?php
require_once file_exists('/opt/vendor/autoload.php') ? '/opt/vendor/autoload.php' : __DIR__ . '/../../vendor/autoload.php';

return function (array $event) {

    $profile_id = $event['pathParameters']['profile_id'] ?? null;

    if (empty($profile_id)) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'Missing required profile_id'])];
    }

    try {

        $limit            = (int)($event['queryStringParameters']['limit'] ?? 20);
        $lastEvaluatedKey = isset($event['queryStringParameters']['cursor'])
            ? json_decode(base64_decode($event['queryStringParameters']['cursor']), true)
            : null;

        $repository = new App\Common\Repositories\ToolRepository();
        $result     = $repository->getTools($profile_id, $limit, $lastEvaluatedKey);

        $nextCursor = $result['last_evaluated_key']
            ? base64_encode(json_encode($result['last_evaluated_key']))
            : null;

        return [
            'statusCode' => 200,
            'headers'    => ['Content-Type' => 'application/json'],
            'body'       => json_encode([
                'data'        => array_map(fn($t) => $t->toArray(), $result['items']),
                'next_cursor' => $nextCursor,
            ]),
        ];

    } catch (Exception $e) {

        return ['statusCode' => 500, 'body' => json_encode(['error' => $e->getMessage()])];
    }
};
