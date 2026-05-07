<?php

namespace App\Common\Repositories;

use Mauloasan\BobConstruye\DynamoDB\Entities\Vaco\RawMaterialEntity;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\RawMaterialUnit;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\RawMaterialCategory;
use Mauloasan\BobConstruye\DynamoDB\DynamoDbClientFactory;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Ramsey\Uuid\Uuid;

class RawMaterialRepository
{
    private DynamoDbClient $dbClient;
    private Marshaler $marshaler;
    private string $tableName;

    public function __construct()
    {
        $this->dbClient  = DynamoDbClientFactory::create();
        $this->marshaler = new Marshaler();
        $this->tableName = $_ENV['DYNAMODB_TABLE_RAW_MATERIALS'];
    }

    public function getRawMaterials(string $profile_id, int $limit = 20, ?array $lastEvaluatedKey = null): array
    {
        $params = [
            'TableName'                 => $this->tableName,
            'IndexName'                 => 'profile_id-index',
            'KeyConditionExpression'    => 'profile_id = :profile_id',
            'ExpressionAttributeValues' => $this->marshaler->marshalItem([':profile_id' => $profile_id]),
            'Limit'                     => $limit,
        ];

        if ($lastEvaluatedKey !== null) {
            $params['ExclusiveStartKey'] = $lastEvaluatedKey;
        }

        $result = $this->dbClient->query($params);

        $items = [];
        foreach ($result['Items'] as $item) {
            $items[] = RawMaterialEntity::fromArray($this->marshaler->unmarshalItem($item));
        }

        return [
            'items'              => $items,
            'last_evaluated_key' => $result['LastEvaluatedKey'] ?? null,
        ];
    }

    public function getRawMaterial(string $profile_id, string $id): ?RawMaterialEntity
    {
        $result = $this->dbClient->getItem([
            'TableName' => $this->tableName,
            'Key'       => $this->marshaler->marshalItem(['id' => $id]),
        ]);

        if (empty($result['Item'])) {
            return null;
        }

        $entity = RawMaterialEntity::fromArray($this->marshaler->unmarshalItem($result['Item']));

        if ($entity->profile_id !== $profile_id) {
            return null;
        }

        return $entity;
    }

    public function getRawMaterialById(string $id): ?RawMaterialEntity
    {
        $result = $this->dbClient->getItem([
            'TableName' => $this->tableName,
            'Key'       => $this->marshaler->marshalItem(['id' => $id]),
        ]);

        if (empty($result['Item'])) {
            return null;
        }

        return RawMaterialEntity::fromArray($this->marshaler->unmarshalItem($result['Item']));
    }

    public function createRawMaterial(string $profile_id, array $data): ?RawMaterialEntity
    {
        if (RawMaterialUnit::tryFrom($data['unit']) === null) {
            throw new \InvalidArgumentException('Invalid unit provided.');
        }

        if (RawMaterialCategory::tryFrom($data['category']) === null) {
            throw new \InvalidArgumentException('Invalid category provided.');
        }

        $id   = Uuid::uuid4()->toString();
        $item = [
            'id'             => $id,
            'profile_id'     => $profile_id,
            'name'           => $data['name'],
            'unit'           => $data['unit'],
            'price_per_unit' => 0.0,
            'stock_quantity' => 0.0,
            'category'       => $data['category'],
            'description'    => $data['description'] ?? null,
            'created_at'     => date('c'),
            'updated_at'     => null,
        ];

        $this->dbClient->putItem([
            'TableName' => $this->tableName,
            'Item'      => $this->marshaler->marshalItem($item),
        ]);

        return $this->getRawMaterial($profile_id, $id);
    }

    public function updateRawMaterial(string $profile_id, string $id, array $data): ?RawMaterialEntity
    {
        if (isset($data['unit']) && RawMaterialUnit::tryFrom($data['unit']) === null) {
            throw new \InvalidArgumentException('Invalid unit provided.');
        }

        if (isset($data['category']) && RawMaterialCategory::tryFrom($data['category']) === null) {
            throw new \InvalidArgumentException('Invalid category provided.');
        }

        $updateExpression          = 'SET ';
        $expressionAttributeValues = [];
        $expressionAttributeNames  = [];

        foreach ($data as $key => $value) {
            if ($key === 'id') {
                continue;
            }
            $updateExpression .= '#' . $key . ' = :' . $key . ', ';
            $expressionAttributeNames['#' . $key]  = $key;
            $expressionAttributeValues[':' . $key] = $value;
        }

        $updateExpression .= '#updated_at = :updated_at';
        $expressionAttributeNames['#updated_at']  = 'updated_at';
        $expressionAttributeValues[':updated_at'] = date('c');

        $this->dbClient->updateItem([
            'TableName'                 => $this->tableName,
            'Key'                       => $this->marshaler->marshalItem(['id' => $id]),
            'UpdateExpression'          => $updateExpression,
            'ExpressionAttributeValues' => $this->marshaler->marshalItem($expressionAttributeValues),
            'ExpressionAttributeNames'  => $expressionAttributeNames,
            'ReturnValues'              => 'ALL_NEW',
        ]);

        return $this->getRawMaterial($profile_id, $id);
    }

    public function deleteRawMaterial(string $id): bool
    {
        $this->dbClient->deleteItem([
            'TableName' => $this->tableName,
            'Key'       => $this->marshaler->marshalItem(['id' => $id]),
        ]);

        return true;
    }
}
