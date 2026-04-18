<?php
require __DIR__ . '/../../vendor/autoload.php';

return function (array $event) {

    $id   = $event['pathParameters']['id'] ?? null;
    $body = json_decode($event['body'] ?? '', true);

    if (empty($id)) {
        return [
            'statusCode' => 400,
            'body' => json_encode(['error' => 'Missing required parameter: id'])
        ];
    }

    if (empty($body)) {
        return [
            'statusCode' => 400,
            'body' => json_encode(['error' => 'Request body is empty'])
        ];
    }

    try {

        $repository = new App\Common\Repositories\BatchMeadDetailRepository();

        $existing = $repository->getBatchMeadDetail($id);
        if ($existing === null) {
            return [
                'statusCode' => 404,
                'body' => json_encode(['error' => 'Batch mead detail not found'])
            ];
        }

        $usedLimits = [
            'sorbate_grams_used' => [
                'enabled' => $existing->use_sorbate,
                'min'     => $existing->sorbate_grams_min,
                'max'     => $existing->sorbate_grams_max,
            ],
            'benzoate_grams_used' => [
                'enabled' => $existing->use_benzoate,
                'min'     => $existing->benzoate_grams_min,
                'max'     => $existing->benzoate_grams_max,
            ],
            'metabisulfite_grams_used' => [
                'enabled' => $existing->use_metabisulfite,
                'min'     => 0.0,
                'max'     => $existing->metabisulfite_grams,
            ],
            'bentonite_grams_used' => [
                'enabled' => $existing->use_bentonite,
                'min'     => $existing->bentonite_grams_min,
                'max'     => $existing->bentonite_grams_max,
            ],
            'albumin_grams_used' => [
                'enabled' => $existing->use_albumin,
                'min'     => $existing->albumin_grams_min,
                'max'     => $existing->albumin_grams_max,
            ],
        ];

        foreach ($usedLimits as $field => $limits) {
            if (!array_key_exists($field, $body)) {
                continue;
            }

            if (!$limits['enabled']) {
                return [
                    'statusCode' => 400,
                    'body' => json_encode(['error' => "{$field} cannot be set: compound was not used in this batch"])
                ];
            }

            $used = (float)$body[$field];

            if ($limits['min'] !== null && $used < $limits['min']) {
                return [
                    'statusCode' => 400,
                    'body' => json_encode(['error' => "{$field} ({$used}g) is below the minimum allowed ({$limits['min']}g)"])
                ];
            }

            if ($limits['max'] !== null && $used > $limits['max']) {
                return [
                    'statusCode' => 400,
                    'body' => json_encode(['error' => "{$field} ({$used}g) exceeds the maximum allowed ({$limits['max']}g)"])
                ];
            }
        }

        $detail = $repository->updateBatchMeadDetail($id, $body);

        return [
            'statusCode' => 200,
            'headers'    => ['Content-Type' => 'application/json'],
            'body'       => json_encode(['data' => $detail->toArray()])
        ];

    } catch (\InvalidArgumentException $e) {

        return [
            'statusCode' => 400,
            'body' => json_encode(['error' => $e->getMessage()])
        ];

    } catch (Exception $e) {

        return [
            'statusCode' => 500,
            'body' => json_encode(['error' => $e->getMessage()])
        ];
    }
};
