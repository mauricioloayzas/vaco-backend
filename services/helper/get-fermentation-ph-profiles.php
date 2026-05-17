<?php
require_once file_exists('/opt/vendor/autoload.php') ? '/opt/vendor/autoload.php' : __DIR__ . '/../../vendor/autoload.php';

use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\BatchType;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\BatchSubtype;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\FermentationPhProfile;

return function (array $event) {

    try {

        $subtypeValue = $event['queryStringParameters']['subtype'] ?? null;

        if ($subtypeValue !== null) {
            $subtype = BatchSubtype::tryFrom($subtypeValue);

            if (!$subtype) {
                return [
                    'statusCode' => 400,
                    'body' => json_encode(['error' => "Invalid subtype: $subtypeValue"])
                ];
            }

            try {
                $profile = FermentationPhProfile::fromSubtype($subtype);
            } catch (\ValueError $e) {
                return [
                    'statusCode' => 400,
                    'body' => json_encode(['error' => $e->getMessage()])
                ];
            }

            return [
                'statusCode' => 200,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode(['data' => [
                    'value'   => $profile->value,
                    'label'   => $profile->name,
                    'ph_min'  => $profile->phMin(),
                    'ph_max'  => $profile->phMax(),
                ]])
            ];
        }

        $data = array_map(fn($case) => [
            'value'  => $case->value,
            'label'  => $case->name,
            'ph_min' => $case->phMin(),
            'ph_max' => $case->phMax(),
        ], FermentationPhProfile::cases());

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
