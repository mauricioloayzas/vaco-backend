<?php

namespace App\Common\Repositories;

use Mauloasan\BobConstruye\DynamoDB\DynamoDbClientFactory;
use Mauloasan\BobConstruye\DynamoDB\Entities\Vaco\FermentationLogEntity;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Ramsey\Uuid\Uuid;

class FermentationLogRepository
{
    private DynamoDbClient $dbClient;
    private Marshaler $marshaler;
    private string $tableName;

    public function __construct()
    {
        $this->dbClient = DynamoDbClientFactory::create();
        $this->marshaler = new Marshaler();
        $this->tableName = $_ENV['DYNAMODB_TABLE_FERMENTATION_LOGS'];
    }

    public function getFermentationLogsByBatchId(string $batchId): ?array
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

        $logs = [];
        foreach ($result['Items'] as $item) {
            $logs[] = FermentationLogEntity::fromArray($this->marshaler->unmarshalItem($item));
        }

        return $logs;
    }

    public function getFermentationLog(string $id): ?FermentationLogEntity
    {
        $result = $this->dbClient->getItem([
            'TableName' => $this->tableName,
            'Key' => $this->marshaler->marshalItem(['id' => $id])
        ]);

        if (empty($result['Item'])) {
            return null;
        }

        return FermentationLogEntity::fromArray($this->marshaler->unmarshalItem($result['Item']));
    }

    public function createFermentationLog(array $data, string $batchId): ?FermentationLogEntity
    {
        $id = Uuid::uuid4()->toString();

        $item = [
            'id'          => $id,
            'batch_id'    => $batchId,
            'recorded_at' => $data['recorded_at'] ?? date('c'),
            'brix'        => isset($data['brix']) ? (float)$data['brix'] : null,
            'density'     => isset($data['density']) ? (float)$data['density'] : null,
            'temperature' => isset($data['temperature']) ? (float)$data['temperature'] : null,
            'created_at'  => date('c'),
            'updated_at'  => null,
        ];

        $this->dbClient->putItem([
            'TableName' => $this->tableName,
            'Item' => $this->marshaler->marshalItem($item)
        ]);

        return $this->getFermentationLog($id);
    }

    public function updateFermentationLog(string $id, array $data): ?FermentationLogEntity
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

        return $this->getFermentationLog($id);
    }

    public function deleteFermentationLog(string $id): bool
    {
        $this->dbClient->deleteItem([
            'TableName' => $this->tableName,
            'Key' => $this->marshaler->marshalItem(['id' => $id])
        ]);

        return true;
    }
}
