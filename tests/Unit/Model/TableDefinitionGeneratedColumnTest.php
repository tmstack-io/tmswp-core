<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Model;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\Core\Exception\SchemaException;
use TMSWP\Core\Model\Connection;
use TMSWP\Core\Model\GeneratedColumnStorage;
use TMSWP\Core\Model\TableDefinition;
use TMSWP\Testing\UnitTestCase;
use TMSWP\Testing\WpdbTestDouble;

/**
 * TableDefinitionの生成カラム(generated())とON UPDATE CURRENT_TIMESTAMP(Model層の設計書の「生成カラム」「列の属性」)。
 * 列の行・属性・型と式の字句の検証・宣言の順序・rawColumn()の生成カラムの構文の拒否と、既存の宣言のtoSql()と
 * ハッシュが変わらないことを、WordPressなしで確かめる。
 */
final class TableDefinitionGeneratedColumnTest extends UnitTestCase
{
    /** 要望の定義(Model層の設計書の利用例「アクティブな行だけで一意にする」)の列の行と、生成カラム・ON UPDATEの属性。 */
    public function testRequestedDefinitionBuildsGeneratedAndOnUpdateLines(): void
    {
        $definition = self::accounts();
        $sql = $definition->toSql(self::connection());

        self::assertSame(<<<'SQL'
CREATE TABLE wp_accounts (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
account_type varchar(20) NOT NULL,
status varchar(20) NOT NULL,
corp_id bigint(20) unsigned DEFAULT NULL,
site_id bigint(20) unsigned DEFAULT NULL,
created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
corp_admin_active_key bigint(20) unsigned AS (CASE WHEN account_type = 'corp_admin' AND status NOT IN ('expired','deleted') THEN corp_id ELSE NULL END) STORED,
site_role_active_key varchar(40) AS (CASE WHEN account_type IN ('office_admin','office') AND status NOT IN ('expired','deleted') THEN CONCAT(account_type, ':', site_id) ELSE NULL END) VIRTUAL,
PRIMARY KEY  (id),
UNIQUE KEY uq_corp_admin (corp_admin_active_key),
UNIQUE KEY uq_site_role (site_role_active_key)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
SQL, $sql);
        self::assertSame(['corp_admin_active_key', 'site_role_active_key'], $definition->generatedColumns());
        self::assertSame(GeneratedColumnStorage::Stored, $definition->columnGeneration('corp_admin_active_key'));
        self::assertSame(GeneratedColumnStorage::Virtual, $definition->columnGeneration('site_role_active_key'));
        self::assertNull($definition->columnGeneration('corp_id'));
        self::assertSame(
            [true, null, null, 'number', 'bigint(20)'],
            [
                $definition->columnNullable('corp_admin_active_key'),
                $definition->columnDefault('corp_admin_active_key'),
                $definition->columnOnUpdate('corp_admin_active_key'),
                $definition->columnKind('corp_admin_active_key'),
                $definition->columnType('corp_admin_active_key'),
            ],
        );
        self::assertSame('string', $definition->columnKind('site_role_active_key'));
        self::assertSame('CURRENT_TIMESTAMP', $definition->columnOnUpdate('updated_at'));
        self::assertNull($definition->columnOnUpdate('created_at'));
        self::assertSame('updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', $definition->columnSql('updated_at'));
        self::assertSame(
            "site_role_active_key varchar(40) AS (CASE WHEN account_type IN ('office_admin','office') AND status NOT IN ('expired','deleted') THEN CONCAT(account_type, ':', site_id) ELSE NULL END) VIRTUAL",
            $definition->columnSql('site_role_active_key'),
        );
        self::assertSame([], TableDefinition::create('plain')->id()->generatedColumns());
    }

    /** 式は宣言のまま書く(大小文字・前後の空白を変えない)。`%`は受け付ける。 */
    public function testExpressionIsWrittenAsDeclared(): void
    {
        $definition = TableDefinition::create('parity')
            ->id()
            ->int('val')
            ->generated('odd', 'tinyint(1)', ' val % 2 ', GeneratedColumnStorage::Virtual)
            ->generated('Label', 'varchar(20)', "concat('n', VAL)", GeneratedColumnStorage::Stored);

        self::assertSame('odd tinyint(1) AS ( val % 2 ) VIRTUAL', $definition->columnSql('odd'));
        self::assertSame("Label varchar(20) AS (concat('n', VAL)) STORED", $definition->columnSql('Label'));
    }

    /** @return iterable<string, array{string, string}> 型、メッセージの一部 */
    public static function invalidTypes(): iterable
    {
        yield '大文字' => ['BIGINT(20) unsigned', 'must be a lowercase type'];
        yield '大文字のunsigned' => ['bigint(20) UNSIGNED', 'must be a lowercase type'];
        yield '属性を含む' => ['bigint(20) unsigned not null', 'must be a lowercase type'];
        yield '空白の重複' => ['bigint(20)  unsigned', 'must be a lowercase type'];
        yield '空' => ['', 'must be a lowercase type'];
        yield '型の別名' => ['integer', "uses the type alias 'integer'"];
        yield 'json' => ['json', "uses the type alias 'json'"];
        yield '表示幅の無い整数' => ['bigint unsigned', "uses the integer type 'bigint' without a display width"];
        yield '精度の引数が1つのfloat' => ['float(10)', "uses 'float(10)' with a single precision argument"];
    }

    /** 型は小文字の表記の正規表現と、rawColumn()と同じ型の表記の規則で検証する。 */
    #[DataProvider('invalidTypes')]
    public function testGeneratedRejectsInvalidType(string $type, string $message): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage($message);

        TableDefinition::create('typed')->id()->int('val')->generated('derived', $type, 'val + 1', GeneratedColumnStorage::Stored);
    }

    /** @return iterable<string, array{literal-string, string}> 式、メッセージの一部 */
    public static function invalidExpressions(): iterable
    {
        yield '空' => ['', 'must be non-empty printable ASCII'];
        yield '空白だけ' => ['   ', 'must be non-empty printable ASCII'];
        yield '改行' => ["val\n+ 1", 'must be non-empty printable ASCII'];
        yield 'タブ' => ["val\t+ 1", 'must be non-empty printable ASCII'];
        yield 'ASCIIでない文字' => ["val + 'あ'", 'must be non-empty printable ASCII'];
        yield 'セミコロン' => ['val; DROP TABLE wp_users', 'must not contain ;'];
        yield 'リテラルの中のセミコロン' => ["CONCAT(val, ';')", 'must not contain ;'];
        yield 'バッククォート' => ['`val` + 1', 'must not contain ;'];
        yield 'ダブルクォート' => ['CONCAT(val, "x")', 'must not contain ;'];
        yield 'バックスラッシュ' => ["CONCAT(val, '\\'')", 'must not contain ;'];
        yield '閉じていないリテラル' => ["CONCAT(val, 'x)", 'has an unterminated string literal'];
        yield '#のコメント' => ['val # 1', 'must not contain comments'];
        yield '--のコメント' => ['val -- 1', 'must not contain comments'];
        yield '/*のコメント' => ['val /* x', 'must not contain comments'];
        yield '*/' => ['val */ 1', 'must not contain comments'];
        yield '閉じ括弧が先' => ['val) STORED, x int(11) AS (1', 'has unbalanced parentheses'];
        yield '閉じていない括弧' => ['(val + 1', 'has unbalanced parentheses'];
        yield '括弧の外への脱出' => ['val)', 'has unbalanced parentheses'];
        yield 'DEFAULTの語' => ['IF(val > 0, val, DEFAULT(val))', 'must not contain the word DEFAULT'];
        yield '小文字のdefault' => ["CONCAT(val, ' default ')", 'must not contain the word DEFAULT'];
    }

    /**
     * 式の字句の検証(Model層の設計書の「生成カラム」の6項目)。違反は宣言の時点でSchemaException。
     *
     * @param literal-string $expression
     */
    #[DataProvider('invalidExpressions')]
    public function testGeneratedRejectsInvalidExpression(string $expression, string $message): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage($message);

        TableDefinition::create('expressions')->id()->int('val')->generated('derived', 'varchar(40)', $expression, GeneratedColumnStorage::Stored);
    }

    /** @return iterable<string, array{literal-string}> */
    public static function acceptedExpressions(): iterable
    {
        yield 'リテラルの中の引用符' => ["CONCAT(val, 'it''s')"];
        yield 'リテラルの中のコメントの記号' => ["CONCAT(val, '-- # /* */')"];
        yield 'リテラルの中の括弧' => ["CONCAT(val, ')(')"];
        yield '剰余の演算子' => ['val % 2'];
        yield '負の数の引き算' => ['val - -1'];
        yield '語の一部のdefault' => ['val + is_default_x'];
    }

    /** @param literal-string $expression */
    #[DataProvider('acceptedExpressions')]
    public function testGeneratedAcceptsExpressionsThatPassTheLexicalRules(string $expression): void
    {
        $definition = TableDefinition::create('expressions')->id()->int('val')->generated('derived', 'varchar(40)', $expression, GeneratedColumnStorage::Stored);

        self::assertSame("derived varchar(40) AS ({$expression}) STORED", $definition->columnSql('derived'));
    }

    /** @return iterable<string, array{Closure(): TableDefinition, string}> */
    public static function declarationErrors(): iterable
    {
        yield '生成カラムの後の通常の列' => [
            static fn(): TableDefinition => TableDefinition::create('ordered')->id()->int('val')->generated('derived', 'int(11)', 'val + 1', GeneratedColumnStorage::Stored)->int('late'),
            "column 'late' must be declared before the generated columns",
        ];
        yield '生成カラムの後のid()' => [
            static fn(): TableDefinition => TableDefinition::create('ordered')->int('val')->generated('derived', 'int(11)', 'val + 1', GeneratedColumnStorage::Stored)->id(),
            "column 'id' must be declared before the generated columns",
        ];
        yield '生成カラムの後のrawColumn()' => [
            static fn(): TableDefinition => TableDefinition::create('ordered')->id()->int('val')->generated('derived', 'int(11)', 'val + 1', GeneratedColumnStorage::Stored)->rawColumn('late', 'int(11) NOT NULL'),
            "column 'late' must be declared before the generated columns",
        ];
        yield '主キーに生成カラム' => [
            static fn(): TableDefinition => TableDefinition::create('keyed')->int('val')->generated('derived', 'int(11)', 'val + 1', GeneratedColumnStorage::Stored)->primary('val', 'derived'),
            'primary key must not contain the generated column: derived',
        ];
        yield 'id()と同じ名前の生成カラム' => [
            static fn(): TableDefinition => TableDefinition::create('keyed')->id()->generated('id', 'int(11)', '1', GeneratedColumnStorage::Stored),
            'duplicate column: id',
        ];
        yield 'textの生成カラムの索引' => [
            static fn(): TableDefinition => TableDefinition::create('indexed')->id()->int('val')->generated('derived', 'text', 'val', GeneratedColumnStorage::Stored)->index('derived'),
            "column 'derived' cannot be indexed",
        ];
        yield '予約語の名前' => [
            static fn(): TableDefinition => TableDefinition::create('named')->id()->int('val')->generated('order', 'int(11)', 'val', GeneratedColumnStorage::Stored),
            'order',
        ];
    }

    /** 宣言の順序・主キー・索引・名前の誤りは、宣言の時点でSchemaException。 */
    #[DataProvider('declarationErrors')]
    public function testGeneratedDeclarationErrors(Closure $declare, string $message): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage($message);

        $declare();
    }

    /** 生成カラムの後に生成カラムを続けられ、索引を張れる。 */
    public function testGeneratedColumnsCanFollowEachOtherAndBeIndexed(): void
    {
        $definition = TableDefinition::create('indexed')
            ->id()
            ->int('val')
            ->generated('a', 'int(11)', 'val + 1', GeneratedColumnStorage::Stored)
            ->generated('b', 'int(11)', 'a + 1', GeneratedColumnStorage::Virtual)
            ->index('a')
            ->unique(['a', 'b'], 'ab');

        self::assertSame(['a', 'b'], $definition->generatedColumns());
        self::assertSame([['type' => 'KEY', 'name' => 'a', 'columns' => ['a']], ['type' => 'UNIQUE KEY', 'name' => 'ab', 'columns' => ['a', 'b']]], $definition->indexes());
    }

    /** @return iterable<string, array{string}> */
    public static function rawGeneratedDefinitions(): iterable
    {
        yield 'AS (' => ['bigint(20) AS (val + 1) STORED'];
        yield '小文字のas(' => ['int(11) as(val) virtual'];
        yield 'GENERATED ALWAYS' => ['int(11) GENERATED ALWAYS AS (val + 1) VIRTUAL'];
        yield 'GENERATED ALWAYSだけ' => ['int(11) generated   always'];
    }

    /** rawColumn()は生成カラムの構文を拒否し、generated()を案内する(破壊的変更)。 */
    #[DataProvider('rawGeneratedDefinitions')]
    public function testRawColumnRejectsGeneratedColumnSyntax(string $definition): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage("raw column 'derived' must not define a generated column; declare it with generated() instead");

        TableDefinition::create('raw')->id()->int('val')->rawColumn('derived', $definition);
    }

    /**
     * datetime(onUpdateCurrentTimestamp: true)の行は、同じ定義のrawColumn()の行とバイト単位で同じで、rawColumn()から
     * 移してもハッシュは変わらない。ON UPDATEの属性は、rawColumn()でも定義の最後のON UPDATEから読む。
     */
    public function testOnUpdateCurrentTimestampMatchesTheRawColumnLine(): void
    {
        $connection = self::connection();
        $builtin = TableDefinition::create('touched')->id()->datetime('updated_at', default: 'CURRENT_TIMESTAMP', onUpdateCurrentTimestamp: true);
        $raw = TableDefinition::create('touched')->id()->rawColumn('updated_at', 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');

        self::assertSame($raw->columnSql('updated_at'), $builtin->columnSql('updated_at'));
        self::assertSame($raw->hash($connection), $builtin->hash($connection));
        self::assertSame(['CURRENT_TIMESTAMP', 'CURRENT_TIMESTAMP'], [$builtin->columnOnUpdate('updated_at'), $raw->columnOnUpdate('updated_at')]);
        self::assertSame(
            'touched_at datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP',
            TableDefinition::create('touched')->id()->datetime('touched_at', nullable: true, onUpdateCurrentTimestamp: true)->columnSql('touched_at'),
        );

        $precise = TableDefinition::create('touched')->id()
            ->rawColumn('touched_at', 'timestamp(6) NULL DEFAULT NULL on update CURRENT_TIMESTAMP(6)')
            ->rawColumn('plain', 'datetime NOT NULL');

        self::assertSame('CURRENT_TIMESTAMP(6)', $precise->columnOnUpdate('touched_at'));
        self::assertNull($precise->columnDefault('touched_at'));
        self::assertNull($precise->columnOnUpdate('plain'));
        self::assertNull($precise->columnOnUpdate('id'));
    }

    /**
     * rawColumn()のON UPDATEは、日時の関数(CURRENT_TIMESTAMP・NOW()・LOCALTIMESTAMP・LOCALTIMEと精度)だけを読む。外部キーの
     * 参照の動作(ON UPDATE CASCADE等)と、括弧の無いnow・nowx等の別の語は読まない。
     */
    public function testRawColumnReadsOnlyTemporalOnUpdate(): void
    {
        $definition = TableDefinition::create('touched')->id()
            ->rawColumn('now_at', 'datetime NULL DEFAULT NULL ON UPDATE NOW()')
            ->rawColumn('local_at', 'datetime(3) NULL DEFAULT NULL on update localtimestamp(3)')
            ->rawColumn('time_at', 'datetime NULL DEFAULT NULL ON UPDATE LOCALTIME')
            ->rawColumn('owner_id', 'bigint(20) unsigned NOT NULL REFERENCES wp_users(ID) ON UPDATE CASCADE')
            ->rawColumn('parent_id', 'bigint(20) unsigned NULL ON UPDATE SET NULL')
            ->rawColumn('nowx_at', 'datetime NULL DEFAULT NULL ON UPDATE nowx')
            ->rawColumn('bare_now_at', 'datetime NULL DEFAULT NULL ON UPDATE now')
            ->rawColumn('suffixed_at', 'datetime NULL DEFAULT NULL ON UPDATE current_timestamp_x');

        self::assertSame(
            ['NOW()', 'localtimestamp(3)', 'LOCALTIME', null, null, null, null, null],
            [
                $definition->columnOnUpdate('now_at'),
                $definition->columnOnUpdate('local_at'),
                $definition->columnOnUpdate('time_at'),
                $definition->columnOnUpdate('owner_id'),
                $definition->columnOnUpdate('parent_id'),
                $definition->columnOnUpdate('nowx_at'),
                $definition->columnOnUpdate('bare_now_at'),
                $definition->columnOnUpdate('suffixed_at'),
            ],
        );
    }

    /** @return iterable<string, array{string}> */
    public static function rawCommentDefinitions(): iterable
    {
        yield '/* */' => ['int(11) /* AS (val + 1) */ NOT NULL'];
        yield '*/だけ' => ['int(11) NOT NULL */'];
        yield '#' => ['int(11) NOT NULL # note'];
        yield '--' => ['int(11) NOT NULL -- note'];
    }

    /** rawColumn()はコメントを拒否する(コメントの中の語で生成カラムの判定をすり抜けさせない。破壊的変更)。 */
    #[DataProvider('rawCommentDefinitions')]
    public function testRawColumnRejectsComments(string $definition): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage("raw column 'commented' definition must not contain comments (/*, */, #, --)");

        TableDefinition::create('raw')->id()->rawColumn('commented', $definition);
    }

    /** 未宣言の列の属性はSchemaException。 */
    public function testUnknownColumnAttributesThrow(): void
    {
        $definition = TableDefinition::create('known')->id();
        $thrown = [];
        $reads = [
            static fn(): mixed => $definition->columnSql('missing'),
            static fn(): mixed => $definition->columnOnUpdate('missing'),
            static fn(): mixed => $definition->columnGeneration('missing'),
        ];

        foreach ($reads as $read) {
            try {
                $read();
                $thrown[] = null;
            } catch (SchemaException $exception) {
                $thrown[] = $exception->getMessage();
            }
        }

        self::assertSame(array_fill(0, 3, 'unknown column: missing'), $thrown);
    }

    /**
     * 生成カラムとON UPDATEを使わない既存の宣言のtoSql()とハッシュは、v0.10.0の前(v0.9.1)と同じ。期待値は、v0.9.1の
     * TableDefinitionで同じ宣言から作った値である。
     */
    public function testExistingDeclarationsKeepTheirSqlAndHash(): void
    {
        $connection = self::connection();
        $builtin = TableDefinition::create('compat_builtin')->id()->string('str_plain')->string('str_short', 20, nullable: true)->string('str_default', 50, default: 'draft')
            ->text('text_nullable')->text('text_required', nullable: false)->int('int_default', default: -5)->int('int_unsigned', default: 7, unsigned: true)
            ->bigint('bigint_nullable', nullable: true, default: 9007199254740993)->decimal('dec_default', 30, 4, default: '12.5')->bool('flag_on', true)
            ->datetime('dt_plain')->datetime('dt_nullable', nullable: true)->datetime('dt_now', default: 'CURRENT_TIMESTAMP')->datetime('dt_fixed', default: '2020-01-02 03:04:05')
            ->json('json_nullable')->foreignId('owner_id')->index('str_plain')->index(['int_default', 'dt_plain'], 'pair')->unique('str_short');
        $raw = TableDefinition::create('compat_raw')->id()->rawColumn('updated_at', 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP')
            ->rawColumn('touched_at', '  timestamp(6) NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP(6) ')->rawColumn('small', 'mediumint(9) NOT NULL')
            ->charset('utf8mb4')->collation('utf8mb4_unicode_ci');

        self::assertSame(<<<'SQL'
CREATE TABLE wp_compat_builtin (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
str_plain varchar(191) NOT NULL,
str_short varchar(20) DEFAULT NULL,
str_default varchar(50) NOT NULL DEFAULT 'draft',
text_nullable text DEFAULT NULL,
text_required text NOT NULL,
int_default int(11) NOT NULL DEFAULT -5,
int_unsigned int(11) unsigned NOT NULL DEFAULT 7,
bigint_nullable bigint(20) DEFAULT NULL DEFAULT 9007199254740993,
dec_default decimal(30,4) NOT NULL DEFAULT 12.5,
flag_on tinyint(1) NOT NULL DEFAULT 1,
dt_plain datetime NOT NULL,
dt_nullable datetime DEFAULT NULL,
dt_now datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
dt_fixed datetime NOT NULL DEFAULT '2020-01-02 03:04:05',
json_nullable longtext DEFAULT NULL,
owner_id bigint(20) unsigned NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY str_short (str_short),
KEY owner_id (owner_id),
KEY str_plain (str_plain),
KEY pair (int_default,dt_plain)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
SQL, $builtin->toSql($connection));
        self::assertSame('cbb2ac48b190273598bfb733112c61e1c9806eac8c1651e0a3fd56ffefdc48b8', $builtin->hash($connection));
        self::assertSame(<<<'SQL'
CREATE TABLE wp_compat_raw (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
touched_at timestamp(6) NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP(6),
small mediumint(9) NOT NULL,
PRIMARY KEY  (id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
SQL, $raw->toSql($connection));
        self::assertSame('d724ebccfad18ea577e7e9e4521a1cbfafc22d34ba3fb7a7ddd009c076f13010', $raw->hash($connection));
    }

    /** 利用例「アクティブな行だけで一意にする」の定義に、2つ目の生成カラム(VIRTUAL)を足したもの。 */
    private static function accounts(): TableDefinition
    {
        return TableDefinition::create('accounts')
            ->id()
            ->string('account_type', 20)
            ->string('status', 20)
            ->bigint('corp_id', nullable: true, unsigned: true)
            ->bigint('site_id', nullable: true, unsigned: true)
            ->datetime('created_at', default: 'CURRENT_TIMESTAMP')
            ->datetime('updated_at', default: 'CURRENT_TIMESTAMP', onUpdateCurrentTimestamp: true)
            ->generated(
                'corp_admin_active_key',
                'bigint(20) unsigned',
                "CASE WHEN account_type = 'corp_admin' AND status NOT IN ('expired','deleted') THEN corp_id ELSE NULL END",
                GeneratedColumnStorage::Stored,
            )
            ->generated(
                'site_role_active_key',
                'varchar(40)',
                "CASE WHEN account_type IN ('office_admin','office') AND status NOT IN ('expired','deleted') THEN CONCAT(account_type, ':', site_id) ELSE NULL END",
                GeneratedColumnStorage::Virtual,
            )
            ->unique('corp_admin_active_key', 'uq_corp_admin')
            ->unique('site_role_active_key', 'uq_site_role')
            ->charset('utf8mb4')
            ->collation('utf8mb4_unicode_ci');
    }

    private static function connection(): Connection
    {
        return new Connection(WpdbTestDouble::create());
    }
}
