<?php

namespace App\Common\Repositories;

use Mauloasan\BobConstruye\DynamoDB\Entities\Vaco\RawMaterialMovementEntity;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\RawMaterialMovementType;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\RawMaterialUnit;
use Mauloasan\BobConstruye\DynamoDB\DynamoDbClientFactory;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Ramsey\Uuid\Uuid;

class RawMaterialMovementRepository
{
    private DynamoDbClient $dbClient;
    private Marshaler $marshaler;
    private string $tableName;

    public function __construct()
    {
        $this->dbClient  = DynamoDbClientFactory::create();
        $this->marshaler = new Marshaler();
        $this->tableName = $_ENV['DYNAMODB_TABLE_RAW_MATERIAL_MOVEMENTS'];
    }

    public function getMovements(string $profile_id, int $limit = 20, ?array $lastEvaluatedKey = null): array
    {
        $params = [
            'TableName'                 => $this->tableName,
            'IndexName'                 => 'profile_id-occurred_at-index',
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
            $items[] = RawMaterialMovementEntity::fromArray($this->marshaler->unmarshalItem($item));
        }

        return [
            'items'              => $items,
            'last_evaluated_key' => $result['LastEvaluatedKey'] ?? null,
        ];
    }

    public function getMovementsByRawMaterial(string $profile_id, string $raw_material_id, int $limit = 20, ?array $lastEvaluatedKey = null): array
    {
        $params = [
            'TableName'                 => $this->tableName,
            'IndexName'                 => 'raw_material_id-index',
            'KeyConditionExpression'    => 'raw_material_id = :raw_material_id',
            'FilterExpression'          => 'profile_id = :profile_id',
            'ExpressionAttributeValues' => $this->marshaler->marshalItem([
                ':raw_material_id' => $raw_material_id,
                ':profile_id'      => $profile_id,
            ]),
            'Limit' => $limit,
        ];

        if ($lastEvaluatedKey !== null) {
            $params['ExclusiveStartKey'] = $lastEvaluatedKey;
        }

        $result = $this->dbClient->query($params);

        $items = [];
        foreach ($result['Items'] as $item) {
            $items[] = RawMaterialMovementEntity::fromArray($this->marshaler->unmarshalItem($item));
        }

        return [
            'items'              => $items,
            'last_evaluated_key' => $result['LastEvaluatedKey'] ?? null,
        ];
    }

    public function getMovement(string $profile_id, string $id): ?RawMaterialMovementEntity
    {
        $result = $this->dbClient->getItem([
            'TableName' => $this->tableName,
            'Key'       => $this->marshaler->marshalItem(['id' => $id]),
        ]);

        if (empty($result['Item'])) {
            return null;
        }

        $entity = RawMaterialMovementEntity::fromArray($this->marshaler->unmarshalItem($result['Item']));

        if ($entity->profile_id !== $profile_id) {
            return null;
        }

        return $entity;
    }

    public function createMovement(string $profile_id, array $data): ?RawMaterialMovementEntity
    {
        if (RawMaterialUnit::tryFrom($data['unit']) === null) {
            throw new \InvalidArgumentException('Invalid unit provided.');
        }

        if (RawMaterialMovementType::tryFrom($data['movement_type']) === null) {
            throw new \InvalidArgumentException('Invalid movement_type provided.');
        }

        $id   = Uuid::uuid4()->toString();
        $item = [
            'id'              => $id,
            'raw_material_id' => $data['raw_material_id'],
            'profile_id'      => $profile_id,
            'movement_type'   => $data['movement_type'],
            'quantity'        => (float)$data['quantity'],
            'price_per_unit'  => isset($data['price_per_unit']) ? (float)$data['price_per_unit'] : null,
            'unit'            => $data['unit'],
            'notes'           => $data['notes'] ?? null,
            'occurred_at'     => $data['occurred_at'] ?? date('c'),
            'created_at'      => date('c'),
        ];

        $this->dbClient->putItem([
            'TableName' => $this->tableName,
            'Item'      => $this->marshaler->marshalItem($item),
        ]);

        return $this->getMovement($profile_id, $id);
    }

    public function deleteMovement(string $id): bool
    {
        $this->dbClient->deleteItem([
            'TableName' => $this->tableName,
            'Key'       => $this->marshaler->marshalItem(['id' => $id]),
        ]);

        return true;
    }
}
