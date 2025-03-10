<?php

namespace Utopia\Database\Adapter;

use PDOException;
use Utopia\Database\Database;
use Utopia\Database\Exception as DatabaseException;
use Utopia\Database\Exception\Timeout as TimeoutException;

class MySQL extends MariaDB
{
    /**
     * Get SQL Index
     *
     * @param string $collection
     * @param string $id
     * @param string $type
     * @param array<string> $attributes
     *
     * @return string
     * @throws DatabaseException
     */
    protected function getSQLIndex(string $collection, string $id, string $type, array $attributes): string
    {
        switch ($type) {
            case Database::INDEX_KEY:
                $type = 'INDEX';
                break;

            case Database::INDEX_ARRAY:
                $type = 'INDEX';

                foreach ($attributes as $key => $value) {
                    $attributes[$key] = '(CAST(' . $value . ' AS char(255) ARRAY))';
                }
                break;

            case Database::INDEX_UNIQUE:
                $type = 'UNIQUE INDEX';
                break;

            case Database::INDEX_FULLTEXT:
                $type = 'FULLTEXT INDEX';
                break;

            default:
                throw new DatabaseException('Unknown index type: ' . $type . '. Must be one of ' . Database::INDEX_KEY . ', ' . Database::INDEX_UNIQUE . ', ' . Database::INDEX_ARRAY . ', ' . Database::INDEX_FULLTEXT);
        }

        return 'CREATE '.$type.' `'.$id.'` ON `'.$this->getDatabase().'`.`'.$this->getNamespace().'_'.$collection.'` ( '.implode(', ', $attributes).' );';
    }

    /**
     * Set max execution time
     * @param int $milliseconds
     * @param string $event
     * @return void
     * @throws DatabaseException
     */
    public function setTimeout(int $milliseconds, string $event = Database::EVENT_ALL): void
    {
        if (!$this->getSupportForTimeouts()) {
            return;
        }
        if ($milliseconds <= 0) {
            throw new DatabaseException('Timeout must be greater than 0');
        }
        $this->before($event, 'timeout', function ($sql) use ($milliseconds) {
            return \preg_replace(
                pattern: '/SELECT/',
                replacement: "SELECT /*+ max_execution_time({$milliseconds}) */",
                subject: $sql,
                limit: 1
            );
        });
    }

    /**
     * @param PDOException $e
     * @throws TimeoutException
     */
    protected function processException(PDOException $e): void
    {
        if ($e->getCode() === 'HY000' && isset($e->errorInfo[1]) && $e->errorInfo[1] === 3024) {
            throw new TimeoutException($e->getMessage());
        }

        // PDOProxy which who switches errorInfo
        if ($e->getCode() === 3024 && isset($e->errorInfo[0]) && $e->errorInfo[0] === "HY000") {
            throw new TimeoutException($e->getMessage());
        }

        throw $e;
    }

    /**
    * Get Collection Size
    * @param string $collection
    * @return int
    * @throws DatabaseException
    */
    public function getSizeOfCollection(string $collection): int
    {
        $collection = $this->filter($collection);
        $collection = $this->getNamespace() . '_' . $collection;
        $database = $this->getDatabase();
        $name = $database . '/' . $collection;
        $permissions = $database . '/' . $collection . '_perms';

        $collectionSize = $this->getPDO()->prepare("
             SELECT SUM(FS_BLOCK_SIZE + ALLOCATED_SIZE)  
             FROM INFORMATION_SCHEMA.INNODB_TABLESPACES
             WHERE NAME = :name
        ");

        $permissionsSize = $this->getPDO()->prepare("
             SELECT SUM(FS_BLOCK_SIZE + ALLOCATED_SIZE)  
             FROM INFORMATION_SCHEMA.INNODB_TABLESPACES
             WHERE NAME = :permissions
        ");

        $collectionSize->bindParam(':name', $name);
        $permissionsSize->bindParam(':permissions', $permissions);

        try {
            $collectionSize->execute();
            $permissionsSize->execute();
            $size = $collectionSize->fetchColumn() + $permissionsSize->fetchColumn();
        } catch (PDOException $e) {
            throw new DatabaseException('Failed to get collection size: ' . $e->getMessage());
        }

        return $size;
    }
}
