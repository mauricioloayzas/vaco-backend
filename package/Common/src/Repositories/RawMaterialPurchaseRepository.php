<?php

namespace App\Common\Repositories;

use Mauloasan\BobConstruye\DynamoDB\Entities\Vaco\RawMaterialPurchaseEntity;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\RawMaterialUnit;
use Mauloasan\BobConstruye\DynamoDB\DynamoDbClientFactory;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Ramsey\Uuid\Uuid;

class RawMaterialPurchaseRepository
{
    private DynamoDbClient $dbClient;
    private Marshaler $marshaler;
    private string $tableName;

    public function __construct()
    {
        $this->dbClient  = DynamoDbClientFactory::create();
        $this->marshaler = new Marshaler();
        $this->tableName = $_ENV['DYNAMODB_TABLE_RAW_MATERIAL_PURCHASES'];
    }

    public function getPurchases(string $profile_id, int $limit = 20, ?array $lastEvaluatedKey = null): array
    {
        $params = [
            'TableName'                 => $this->tableName,
            'IndexName'                 => 'profile_id-purchased_at-index',
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
            $items[] = RawMaterialPurchaseEntity::fromArray($this->marshaler->unmarshalItem($item));
        }

        return [
            'items'              => $items,
            'last_evaluated_key' => $result['LastEvaluatedKey'] ?? null,
        ];
    }

    public function getPurchasesByRawMaterial(string $profile_id, string $raw_material_id, int $limit = 20, ?array $lastEvaluatedKey = null): array
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
            $items[] = RawMaterialPurchaseEntity::fromArray($this->marshaler->unmarshalItem($item));
        }

        return [
            'items'              => $items,
            'last_evaluated_key' => $result['LastEvaluatedKey'] ?? null,
        ];
    }

    public function getPurchase(string $profile_id, string $id): ?RawMaterialPurchaseEntity
    {
        $result = $this->dbClient->getItem([
            'TableName' => $this->tableName,
            'Key'       => $this->marshaler->marshalItem(['id' => $id]),
        ]);

        if (empty($result['Item'])) {
            return null;
        }

        $entity = RawMaterialPurchaseEntity::fromArray($this->marshaler->unmarshalItem($result['Item']));

        if ($entity->profile_id !== $profile_id) {
            return null;
        }

        return $entity;
    }

    public function createPurchase(string $profile_id, array $data): ?RawMaterialPurchaseEntity
    {
        if (RawMaterialUnit::tryFrom($data['unit']) === null) {
            throw new \InvalidArgumentException('Invalid unit provided.');
        }

        $id   = Uuid::uuid4()->toString();
        $item = [
            'id'              => $id,
            'raw_material_id' => $data['raw_material_id'],
            'profile_id'      => $profile_id,
            'quantity'        => (float)$data['quantity'],
            'price_per_unit'  => (float)$data['price_per_unit'],
            'unit'            => $data['unit'],
            'notes'           => $data['notes'] ?? null,
            'purchased_at'    => $data['purchased_at'] ?? date('c'),
            'created_at'      => date('c'),
        ];

        $this->dbClient->putItem([
            'TableName' => $this->tableName,
            'Item'      => $this->marshaler->marshalItem($item),
        ]);

        return $this->getPurchase($profile_id, $id);
    }

    public function deletePurchase(string $id): bool
    {
        $this->dbClient->deleteItem([
            'TableName' => $this->tableName,
            'Key'       => $this->marshaler->marshalItem(['id' => $id]),
        ]);

        return true;
    }
}
