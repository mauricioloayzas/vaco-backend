<?php

namespace App\Common\Repositories;

use Mauloasan\BobConstruye\DynamoDB\DynamoDbClientFactory;
use Mauloasan\BobConstruye\DynamoDB\Entities\Orchestrator\ProfileEntity;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;

class ProfileRepository
{
    private DynamoDbClient $dbClient;
    private Marshaler $marshaler;
    private string $tableName;

    public function __construct()
    {
        $this->dbClient  = DynamoDbClientFactory::create();
        $this->marshaler = new Marshaler();
        $this->tableName = $_ENV['DYNAMODB_TABLE_PROFILES'];
    }

    public function getProfileByUrlName(string $urlName): ?ProfileEntity
    {
        $result = $this->dbClient->query([
            'TableName'                 => $this->tableName,
            'IndexName'                 => 'url_name-index',
            'KeyConditionExpression'    => 'url_name = :url_name',
            'ExpressionAttributeValues' => $this->marshaler->marshalItem([
                ':url_name' => $urlName,
            ]),
            'Limit' => 1,
        ]);

        if (empty($result['Items'])) {
            return null;
        }

        return ProfileEntity::fromArray($this->marshaler->unmarshalItem(reset($result['Items'])));
    }
}
