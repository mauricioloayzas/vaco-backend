<?php

namespace App\Common\Repositories;

use Mauloasan\BobConstruye\DynamoDB\DynamoDbClientFactory;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\MetabisulfiteType;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\NutrientType;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\YeastStrain;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\YeastType;
use Mauloasan\BobConstruye\DynamoDB\Entities\Vaco\BatchMeadDetailEntity;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Ramsey\Uuid\Uuid;

class BatchMeadDetailRepository
{
    private DynamoDbClient $dbClient;
    private Marshaler $marshaler;
    private string $tableName;

    public function __construct()
    {
        $this->dbClient = DynamoDbClientFactory::create();
        $this->marshaler = new Marshaler();
        $this->tableName = $_ENV['DYNAMODB_TABLE_BATCH_MEAD_DETAILS'];
    }

    public function getBatchMeadDetailsByBatchId(string $batchId): ?array
    {
        $params = [
            'TableName' => $this->tableName,
            'IndexName' => 'batch_id-index',
            'KeyConditionExpression' => 'batch_id = :batch_id',
            'ExpressionAttributeValues' => $this->marshaler->marshalItem([
                ':batch_id' => $batchId
            ]),
            'ScanIndexForward' => true
        ];

        $result = $this->dbClient->query($params);

        if (empty($result['Items'])) {
            return null;
        }

        $details = [];
        foreach ($result['Items'] as $item) {
            $details[] = BatchMeadDetailEntity::fromArray($this->marshaler->unmarshalItem($item));
        }

        return $details;
    }

    public function getBatchMeadDetail(string $id): ?BatchMeadDetailEntity
    {
        $result = $this->dbClient->getItem([
            'TableName' => $this->tableName,
            'Key' => $this->marshaler->marshalItem(['id' => $id])
        ]);

        if (empty($result['Item'])) {
            return null;
        }

        return BatchMeadDetailEntity::fromArray($this->marshaler->unmarshalItem($result['Item']));
    }

    public function createBatchMeadDetail(array $data, string $batchId): ?BatchMeadDetailEntity
    {
        $id = Uuid::uuid4()->toString();

        if (YeastType::tryFrom($data['yeast_type']) === null) {
            throw new \InvalidArgumentException('Invalid yeast_type provided.');
        }

        if (YeastStrain::tryFrom($data['yeast_strain']) === null) {
            throw new \InvalidArgumentException('Invalid yeast_strain provided.');
        }

        if (
            isset($data['nutrient_primary']) &&
            NutrientType::tryFrom($data['nutrient_primary']) === null
        ) {
            throw new \InvalidArgumentException('Invalid nutrient_primary provided.');
        }

        if (
            isset($data['nutrient_secondary']) &&
            NutrientType::tryFrom($data['nutrient_secondary']) === null
        ) {
            throw new \InvalidArgumentException('Invalid nutrient_secondary provided.');
        }

        if (
            isset($data['metabisulfite_type']) &&
            MetabisulfiteType::tryFrom($data['metabisulfite_type']) === null
        ) {
            throw new \InvalidArgumentException('Invalid metabisulfite_type provided.');
        }

        $item = [
            'id'                       => $id,
            'batch_id'                 => $batchId,
            'honey_kg'                 => (float)$data['honey_kg'],
            'honey_brix'               => (float)$data['honey_brix'],
            'initial_brix'             => (float)$data['initial_brix'],
            'water_liters'             => (float)$data['water_liters'],
            'total_must_liters'        => (float)$data['total_must_liters'],
            'final_brix_desired'       => (float)$data['final_brix_desired'],
            'yeast_type'               => $data['yeast_type'],
            'yeast_strain'             => $data['yeast_strain'],
            'yeast_dose_g_per_l'       => (float)$data['yeast_dose_g_per_l'],
            'yeast_grams'              => (float)$data['yeast_grams'],
            'nutrient_primary'         => $data['nutrient_primary'] ?? null,
            'nutrient_primary_grams'   => isset($data['nutrient_primary_grams']) ? (float)$data['nutrient_primary_grams'] : null,
            'nutrient_secondary'       => $data['nutrient_secondary'] ?? null,
            'nutrient_secondary_grams' => isset($data['nutrient_secondary_grams']) ? (float)$data['nutrient_secondary_grams'] : null,
            'use_sorbate'              => (bool)($data['use_sorbate'] ?? false),
            'use_benzoate'             => (bool)($data['use_benzoate'] ?? false),
            'use_metabisulfite'        => (bool)($data['use_metabisulfite'] ?? false),
            'metabisulfite_type'       => $data['metabisulfite_type'] ?? null,
            'so2_target_mg_per_l'      => isset($data['so2_target_mg_per_l']) ? (float)$data['so2_target_mg_per_l'] : null,
            'use_bentonite'            => (bool)($data['use_bentonite'] ?? false),
            'use_albumin'              => (bool)($data['use_albumin'] ?? false),
            'created_at'               => date('c'),
            'updated_at'               => null,
        ];

        $this->dbClient->putItem([
            'TableName' => $this->tableName,
            'Item' => $this->marshaler->marshalItem($item)
        ]);

        return $this->getBatchMeadDetail($id);
    }

    public function updateBatchMeadDetail(string $id, array $data): ?BatchMeadDetailEntity
    {
        $updateExpression = 'SET ';
        $expressionAttributeValues = [];
        $expressionAttributeNames = [];

        foreach ($data as $key => $value) {
            if ($key === 'id') {
                continue;
            }

            $placeholderName = '#' . $key;
            $placeholderValue = ':' . $key;

            $updateExpression .= $placeholderName . ' = ' . $placeholderValue . ', ';

            $expressionAttributeNames[$placeholderName] = $key;
            $expressionAttributeValues[$placeholderValue] = $value;
        }

        $updateExpression .= '#updated_at = :updated_at';
        $expressionAttributeNames['#updated_at'] = 'updated_at';
        $expressionAttributeValues[':updated_at'] = date('c');

        $params = [
            'TableName' => $this->tableName,
            'Key' => $this->marshaler->marshalItem(['id' => $id]),
            'UpdateExpression' => $updateExpression,
            'ExpressionAttributeValues' => $this->marshaler->marshalItem($expressionAttributeValues),
            'ExpressionAttributeNames' => $expressionAttributeNames,
            'ReturnValues' => 'ALL_NEW'
        ];

        $this->dbClient->updateItem($params);

        return $this->getBatchMeadDetail($id);
    }

    public function deleteBatchMeadDetail(string $id): bool
    {
        $this->dbClient->deleteItem([
            'TableName' => $this->tableName,
            'Key' => $this->marshaler->marshalItem(['id' => $id])
        ]);

        return true;
    }
}
