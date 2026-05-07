<?php
require_once file_exists('/opt/vendor/autoload.php') ? '/opt/vendor/autoload.php' : __DIR__ . '/../../vendor/autoload.php';

return function (array $event) {

    $profile_id = $event['pathParameters']['profile_id'] ?? null;
    $body       = json_decode($event['body'] ?? '', true);

    if (empty($profile_id)) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'Missing required profile_id'])];
    }

    $required = ['name', 'unit', 'category'];
    foreach ($required as $field) {
        if (!isset($body[$field]) || $body[$field] === '') {
            return ['statusCode' => 400, 'body' => json_encode(['error' => "Missing required field: {$field}"])];
        }
    }

    try {

        $repository  = new App\Common\Repositories\RawMaterialRepository();
        $rawMaterial = $repository->createRawMaterial($profile_id, $body);

        return [
            'statusCode' => 201,
            'headers'    => ['Content-Type' => 'application/json'],
            'body'       => json_encode(['data' => $rawMaterial->toArray()]),
        ];

    } catch (\InvalidArgumentException $e) {

        return ['statusCode' => 400, 'body' => json_encode(['error' => $e->getMessage()])];

    } catch (Exception $e) {

        return ['statusCode' => 500, 'body' => json_encode(['error' => $e->getMessage()])];
    }
};
