<?php

namespace App\Common\Repositories;

use Mauloasan\BobConstruye\DynamoDB\DynamoDbClientFactory;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\BatchStatus;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\BatchType;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\BatchSubtype;
use Mauloasan\BobConstruye\DynamoDB\Entities\Vaco\BatchEntity;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Ramsey\Uuid\Uuid;

class BatchRepository
{
    private DynamoDbClient $dbClient;
    private Marshaler $marshaler;
    private string $tableName;

    public function __construct()
    {
        $this->dbClient = DynamoDbClientFactory::create();
        $this->marshaler = new Marshaler();
        $this->tableName = $_ENV['DYNAMODB_TABLE_BATCHES'];
    }

    public function getBatches(string $profile_id): ?array
    {
        $params = [
            'TableName' => $this->tableName,
            'IndexName' => 'profile_id-index',
            'KeyConditionExpression' => 'profile_id = :profile_id',
            'ExpressionAttributeValues' => $this->marshaler->marshalItem([
                ':profile_id' => $profile_id
            ]),
        ];

        $result = $this->dbClient->query($params);

        if (empty($result['Items'])) {
            return null;
        }

        $batches = [];
        foreach ($result['Items'] as $item) {
            $batches[] = BatchEntity::fromArray($this->marshaler->unmarshalItem($item));
        }

        return $batches;
    }

    public function getBatch(string $profile_id, string $id): ?BatchEntity
    {
        $result = $this->dbClient->getItem([
            'TableName' => $this->tableName,
            'Key' => $this->marshaler->marshalItem(['id' => $id])
        ]);

        if (empty($result['Item'])) {
            return null;
        }

        $entity = BatchEntity::fromArray($this->marshaler->unmarshalItem($result['Item']));

        if ($entity->profile_id !== $profile_id) {
            return null;
        }

        return $entity;
    }

    public function getBatchByCode(string $profile_id, string $code): ?BatchEntity
    {
        $params = [
            'TableName' => $this->tableName,
            'IndexName' => 'code-index',
            'KeyConditionExpression' => 'code = :code',
            'FilterExpression' => 'profile_id = :profile_id',
            'ExpressionAttributeValues' => $this->marshaler->marshalItem([
                ':code' => $code,
                ':profile_id' => $profile_id,
            ]),
        ];

        $result = $this->dbClient->query($params);

        if (empty($result['Items'])) {
            return null;
        }

        return BatchEntity::fromArray($this->marshaler->unmarshalItem(reset($result['Items'])));
    }

    public function createBatch(string $profile_id, array $data): ?BatchEntity
    {
        $id = Uuid::uuid4()->toString();

        if (BatchType::tryFrom($data['type']) === null) {
            throw new \InvalidArgumentException('Invalid type provided.');
        }

        if (BatchSubtype::tryFrom($data['subtype']) === null) {
            throw new \InvalidArgumentException('Invalid subtype provided.');
        }

        if (BatchStatus::tryFrom($data['status'] ?? BatchStatus::BEGIN->value) === null) {
            throw new \InvalidArgumentException('Invalid status provided.');
        }

        $item = [
            'id'         => $id,
            'code'       => $data['code'],
            'name'       => $data['name'],
            'profile_id' => $profile_id,
            'type'       => $data['type'],
            'subtype'    => $data['subtype'],
            'status'     => $data['status'] ?? BatchStatus::BEGIN->value,
            'created_at' => date('c'),
            'updated_at' => null,
        ];

        $this->dbClient->putItem([
            'TableName' => $this->tableName,
            'Item' => $this->marshaler->marshalItem($item)
        ]);

        return $this->getBatch($profile_id, $id);
    }

    public function updateBatch(string $profile_id, string $id, array $data): ?BatchEntity
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

        return $this->getBatch($profile_id, $id);
    }

    public function updateBatchStatus(string $profile_id, string $id, string $status): ?BatchEntity
    {
        if (BatchStatus::tryFrom($status) === null) {
            throw new \InvalidArgumentException('Invalid status provided.');
        }

        $updateExpression = 'SET #status = :status, #updated_at = :updated_at';
        $expressionAttributeNames = [
            '#status' => 'status',
            '#updated_at' => 'updated_at',
        ];
        $expressionAttributeValues = [
            ':status' => $status,
            ':updated_at' => date('c'),
        ];

        $params = [
            'TableName' => $this->tableName,
            'Key' => $this->marshaler->marshalItem(['id' => $id]),
            'UpdateExpression' => $updateExpression,
            'ExpressionAttributeNames' => $expressionAttributeNames,
            'ExpressionAttributeValues' => $this->marshaler->marshalItem($expressionAttributeValues),
            'ReturnValues' => 'ALL_NEW'
        ];

        $this->dbClient->updateItem($params);

        return $this->getBatch($profile_id, $id);
    }

    public function deleteBatch(string $profile_id, string $id): bool
    {
        $this->dbClient->deleteItem([
            'TableName' => $this->tableName,
            'Key' => $this->marshaler->marshalItem(['id' => $id])
        ]);

        return true;
    }
}
