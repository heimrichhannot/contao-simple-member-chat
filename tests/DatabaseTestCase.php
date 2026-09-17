<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;

abstract class DatabaseTestCase extends ServiceTestCase
{
    protected Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $url = getenv('MEMBER_CHAT_TEST_DATABASE_URL');
        if ($url === false || $url === '') {
            self::markTestSkipped('Set MEMBER_CHAT_TEST_DATABASE_URL to a dedicated *_test database.');
        }

        $this->connection = DriverManager::getConnection([
            'url' => $url,
        ]);
        $database = $this->connection->getDatabase();
        if ($database === null || !str_ends_with($database, '_test')) {
            throw new \LogicException('Integration tests require a dedicated *_test database.');
        }

        $schema = new Schema();
        foreach (['tl_chat_conversation', 'tl_chat_participant', 'tl_chat_message'] as $name) {
            $this->connection->executeStatement('DROP TABLE IF EXISTS ' . $name);
            require __DIR__ . '/../contao/dca/' . $name . '.php';
            /** @var array{fields: array<string, array{sql: array{type: string}}>, config: array{sql: array{keys: array<string, string>}}} $dca */
            $dca = (\is_array($GLOBALS['TL_DCA']) ? $GLOBALS['TL_DCA'] : [])[$name];
            $table = $schema->createTable($name);
            foreach ($dca['fields'] as $field => $definition) {
                $options = $definition['sql'];
                $type = $options['type'];
                unset($options['type']);
                $table->addColumn($field, $type, $options);
            }

            foreach ($dca['config']['sql']['keys'] as $columns => $type) {
                $columns = explode(',', $columns);
                match ($type) {
                    'primary' => $table->setPrimaryKey($columns),
                    'unique' => $table->addUniqueIndex($columns),
                    default => $table->addIndex($columns),
                };
            }
        }

        foreach ($schema->toSql($this->connection->getDatabasePlatform()) as $sql) {
            $this->connection->executeStatement($sql);
        }

        foreach (['tl_member', 'tl_member_group'] as $table) {
            $this->connection->executeStatement('DROP TABLE IF EXISTS ' . $table);
        }

        $this->connection->executeStatement("CREATE TABLE tl_member (id INT PRIMARY KEY, firstname VARCHAR(255) NOT NULL DEFAULT '', lastname VARCHAR(255) NOT NULL DEFAULT '', username VARCHAR(64) DEFAULT NULL, `groups` BLOB NULL, disable TINYINT(1) NOT NULL DEFAULT 0, login TINYINT(1) NOT NULL DEFAULT 0, start VARCHAR(10) NOT NULL DEFAULT '', stop VARCHAR(10) NOT NULL DEFAULT '', avatar BINARY(16) NULL)");
        $this->connection->executeStatement("CREATE TABLE tl_member_group (id INT PRIMARY KEY, disable TINYINT(1) NOT NULL DEFAULT 0, start VARCHAR(10) NOT NULL DEFAULT '', stop VARCHAR(10) NOT NULL DEFAULT '')");
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->close();
        }

        parent::tearDown();
    }
}
