<?php
require_once file_exists('/opt/vendor/autoload.php') ? '/opt/vendor/autoload.php' : __DIR__ . '/../../vendor/autoload.php';

return function (array $event) {

    $profile_id = $event['pathParameters']['profile_id'] ?? null;
    $id = $event['pathParameters']['id'] ?? null;
    $body = json_decode($event['body'] ?? '', true);

    if (empty($profile_id)) {
        return [
            'statusCode' => 400,
            'body' => json_encode(['error' => 'Missing required profile_id'])
        ];
    }

    if (empty($id)) {
        return [
            'statusCode' => 400,
            'body' => json_encode([
                'error' => 'Missing required parameter: id'
            ])
        ];
    }

    if (empty($body)) {
        return [
            'statusCode' => 400,
            'body' => json_encode([
                'error' => 'Request body is empty',
                'payload' => $body
            ])
        ];
    }

    $requiredFields = ['code', 'name'];
    foreach ($requiredFields as $field) {
        if (!isset($body[$field])) {
            return [
                'statusCode' => 400,
                'body' => json_encode([
                    'error' => "Missing required field: $field",
                    'payload' => $body
                ])
            ];
        }
    }

    if (!empty($body['video_url'])) {
        if (!filter_var($body['video_url'], FILTER_VALIDATE_URL)) {
            return [
                'statusCode' => 400,
                'body' => json_encode(['error' => 'Invalid video_url format'])
            ];
        }

        $socialMediaHosts = ['youtube.com', 'youtu.be', 'tiktok.com', 'instagram.com', 'fb.watch', 'facebook.com'];
        $host = strtolower(parse_url($body['video_url'], PHP_URL_HOST) ?? '');
        $isSocialMedia = array_filter($socialMediaHosts, fn($h) => str_ends_with($host, $h));

        if (!$isSocialMedia) {
            $ffprobe = file_exists('/opt/bin/ffprobe') ? '/opt/bin/ffprobe' : 'ffprobe';
            $escapedUrl = escapeshellarg($body['video_url']);

            exec("$ffprobe -v quiet -print_format json -show_streams -select_streams v:0 $escapedUrl 2>/dev/null", $outputLines, $returnCode);
            $metadata = json_decode(implode("\n", $outputLines), true);

            if (empty($metadata['streams'][0])) {
                return [
                    'statusCode' => 400,
                    'body' => json_encode(['error' => 'Could not read video metadata from video_url'])
                ];
            }

            $width = $metadata['streams'][0]['width'] ?? 0;
            $height = $metadata['streams'][0]['height'] ?? 0;

            if ($width === 0 || $height === 0) {
                return [
                    'statusCode' => 400,
                    'body' => json_encode(['error' => 'Could not determine video dimensions'])
                ];
            }

            if ($width * 16 !== $height * 9) {
                return [
                    'statusCode' => 400,
                    'body' => json_encode([
                        'error' => "El video debe tener proporción 9:16. Se recibió {$width}x{$height}"
                    ])
                ];
            }
        }
    }

    try {

        $repository = new App\Common\Repositories\BatchRepository();
        $batch = $repository->updateBatch($profile_id, $id, $body);

        if ($batch === null) {
            return [
                'statusCode' => 404,
                'body' => json_encode([
                    'error' => 'Batch not found'
                ])
            ];
        }

        return [
            'statusCode' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode([
                'data' => $batch->toArray()
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
