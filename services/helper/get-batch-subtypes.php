<?php
require __DIR__ . '/../../vendor/autoload.php';

use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\BatchType;

return function (array $event) {

    try {

        $typeValue = $event['queryStringParameters']['type'] ?? null;

        if (!$typeValue) {
            return [
                'statusCode' => 400,
                'body' => json_encode(['error' => 'Missing required parameter: type'])
            ];
        }

        $batchType = BatchType::tryFrom($typeValue);

        if (!$batchType) {
            return [
                'statusCode' => 400,
                'body' => json_encode(['error' => "Invalid type: $typeValue"])
            ];
        }

        $data = array_map(fn($case) => [
            'value' => $case->value,
            'label' => $case->name,
        ], $batchType->getSubtypes());

        return [
            'statusCode' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['data' => $data])
        ];

    } catch (Exception $e) {

        return [
            'statusCode' => 500,
            'body' => json_encode(['error' => $e->getMessage()])
        ];
    }
};
