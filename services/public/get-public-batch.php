<?php
require_once file_exists('/opt/vendor/autoload.php') ? '/opt/vendor/autoload.php' : __DIR__ . '/../../vendor/autoload.php';

return function (array $event) {

    $slug    = $event['pathParameters']['slug'] ?? null;
    $batchId = $event['pathParameters']['batch_id'] ?? null;

    if (empty($slug)) {
        return [
            'statusCode' => 400,
            'body'       => json_encode(['error' => 'Missing required parameter: slug']),
        ];
    }

    if (empty($batchId)) {
        return [
            'statusCode' => 400,
            'body'       => json_encode(['error' => 'Missing required parameter: batch_id']),
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

        $batchRepository = new App\Common\Repositories\BatchRepository();
        $batch = $batchRepository->getBatch($profile->id, $batchId);

        if ($batch === null) {
            return [
                'statusCode' => 404,
                'body'       => json_encode(['error' => 'Batch not found']),
            ];
        }

        $batchDetail = null;
        switch ($batch->type->value) {
            case 'mead':
                $detailRepo  = new App\Common\Repositories\BatchMeadDetailRepository();
                $batchDetail = $detailRepo->getBatchMeadDetailsByBatchId($batchId);
                break;
            case 'fruit ferment':
                $detailRepo  = new App\Common\Repositories\BatchFruitWineDetailRepository();
                $batchDetail = $detailRepo->getBatchFruitWineDetailsByBatchId($batchId);
                break;
            case 'sugarcane':
                $detailRepo  = new App\Common\Repositories\BatchSugarCaneWineDetailRepository();
                $batchDetail = $detailRepo->getBatchSugarCaneWineDetailsByBatchId($batchId);
                break;
        }

        $limit            = (int)($event['queryStringParameters']['limit'] ?? 100);
        $lastEvaluatedKey = isset($event['queryStringParameters']['cursor'])
            ? json_decode(base64_decode($event['queryStringParameters']['cursor']), true)
            : null;

        $fermentationLogRepository = new App\Common\Repositories\FermentationLogRepository();
        $logsResult = $fermentationLogRepository->getFermentationLogsByBatchId(
            $batchId,
            $limit,
            $lastEvaluatedKey
        );

        $nextCursor = $logsResult['last_evaluated_key']
            ? base64_encode(json_encode($logsResult['last_evaluated_key']))
            : null;

        return [
            'statusCode' => 200,
            'headers'    => ['Content-Type' => 'application/json'],
            'body'       => json_encode([
                'data' => [
                    'profile'            => ['name' => $profile->name, 'url_name' => $profile->url_name],
                    'batch'              => $batch->toArray(),
                    'batch_detail'       => $batchDetail?->toArray(),
                    'fermentation_logs'  => array_map(fn($l) =>
                        $l->toArray(), $logsResult['items']
                    ),
                    'next_cursor'        => $nextCursor,
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
