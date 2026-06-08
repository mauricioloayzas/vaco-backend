<?php
require_once file_exists('/opt/vendor/autoload.php') ? '/opt/vendor/autoload.php' : __DIR__ . '/../../vendor/autoload.php';

return function (array $event) {

    $slug = $event['pathParameters']['slug'] ?? null;

    if (empty($slug)) {
        return [
            'statusCode' => 400,
            'body'       => json_encode(['error' => 'Missing required parameter: slug']),
        ];
    }

    try {

        $profileRepository = new App\Common\Repositories\ProfileRepository();
        $profile = $profileRepository->getProfileByUrlName($slug);

        if ($profile === null) {
            return [
                'statusCode' => 404,
                'body'       => json_encode(['error' => 'Profile not found']),
            ];
        }

        return [
            'statusCode' => 200,
            'headers'    => ['Content-Type' => 'application/json'],
            'body'       => json_encode([
                'data' => [
                    'profile' => [
                        'name'        => $profile->name,
                        'url_name'    => $profile->url_name,
                        'type'        => $profile->type->value,
                        'country_id'  => $profile->country_id,
                        'currency_id' => $profile->currency_id,
                    ],
                ],
            ]),
        ];

    } catch (Exception $e) {

        return [
            'statusCode' => 500,
            'body'       => json_encode(['error' => $e->getMessage()]),
        ];
    }
};
