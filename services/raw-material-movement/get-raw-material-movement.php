<?php
require_once file_exists('/opt/vendor/autoload.php') ? '/opt/vendor/autoload.php' : __DIR__ . '/../../vendor/autoload.php';

return function (array $event) {

    $profile_id = $event['pathParameters']['profile_id'] ?? null;
    $id         = $event['pathParameters']['id'] ?? null;

    if (empty($profile_id)) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'Missing required profile_id'])];
    }

    if (empty($id)) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'Missing required parameter: id'])];
    }

    try {

        $repository = new App\Common\Repositories\RawMaterialMovementRepository();
        $purchase   = $repository->getMovement($profile_id, $id);

        if ($purchase === null) {
            return ['statusCode' => 404, 'body' => json_encode(['error' => 'Raw material movement not found'])];
        }

        return [
            'statusCode' => 200,
            'headers'    => ['Content-Type' => 'application/json'],
            'body'       => json_encode(['data' => $purchase->toArray()]),
        ];

    } catch (Exception $e) {

        return ['statusCode' => 500, 'body' => json_encode(['error' => $e->getMessage()])];
    }
};
