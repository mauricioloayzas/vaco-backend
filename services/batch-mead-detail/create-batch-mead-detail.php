<?php
require __DIR__ . '/../../vendor/autoload.php';

return function (array $event) {

    $batchId = $event['pathParameters']['batch_id'] ?? null;
    $body = json_decode($event['body'] ?? '', true);

    if (empty($batchId)) {
        return [
            'statusCode' => 400,
            'body' => json_encode([
                'error' => 'Missing required parameter: batch_id'
            ])
        ];
    }

    $required = [
        'honey_kg', 'honey_brix', 'initial_brix', 'water_liters',
        'total_must_liters', 'final_brix_desired', 'yeast_type',
        'yeast_strain', 'yeast_dose_g_per_l', 'yeast_grams'
    ];

    foreach ($required as $field) {
        if (!isset($body[$field]) || $body[$field] === '') {
            return [
                'statusCode' => 400,
                'body' => json_encode([
                    'error' => "Missing required field: {$field}"
                ])
            ];
        }
    }

    try {

        $repository = new App\Common\Repositories\BatchMeadDetailRepository();
        $detail = $repository->createBatchMeadDetail($body, $batchId);

        return [
            'statusCode' => 201,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode([
                'data' => $detail->toArray()
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
