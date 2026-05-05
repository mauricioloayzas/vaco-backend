<?php
require_once file_exists('/opt/vendor/autoload.php') ? '/opt/vendor/autoload.php' : __DIR__ . '/../../vendor/autoload.php';

return function (array $event) {

    $profile_id = $event['pathParameters']['profile_id'] ?? null;
    $id         = $event['pathParameters']['id'] ?? null;
    $body       = json_decode($event['body'] ?? '', true);

    if (empty($profile_id)) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'Missing required profile_id'])];
    }

    if (empty($id)) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'Missing required parameter: id'])];
    }

    if (empty($body)) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'Request body is empty'])];
    }

    try {

        $repository = new App\Common\Repositories\ToolRepository();
        $tool       = $repository->getTool($profile_id, $id);

        if ($tool === null) {
            return ['statusCode' => 404, 'body' => json_encode(['error' => 'Tool not found'])];
        }

        $updated = $repository->updateTool($profile_id, $id, $body);

        return [
            'statusCode' => 200,
            'headers'    => ['Content-Type' => 'application/json'],
            'body'       => json_encode(['data' => $updated->toArray()]),
        ];

    } catch (\InvalidArgumentException $e) {

        return ['statusCode' => 400, 'body' => json_encode(['error' => $e->getMessage()])];

    } catch (Exception $e) {

        return ['statusCode' => 500, 'body' => json_encode(['error' => $e->getMessage()])];
    }
};
