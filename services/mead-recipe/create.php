<?php
require __DIR__ . '/../../vendor/autoload.php';

return function (array $event) {
    
    $body = json_decode($event['body'] ?? '', true);

    if (empty($body['name']) || empty($body['email'])) {
        return [
            'statusCode' => 400,
            'body' => json_encode(['error' => 'Faltan los campos name y email'])
        ];
    }

    try {
        $userId = "";

        return [
            'statusCode' => 201,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode([
                'message' => 'Usuario creado',
                'userId' => $userId
            ])
        ];

    } catch (Exception $e) {
        return [
            'statusCode' => 500,
            'body' => json_encode(['error' => $e->getMessage()])
        ];
    }
};