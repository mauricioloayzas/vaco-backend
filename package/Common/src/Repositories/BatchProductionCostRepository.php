<?php

namespace App\Common\Repositories;

use Mauloasan\BobConstruye\DynamoDB\Entities\Vaco\BatchProductionCostEntity;
use Mauloasan\BobConstruye\DynamoDB\Entities\Vaco\RawMaterialEntity;
use Mauloasan\BobConstruye\DynamoDB\Entities\Vaco\ToolEntity;
use Mauloasan\BobConstruye\DynamoDB\DynamoDbClientFactory;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Ramsey\Uuid\Uuid;

class BatchProductionCostRepository
{
    private DynamoDbClient $dbClient;
    private Marshaler $marshaler;
    private string $tableName;

    public function __construct()
    {
        $this->dbClient  = DynamoDbClientFactory::create();
        $this->marshaler = new Marshaler();
        $this->tableName = $_ENV['DYNAMODB_TABLE_BATCH_PRODUCTION_COSTS'];
    }

    public function getBatchProductionCostsByBatchId(string $batchId): array
    {
        $params = [
            'TableName'                 => $this->tableName,
            'IndexName'                 => 'batch_id-index',
            'KeyConditionExpression'    => 'batch_id = :batch_id',
            'ExpressionAttributeValues' => $this->marshaler->marshalItem([':batch_id' => $batchId]),
        ];

        $result = $this->dbClient->query($params);

        $items = [];
        foreach ($result['Items'] as $item) {
            $items[] = BatchProductionCostEntity::fromArray($this->marshaler->unmarshalItem($item));
        }

        return $items;
    }

    public function getBatchProductionCost(string $id): ?BatchProductionCostEntity
    {
        $result = $this->dbClient->getItem([
            'TableName' => $this->tableName,
            'Key'       => $this->marshaler->marshalItem(['id' => $id]),
        ]);

        if (empty($result['Item'])) {
            return null;
        }

        return BatchProductionCostEntity::fromArray($this->marshaler->unmarshalItem($result['Item']));
    }

    /**
     * @param string $batchId
     * @param string $profileId
     * @param array  $rawMaterialCosts  [{raw_material_id?, name, quantity, unit, price_per_unit?}]
     * @param array  $toolCosts         [{tool_id, name, depreciation_method, depreciation_amount}]
     * @param float  $laborCost
     * @param float  $gasCost
     * @param float  $electricityCost
     * @param float  $otherCosts
     * @param float|null $laborHours
     * @param float|null $totalMustLiters
     * @param int|null   $batchDurationDays
     * @param string|null $notes
     */
    public function createBatchProductionCost(
        string $batchId,
        string $profileId,
        array  $rawMaterialCosts,
        array  $toolCosts,
        float  $laborCost,
        float  $gasCost,
        float  $electricityCost,
        float  $otherCosts,
        ?float $laborHours = null,
        ?float $totalMustLiters = null,
        ?int   $batchDurationDays = null,
        ?string $notes = null
    ): ?BatchProductionCostEntity {
        $id = Uuid::uuid4()->toString();

        $rawMaterialTotal = array_sum(array_column($rawMaterialCosts, 'total'));
        $toolTotal        = array_sum(array_column($toolCosts, 'depreciation_amount'));
        $totalCost        = round($rawMaterialTotal + $toolTotal + $laborCost + $gasCost + $electricityCost + $otherCosts, 4);
        $costPerLiter     = ($totalMustLiters !== null && $totalMustLiters > 0)
            ? round($totalCost / $totalMustLiters, 4)
            : null;

        $item = [
            'id'                  => $id,
            'batch_id'            => $batchId,
            'profile_id'          => $profileId,
            'raw_material_costs'  => $rawMaterialCosts,
            'tool_costs'          => $toolCosts,
            'labor_cost'          => $laborCost,
            'labor_hours'         => $laborHours,
            'gas_cost'            => $gasCost,
            'electricity_cost'    => $electricityCost,
            'other_costs'         => $otherCosts,
            'total_cost'          => $totalCost,
            'cost_per_liter'      => $costPerLiter,
            'total_must_liters'   => $totalMustLiters,
            'batch_duration_days' => $batchDurationDays,
            'notes'               => $notes,
            'created_at'          => date('c'),
            'updated_at'          => null,
        ];

        $this->dbClient->putItem([
            'TableName' => $this->tableName,
            'Item'      => $this->marshaler->marshalItem($item),
        ]);

        return $this->getBatchProductionCost($id);
    }

    public function updateBatchProductionCost(string $id, array $data): ?BatchProductionCostEntity
    {
        // Recalculate totals if cost components are updated
        $fieldsToRecalc = ['raw_material_costs', 'tool_costs', 'labor_cost', 'gas_cost', 'electricity_cost', 'other_costs'];
        $needsRecalc    = count(array_intersect(array_keys($data), $fieldsToRecalc)) > 0;

        if ($needsRecalc) {
            $existing = $this->getBatchProductionCost($id);
            if ($existing !== null) {
                $rawMaterialCosts = $data['raw_material_costs'] ?? $existing->raw_material_costs;
                $toolCosts        = $data['tool_costs']         ?? $existing->tool_costs;
                $laborCost        = (float)($data['labor_cost']       ?? $existing->labor_cost);
                $gasCost          = (float)($data['gas_cost']         ?? $existing->gas_cost);
                $electricityCost  = (float)($data['electricity_cost'] ?? $existing->electricity_cost);
                $otherCosts       = (float)($data['other_costs']      ?? $existing->other_costs);
                $totalMustLiters  = isset($data['total_must_liters']) ? (float)$data['total_must_liters'] : $existing->total_must_liters;

                $rawMaterialTotal = array_sum(array_column($rawMaterialCosts, 'total'));
                $toolTotal        = array_sum(array_column($toolCosts, 'depreciation_amount'));
                $totalCost        = round($rawMaterialTotal + $toolTotal + $laborCost + $gasCost + $electricityCost + $otherCosts, 4);
                $costPerLiter     = ($totalMustLiters !== null && $totalMustLiters > 0)
                    ? round($totalCost / $totalMustLiters, 4)
                    : null;

                $data['total_cost']      = $totalCost;
                $data['cost_per_liter']  = $costPerLiter;
            }
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

        return $this->getBatchProductionCost($id);
    }

    public function deleteBatchProductionCost(string $id): bool
    {
        $this->dbClient->deleteItem([
            'TableName' => $this->tableName,
            'Key'       => $this->marshaler->marshalItem(['id' => $id]),
        ]);

        return true;
    }
}
