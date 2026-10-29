<?php

declare(strict_types=1);

namespace TMSWP\Core\Model;

use TMSWP\Core\Exception\SchemaException;

final class TableDefinition
{
    /**
     * rawColumn() で拒否する型の別名(定義の先頭の語) => 案内する型名。DB はこれらを別の型名で記録し、dbDelta は宣言の
     * 型名と DB の型名を文字列で比べるため、適用の後に差分が永久に残る。MySQL 8.0.44・MariaDB 10.11.18・12.3.3 で
     * 確かめた(json は MariaDB が longtext として記録する)。2語の別名は、先頭の1語の別名より先に照らし合わせる。
     *
     * @var array<string, string>
     */
    private const array TYPE_ALIASES = [
        'character varying' => 'varchar',
        'char varying' => 'varchar',
        'long varchar' => 'mediumtext',
        'national' => 'char or varchar',
        'character' => 'char',
        'varcharacter' => 'varchar',
        'nchar' => 'char',
        'nvarchar' => 'varchar',
        'integer' => 'int',
        'int1' => 'tinyint',
        'int2' => 'smallint',
        'int3' => 'mediumint',
        'middleint' => 'mediumint',
        'int4' => 'int',
        'int8' => 'bigint',
        'numeric' => 'decimal',
        'dec' => 'decimal',
        'fixed' => 'decimal',
        'real' => 'double',
        'float8' => 'double',
        'float4' => 'float',
        'boolean' => 'tinyint(1)',
        'bool' => 'tinyint(1)',
        'long' => 'mediumtext',
        'json' => 'longtext',
    ];

    /**
     * rawColumn() で表示幅を求める整数の型 => 案内する表示幅付きの型。MariaDB は表示幅の無い整数の型を表示幅付きで記録し
     * (`int` は `int(11)`)、dbDelta の差分が残る。表示幅を付けた形は MySQL 8.0 でも dbDelta が収束する。
     *
     * @var array<string, string>
     */
    private const array INTEGER_WIDTHS = [
        'tinyint' => 'tinyint(4)',
        'smallint' => 'smallint(6)',
        'mediumint' => 'mediumint(9)',
        'int' => 'int(11)',
        'bigint' => 'bigint(20)',
    ];

    /** 既定値を数値として比べる列の型(rawColumn() の型の名前で判定する) */
    private const array NUMBER_TYPES = ['tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal', 'float', 'double'];

    /** 既定値を日時として比べる列の型(rawColumn() の型の名前で判定する) */
    private const array TEMPORAL_TYPES = ['date', 'datetime', 'timestamp', 'time', 'year'];

    /** @var list<string> */
    private array $columnLines = [];

    /** @var list<string> */
    private array $columnNames = [];

    /** @var array<string, string> */
    private array $columnTypes = [];

    /**
     * 列の NULL の可否・既定値・既定値の種類。組込のメソッドは引数の値をそのまま持つ。既定値は DB が SHOW FULL COLUMNS で
     * 返す形と比べられる値(文字列は引用符を外した値、bool は '0' / '1'、数値は10進の文字列)で、無ければ null。
     *
     * @var array<string, array{nullable: bool, default: string|null, kind: 'number'|'string'|'temporal'}>
     */
    private array $columnAttributes = [];

    /** @var list<array{type: 'KEY'|'UNIQUE KEY', name: string, columns: list<string>}> */
    private array $indexes = [];

    /** @var list<string>|null */
    private ?array $primaryColumns = null;

    private ?string $primaryKeyColumn = null;

    private ?string $charset = null;

    private ?string $collation = null;

    private function __construct(
        private readonly string $name,
    ) {
        Identifier::schemaBareTable($name);
    }

    public static function create(string $name): self
    {
        return new self($name);
    }

    public function id(string $name = 'id'): self
    {
        $this->addColumn($name, 'bigint(20) unsigned NOT NULL AUTO_INCREMENT', false, null, 'number');
        $this->setPrimary([$name]);
        $this->primaryKeyColumn = $name;

        return $this;
    }

    public function string(string $name, int $length = 191, bool $nullable = false, ?string $default = null): self
    {
        if ($length < 1) {
            throw new SchemaException('varchar length must be greater than 0');
        }

        return $this->addColumn($name, "varchar({$length})" . $this->nullAndDefault($nullable, $default), $nullable, $default, 'string');
    }

    public function text(string $name, bool $nullable = true): self
    {
        return $this->addColumn($name, 'text' . $this->nullAndDefault($nullable, null), $nullable, null, 'string');
    }

    public function int(string $name, bool $nullable = false, ?int $default = null, bool $unsigned = false): self
    {
        return $this->addColumn($name, 'int(11)' . ($unsigned ? ' unsigned' : '') . $this->nullAndDefault($nullable, $default), $nullable, $default === null ? null : (string) $default, 'number');
    }

    public function bigint(string $name, bool $nullable = false, ?int $default = null, bool $unsigned = false): self
    {
        return $this->addColumn($name, 'bigint(20)' . ($unsigned ? ' unsigned' : '') . $this->nullAndDefault($nullable, $default), $nullable, $default === null ? null : (string) $default, 'number');
    }

    public function decimal(
        string $name,
        int $precision = 10,
        int $scale = 2,
        bool $nullable = false,
        ?string $default = null,
        bool $unsigned = false,
    ): self {
        if ($precision < 1 || $scale < 0 || $scale > $precision) {
            throw new SchemaException('invalid decimal precision or scale');
        }

        // 既定値はそのまま SQL に書くため、10進の数の表記だけを受け付ける(指数・空白・+ の付いた表記は拒否する)
        if ($default !== null && preg_match('/\A-?\d+(\.\d+)?\z/', $default) !== 1) {
            throw new SchemaException('decimal default must be a decimal number string such as 12.50');
        }

        return $this->addColumn($name, "decimal({$precision},{$scale})" . ($unsigned ? ' unsigned' : '') . $this->nullAndDefault($nullable, $default, quoteString: false), $nullable, $default, 'number');
    }

    public function bool(string $name, bool $default = false): self
    {
        $value = $default ? '1' : '0';

        return $this->addColumn($name, 'tinyint(1) NOT NULL DEFAULT ' . $value, false, $value, 'number');
    }

    public function datetime(string $name, bool $nullable = false, ?string $default = null): self
    {
        return $this->addColumn($name, 'datetime' . $this->datetimeNullAndDefault($nullable, $default), $nullable, $default, 'temporal');
    }

    public function json(string $name, bool $nullable = true): self
    {
        return $this->addColumn($name, 'longtext' . $this->nullAndDefault($nullable, null), $nullable, null, 'string');
    }

    public function foreignId(string $name, bool $nullable = false): self
    {
        $this->addColumn($name, 'bigint(20) unsigned' . $this->nullAndDefault($nullable, null), $nullable, null, 'number');

        return $this->index($name, $name);
    }

    /**
     * 型以降の定義を書いた列。引用符・バッククォート・セミコロンは拒否する(そのため文字列の既定値は書けない)。
     *
     * DB が別の表記で記録し、dbDelta の差分が残り続ける型の表記は、宣言の時点で拒否する。型の別名(TYPE_ALIASES)、
     * `serial`、精度の引数が1つの `float(p)`・`double(p)`、表示幅の無い整数の型(INTEGER_WIDTHS)である。
     * NULL の可否と既定値は、定義の文字列の NOT NULL と最後の DEFAULT から読み、既定値の種類は型の名前で決める。
     *
     * @throws SchemaException
     */
    public function rawColumn(string $name, string $definition): self
    {
        if (preg_match('/[\'"`;]/', $definition) === 1) {
            throw new SchemaException('raw column definition must not contain quotes, backticks, or semicolons');
        }

        $normalized = strtolower((string) preg_replace('/\s+/', ' ', trim($definition)));

        foreach (self::TYPE_ALIASES as $alias => $canonical) {
            if (preg_match('/\A' . preg_quote($alias, '/') . '(?![a-z0-9_])/', $normalized) === 1) {
                throw new SchemaException("column '{$name}' uses the type alias '{$alias}', which the database stores under another name; use '{$canonical}' instead");
            }
        }

        $this->assertConvergingNotation($name, $normalized);
        preg_match('/\A[a-z]+/', $normalized, $type);
        $typeName = $type[0] ?? '';
        $kind = \in_array($typeName, self::NUMBER_TYPES, true) ? 'number' : (\in_array($typeName, self::TEMPORAL_TYPES, true) ? 'temporal' : 'string');

        // dbDelta は列名の後の1つの空白に続く語を型として読むため、前後の空白を除いて書く
        $definition = trim($definition);

        return $this->addColumn($name, $definition, preg_match('/\bnot\s+null\b/', $normalized) !== 1, self::lastDefault($definition), $kind);
    }

    public function primary(string ...$columns): self
    {
        $columns = array_values($columns);
        $this->setPrimary($this->normalizeColumns($columns));
        $this->primaryKeyColumn = \count($columns) === 1 ? $columns[0] : null;

        return $this;
    }

    /** @param string|list<string> $columns */
    public function index(string|array $columns, ?string $name = null): self
    {
        $columns = $this->normalizeColumns(\is_array($columns) ? $columns : [$columns]);
        $name ??= implode('_', $columns);
        Identifier::schemaSimple($name);
        $this->assertNameLength($name, 'index name');
        $this->assertIndexable($columns);
        $this->indexes[] = ['type' => 'KEY', 'name' => $name, 'columns' => $columns];

        return $this;
    }

    /** @param string|list<string> $columns */
    public function unique(string|array $columns, ?string $name = null): self
    {
        $columns = $this->normalizeColumns(\is_array($columns) ? $columns : [$columns]);
        $name ??= implode('_', $columns);
        Identifier::schemaSimple($name);
        $this->assertNameLength($name, 'index name');
        $this->assertIndexable($columns);
        $this->indexes[] = ['type' => 'UNIQUE KEY', 'name' => $name, 'columns' => $columns];

        return $this;
    }

    public function charset(string $charset): self
    {
        $this->charset = $this->charsetIdentifier($charset);

        return $this;
    }

    /** charset() の後に呼ぶ。charset を指定しない collation は、CREATE TABLE に出せないため宣言の時点で拒否する。 */
    public function collation(string $collation): self
    {
        if ($this->charset === null) {
            throw new SchemaException("collation requires charset to be declared first: {$collation}");
        }

        $this->collation = $this->charsetIdentifier($collation);

        return $this;
    }

    public function tableName(): string
    {
        return $this->name;
    }

    public function primaryKey(): ?string
    {
        return $this->primaryKeyColumn;
    }

    /** @return list<string> */
    public function columns(): array
    {
        return $this->columnNames;
    }

    /** @return list<string> */
    public function primaryColumns(): array
    {
        if ($this->primaryColumns === null) {
            throw new SchemaException('table must declare a primary key');
        }

        return $this->primaryColumns;
    }

    /** @return list<array{type: 'KEY'|'UNIQUE KEY', name: string, columns: list<string>}> */
    public function indexes(): array
    {
        return $this->indexes;
    }

    public function toSql(Connection $connection): string
    {
        if ($this->columnLines === []) {
            throw new SchemaException('table must declare at least one column');
        }

        $primaryColumns = $this->primaryColumns;

        if ($primaryColumns === null) {
            throw new SchemaException('table must declare a primary key');
        }

        $fullName = $connection->tableName($this->name);
        $this->assertNameLength($fullName, 'full table name');
        $lines = $this->columnLines;
        $lines[] = 'PRIMARY KEY  (' . implode(',', $primaryColumns) . ')';

        // UNIQUE KEYの行(宣言順)の後にKEYの行(宣言順)を置く
        foreach (['UNIQUE KEY', 'KEY'] as $type) {
            foreach ($this->indexes as $index) {
                if ($index['type'] === $type) {
                    $lines[] = "{$index['type']} {$index['name']} (" . implode(',', $index['columns']) . ')';
                }
            }
        }

        return 'CREATE TABLE ' . $fullName . " (\n"
            . implode(",\n", $lines)
            . "\n) " . $this->charsetClause($connection) . ';';
    }

    public function hash(Connection $connection): string
    {
        return hash('sha256', $this->toSql($connection));
    }

    /**
     * 列が NULL を許すか。組込のメソッドは引数の値、rawColumn() は定義に NOT NULL が無ければ true。主キーの列は DB が
     * NOT NULL にするため false。
     *
     * @throws SchemaException 列が無い場合
     */
    public function columnNullable(string $name): bool
    {
        return $this->columnAttributes($name)['nullable'];
    }

    /**
     * 列の既定値。DB が SHOW FULL COLUMNS で返す形と比べられる値(文字列は引用符を外した値、bool は '0' / '1'、数値は
     * 10進の文字列、日時は `CURRENT_TIMESTAMP` 等の書いたままの値)。DEFAULT が無いか DEFAULT NULL なら null。
     *
     * @throws SchemaException 列が無い場合
     */
    public function columnDefault(string $name): ?string
    {
        return $this->columnAttributes($name)['default'];
    }

    /**
     * 既定値の比べ方を決める列の種類。number は10進の数として、string はそのまま、temporal は CURRENT_TIMESTAMP の表記を
     * そろえて比べる。
     *
     * @throws SchemaException 列が無い場合
     *
     * @return 'number'|'string'|'temporal'
     */
    public function columnKind(string $name): string
    {
        return $this->columnAttributes($name)['kind'];
    }

    /** collation() で宣言した照合順序。宣言していなければ null。 */
    public function declaredCollation(): ?string
    {
        return $this->collation;
    }

    public function hasColumn(string $name): bool
    {
        return \in_array($name, $this->columnNames, true);
    }

    public function columnType(string $name): string
    {
        if (!\array_key_exists($name, $this->columnTypes)) {
            throw new SchemaException("unknown column: {$name}");
        }

        return $this->columnTypes[$name];
    }

    /** @param 'number'|'string'|'temporal' $kind 既定値の比べ方を決める種類 */
    private function addColumn(string $name, string $definition, bool $nullable, ?string $default, string $kind): self
    {
        Identifier::schemaSimple($name);

        if (\in_array($name, $this->columnNames, true)) {
            throw new SchemaException("duplicate column: {$name}");
        }

        $typeParts = explode(' ', $definition, 2);
        $this->columnNames[] = $name;
        $this->columnTypes[$name] = $typeParts[0] === '' ? $definition : $typeParts[0];
        $this->columnLines[] = "{$name} {$definition}";
        $this->columnAttributes[$name] = ['nullable' => $nullable, 'default' => $default, 'kind' => $kind];

        return $this;
    }

    /**
     * rawColumn() の定義の最後の DEFAULT の値(MySQL は最後の DEFAULT を使う)。rawColumn() は引用符を拒否するため、値は
     * 空白までの1語である。DEFAULT が無いか DEFAULT NULL なら null。
     */
    private static function lastDefault(string $definition): ?string
    {
        if (preg_match_all('/\bDEFAULT\s+(\S+)/i', $definition, $matches) < 1) {
            return null;
        }

        $value = end($matches[1]);

        return $value === false || strcasecmp($value, 'NULL') === 0 ? null : $value;
    }

    /**
     * DB が別の表記で記録し、dbDelta の差分が残り続ける rawColumn() の型の表記を拒否する(MySQL 8.0.44・MariaDB 10.11.18・
     * 12.3.3 で確かめた)。
     *
     * @param string $normalized 小文字にし、連続する空白を1つにした定義
     *
     * @throws SchemaException
     */
    private function assertConvergingNotation(string $name, string $normalized): void
    {
        if (preg_match('/\Aserial(?![a-z0-9_])/', $normalized) === 1) {
            throw new SchemaException("column '{$name}' uses the type 'serial', which the database stores as bigint unsigned with AUTO_INCREMENT; use id() or 'bigint(20) unsigned NOT NULL AUTO_INCREMENT' instead");
        }

        if (preg_match('/\A(float|double) ?\( ?(\d+) ?\)/', $normalized, $matches) === 1) {
            throw new SchemaException("column '{$name}' uses '{$matches[1]}({$matches[2]})' with a single precision argument, which the database stores as float or double; use 'float' for a precision up to 24 or 'double' for 25 to 53 instead");
        }

        if (preg_match('/\A(tinyint|smallint|mediumint|int|bigint)(?![a-z0-9_(])/', $normalized, $matches) === 1) {
            throw new SchemaException("column '{$name}' uses the integer type '{$matches[1]}' without a display width, which MariaDB stores with one; use '" . self::INTEGER_WIDTHS[$matches[1]] . "' instead");
        }
    }

    /**
     * @throws SchemaException 列が無い場合
     *
     * @return array{nullable: bool, default: string|null, kind: 'number'|'string'|'temporal'}
     */
    private function columnAttributes(string $name): array
    {
        if (!\array_key_exists($name, $this->columnAttributes)) {
            throw new SchemaException("unknown column: {$name}");
        }

        $attributes = $this->columnAttributes[$name];

        if (\in_array($name, $this->primaryColumns ?? [], true)) {
            $attributes['nullable'] = false;
        }

        return $attributes;
    }

    /** @param list<string> $columns */
    private function setPrimary(array $columns): void
    {
        if ($columns === []) {
            throw new SchemaException('primary key must contain at least one column');
        }

        if ($this->primaryColumns !== null) {
            throw new SchemaException('primary key is already declared');
        }

        $this->assertColumnsExist($columns);
        $this->primaryColumns = $columns;
    }

    /**
     * @param list<string> $columns
     *
     * @return list<string>
     */
    private function normalizeColumns(array $columns): array
    {
        if ($columns === []) {
            throw new SchemaException('column list must not be empty');
        }

        $normalized = [];

        foreach ($columns as $column) {
            Identifier::schemaSimple($column);
            $normalized[] = $column;
        }

        $this->assertColumnsExist($normalized);

        return $normalized;
    }

    /** @param list<string> $columns */
    private function assertColumnsExist(array $columns): void
    {
        foreach ($columns as $column) {
            if (!\in_array($column, $this->columnNames, true)) {
                throw new SchemaException("unknown column for key: {$column}");
            }
        }
    }

    /** @param list<string> $columns */
    private function assertIndexable(array $columns): void
    {
        foreach ($columns as $column) {
            $type = $this->columnTypes[$column] ?? '';

            if (\in_array($type, ['text', 'longtext'], true)) {
                throw new SchemaException("column '{$column}' cannot be indexed");
            }
        }
    }

    private function nullAndDefault(bool $nullable, int|string|null $default, bool $quoteString = true): string
    {
        $sql = $nullable ? ' DEFAULT NULL' : ' NOT NULL';

        if ($default !== null) {
            $sql .= ' DEFAULT ' . $this->literal($default, $quoteString);
        }

        return $sql;
    }

    private function datetimeNullAndDefault(bool $nullable, ?string $default): string
    {
        $sql = $nullable ? ' DEFAULT NULL' : ' NOT NULL';

        if ($default !== null) {
            $sql .= ' DEFAULT ' . ($default === 'CURRENT_TIMESTAMP' ? 'CURRENT_TIMESTAMP' : $this->literal($default));
        }

        return $sql;
    }

    private function literal(int|string $value, bool $quoteString = true): string
    {
        if (\is_int($value) || !$quoteString) {
            return (string) $value;
        }

        if (str_contains($value, "'")) {
            throw new SchemaException('string default must not contain single quote');
        }

        return "'{$value}'";
    }

    private function charsetIdentifier(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $value) !== 1) {
            throw new SchemaException("invalid charset or collation: {$value}");
        }

        return $value;
    }

    private function charsetClause(Connection $connection): string
    {
        if ($this->charset === null) {
            return $connection->charsetCollate();
        }

        $clause = 'DEFAULT CHARACTER SET ' . $this->charset;

        if ($this->collation !== null) {
            $clause .= ' COLLATE ' . $this->collation;
        }

        return $clause;
    }

    private function assertNameLength(string $name, string $label): void
    {
        if (\strlen($name) > 64) {
            throw new SchemaException("{$label} exceeds 64 characters: {$name}");
        }
    }
}
