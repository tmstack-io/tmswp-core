<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Option;

use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use TMSWP\Core\Attribute\AsOptionGroup;
use TMSWP\Core\Attribute\OptionSchema;
use TMSWP\Core\Container\Container;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Exception\InvalidSchemaException;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Option\OptionAccess;
use TMSWP\Core\Option\OptionRepositoryFactory;
use TMSWP\Core\Option\OptionStorageInterface;
use TMSWP\Testing\UnitTestCase;

final class OptionRepositoryTest extends UnitTestCase
{
    public function testRepositoryAppliesPrefixSchemaDefaultAndAutoload(): void
    {
        $storage = new ArrayOptionStorage();
        $factory = $this->factory($storage);
        $repository = $factory->for(SiteOptionFixture::class);

        self::assertSame('site_contact_email', $repository->name('contact_email'));
        self::assertSame([
            'contact_email' => 'info@example.com',
            'maintenance_mode' => false,
        ], $repository->all());
        self::assertSame('info@example.com', $repository->string('contact_email'));
        self::assertFalse($repository->bool('maintenance_mode'));

        self::assertTrue($repository->set('maintenance_mode', true));
        self::assertTrue($repository->bool('maintenance_mode'));
        self::assertFalse($storage->autoloads['site_maintenance_mode']);
    }

    public function testRepositoryAllowsUndeclaredKeysThroughDirectAccess(): void
    {
        $storage = new ArrayOptionStorage();
        $repository = $this->factory($storage)->for(SiteOptionFixture::class);

        self::assertFalse($repository->has('legacy_key'));
        self::assertNull($repository->get('legacy_key'));
        self::assertTrue($repository->set('legacy_key', 'legacy'));

        self::assertSame('legacy', $repository->string('legacy_key'));
        self::assertArrayHasKey('site_legacy_key', $storage->values);
    }

    public function testRepositoryDeletesAllDeclaredOptions(): void
    {
        $storage = new ArrayOptionStorage();
        $repository = $this->factory($storage)->for(SiteOptionFixture::class);

        self::assertTrue($repository->set('contact_email', 'changed@example.com'));
        self::assertTrue($repository->set('maintenance_mode', true));

        self::assertSame(['site_contact_email', 'site_maintenance_mode'], $repository->deleteAll());
        self::assertFalse($repository->has('contact_email'));
        self::assertFalse($repository->has('maintenance_mode'));
    }

    public function testDeleteAllReturnsOnlyTheOptionsThatExisted(): void
    {
        $storage = new ArrayOptionStorage();
        $repository = $this->factory($storage)->for(SiteOptionFixture::class);
        $repository->set('maintenance_mode', true);

        self::assertSame(['site_maintenance_mode'], $repository->deleteAll());
        self::assertSame([], $repository->deleteAll());
    }

    /**
     * nullは宣言したキーにも宣言していないキーにもsetできず、保存した値は変わらない(削除はdelete())。
     *
     * @return iterable<string, array{string}>
     */
    public static function keysSetToNull(): iterable
    {
        yield 'declared key' => ['contact_email'];
        yield 'undeclared key' => ['legacy_key'];
    }

    #[DataProvider('keysSetToNull')]
    public function testSetRejectsNullAndKeepsTheStoredValue(string $key): void
    {
        $storage = new ArrayOptionStorage();
        $repository = $this->factory($storage)->for(SiteOptionFixture::class);
        $repository->set('contact_email', 'kept@example.com');

        try {
            $repository->set($key, null);
            self::fail("null を set できてしまいました: {$key}");
        } catch (InvalidArgumentException $exception) {
            self::assertSame("option '{$key}' cannot be set to null; use delete() to remove it", $exception->getMessage());
        }

        self::assertSame(['site_contact_email' => 'kept@example.com'], $storage->values);
    }

    /**
     * スキーマの型に合わない値はsetできず、何も保存しない。
     *
     * @return iterable<string, array{string, mixed, string, string}>
     */
    public static function valuesNotMatchingTheSchemaType(): iterable
    {
        yield 'string for a boolean' => ['maintenance_mode', '1', 'boolean', 'string'];
        yield 'array for a string' => ['contact_email', ['a@example.com'], 'string', 'array'];
    }

    #[DataProvider('valuesNotMatchingTheSchemaType')]
    public function testSetRejectsValuesThatDoNotMatchTheSchemaType(string $key, mixed $value, string $expected, string $actual): void
    {
        $storage = new ArrayOptionStorage();
        $repository = $this->factory($storage)->for(SiteOptionFixture::class);

        try {
            $repository->set($key, $value);
            self::fail("スキーマの型に合わない値を set できてしまいました: {$key}");
        } catch (InvalidArgumentException $exception) {
            self::assertSame("option '{$key}' expects {$expected}, got {$actual}", $exception->getMessage());
        }

        self::assertSame([], $storage->values);
    }

    public function testOptionSchemaOnANonCaseConstantIsRejected(): void
    {
        $this->expectException(InvalidSchemaException::class);
        $this->expectExceptionMessage('#[OptionSchema] must be placed on enum cases, not on a constant: ' . ConstantOptionSchemaFixture::class . '::DEFAULT_EMAIL');

        $this->factory(new ArrayOptionStorage())->for(ConstantOptionSchemaFixture::class);
    }

    /**
     * enum 自身・メソッド・引数への付与は PHP の属性ターゲット(クラス定数)にも反するため、
     * PHPStan の解析対象の外の fixture に置く。
     */
    #[DataProvider('misplacedOptionSchemaProvider')]
    public function testOptionSchemaOutsideEnumCasesIsRejected(string $groupClass, string $message): void
    {
        require_once __DIR__ . '/../../fixtures/option/misplaced-option-schema.php';

        if (!enum_exists($groupClass)) {
            self::fail("fixture enum が見つかりません: {$groupClass}");
        }

        $this->expectException(InvalidSchemaException::class);
        $this->expectExceptionMessage($message);

        $this->factory(new ArrayOptionStorage())->for($groupClass);
    }

    /** @return iterable<string, array{string, string}> */
    public static function misplacedOptionSchemaProvider(): iterable
    {
        $namespace = 'TMSWP\\Core\\Tests\\Fixtures\\Option\\';

        yield 'enum itself' => [$namespace . 'EnumLevelOptionSchema', "#[OptionSchema] must be placed on enum cases, not on the option group itself: {$namespace}EnumLevelOptionSchema"];
        yield 'method' => [$namespace . 'MethodOptionSchema', "#[OptionSchema] must be placed on enum cases, not on a method: {$namespace}MethodOptionSchema::contactEmail()"];
        yield 'parameter' => [$namespace . 'ParameterOptionSchema', "#[OptionSchema] must be placed on enum cases, not on a parameter: {$namespace}ParameterOptionSchema::withDefault(\$value)"];
    }

    public function testOptionAccessRejectsEnumsThatAreNotStringBacked(): void
    {
        // repository() が先に string backed でない enum を拒否するため、キーの解決は公開の API からは届かない。
        // 型の確認そのものが設定の誤りとして InvalidSchemaException を投げることを、リフレクションで確かめる。
        $method = new ReflectionMethod(IntBackedOptionAccessFixture::Key, 'optionKey');

        $this->expectException(InvalidSchemaException::class);
        $this->expectExceptionMessage('OptionAccess requires a string backed enum: ' . IntBackedOptionAccessFixture::class);

        $method->invoke(IntBackedOptionAccessFixture::Key);
    }

    public function testFactoryRejectsInvalidOptionGroup(): void
    {
        $this->expectException(InvalidSchemaException::class);

        $this->factory(new ArrayOptionStorage())->for(NoOptionGroupAttributeFixture::class);
    }

    public function testFactoryRejectsNonStringBackedEnum(): void
    {
        $this->expectException(InvalidSchemaException::class);

        $this->factory(new ArrayOptionStorage())->for(IntBackedOptionFixture::class);
    }

    private function factory(ArrayOptionStorage $storage): OptionRepositoryFactory
    {
        $container = new Container();
        $container->set(ArrayOptionStorage::class, $storage);

        return new OptionRepositoryFactory($container);
    }
}

#[AsOptionGroup(prefix: 'site_', storage: ArrayOptionStorage::class)]
enum SiteOptionFixture: string
{
    use OptionAccess;

    #[OptionSchema(type: MetaValueType::String, default: 'info@example.com')]
    case ContactEmail = 'contact_email';

    #[OptionSchema(type: MetaValueType::Boolean, default: false, autoload: false)]
    case Maintenance = 'maintenance_mode';
}

enum NoOptionGroupAttributeFixture: string
{
    case Key = 'key';
}

#[AsOptionGroup(prefix: 'int_', storage: ArrayOptionStorage::class)]
enum IntBackedOptionFixture: int
{
    #[OptionSchema(type: MetaValueType::Integer, default: 1)]
    case Key = 1;
}

#[AsOptionGroup(prefix: 'const_', storage: ArrayOptionStorage::class)]
enum ConstantOptionSchemaFixture: string
{
    #[OptionSchema(type: MetaValueType::String)]
    public const DEFAULT_EMAIL = 'info@example.com';

    #[OptionSchema(type: MetaValueType::String, default: self::DEFAULT_EMAIL)]
    case ContactEmail = 'contact_email';
}

#[AsOptionGroup(prefix: 'int_access_', storage: ArrayOptionStorage::class)]
enum IntBackedOptionAccessFixture: int
{
    use OptionAccess;

    case Key = 1;
}

/**
 * OptionStorageInterface の契約(書き込みは変えたかどうかを返す)に従う、プロセス内配列への保存実装。
 */
final class ArrayOptionStorage implements OptionStorageInterface
{
    /** @var array<string, mixed> */
    public array $values = [];

    /** @var array<string, bool|null> */
    public array $autoloads = [];

    public function has(string $name): bool
    {
        return \array_key_exists($name, $this->values);
    }

    public function get(string $name): mixed
    {
        if (!\array_key_exists($name, $this->values)) {
            return null;
        }

        return $this->values[$name];
    }

    public function set(string $name, mixed $value, ?bool $autoload = null): bool
    {
        $changed = !$this->has($name) || $this->values[$name] !== $value;
        $this->values[$name] = $value;
        $this->autoloads[$name] = $autoload;

        return $changed;
    }

    public function delete(string $name): bool
    {
        $existed = $this->has($name);
        unset($this->values[$name], $this->autoloads[$name]);

        return $existed;
    }
}
