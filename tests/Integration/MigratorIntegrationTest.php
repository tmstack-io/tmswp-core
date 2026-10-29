<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use TMSWP\Core\Model\Connection;
use TMSWP\Core\Model\MigrationAction;
use TMSWP\Core\Model\MigrationResult;
use TMSWP\Core\Model\MigrationStatus;
use TMSWP\Core\Model\Migrator;
use TMSWP\Core\Model\Model;
use TMSWP\Core\Model\TableDefinition;
use TMSWP\Testing\IntegrationTestCase;

/**
 * Migrator::migrateAll()の2回目(定義の変更なし)の扱いと、その後のstatus()を、実物のデータベースで確かめる
 * (docs/06-model.mdの受け入れ基準)。2つのテーブルを宣言するこのファイルのModelを、新しいMigratorに登録して使う。
 */
final class MigratorIntegrationTest extends IntegrationTestCase
{
    private const string ALPHA_TABLE = 'tmswp_it_mig_alpha';

    private const string BETA_TABLE = 'tmswp_it_mig_beta';

    private const string OPTION = 'tmswp_schema_hashes';

    /** @var list<string> recordQueries()の後に発行されたSQL */
    private array $queries = [];

    /**
     * 保存済みのハッシュが無い状態から始める(ModelIntegrationTestと同じ)。テーブルは実際のテーブルで作り、tear_downで
     * 消える。DDLでコミットされたハッシュのoptionは、ロールバックの後の後始末で消す。
     */
    public function set_up(): void
    {
        parent::set_up();
        delete_option(self::OPTION);
        $this->useRealTables(self::ALPHA_TABLE, self::BETA_TABLE)->onTearDown(static function (): void {
            delete_option(self::OPTION);
        });
    }

    /**
     * docs/06-model.mdの「初回`migrateAll()`でテーブルが作成され、無変更の2回目はdbDeltaが実行されない(結果が
     * `MigrationAction::Skipped`で、`status()`が全テーブル`current`)」を確かめる。初回の前のstatus()はどのテーブルも
     * currentでなく、初回は2つのテーブルを作ってMigratedを返す。2回目はどのテーブルにもDESCRIBE・CREATE・ALTERを
     * 発行せずSkippedを返し、その後のstatus()は、どのテーブルも保存済みのハッシュが定義のハッシュと同じでcurrentである。
     */
    public function testUnchangedSecondMigrateAllSkipsEveryTableAndStatusStaysCurrent(): void
    {
        $wpdb = self::wpdb();
        $connection = new Connection($wpdb);
        $migrator = new Migrator($connection);
        $migrator->register(MigratorIntegrationTestAlpha::class);
        $migrator->register(MigratorIntegrationTestBeta::class);

        self::assertSame([[self::ALPHA_TABLE, false], [self::BETA_TABLE, false]], self::currents($migrator->status()));

        self::assertSame(
            [[self::ALPHA_TABLE, MigrationAction::Migrated], [self::BETA_TABLE, MigrationAction::Migrated]],
            self::actions($migrator->migrateAll()),
        );

        foreach ([self::ALPHA_TABLE, self::BETA_TABLE] as $table) {
            self::assertSame($wpdb->prefix . $table, $connection->selectVar('SHOW TABLES LIKE %s', [$wpdb->prefix . $table]));
        }

        $this->recordQueries();
        $second = $migrator->migrateAll();
        $statuses = $migrator->status();

        self::assertSame(
            [[self::ALPHA_TABLE, MigrationAction::Skipped], [self::BETA_TABLE, MigrationAction::Skipped]],
            self::actions($second),
        );
        self::assertSame([], array_values(array_filter($this->queries, static fn(string $query): bool => preg_match('/^\s*(DESCRIBE|CREATE|ALTER)\b/i', $query) === 1)));
        self::assertSame([[self::ALPHA_TABLE, true], [self::BETA_TABLE, true]], self::currents($statuses));

        foreach ($statuses as $index => $status) {
            self::assertSame($status->schemaHash, $status->storedHash, $status->table);
            self::assertSame($second[$index]->schemaHash, $status->schemaHash, $status->table);
        }
    }

    /** これから発行されるSQLを$this->queriesに控える。*/
    private function recordQueries(): void
    {
        add_filter('query', function (string $query): string {
            $this->queries[] = $query;

            return $query;
        });
    }

    /**
     * @param list<MigrationResult> $results
     *
     * @return list<array{string, MigrationAction}>
     */
    private static function actions(array $results): array
    {
        return array_map(static fn(MigrationResult $result): array => [$result->table, $result->action], $results);
    }

    /**
     * @param list<MigrationStatus> $statuses
     *
     * @return list<array{string, bool}>
     */
    private static function currents(array $statuses): array
    {
        return array_map(static fn(MigrationStatus $status): array => [$status->table, $status->current], $statuses);
    }
}

/** 2回目のmigrateAll()を確かめる1つ目のテーブル。*/
final class MigratorIntegrationTestAlpha extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_it_mig_alpha')->id()->string('name', 50)->index('name');
    }
}

/** 2回目のmigrateAll()を確かめる2つ目のテーブル。*/
final class MigratorIntegrationTestBeta extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_it_mig_beta')->id()->bool('enabled')->string('label', 20, nullable: true);
    }
}
