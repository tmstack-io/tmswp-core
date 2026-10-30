<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use TMSWP\Core\Exception\SchemaException;
use TMSWP\Core\Model\TableDefinition;
use TMSWP\Testing\IntegrationTestCase;

/**
 * Identifierの予約語の集合を、接続しているDBのキーワードの一覧(information_schema.KEYWORDS)と照合する(06-model §2)。
 *
 * キーワードのすべての語について、クォートしないカラム名でのテーブルの作成を実物のDBで試し、作成に失敗する語がすべて
 * TableDefinitionの宣言の時点で拒否されることを確かめる。DBの版が上がって予約語が増えたときに、ここで気づく。
 * MariaDBのKEYWORDSにはRESERVEDの列が無く、MySQLのRESERVED = 1の語にもカラム名に使える語があるため、RESERVEDで
 * 絞らずに全語を試す。CIでは、wp-envのMariaDBと、MySQL 8.4 LTS・9.7 LTS・MariaDB 10.11 LTSのジョブで走る
 * (10-quality §5.1)。
 */
final class IdentifierReservedWordIntegrationTest extends IntegrationTestCase
{
    private const string PROBE_TABLE = 'tmswp_it_keyword_probe';

    public function tear_down(): void
    {
        self::wpdb()->query('DROP TEMPORARY TABLE IF EXISTS ' . self::wpdb()->prefix . self::PROBE_TABLE);
        parent::tear_down();
    }

    public function testEveryKeywordThatCannotBeAnUnquotedColumnNameIsRejectedAtDeclaration(): void
    {
        $wpdb = self::wpdb();
        $version = (string) $wpdb->get_var('SELECT VERSION()');
        /** @var list<string> $keywords */
        $keywords = $wpdb->get_col('SELECT WORD FROM information_schema.KEYWORDS ORDER BY WORD');
        $failing = [];
        $accepted = [];

        // 一覧が空のまま成功しないよう、どのDBの一覧にもある語で確かめる
        self::assertContains('SELECT', array_map('strtoupper', $keywords), "information_schema.KEYWORDS of {$version}");

        foreach ($keywords as $keyword) {
            $error = $this->createProbeTable($keyword);

            if ($error === null) {
                continue;
            }

            $failing[] = $keyword;

            if (!self::rejectedAtDeclaration($keyword)) {
                $accepted[] = "{$keyword} ({$error})";
            }
        }

        self::assertContains('SELECT', array_map('strtoupper', $failing), "an unquoted column named select must fail on {$version}");
        self::assertSame([], $accepted, "keywords that {$version} cannot use as unquoted column names but TableDefinition accepts");
    }

    /**
     * クォートしないカラム名$keywordで一時テーブルを作り、失敗したらDBのエラーを返す。成功したら作ったテーブルを消してnullを返す。
     * 識別子の形でない語(MariaDBの`!=`等の記号)はSQLに書かず、失敗とみなす(宣言の時点で識別子の形の検査が拒否する)。
     */
    private function createProbeTable(string $keyword): ?string
    {
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $keyword) !== 1) {
            return 'not an identifier';
        }

        $wpdb = self::wpdb();
        $table = $wpdb->prefix . self::PROBE_TABLE;
        $suppressed = $wpdb->suppress_errors(true);

        try {
            $wpdb->query("CREATE TEMPORARY TABLE {$table} ({$keyword} int(11) NOT NULL)");
            $error = $wpdb->last_error;
            $wpdb->query("DROP TEMPORARY TABLE IF EXISTS {$table}");
        } finally {
            $wpdb->suppress_errors($suppressed);
        }

        return $error === '' ? null : $error;
    }

    private static function rejectedAtDeclaration(string $keyword): bool
    {
        try {
            TableDefinition::create(self::PROBE_TABLE)->id()->int($keyword);
        } catch (SchemaException) {
            return true;
        }

        return false;
    }
}
