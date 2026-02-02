<?php

declare(strict_types=1);

namespace StoreCredit\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

class Migration1769640000CustomerStoreCreditValueCustomField extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1769640000;
    }

    public function update(Connection $connection): void
    {
        
        if (!$this->tableExists($connection, 'custom_field_set')
            || !$this->tableExists($connection, 'custom_field')
            || !$this->tableExists($connection, 'custom_field_set_relation')
        ) {
            return;
        }

        $setName = 'store_credit';
        $fieldName = 'store_credit_value_per_unit';

        $setId = $this->getIdByName($connection, 'custom_field_set', $setName);
        if ($setId === null) {
            $setId = Uuid::randomBytes();
            $this->insert($connection, 'custom_field_set', [
                'id' => $setId,
                'name' => $setName,
                'config' => json_encode([
                    'label' => [
                        'en-GB' => 'Store credit',
                    ],
                ], JSON_THROW_ON_ERROR),
                'active' => 1,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
            ]);
        }

        $fieldId = $this->getIdByName($connection, 'custom_field', $fieldName);
        if ($fieldId === null) {
            $fieldId = Uuid::randomBytes();
            $this->insert($connection, 'custom_field', [
                'id' => $fieldId,
                'name' => $fieldName,
                'type' => 'float',
                'config' => json_encode([
                    'label' => [
                        'en-GB' => 'Value per 1 credit',
                    ],
                    'helpText' => [
                        'en-GB' => 'Overrides the default value per credit. Can be set on customers and customer groups (e.g. 1 = $1 per credit, 3 = $3 per credit).',
                    ],
                    'componentName' => 'sw-field',
                    'customFieldType' => 'float',
                ], JSON_THROW_ON_ERROR),
                'active' => 1,
                'set_id' => $setId,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
            ]);
        }

        
        $this->ensureSetEntityRelation($connection, $setId, 'customer');
        $this->ensureSetEntityRelation($connection, $setId, 'customer_group');
    }

    public function updateDestructive(Connection $connection): void
    {
        
    }

    private function tableExists(Connection $connection, string $table): bool
    {
        $schemaManager = $connection->createSchemaManager();
        return in_array($table, $schemaManager->listTableNames(), true);
    }

    private function getIdByName(Connection $connection, string $table, string $name): ?string
    {
        $id = $connection->fetchOne(
            "SELECT `id` FROM `$table` WHERE `name` = :name LIMIT 1",
            ['name' => $name]
        );

        return is_string($id) ? $id : null;
    }

    private function insert(Connection $connection, string $table, array $data): void
    {
        $schemaManager = $connection->createSchemaManager();
        $columns = array_keys($schemaManager->listTableColumns($table));

        $filtered = [];
        foreach ($data as $key => $value) {
            if (in_array($key, $columns, true)) {
                $filtered[$key] = $value;
            }
        }

        
        if ($filtered === []) {
            return;
        }

        $connection->insert($table, $filtered);
    }

    private function ensureSetEntityRelation(Connection $connection, string $setId, string $entityName): void
    {
        $schemaManager = $connection->createSchemaManager();
        $columns = array_keys($schemaManager->listTableColumns('custom_field_set_relation'));

        if (!in_array('entity_name', $columns, true) || !in_array('set_id', $columns, true)) {
            return;
        }

        $exists = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM `custom_field_set_relation` WHERE `set_id` = :setId AND `entity_name` = :entityName',
            ['setId' => $setId, 'entityName' => $entityName]
        );
        if ($exists > 0) {
            return;
        }

        $this->insert($connection, 'custom_field_set_relation', [
            'id' => Uuid::randomBytes(),
            'set_id' => $setId,
            'entity_name' => $entityName,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
        ]);
    }
}

