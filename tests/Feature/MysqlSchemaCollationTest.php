<?php

namespace Test\Vesper\Tool\Event\Feature;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Vesper\Tool\Event\Infrastructure\Schema\MysqlEventStoreSchema;
use Vesper\Tool\Event\Infrastructure\Schema\MysqlRedeliverySchema;

/**
 * Regression test: SqlRedeliveryStore::fetchNextDueRow joins event_outbox_redelivery to
 * event_outbox on the event id and listener columns. If those columns inherit different
 * collations from the schema/server default at table-creation time, MySQL 8 raises
 * SQLSTATE 1267 "Illegal mix of collations" when the join runs. The schema templates and
 * boot-time helpers pin the join keys to utf8mb4_unicode_ci so the join always works
 * regardless of the surrounding database default.
 *
 * Requires a MySQL DSN in the EVENTS_MYSQL_DSN env var (with optional EVENTS_MYSQL_USER /
 * EVENTS_MYSQL_PASSWORD). Skipped when unavailable.
 */
class MysqlSchemaCollationTest extends TestCase
{
    private const REQUIRED_COLLATION = 'utf8mb4_unicode_ci';

    private const JOIN_KEY_COLUMNS = [
        'event_outbox' => ['id'],
        'event_outbox_status' => ['event_id'],
        'event_outbox_redelivery' => ['event_id', 'listener'],
    ];

    private PDO $pdo;

    protected function setUp(): void
    {
        $dsn = getenv('EVENTS_MYSQL_DSN');

        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('Set EVENTS_MYSQL_DSN to a MySQL DSN to exercise this test.');
        }

        $user = getenv('EVENTS_MYSQL_USER') ?: null;
        $password = getenv('EVENTS_MYSQL_PASSWORD') ?: null;

        try {
            $this->pdo = new PDO($dsn, $user, $password);
        } catch (PDOException $e) {
            self::markTestSkipped('MySQL unavailable: ' . $e->getMessage());
        }

        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->dropTables();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->dropTables();
        }
    }

    public function test_migration_templates_pin_join_keys_to_utf8mb4_unicode_ci(): void
    {
        $this->pdo->exec((string) file_get_contents(__DIR__ . '/../../migrations/mysql/0001_create_event_outbox.sql'));
        $this->pdo->exec((string) file_get_contents(__DIR__ . '/../../migrations/mysql/0001_create_event_outbox_redelivery.sql'));

        $this->assertJoinKeyCollations();
    }

    public function test_boot_time_schema_helpers_pin_join_keys_to_utf8mb4_unicode_ci(): void
    {
        MysqlEventStoreSchema::create($this->pdo);
        MysqlRedeliverySchema::create($this->pdo);

        $this->assertJoinKeyCollations();
    }

    private function assertJoinKeyCollations(): void
    {
        foreach (self::JOIN_KEY_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                self::assertSame(
                    self::REQUIRED_COLLATION,
                    $this->collationOf($table, $column),
                    "{$table}.{$column} must use " . self::REQUIRED_COLLATION,
                );
            }
        }
    }

    private function collationOf(string $table, string $column): string
    {
        $stmt = $this->pdo->prepare(
            <<<SQL
                    SELECT collation_name
                    FROM information_schema.columns
                    WHERE table_schema = DATABASE()
                      AND table_name = :table
                      AND column_name = :column
                SQL,
        );
        $stmt->execute(['table' => $table, 'column' => $column]);

        $collation = $stmt->fetchColumn();

        self::assertIsString($collation, "Column {$table}.{$column} not found");

        return $collation;
    }

    private function dropTables(): void
    {
        foreach (array_keys(self::JOIN_KEY_COLUMNS) as $table) {
            $this->pdo->exec("DROP TABLE IF EXISTS {$table}");
        }
    }
}
