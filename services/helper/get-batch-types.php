<?php
require __DIR__ . '/../../vendor/autoload.php';

use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\BatchTYPE;

return function (array $event) {

    try {

        $data = array_map(fn($case) => [
            'value' => $case->value,
            'label' => $case->name,
        ], BatchTYPE::cases());

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
