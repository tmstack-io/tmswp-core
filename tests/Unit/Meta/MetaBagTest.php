<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Meta;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\Core\Attribute\MetaSchema;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Exception\InvalidSchemaException;
use TMSWP\Core\Exception\LogicException;
use TMSWP\Core\Meta\MetaBag;
use TMSWP\Core\Meta\MetaSchemaDefinition;
use TMSWP\Core\Meta\MetaSchemaSet;
use TMSWP\Core\Meta\MetaStorageInterface;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Meta\ObjectType;
use TMSWP\Testing\UnitTestCase;

/**
 * MetaBagの型付きアクセサの値の決め方と、書き込みの検査(docs/04。docs/10 §8.2のMetaBag)。
 */
final class MetaBagTest extends UnitTestCase
{
    /**
     * すべての getter が、ストレージの値 → 引数の default → スキーマの default → ゼロ値(get() は null)の順に値を決める。
     * 型付きの getter はストレージの値とスキーマの default を自分の型にキャストし、引数の default はそのまま返す。
     * 各行は、読み取り・ストレージに置く値(キー・値・複数行か。無ければnull)・期待値。
     *
     * @return iterable<string, array{Closure(MetaBag<MetaBagFixtureEntity>): mixed, array{string, mixed, bool}|null, mixed}>
     */
    public static function getterResolutions(): iterable
    {
        // 4. ストレージにも引数にもスキーマにも値が無い: 型付きの getter はその型のゼロ値、get() は null
        yield 'zero value: int' => [static fn(MetaBag $bag): mixed => $bag->int('plain'), null, 0];
        yield 'zero value: float' => [static fn(MetaBag $bag): mixed => $bag->float('plain'), null, 0.0];
        yield 'zero value: bool' => [static fn(MetaBag $bag): mixed => $bag->bool('plain'), null, false];
        yield 'zero value: string' => [static fn(MetaBag $bag): mixed => $bag->string('plain'), null, ''];
        yield 'zero value: list' => [static fn(MetaBag $bag): mixed => $bag->list('plain'), null, []];
        yield 'zero value: list of rows' => [static fn(MetaBag $bag): mixed => $bag->list('rows'), null, []];
        yield 'zero value: json' => [static fn(MetaBag $bag): mixed => $bag->json('plain'), null, []];
        yield 'zero value: get' => [static fn(MetaBag $bag): mixed => $bag->get('plain'), null, null];
        yield 'zero value: get of rows' => [static fn(MetaBag $bag): mixed => $bag->get('rows'), null, null];

        // 3. スキーマの default(getter の型にキャストする)
        yield 'schema default: int' => [static fn(MetaBag $bag): mixed => $bag->int('int'), null, 100];
        yield 'schema default: float' => [static fn(MetaBag $bag): mixed => $bag->float('float'), null, 1.5];
        yield 'schema default: bool' => [static fn(MetaBag $bag): mixed => $bag->bool('bool'), null, true];
        yield 'schema default: string' => [static fn(MetaBag $bag): mixed => $bag->string('string'), null, 'schema'];
        yield 'schema default: list' => [static fn(MetaBag $bag): mixed => $bag->list('array'), null, ['schema']];
        yield 'schema default: json' => [static fn(MetaBag $bag): mixed => $bag->json('object'), null, ['from' => 'schema']];
        yield 'schema default: get' => [static fn(MetaBag $bag): mixed => $bag->get('int'), null, 100];
        yield 'schema default: string of a float' => [static fn(MetaBag $bag): mixed => $bag->string('float'), null, '1.5'];

        // 2. 引数の default はスキーマの default より優先し、キャストしない
        yield 'argument default: int' => [static fn(MetaBag $bag): mixed => $bag->int('int', 7), null, 7];
        yield 'argument default: float' => [static fn(MetaBag $bag): mixed => $bag->float('float', 9.5), null, 9.5];
        yield 'argument default: bool' => [static fn(MetaBag $bag): mixed => $bag->bool('bool', false), null, false];
        yield 'argument default: string' => [static fn(MetaBag $bag): mixed => $bag->string('string', 'argument'), null, 'argument'];
        yield 'argument default: list' => [static fn(MetaBag $bag): mixed => $bag->list('array', ['argument']), null, ['argument']];
        yield 'argument default: list of rows' => [static fn(MetaBag $bag): mixed => $bag->list('rows', [3]), null, [3]];
        yield 'argument default: json' => [static fn(MetaBag $bag): mixed => $bag->json('object', ['from' => 'argument']), null, ['from' => 'argument']];
        yield 'argument default: get' => [static fn(MetaBag $bag): mixed => $bag->get('string', 'argument'), null, 'argument'];
        yield 'argument default: get of rows' => [static fn(MetaBag $bag): mixed => $bag->get('rows', [3]), null, [3]];

        // 1. ストレージの値は引数の default・スキーマの default より優先し、getter の型にキャストする
        yield 'storage: int' => [static fn(MetaBag $bag): mixed => $bag->int('int', 7), ['int', '42', false], 42];
        yield 'storage: float' => [static fn(MetaBag $bag): mixed => $bag->float('float', 9.5), ['float', '2.25', false], 2.25];
        yield 'storage: bool' => [static fn(MetaBag $bag): mixed => $bag->bool('bool', true), ['bool', '0', false], false];
        yield 'storage: string' => [static fn(MetaBag $bag): mixed => $bag->string('string', 'argument'), ['string', 42, false], '42'];
        yield 'storage: list' => [static fn(MetaBag $bag): mixed => $bag->list('array', ['argument']), ['array', 'stored', false], ['stored']];
        yield 'storage: list of rows' => [static fn(MetaBag $bag): mixed => $bag->list('rows', [3]), ['rows', [1, 2], true], [1, 2]];
        yield 'storage: json' => [static fn(MetaBag $bag): mixed => $bag->json('object', ['from' => 'argument']), ['object', '{"from":"storage"}', false], ['from' => 'storage']];
        yield 'storage: get' => [static fn(MetaBag $bag): mixed => $bag->get('string', 'argument'), ['string', 42, false], 42];
        yield 'storage: get of rows' => [static fn(MetaBag $bag): mixed => $bag->get('rows', [3]), ['rows', [1, 2], true], [1, 2]];
    }

    /**
     * @param Closure(MetaBag<MetaBagFixtureEntity>): mixed $read
     * @param array{string, mixed, bool}|null $stored
     */
    #[DataProvider('getterResolutions')]
    public function testEveryGetterResolvesStorageThenArgumentDefaultThenSchemaDefaultThenZeroValue(Closure $read, ?array $stored, mixed $expected): void
    {
        $storage = new ArrayMetaStorage();
        $schema = static fn(string $key, MetaValueType $type, mixed $default, bool $single = true): MetaSchemaDefinition => new MetaSchemaDefinition(MetaBagFixtureEntity::class, 'META_' . strtoupper($key), $key, $type, $default, $single, false, '', null);
        $bag = new MetaBag(
            MetaBagFixtureEntity::class,
            ObjectType::Post,
            10,
            $storage,
            MetaSchemaSet::fromList([
                $schema('int', MetaValueType::Integer, 100),
                $schema('float', MetaValueType::Number, 1.5),
                $schema('bool', MetaValueType::Boolean, true),
                $schema('string', MetaValueType::String, 'schema'),
                $schema('array', MetaValueType::Array, ['schema']),
                $schema('object', MetaValueType::Object, ['from' => 'schema']),
                $schema('rows', MetaValueType::Integer, null, false),
                $schema('plain', MetaValueType::String, null),
            ]),
            static fn(): bool => true,
        );

        if ($stored !== null) {
            [$key, $value, $rows] = $stored;

            if ($rows) {
                self::assertIsArray($value);
                $storage->setAll(ObjectType::Post, 10, $key, array_values($value));
            } else {
                $storage->set(ObjectType::Post, 10, $key, $value);
            }
        }

        self::assertSame($expected, $read($bag));
    }

    public function testSingleFalseUsesAllRowsAndRequiresListOnSet(): void
    {
        $storage = new ArrayMetaStorage();
        $bag = $this->bag($storage);

        self::assertTrue($bag->set('related_id', [12, 34]));
        self::assertSame([12, 34], $bag->list('related_id'));
        self::assertSame([12, 34], $bag->get('related_id'));

        $this->expectException(InvalidArgumentException::class);

        $bag->set('related_id', '12');
    }

    public function testAllReturnsDeclaredKeysOnlyWithSchemaDefaults(): void
    {
        $storage = new ArrayMetaStorage();
        $bag = $this->bag($storage);
        $storage->set(ObjectType::Post, 10, 'code', 'A-001');
        $storage->set(ObjectType::Post, 10, 'external', 'ignored');

        self::assertSame([
            'price' => 100,
            'code' => 'A-001',
            'related_id' => null,
        ], $bag->all());
    }

    public function testAllReturnsTheSameListAsGetForSingleFalseKeys(): void
    {
        $storage = new ArrayMetaStorage();
        $bag = $this->bag($storage);
        $bag->set('related_id', [12, 34, 56]);

        self::assertSame([12, 34, 56], $bag->get('related_id'));
        self::assertSame($bag->get('related_id'), $bag->all()['related_id']);
    }

    /** @return iterable<string, array{string}> */
    public static function keysSetToNull(): iterable
    {
        yield 'declared key' => ['code'];
        yield 'undeclared key' => ['undeclared'];
    }

    #[DataProvider('keysSetToNull')]
    public function testSetRejectsNullForDeclaredAndUndeclaredKeys(string $key): void
    {
        $bag = $this->bag(new ArrayMetaStorage());

        try {
            $bag->set($key, null);
            self::fail("null を set できてしまいました: {$key}");
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString("meta '{$key}' cannot be set to null; use delete()", $exception->getMessage());
        }

        self::assertFalse($bag->has($key));
    }

    /**
     * スキーマの型に合わない値は書けない(複数行のキーは要素ごとに確かめる)。
     *
     * @return iterable<string, array{string, mixed, string}>
     */
    public static function valuesNotMatchingTheSchemaType(): iterable
    {
        yield 'string for an integer' => ['price', '1200', "meta 'price' expects integer, got string"];
        yield 'array for a string' => ['code', ['A-001'], "meta 'code' expects string, got array"];
        yield 'string element for integer rows' => ['related_id', [12, '34'], "meta 'related_id' expects integer, got string"];
    }

    #[DataProvider('valuesNotMatchingTheSchemaType')]
    public function testSetRejectsValuesThatDoNotMatchTheSchemaType(string $key, mixed $value, string $message): void
    {
        $bag = $this->bag(new ArrayMetaStorage());

        try {
            $bag->set($key, $value);
            self::fail("スキーマの型に合わない値を set できてしまいました: {$key}");
        } catch (InvalidArgumentException $exception) {
            self::assertSame($message, $exception->getMessage());
        }

        self::assertFalse($bag->has($key));
    }

    /** 未宣言のキーは型を問わない(WP組み込みのメタや他プラグインのキーを書く経路) */
    public function testSetAcceptsAnyTypeForUndeclaredKeys(): void
    {
        $bag = $this->bag(new ArrayMetaStorage());

        self::assertTrue($bag->set('undeclared', ['any' => 'shape']));
        self::assertSame(['any' => 'shape'], $bag->get('undeclared'));
    }

    /**
     * sanitize を宣言したキーは、スキーマを WordPress に登録する前には書けない(WordPress が sanitize を適用しないため)。
     * sanitize の無いキーの書き込み・読み取り・delete() は登録の前でも使え、登録の後は sanitize のキーにも書ける。
     */
    public function testSanitizedKeyCannotBeWrittenBeforeRegistrationWithWordPress(): void
    {
        $storage = new ArrayMetaStorage();
        $registered = false;
        $bag = new MetaBag(
            MetaBagFixtureEntity::class,
            ObjectType::Post,
            10,
            $storage,
            MetaSchemaSet::fromList([
                new MetaSchemaDefinition(MetaBagFixtureEntity::class, 'META_CODE', 'code', MetaValueType::String, null, true, false, '', null),
                new MetaSchemaDefinition(MetaBagFixtureEntity::class, 'META_NOTE', 'note', MetaValueType::String, null, true, false, '', 'trim'),
            ]),
            static function () use (&$registered): bool {
                return $registered;
            },
        );

        try {
            $bag->set('note', 'before');
            self::fail('LogicException was not thrown');
        } catch (LogicException $exception) {
            self::assertSame(
                "meta 'note' of " . MetaBagFixtureEntity::class . ' declares sanitize and cannot be written before its schema is registered with WordPress; WordPress applies sanitize only after the registration at init priority 20, so write it after that',
                $exception->getMessage(),
            );
        }

        self::assertFalse($bag->has('note'));
        self::assertTrue($bag->set('code', 'A-1'));
        self::assertSame('A-1', $bag->string('code'));
        self::assertTrue($bag->delete('code'));
        self::assertSame('', $bag->string('note'));

        $registered = true;

        self::assertTrue($bag->set('note', 'after'));
        self::assertSame('after', $bag->string('note'));
    }

    public function testSchemaDefinitionRejectsInvalidDeclaration(): void
    {
        $this->expectException(InvalidSchemaException::class);

        MetaSchemaDefinition::fromAttribute(
            MetaBagFixtureEntity::class,
            'META_PRICE',
            'price',
            new MetaSchema(type: MetaValueType::Integer, default: 'invalid'),
        );
    }

    /** @return MetaBag<MetaBagFixtureEntity> */
    private function bag(ArrayMetaStorage $storage): MetaBag
    {
        return new MetaBag(
            MetaBagFixtureEntity::class,
            ObjectType::Post,
            10,
            $storage,
            MetaSchemaSet::fromList([
                new MetaSchemaDefinition(MetaBagFixtureEntity::class, 'META_PRICE', 'price', MetaValueType::Integer, 100, true, true, '', null),
                new MetaSchemaDefinition(MetaBagFixtureEntity::class, 'META_CODE', 'code', MetaValueType::String, null, true, false, '', null),
                new MetaSchemaDefinition(MetaBagFixtureEntity::class, 'META_RELATED_IDS', 'related_id', MetaValueType::Integer, null, false, false, '', null),
            ]),
            static fn(): bool => true,
        );
    }
}

final class MetaBagFixtureEntity {}

/**
 * MetaStorageInterface の契約(書き込みは変えたかどうかを返す)に従う、プロセス内配列への保存実装。
 */
final class ArrayMetaStorage implements MetaStorageInterface
{
    /** @var array<string, mixed> */
    private array $values = [];

    /** @var array<string, list<mixed>> */
    private array $rows = [];

    public function has(ObjectType $type, int $objectId, string $key): bool
    {
        return \array_key_exists($this->storageKey($type, $objectId, $key), $this->values)
            || \array_key_exists($this->storageKey($type, $objectId, $key), $this->rows);
    }

    public function get(ObjectType $type, int $objectId, string $key): mixed
    {
        $storageKey = $this->storageKey($type, $objectId, $key);

        if (!\array_key_exists($storageKey, $this->values)) {
            return null;
        }

        return $this->values[$storageKey];
    }

    public function getAll(ObjectType $type, int $objectId, string $key): array
    {
        $storageKey = $this->storageKey($type, $objectId, $key);

        if (!\array_key_exists($storageKey, $this->rows)) {
            return [];
        }

        return $this->rows[$storageKey];
    }

    public function getMultiple(ObjectType $type, int $objectId, array $keys): array
    {
        $values = [];

        foreach ($keys as $key) {
            $storageKey = $this->storageKey($type, $objectId, $key);

            if (\array_key_exists($storageKey, $this->values)) {
                $values[$key] = $this->values[$storageKey];
            }
        }

        return $values;
    }

    public function set(ObjectType $type, int $objectId, string $key, mixed $value): bool
    {
        $storageKey = $this->storageKey($type, $objectId, $key);
        $changed = !\array_key_exists($storageKey, $this->values) || $this->values[$storageKey] !== $value;
        $this->values[$storageKey] = $value;

        return $changed;
    }

    public function setAll(ObjectType $type, int $objectId, string $key, array $values): bool
    {
        $storageKey = $this->storageKey($type, $objectId, $key);
        $changed = ($this->rows[$storageKey] ?? []) !== $values;
        $this->rows[$storageKey] = $values;

        return $changed;
    }

    public function delete(ObjectType $type, int $objectId, string $key): bool
    {
        $existed = $this->has($type, $objectId, $key);
        unset($this->values[$this->storageKey($type, $objectId, $key)], $this->rows[$this->storageKey($type, $objectId, $key)]);

        return $existed;
    }

    private function storageKey(ObjectType $type, int $objectId, string $key): string
    {
        return $type->value . ':' . $objectId . ':' . $key;
    }
}
