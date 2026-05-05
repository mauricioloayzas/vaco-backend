<?php

namespace App\Common\Repositories;

use Mauloasan\BobConstruye\DynamoDB\Entities\Vaco\ToolEntity;
use Mauloasan\BobConstruye\DynamoDB\Enums\Vaco\DepreciationMethod;
use Mauloasan\BobConstruye\DynamoDB\DynamoDbClientFactory;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Ramsey\Uuid\Uuid;

class ToolRepository
{
    private DynamoDbClient $dbClient;
    private Marshaler $marshaler;
    private string $tableName;

    public function __construct()
    {
        $this->dbClient  = DynamoDbClientFactory::create();
        $this->marshaler = new Marshaler();
        $this->tableName = $_ENV['DYNAMODB_TABLE_TOOLS'];
    }

    public function getTools(string $profile_id, int $limit = 20, ?array $lastEvaluatedKey = null): array
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
            $items[] = ToolEntity::fromArray($this->marshaler->unmarshalItem($item));
        }

        return [
            'items'              => $items,
            'last_evaluated_key' => $result['LastEvaluatedKey'] ?? null,
        ];
    }

    public function getTool(string $profile_id, string $id): ?ToolEntity
    {
        $result = $this->dbClient->getItem([
            'TableName' => $this->tableName,
            'Key'       => $this->marshaler->marshalItem(['id' => $id]),
        ]);

        if (empty($result['Item'])) {
            return null;
        }

        $entity = ToolEntity::fromArray($this->marshaler->unmarshalItem($result['Item']));

        if ($entity->profile_id !== $profile_id) {
            return null;
        }

        return $entity;
    }

    public function getToolById(string $id): ?ToolEntity
    {
        $result = $this->dbClient->getItem([
            'TableName' => $this->tableName,
            'Key'       => $this->marshaler->marshalItem(['id' => $id]),
        ]);

        if (empty($result['Item'])) {
            return null;
        }

        return ToolEntity::fromArray($this->marshaler->unmarshalItem($result['Item']));
    }

    public function createTool(string $profile_id, array $data): ?ToolEntity
    {
        $method = DepreciationMethod::tryFrom($data['depreciation_method'] ?? '');
        if ($method === null) {
            throw new \InvalidArgumentException('Invalid depreciation_method provided.');
        }

        if ($method === DepreciationMethod::LINEAL && empty($data['useful_life_months'])) {
            throw new \InvalidArgumentException('useful_life_months is required for lineal depreciation method.');
        }

        if ($method === DepreciationMethod::POR_LOTE && empty($data['batches_estimated'])) {
            throw new \InvalidArgumentException('batches_estimated is required for por_lote depreciation method.');
        }

        $id   = Uuid::uuid4()->toString();
        $item = [
            'id'                  => $id,
            'profile_id'          => $profile_id,
            'name'                => $data['name'],
            'purchase_price'      => (float)$data['purchase_price'],
            'purchase_date'       => $data['purchase_date'],
            'residual_value'      => (float)($data['residual_value'] ?? 0),
            'depreciation_method' => $data['depreciation_method'],
            'useful_life_months'  => isset($data['useful_life_months'])  ? (int)$data['useful_life_months']  : null,
            'batches_estimated'   => isset($data['batches_estimated'])   ? (int)$data['batches_estimated']   : null,
            'description'         => $data['description'] ?? null,
            'created_at'          => date('c'),
            'updated_at'          => null,
        ];

        $this->dbClient->putItem([
            'TableName' => $this->tableName,
            'Item'      => $this->marshaler->marshalItem($item),
        ]);

        return $this->getTool($profile_id, $id);
    }

    public function updateTool(string $profile_id, string $id, array $data): ?ToolEntity
    {
        if (isset($data['depreciation_method']) && DepreciationMethod::tryFrom($data['depreciation_method']) === null) {
            throw new \InvalidArgumentException('Invalid depreciation_method provided.');
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

        return $this->getTool($profile_id, $id);
    }

    public function deleteTool(string $id): bool
    {
        $this->dbClient->deleteItem([
            'TableName' => $this->tableName,
            'Key'       => $this->marshaler->marshalItem(['id' => $id]),
        ]);

        return true;
    }
}
