<?php

declare(strict_types=1);

namespace TMSWP\Core\Model;

use BackedEnum;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use JsonException;
use ReflectionClass;
use ReflectionEnum;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use TMSWP\Core\Exception\HydrationException;
use TMSWP\Core\Exception\InvalidQueryException;
use TMSWP\Core\Exception\QueryException;
use TMSWP\Core\Query\SortOrder;
use ValueError;

/**
 * @phpstan-type RowParameter array{
 *     name: string,
 *     column: string,
 *     parameter: ReflectionParameter,
 *     type: ReflectionType|null,
 *     hasDefault: bool,
 * }
 */
final class QueryBuilder
{
    /**
     * 行クラスのコンストラクタの引数の情報の、クラス別キャッシュ。クラスの定義だけで決まる不変の情報なので、
     * 行ごと・クエリごとにReflectionで求め直さない。引数の型のクラスは読み込まず、enumかどうかは変換の位置で求める。
     *
     * @var array<class-string, array{class: ReflectionClass<object>, parameters: list<RowParameter>}>
     */
    private static array $rowConstructors = [];

    /**
     * 変換に使ったbacked enumのbacking type('int'か'string')の、enumのクラス別キャッシュ。enumであると分かったクラス
     * だけを入れる(enumでないという結果は、後からクラスが読み込まれうるため入れない)。
     *
     * @var array<class-string<BackedEnum>, string>
     */
    private static array $enumBackingTypes = [];

    /** @var list<string> */
    private array $selects = [];

    /** @var list<mixed> */
    private array $selectBindings = [];

    private ?string $fromAlias = null;

    /** @var list<string> */
    private array $joins = [];

    /** @var list<mixed> */
    private array $joinBindings = [];

    /** @var list<array{0: 'AND'|'OR', 1: string}> */
    private array $wheres = [];

    /** @var list<mixed> */
    private array $whereBindings = [];

    /** @var list<string> */
    private array $groups = [];

    /** @var list<string> */
    private array $orders = [];

    private ?int $limitValue = null;

    private ?int $offsetValue = null;

    /** @var class-string|null */
    private ?string $rowClass;

    /**
     * @param class-string|null $rowClass
     *
     * @throws HydrationException $rowClass が存在しないクラスの場合
     * @throws InvalidQueryException $rowClass が Model のサブクラスの場合(as() と同じ検査)
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
        ?string $rowClass = null,
    ) {
        Identifier::bareTable($table);
        $this->rowClass = $rowClass === null ? null : self::rowClassOrFail($rowClass);
    }

    public function alias(string $alias): self
    {
        $this->fromAlias = Identifier::simple($alias);

        return $this;
    }

    public function select(string ...$columns): self
    {
        if ($columns === []) {
            throw new InvalidQueryException('select requires at least one column');
        }

        $this->selects = [];

        foreach ($columns as $column) {
            $this->selects[] = Identifier::selectable($column);
        }

        $this->selectBindings = [];

        return $this;
    }

    /**
     * @param literal-string $expression
     * @param list<mixed> $bindings
     */
    public function selectRaw(string $expression, array $bindings = []): self
    {
        $this->assertRaw($expression, $bindings);
        $this->selects[] = $expression;
        array_push($this->selectBindings, ...$bindings);

        return $this;
    }

    public function where(string|Closure $column, mixed $operator = null, mixed $value = null): self
    {
        return $this->addWhere('AND', $column, \func_num_args(), $operator, $value);
    }

    public function orWhere(string|Closure $column, mixed $operator = null, mixed $value = null): self
    {
        return $this->addWhere('OR', $column, \func_num_args(), $operator, $value);
    }

    /** @param list<mixed> $values */
    public function whereIn(string $column, array $values): self
    {
        return $this->whereInClause('AND', $column, $values, false);
    }

    /** @param list<mixed> $values */
    public function whereNotIn(string $column, array $values): self
    {
        return $this->whereInClause('AND', $column, $values, true);
    }

    public function whereNull(string $column): self
    {
        return $this->pushWhere('AND', Identifier::qualified($column) . ' IS NULL');
    }

    public function whereNotNull(string $column): self
    {
        return $this->pushWhere('AND', Identifier::qualified($column) . ' IS NOT NULL');
    }

    public function whereBetween(string $column, int|float|string|DateTimeInterface $min, int|float|string|DateTimeInterface $max): self
    {
        return $this->pushWhere(
            'AND',
            Identifier::qualified($column) . ' BETWEEN ' . Connection::placeholderFor($min) . ' AND ' . Connection::placeholderFor($max),
            [$min, $max],
        );
    }

    public function whereLike(string $column, string $pattern): self
    {
        return $this->where($column, 'LIKE', $pattern);
    }

    /**
     * @param literal-string $sql
     * @param list<mixed> $bindings
     */
    public function whereRaw(string $sql, array $bindings = []): self
    {
        $this->assertRaw($sql, $bindings);

        return $this->pushWhere('AND', $sql, $bindings);
    }

    /**
     * @param literal-string $sql
     * @param list<mixed> $bindings
     */
    public function orWhereRaw(string $sql, array $bindings = []): self
    {
        $this->assertRaw($sql, $bindings);

        return $this->pushWhere('OR', $sql, $bindings);
    }

    public function innerJoin(string $table, string $left, string $operator, string $right, bool $prefix = true): self
    {
        return $this->join('INNER JOIN', $table, Identifier::qualified($left) . ' ' . $this->joinOperator($operator) . ' ' . Identifier::qualified($right), [], $prefix);
    }

    public function leftJoin(string $table, string $left, string $operator, string $right, bool $prefix = true): self
    {
        return $this->join('LEFT JOIN', $table, Identifier::qualified($left) . ' ' . $this->joinOperator($operator) . ' ' . Identifier::qualified($right), [], $prefix);
    }

    /**
     * @param literal-string $on
     * @param list<mixed> $bindings
     */
    public function innerJoinRaw(string $table, string $on, array $bindings = [], bool $prefix = true): self
    {
        $this->assertRaw($on, $bindings);

        return $this->join('INNER JOIN', $table, $on, $bindings, $prefix);
    }

    /**
     * @param literal-string $on
     * @param list<mixed> $bindings
     */
    public function leftJoinRaw(string $table, string $on, array $bindings = [], bool $prefix = true): self
    {
        $this->assertRaw($on, $bindings);

        return $this->join('LEFT JOIN', $table, $on, $bindings, $prefix);
    }

    public function groupBy(string ...$columns): self
    {
        if ($columns === []) {
            throw new InvalidQueryException('groupBy requires at least one column');
        }

        foreach ($columns as $column) {
            $this->groups[] = Identifier::qualified($column);
        }

        return $this;
    }

    public function orderBy(string $column, SortOrder $direction = SortOrder::Asc): self
    {
        $this->orders[] = Identifier::qualified($column) . ' ' . $direction->value;

        return $this;
    }

    public function orderByDesc(string $column): self
    {
        return $this->orderBy($column, SortOrder::Desc);
    }

    public function limit(int $limit): self
    {
        if ($limit < 0) {
            throw new InvalidQueryException('limit must be 0 or greater');
        }

        $this->limitValue = $limit;

        return $this;
    }

    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new InvalidQueryException('offset must be 0 or greater');
        }

        $this->offsetValue = $offset;

        return $this;
    }

    /**
     * @param class-string<object> $class
     *
     * @throws HydrationException $class が存在しないクラスの場合
     * @throws InvalidQueryException $class が Model のサブクラスの場合(Model は行を表さない)
     */
    public function as(string $class): self
    {
        $this->rowClass = self::rowClassOrFail($class);

        return $this;
    }

    /** @return list<object> */
    public function get(): array
    {
        $query = $this->buildSelect();
        $rows = $this->connection->select($query->sql, $query->bindings);
        $objects = [];

        foreach ($rows as $row) {
            $objects[] = $this->hydrate($row);
        }

        return $objects;
    }

    public function first(): ?object
    {
        $clone = clone $this;
        $clone->limit(1);

        return $clone->get()[0] ?? null;
    }

    /**
     * 先頭行の指定カラムの値。SELECT 句が未指定ならそのカラムだけを選び、指定済みなら SELECT 句を保って
     * 結果のキーと照合する。結果が0行なら null、行に照合するキーが無ければ QueryException。
     */
    public function value(string $column): mixed
    {
        $key = $this->resultKey($column);
        $clone = clone $this;

        if ($clone->selects === []) {
            $clone->select($column);
        }

        $clone->limit(1);
        $query = $clone->buildSelect();
        $row = $this->connection->selectRow($query->sql, $query->bindings);

        if ($row === null) {
            return null;
        }

        return $this->resultColumn($row, $key);
    }

    /**
     * 単一カラムの値のリスト。$keyColumn を指定すると、その値をキーにする(キーの重複は後勝ち)。
     * SELECT 句の扱いは value() と同じ。行に照合するキーが無い場合と、キーの値が int・string でない場合は
     * QueryException。
     *
     * @return array<int|string, mixed>
     */
    public function pluck(string $column, ?string $keyColumn = null): array
    {
        $valueKey = $this->resultKey($column);
        $keyKey = $keyColumn === null ? null : $this->resultKey($keyColumn);
        $values = [];

        $clone = clone $this;

        if ($clone->selects === []) {
            $clone->select(...($keyColumn === null ? [$column] : [$column, $keyColumn]));
        }

        $query = $clone->buildSelect();

        foreach ($this->connection->select($query->sql, $query->bindings) as $row) {
            $value = $this->resultColumn($row, $valueKey);

            if ($keyKey === null) {
                $values[] = $value;

                continue;
            }

            $key = $this->resultColumn($row, $keyKey);

            if (!\is_int($key) && !\is_string($key)) {
                throw new QueryException("pluck key column '{$keyKey}' must be an integer or a string, " . get_debug_type($key) . ' given');
            }

            $values[$key] = $value;
        }

        return $values;
    }

    public function count(): int
    {
        $query = $this->buildCount();
        $value = $this->connection->selectVar($query->sql, $query->bindings);

        if (\is_int($value)) {
            return $value;
        }

        if (\is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new QueryException('count query returned a non-integer value');
    }

    public function exists(): bool
    {
        $query = $this->buildSelect('1', 1);

        return $this->connection->selectVar($query->sql, $query->bindings) !== null;
    }

    /** @param array<string, mixed> $values */
    public function update(array $values): int
    {
        $this->assertWritableQuery($values);
        $assignments = [];
        $bindings = [];

        foreach ($values as $column => $value) {
            Identifier::simple($column);

            if ($value === null) {
                $assignments[] = $column . ' = NULL';

                continue;
            }

            $assignments[] = $column . ' = ' . Connection::placeholderFor($value);
            $bindings[] = $value;
        }

        [$whereSql, $whereBindings] = $this->compileWhere();

        return $this->connection->execute(
            'UPDATE ' . $this->connection->tableName($this->table) . ' SET ' . implode(', ', $assignments) . ' WHERE ' . $whereSql,
            [...$bindings, ...$whereBindings],
        );
    }

    public function delete(): int
    {
        $this->assertWritableQuery(['delete' => true]);
        [$whereSql, $whereBindings] = $this->compileWhere();

        return $this->connection->execute(
            'DELETE FROM ' . $this->connection->tableName($this->table) . ' WHERE ' . $whereSql,
            $whereBindings,
        );
    }

    public function toSql(): string
    {
        $query = $this->buildSelect();

        return $this->connection->prepare($query->sql, $query->bindings);
    }

    private function addWhere(string $boolean, string|Closure $column, int $argCount, mixed $operator, mixed $value): self
    {
        if ($argCount === 1) {
            if (!$column instanceof Closure) {
                throw new InvalidQueryException('where requires a value, or use whereNull()');
            }

            return $this->whereGroup($boolean, $column);
        }

        if (!$column instanceof Closure && $argCount === 2) {
            return $operator === null
                ? $this->pushWhere($boolean, Identifier::qualified($column) . ' IS NULL')
                : $this->pushComparison($boolean, $column, '=', $operator);
        }

        if ($column instanceof Closure || $argCount !== 3 || !\is_string($operator)) {
            throw new InvalidQueryException('invalid where arguments');
        }

        return $this->pushComparison($boolean, $column, $operator, $value);
    }

    private function pushComparison(string $boolean, string $column, string $operator, mixed $value): self
    {
        if (\is_array($value)) {
            throw new InvalidQueryException('where value must not be array');
        }

        $column = Identifier::qualified($column);
        $operator = $this->operator($operator);

        if ($value === null) {
            return match ($operator) {
                '=' => $this->pushWhere($boolean, "{$column} IS NULL"),
                '!=', '<>' => $this->pushWhere($boolean, "{$column} IS NOT NULL"),
                default => throw new InvalidQueryException('null is allowed only with =, !=, or <>'),
            };
        }

        return $this->pushWhere($boolean, "{$column} {$operator} " . Connection::placeholderFor($value), [$value]);
    }

    /**
     * 行クラスの検査。行クラスを受け取る入口(コンストラクタと as())はすべてこれを通す。
     *
     * @param class-string $class
     *
     * @return class-string
     */
    private static function rowClassOrFail(string $class): string
    {
        if (!class_exists($class)) {
            throw new HydrationException("row class not found: {$class}");
        }

        if (is_a($class, Model::class, true)) {
            throw new InvalidQueryException("row class must not be a Model; declare a separate row class: {$class}");
        }

        return $class;
    }

    /** @param list<mixed> $bindings */
    private function pushWhere(string $boolean, string $sql, array $bindings = []): self
    {
        $this->wheres[] = [$boolean === 'OR' ? 'OR' : 'AND', $sql];
        array_push($this->whereBindings, ...$bindings);

        return $this;
    }

    private function whereGroup(string $boolean, Closure $group): self
    {
        $child = new self($this->connection, $this->table, $this->rowClass);
        $group($child);

        if ($child->selects !== [] || $child->fromAlias !== null || $child->joins !== [] || $child->groups !== [] || $child->orders !== [] || $child->limitValue !== null || $child->offsetValue !== null) {
            throw new InvalidQueryException('where group can contain only where clauses');
        }

        [$sql, $bindings] = $child->compileWhere();

        return $this->pushWhere($boolean, '(' . $sql . ')', $bindings);
    }

    /** @param list<mixed> $values */
    private function whereInClause(string $boolean, string $column, array $values, bool $not): self
    {
        $column = Identifier::qualified($column);

        if (!array_is_list($values)) {
            throw new InvalidQueryException('whereIn values must be a list');
        }

        if ($values === []) {
            return $this->pushWhere($boolean, $not ? '1 = 1' : '0 = 1');
        }

        $placeholders = [];

        foreach ($values as $value) {
            if ($value === null || \is_array($value)) {
                throw new InvalidQueryException('IN values must not contain null or array');
            }

            $placeholders[] = Connection::placeholderFor($value);
        }

        return $this->pushWhere($boolean, $column . ($not ? ' NOT IN ' : ' IN ') . '(' . implode(', ', $placeholders) . ')', $values);
    }

    /**
     * @param list<mixed> $bindings
     */
    private function join(string $type, string $table, string $on, array $bindings, bool $prefix): self
    {
        [$tableName, $alias] = $this->parseJoinTable($table, $prefix);
        $this->joins[] = "{$type} {$tableName}" . ($alias === null ? '' : " AS {$alias}") . " ON {$on}";
        array_push($this->joinBindings, ...$bindings);

        return $this;
    }

    /** @return array{0: string, 1: string|null} */
    private function parseJoinTable(string $table, bool $prefix): array
    {
        if (preg_match('/\A([A-Za-z_][A-Za-z0-9_]*|[a-z][a-z0-9_]*)(?:\s+(?i:AS)\s+([A-Za-z_][A-Za-z0-9_]*))?\z/', $table, $matches) !== 1) {
            throw new InvalidQueryException("invalid join table: {$table}");
        }

        $name = $matches[1];
        $alias = $matches[2] ?? null;

        return [$prefix ? $this->connection->tableName($name) : Identifier::table($name), $alias];
    }

    private function operator(string $operator): string
    {
        $normalized = strtoupper($operator);

        if (!\in_array($normalized, ['=', '!=', '<>', '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE'], true)) {
            throw new InvalidQueryException("invalid where operator: {$operator}");
        }

        return $normalized;
    }

    private function joinOperator(string $operator): string
    {
        $normalized = $this->operator($operator);

        if (!\in_array($normalized, ['=', '!=', '<>', '>', '>=', '<', '<='], true)) {
            throw new InvalidQueryException("invalid join operator: {$operator}");
        }

        return $normalized;
    }

    /**
     * @param list<mixed> $bindings
     */
    private function assertRaw(string $sql, array $bindings): void
    {
        if (preg_match('/[\'"`;]/', $sql) === 1) {
            throw new InvalidQueryException('raw SQL fragment must not contain quotes, backticks, or semicolons');
        }

        $this->connection->prepare($sql, $bindings);
    }

    /** @return array{0: string, 1: list<mixed>} */
    private function compileWhere(): array
    {
        if ($this->wheres === []) {
            throw new InvalidQueryException('where clause is required');
        }

        $parts = [];

        foreach ($this->wheres as $index => [$boolean, $sql]) {
            $parts[] = ($index === 0 ? '' : " {$boolean} ") . $sql;
        }

        return [implode('', $parts), $this->whereBindings];
    }

    /**
     * ORDER BY・LIMIT・OFFSET までの SELECT 文。$selectOverride を渡すと SELECT 句とその値を置き換え、
     * $limitOverride を渡すと limit() の値を置き換える。
     */
    private function buildSelect(?string $selectOverride = null, ?int $limitOverride = null): SqlQuery
    {
        $limit = $limitOverride ?? $this->limitValue;

        if ($this->offsetValue !== null && $limit === null) {
            throw new InvalidQueryException('offset requires limit');
        }

        $query = $selectOverride === null
            ? $this->buildSelectBody($this->selects === [] ? '*' : implode(', ', $this->selects), $this->selectBindings)
            : $this->buildSelectBody($selectOverride, []);
        $sql = $query->sql;
        $bindings = $query->bindings;

        if ($this->orders !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orders);
        }

        if ($limit !== null) {
            $sql .= ' LIMIT %d';
            $bindings[] = $limit;
        }

        if ($this->offsetValue !== null) {
            $sql .= ' OFFSET %d';
            $bindings[] = $this->offsetValue;
        }

        return new SqlQuery($sql, $bindings);
    }

    /**
     * 件数の SELECT 文。SELECT 句・ORDER BY・LIMIT・OFFSET を捨てる。GROUP BY があればグループの数を数える。
     */
    private function buildCount(): SqlQuery
    {
        if ($this->groups === []) {
            return $this->buildSelectBody('COUNT(*)', []);
        }

        $inner = $this->buildSelectBody('1', []);

        return new SqlQuery('SELECT COUNT(*) FROM (' . $inner->sql . ') AS tmswp_count', $inner->bindings);
    }

    /**
     * SELECT 句から GROUP BY までの SELECT 文。
     *
     * @param list<mixed> $selectBindings
     */
    private function buildSelectBody(string $selects, array $selectBindings): SqlQuery
    {
        $sql = 'SELECT ' . $selects . ' FROM ' . $this->connection->tableName($this->table);

        if ($this->fromAlias !== null) {
            $sql .= ' AS ' . $this->fromAlias;
        }

        if ($this->joins !== []) {
            $sql .= ' ' . implode(' ', $this->joins);
        }

        $bindings = [...$selectBindings, ...$this->joinBindings];

        if ($this->wheres !== []) {
            [$whereSql, $whereBindings] = $this->compileWhere();
            $sql .= ' WHERE ' . $whereSql;
            array_push($bindings, ...$whereBindings);
        }

        if ($this->groups !== []) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groups);
        }

        return new SqlQuery($sql, $bindings);
    }

    /** @param array<string, mixed> $values */
    private function assertWritableQuery(array $values): void
    {
        if ($values === []) {
            throw new InvalidQueryException('write query requires values');
        }

        if ($this->joins !== [] || $this->groups !== [] || $this->orders !== [] || $this->limitValue !== null || $this->offsetValue !== null || $this->fromAlias !== null) {
            throw new InvalidQueryException('write query does not support join, group, order, limit, offset, or alias');
        }

        if ($this->wheres === []) {
            throw new InvalidQueryException('write query requires where clause');
        }
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): object
    {
        if ($this->rowClass === null) {
            return (object) $row;
        }

        $constructor = self::rowConstructor($this->rowClass);
        $arguments = [];

        foreach ($constructor['parameters'] as $parameter) {
            $arguments[] = $this->resolveParameter($parameter, $row);
        }

        return $constructor['class']->newInstanceArgs($arguments);
    }

    /**
     * 行クラスとそのコンストラクタの引数の情報。クラスごとに1回だけReflectionで求め、2回目からはキャッシュを返す。
     * 既定値そのものは持たず、使うたびにReflectionParameterから得る(new式の既定値を行の間で共有しないため)。
     *
     * @param class-string $class
     *
     * @throws HydrationException コンストラクタが無いかpublicでない場合
     *
     * @return array{class: ReflectionClass<object>, parameters: list<RowParameter>}
     */
    private static function rowConstructor(string $class): array
    {
        if (isset(self::$rowConstructors[$class])) {
            return self::$rowConstructors[$class];
        }

        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        if ($constructor === null || !$constructor->isPublic()) {
            throw new HydrationException("row class '{$class}' requires a public constructor");
        }

        $parameters = [];

        foreach ($constructor->getParameters() as $parameter) {
            $parameters[] = [
                'name' => $parameter->getName(),
                'column' => self::snake($parameter->getName()),
                'parameter' => $parameter,
                'type' => $parameter->getType(),
                'hasDefault' => $parameter->isDefaultValueAvailable(),
            ];
        }

        return self::$rowConstructors[$class] = ['class' => $reflection, 'parameters' => $parameters];
    }

    /**
     * @param RowParameter $parameter
     * @param array<string, mixed> $row
     */
    private function resolveParameter(array $parameter, array $row): mixed
    {
        $name = $parameter['name'];
        $key = \array_key_exists($name, $row) ? $name : $parameter['column'];

        if (!\array_key_exists($key, $row)) {
            if ($parameter['hasDefault']) {
                return $parameter['parameter']->getDefaultValue();
            }

            throw new HydrationException("missing column '{$name}' for row class '{$this->rowClass}'; available columns: " . implode(', ', array_keys($row)));
        }

        return $this->coerce($row[$key], $parameter);
    }

    /** @param RowParameter $parameter */
    private function coerce(mixed $value, array $parameter): mixed
    {
        $type = $parameter['type'];
        $parameterName = $parameter['name'];

        if (!$type instanceof ReflectionNamedType) {
            if ($type === null) {
                return $value;
            }

            throw new HydrationException("unsupported parameter type for '{$parameterName}'");
        }

        if ($value === null) {
            if ($type->allowsNull()) {
                return null;
            }

            throw new HydrationException("non-nullable parameter '{$parameterName}' received null");
        }

        $name = $type->getName();

        if ($type->isBuiltin()) {
            return match ($name) {
                'int' => $this->coerceInt($value, $parameterName),
                'float' => $this->coerceFloat($value, $parameterName),
                'string' => $this->coerceString($value, $parameterName),
                'bool' => $this->coerceBool($value, $parameterName),
                'array' => $this->decodeJson($this->coerceString($value, $parameterName), $parameterName),
                'mixed' => $value,
                default => throw new HydrationException("unsupported parameter type '{$name}'"),
            };
        }

        if ($name === DateTimeImmutable::class || $name === DateTimeInterface::class) {
            return $this->dateTime($this->coerceString($value, $parameterName), $parameterName);
        }

        if (is_subclass_of($name, BackedEnum::class)) {
            // DB の値は string で届くため、int を値に持つ enum では int に変換してから from() に渡す
            $backingValue = self::enumBackingType($name) === 'int'
                ? $this->coerceInt($value, $parameterName)
                : $this->coerceString($value, $parameterName);

            try {
                return $name::from($backingValue);
            } catch (ValueError $exception) {
                throw new HydrationException("invalid enum value for '{$parameterName}'", previous: $exception);
            }
        }

        throw new HydrationException("unsupported parameter class '{$name}'");
    }

    /**
     * backed enumのbacking type('int'か'string')。enumのクラスごとに1回だけReflectionで求める。
     *
     * @param class-string<BackedEnum> $enum
     */
    private static function enumBackingType(string $enum): string
    {
        return self::$enumBackingTypes[$enum] ??= (string) (new ReflectionEnum($enum))->getBackingType();
    }

    private function coerceInt(mixed $value, string $parameter): int
    {
        if (\is_int($value)) {
            return $value;
        }

        if (\is_string($value) && preg_match('/\A(0|-?[1-9][0-9]*)\z/', $value) === 1) {
            $integer = (int) $value;

            // PHP の int の範囲を超える値(bigint unsigned の上半分等)は、(int) で上限に丸められるため拒否する
            if ((string) $integer !== $value) {
                throw new HydrationException("integer out of range for '{$parameter}'");
            }

            return $integer;
        }

        throw new HydrationException("invalid integer for '{$parameter}'");
    }

    private function coerceFloat(mixed $value, string $parameter): float
    {
        if (\is_float($value)) {
            return $value;
        }

        if (\is_int($value)) {
            return (float) $value;
        }

        if (\is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        throw new HydrationException("invalid float for '{$parameter}'");
    }

    private function coerceString(mixed $value, string $parameter): string
    {
        if (\is_string($value)) {
            return $value;
        }

        throw new HydrationException("invalid string for '{$parameter}'");
    }

    private function coerceBool(mixed $value, string $parameter): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        if ($value === 0 || $value === '0') {
            return false;
        }

        if ($value === 1 || $value === '1') {
            return true;
        }

        throw new HydrationException("invalid boolean for '{$parameter}'");
    }

    /** @return array<mixed> */
    private function decodeJson(string $value, string $parameter): array
    {
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new HydrationException("invalid JSON for '{$parameter}'", previous: $exception);
        }

        if (!\is_array($decoded)) {
            throw new HydrationException("JSON value for '{$parameter}' must decode to array");
        }

        return $decoded;
    }

    private function dateTime(string $value, string $parameter): DateTimeImmutable
    {
        if (!\function_exists('wp_timezone')) {
            throw new HydrationException('wp_timezone is not available');
        }

        // createFromFormat()はNULを含む文字列でValueErrorを投げるため、呼ぶ前に変換できない値として拒否する
        if (str_contains($value, "\0")) {
            throw new HydrationException("invalid datetime for '{$parameter}'");
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, wp_timezone());
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false || (\is_array($errors) && (($errors['warning_count'] ?? 0) !== 0 || ($errors['error_count'] ?? 0) !== 0))) {
            throw new HydrationException("invalid datetime for '{$parameter}'");
        }

        return $date;
    }

    private static function snake(string $name): string
    {
        $snake = preg_replace('/(?<=[a-z0-9])([A-Z])/', '_$1', $name);

        if (!\is_string($snake)) {
            throw new HydrationException("failed to resolve column name for '{$name}'");
        }

        return strtolower($snake);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resultColumn(array $row, string $key): mixed
    {
        if (!\array_key_exists($key, $row)) {
            throw new QueryException("result row has no column '{$key}'; available columns: " . implode(', ', array_keys($row)));
        }

        return $row[$key];
    }

    private function resultKey(string $column): string
    {
        Identifier::selectable($column);

        if (preg_match('/\s+(?i:AS)\s+([A-Za-z_][A-Za-z0-9_]*)\z/', $column, $matches) === 1) {
            return $matches[1];
        }

        $parts = explode('.', $column);

        $last = end($parts);

        return $last === false ? $column : $last;
    }
}
