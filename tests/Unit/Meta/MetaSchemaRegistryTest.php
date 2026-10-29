<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Meta;

use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Attribute\MetaSchema;
use TMSWP\Core\Attribute\UseMetaStorage;
use TMSWP\Core\Attribute\WithMetaRevisions;
use TMSWP\Core\Container\Container;
use TMSWP\Core\Exception\DuplicateMetaKeyException;
use TMSWP\Core\Exception\InvalidSchemaException;
use TMSWP\Core\Exception\LogicException;
use TMSWP\Core\Meta\MetaSchemaRegistry;
use TMSWP\Core\Meta\MetaStorageInterface;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Meta\ObjectType;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\PostTypeDefinition;
use TMSWP\Testing\MemoryMetaStorage;
use TMSWP\Testing\UnitTestCase;

final class MetaSchemaRegistryTest extends UnitTestCase
{
    public function testItAllowsSameMetaKeyAcrossDifferentSubtypesAndRejectsDuplicateScope(): void
    {
        $registry = new MetaSchemaRegistry(self::container());
        $registry->registerEntity(RegistryProductPost::class, ObjectType::Post, 'registry_product');
        $registry->registerEntity(RegistryOtherProductPost::class, ObjectType::Post, 'registry_other');

        self::assertSame(ObjectType::Post, $registry->objectTypeOf(RegistryProductPost::class));
        self::assertSame(ObjectType::Post, $registry->objectTypeOf(RegistryOtherProductPost::class));

        $this->expectException(DuplicateMetaKeyException::class);

        $registry->registerEntity(RegistryDuplicateProductPost::class, ObjectType::Post, 'registry_product');
    }

    /** 1つのエンティティの中で同じキーを2つの定数に宣言すると DuplicateMetaKeyException になる。 */
    public function testDuplicateKeyWithinOneEntityIsRejected(): void
    {
        $registry = new MetaSchemaRegistry(self::container());

        $this->expectException(DuplicateMetaKeyException::class);
        $this->expectExceptionMessage("duplicate meta key 'same_key' in " . RegistrySelfDuplicatePost::class);

        $registry->registerEntity(RegistrySelfDuplicatePost::class, ObjectType::Post, 'registry_self');
    }

    /**
     * 外部管理(acf-bridge 等)と申告したキーは、registerAll() で register_*_meta に渡さない。特定のサブタイプへの申告と、
     * 全サブタイプへの申告のどちらも同じ。
     */
    public function testRegisterAllDoesNotRegisterExternallyManagedKeys(): void
    {
        $registry = new MetaSchemaRegistry(self::container());
        $registry->markExternallyManaged(ObjectType::Post, 'registry_product', 'acf_field', RegistryArrayMetaStorage::class, "field group 'group_registry'");
        $registry->markExternallyManagedInAllSubtypes(ObjectType::Post, 'acf_shared_field', RegistryArrayMetaStorage::class, "field group 'group_registry'");
        $registry->registerEntity(RegistryProductPost::class, ObjectType::Post, 'registry_product');
        Functions\expect('register_post_meta')
            ->once()
            ->with('registry_product', 'shared_key', Mockery::type('array'))
            ->andReturn(true);

        $registry->registerAll();
    }

    /**
     * 外部管理のキーを、申告と違うストレージのエンティティが後から宣言するとDuplicateMetaKeyExceptionになる。メッセージは
     * キー・(ObjectType, サブタイプ)・宣言クラスと定数名・申告元・両方のストレージ・解消方法を含む。
     */
    public function testExternallyManagedKeysCannotBeDeclaredByEntityWithAnotherStorage(): void
    {
        $registry = new MetaSchemaRegistry(self::container());
        $registry->markExternallyManaged(ObjectType::Post, 'registry_product', 'shared_key', RegistryArrayMetaStorage::class, "field group 'group_registry'");

        $this->expectException(DuplicateMetaKeyException::class);
        $this->expectExceptionMessage(
            "meta key 'shared_key' for post:registry_product is externally managed by field group 'group_registry' with meta storage " . RegistryArrayMetaStorage::class
            . ' and cannot be declared by ' . RegistryProductPost::class . '::META_SHARED with meta storage ' . MemoryMetaStorage::class
            . '; Add #[UseMetaStorage(\\' . RegistryArrayMetaStorage::class . '::class)] to ' . RegistryProductPost::class
            . ", remove the #[MetaSchema] constant META_SHARED, or remove the key from field group 'group_registry'",
        );

        $registry->registerEntity(RegistryProductPost::class, ObjectType::Post, 'registry_product');
    }

    public function testMarkingAKeyDeclaredByEntityWithAnotherStorageIsRejected(): void
    {
        $registry = new MetaSchemaRegistry(self::container());
        $registry->registerEntity(RegistryProductPost::class, ObjectType::Post, 'registry_product');

        $this->expectException(DuplicateMetaKeyException::class);
        $this->expectExceptionMessage(
            "externally managed meta key 'shared_key' for post:registry_product is already declared by " . RegistryProductPost::class . '::META_SHARED'
            . ' with meta storage ' . MemoryMetaStorage::class . "; field group 'group_registry' manages it with meta storage " . RegistryArrayMetaStorage::class
            . '. Add #[UseMetaStorage(\\' . RegistryArrayMetaStorage::class . '::class)] to ' . RegistryProductPost::class
            . ", remove the #[MetaSchema] constant META_SHARED, or remove the key from field group 'group_registry'",
        );

        $registry->markExternallyManaged(ObjectType::Post, 'registry_product', 'shared_key', RegistryArrayMetaStorage::class, "field group 'group_registry'");
    }

    /**
     * 外部管理のキーでも、申告と同じストレージ(#[UseMetaStorage])のエンティティなら、宣言の前後どちらの申告とも衝突せず、
     * 宣言どおりにregister_*_metaする。宣言の無い外部管理のキーはregister_*_metaしない。
     */
    public function testExternallyManagedKeyCanBeDeclaredByEntityWithTheSameStorage(): void
    {
        $registry = new MetaSchemaRegistry(self::container());
        $registry->markExternallyManaged(ObjectType::Post, 'registry_custom', 'custom_key', RegistryArrayMetaStorage::class, "field group 'group_registry'");
        $registry->markExternallyManaged(ObjectType::Post, 'registry_custom', 'undeclared_key', RegistryArrayMetaStorage::class, "field group 'group_registry'");
        $registry->registerEntity(RegistryCustomStoragePost::class, ObjectType::Post, 'registry_custom');
        $registry->registerEntity(RegistryOtherCustomStoragePost::class, ObjectType::Post, 'registry_other_custom');
        $registry->markExternallyManaged(ObjectType::Post, 'registry_other_custom', 'custom_key', RegistryArrayMetaStorage::class, "field group 'group_registry'");
        $registry->markExternallyManagedInAllSubtypes(ObjectType::Post, 'custom_key', RegistryArrayMetaStorage::class, "field group 'group_registry'");

        self::assertTrue($registry->isExternallyManaged(ObjectType::Post, 'registry_custom', 'custom_key'));
        self::assertTrue($registry->schemasFor(RegistryCustomStoragePost::class)->has('custom_key'));

        Functions\expect('register_post_meta')->once()->with('registry_custom', 'custom_key', Mockery::type('array'))->andReturn(true);
        Functions\expect('register_post_meta')->once()->with('registry_other_custom', 'custom_key', Mockery::type('array'))->andReturn(true);

        $registry->registerAll();
    }

    public function testExternallyManagedInAllSubtypesCoversConcreteSubtypes(): void
    {
        $registry = new MetaSchemaRegistry(self::container());
        $registry->markExternallyManagedInAllSubtypes(ObjectType::Post, 'shared_key', RegistryArrayMetaStorage::class, "field group 'group_registry'");

        self::assertTrue($registry->isExternallyManaged(ObjectType::Post, 'registry_product', 'shared_key'));
        self::assertTrue($registry->isExternallyManaged(ObjectType::Post, 'registry_other', 'shared_key'));
        self::assertFalse($registry->isExternallyManaged(ObjectType::Term, 'registry_product', 'shared_key'));

        $this->expectException(DuplicateMetaKeyException::class);

        $registry->registerEntity(RegistryProductPost::class, ObjectType::Post, 'registry_product');
    }

    public function testExternallyManagedInAllSubtypesRejectsKeysDeclaredInAnySubtype(): void
    {
        $registry = new MetaSchemaRegistry(self::container());
        $registry->registerEntity(RegistryOtherProductPost::class, ObjectType::Post, 'registry_other');

        $this->expectException(DuplicateMetaKeyException::class);
        $this->expectExceptionMessage(
            "externally managed meta key 'shared_key' for all post subtypes is already declared by " . RegistryOtherProductPost::class . '::META_SHARED (post:registry_other)'
            . ' with meta storage ' . MemoryMetaStorage::class . "; field group 'group_registry' manages it with meta storage " . RegistryArrayMetaStorage::class,
        );

        $registry->markExternallyManagedInAllSubtypes(ObjectType::Post, 'shared_key', RegistryArrayMetaStorage::class, "field group 'group_registry'");
    }

    public function testSubtypeNamedAllIsAnOrdinarySubtype(): void
    {
        $registry = new MetaSchemaRegistry(self::container());
        // 'all' という名前の投稿タイプへの申告は、その投稿タイプだけを対象にする(全サブタイプの意味を持たない)
        $registry->markExternallyManaged(ObjectType::Post, 'all', 'shared_key', RegistryArrayMetaStorage::class, "field group 'group_registry'");

        self::assertTrue($registry->isExternallyManaged(ObjectType::Post, 'all', 'shared_key'));
        self::assertFalse($registry->isExternallyManaged(ObjectType::Post, 'registry_product', 'shared_key'));

        $registry->registerEntity(RegistryProductPost::class, ObjectType::Post, 'registry_product');
        self::assertSame(ObjectType::Post, $registry->objectTypeOf(RegistryProductPost::class));
    }

    #[DataProvider('nonPublicMetaSchemaConstantProvider')]
    public function testMetaSchemaOnNonPublicConstantIsRejected(string $entityClass, string $constantName): void
    {
        $registry = new MetaSchemaRegistry(self::container());
        require_once __DIR__ . '/../../fixtures/meta/non-public-meta-schema-entities.php';

        if (!is_subclass_of($entityClass, PostType::class)) {
            self::fail("fixture entity class が見つかりません: {$entityClass}");
        }

        $this->expectException(InvalidSchemaException::class);
        $this->expectExceptionMessage("#[MetaSchema] requires a public constant: {$entityClass}::{$constantName}");

        $registry->registerEntity($entityClass, ObjectType::Post, 'registry_hidden');
    }

    /** @return iterable<string, array{string, string}> */
    public static function nonPublicMetaSchemaConstantProvider(): iterable
    {
        yield 'protected' => ['TMSWP\\Core\\Tests\\Fixtures\\Meta\\ProtectedMetaSchemaPost', 'META_HIDDEN'];
        yield 'private' => ['TMSWP\\Core\\Tests\\Fixtures\\Meta\\PrivateMetaSchemaPost', 'META_HIDDEN'];
    }

    public function testRegisterAllRejectsMetaThatWordPressRefusesToRegister(): void
    {
        $registry = new MetaSchemaRegistry(self::container());
        $registry->registerEntity(RegistryProductPost::class, ObjectType::Post, 'registry_product');
        Functions\expect('register_post_meta')
            ->once()
            ->with('registry_product', 'shared_key', Mockery::type('array'))
            ->andReturn(false);

        $this->expectException(InvalidSchemaException::class);
        $this->expectExceptionMessage("WordPress rejected the meta registration of 'shared_key' for post:registry_product: " . RegistryProductPost::class . '::META_SHARED');

        $registry->registerAll();
    }

    public function testRegisterAllRegistersEachDeclaredMetaWithWordPress(): void
    {
        $registry = new MetaSchemaRegistry(self::container());
        $registry->registerEntity(RegistryProductPost::class, ObjectType::Post, 'registry_product');
        Functions\expect('register_post_meta')
            ->once()
            ->with('registry_product', 'shared_key', [
                'type' => 'string',
                'description' => '',
                'single' => true,
                'show_in_rest' => false,
            ])
            ->andReturn(true);

        $registry->registerAll();
    }

    public function testRegisteringEntityAfterRegisterAllIsRejected(): void
    {
        $registry = new MetaSchemaRegistry(self::container());
        $registry->registerAll();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('MetaSchemaRegistry::registerEntity() must be called before registerAll(): ' . RegistryProductPost::class);

        $registry->registerEntity(RegistryProductPost::class, ObjectType::Post, 'registry_product');
    }

    public function testBagRejectsWritingSanitizedKeyUntilRegisterAllHasRegisteredIt(): void
    {
        $container = self::container();
        $registry = new MetaSchemaRegistry($container);
        $registry->registerEntity(RegistrySanitizedPost::class, ObjectType::Post, 'registry_sanitized');
        // 登録の前に作った MetaBag も、登録の後は書ける(登録の状態は書き込みのたびに確かめる)
        $bag = $registry->bagFor(RegistrySanitizedPost::class, 10);

        try {
            $bag->set(RegistrySanitizedPost::META_NOTE, 'before');
            self::fail('LogicException was not thrown');
        } catch (LogicException $exception) {
            self::assertStringStartsWith("meta 'sanitized_note' of " . RegistrySanitizedPost::class . ' declares sanitize and cannot be written before', $exception->getMessage());
        }

        self::assertTrue($bag->set(RegistrySanitizedPost::META_PLAIN, 'plain'));
        Functions\when('register_post_meta')->justReturn(true);
        $registry->registerAll();

        self::assertTrue($bag->set(RegistrySanitizedPost::META_NOTE, 'after'));
        self::assertSame('after', $container->get(RegistryArrayMetaStorage::class)->get(ObjectType::Post, 10, 'sanitized_note'));
    }

    public function testUseMetaStorageControlsBagStorage(): void
    {
        $container = self::container();
        $registry = new MetaSchemaRegistry($container);
        $registry->registerEntity(RegistryCustomStoragePost::class, ObjectType::Post, 'registry_custom');

        $bag = $registry->bagFor(RegistryCustomStoragePost::class, 10);
        self::assertTrue($bag->set(RegistryCustomStoragePost::META_CUSTOM, 'stored'));
        self::assertSame('stored', $bag->string(RegistryCustomStoragePost::META_CUSTOM));

        self::assertSame('stored', $container->get(RegistryArrayMetaStorage::class)->get(ObjectType::Post, 10, 'custom_key'));
    }

    public function testItRejectsInvalidStorageClass(): void
    {
        $registry = new MetaSchemaRegistry(self::container());
        require_once __DIR__ . '/../../fixtures/meta/invalid-storage-entity.php';
        $entityClass = 'TMSWP\\Core\\Tests\\Fixtures\\Meta\\InvalidStoragePost';

        if (!is_subclass_of($entityClass, PostType::class)) {
            self::fail("fixture entity class が見つかりません: {$entityClass}");
        }

        $this->expectException(InvalidSchemaException::class);

        $registry->registerEntity($entityClass, ObjectType::Post, 'registry_invalid_storage');
    }

    public function testItRejectsMissingSanitizeCallableWhenRegisteringMeta(): void
    {
        $registry = new MetaSchemaRegistry(self::container());
        $registry->registerEntity(RegistryMissingSanitizePost::class, ObjectType::Post, 'registry_missing_sanitize');

        $this->expectException(InvalidSchemaException::class);

        $registry->registerAll();
    }

    public function testWithMetaRevisionsRequiresPostTypeWithRevisionSupport(): void
    {
        $registry = new MetaSchemaRegistry(self::container());

        $this->expectException(InvalidSchemaException::class);

        $registry->registerEntity(RegistryRevisionlessPost::class, ObjectType::Post, 'registry_revisionless');
    }

    public function testWithMetaRevisionsIsOnlyAllowedForPostObjectType(): void
    {
        $registry = new MetaSchemaRegistry(self::container());

        $this->expectException(InvalidSchemaException::class);

        $registry->registerEntity(RegistryRevisionedPost::class, ObjectType::Term, 'registry_term');
    }

    /** #[UseMetaStorage] の無いエンティティの MetaBag は、Container の MetaStorageInterface の id に登録したストレージを使う。 */
    public function testEntityWithoutUseMetaStorageUsesTheStorageRegisteredForTheInterface(): void
    {
        $container = self::container();
        $registry = new MetaSchemaRegistry($container);
        $registry->registerEntity(RegistryProductPost::class, ObjectType::Post, 'registry_product');

        self::assertTrue($registry->bagFor(RegistryProductPost::class, 10)->set(RegistryProductPost::META_SHARED, 'stored'));
        self::assertSame('stored', $container->get(MetaStorageInterface::class)->get(ObjectType::Post, 10, 'shared_key'));
    }

    /** Kernel と同じく、既定のメタのストレージを MetaStorageInterface の id に登録した Container。 */
    private static function container(): Container
    {
        $container = new Container();
        $container->set(MetaStorageInterface::class, new MemoryMetaStorage());

        return $container;
    }
}

#[AsPostType]
final class RegistryProductPost extends PostType
{
    #[MetaSchema(type: MetaValueType::String)]
    public const META_SHARED = 'shared_key';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('registry_product');
    }
}

#[AsPostType]
final class RegistrySelfDuplicatePost extends PostType
{
    #[MetaSchema(type: MetaValueType::String)]
    public const META_FIRST = 'same_key';

    #[MetaSchema(type: MetaValueType::String)]
    public const META_SECOND = 'same_key';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('registry_self');
    }
}

#[AsPostType]
final class RegistryOtherProductPost extends PostType
{
    #[MetaSchema(type: MetaValueType::String)]
    public const META_SHARED = 'shared_key';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('registry_other');
    }
}

#[AsPostType]
final class RegistryDuplicateProductPost extends PostType
{
    #[MetaSchema(type: MetaValueType::String)]
    public const META_SHARED = 'shared_key';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('registry_duplicate');
    }
}

#[AsPostType]
#[UseMetaStorage(RegistryArrayMetaStorage::class)]
final class RegistryCustomStoragePost extends PostType
{
    #[MetaSchema(type: MetaValueType::String)]
    public const META_CUSTOM = 'custom_key';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('registry_custom');
    }
}

#[AsPostType]
#[UseMetaStorage(RegistryArrayMetaStorage::class)]
final class RegistryOtherCustomStoragePost extends PostType
{
    #[MetaSchema(type: MetaValueType::String)]
    public const META_CUSTOM = 'custom_key';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('registry_other_custom');
    }
}

#[AsPostType]
#[UseMetaStorage(RegistryArrayMetaStorage::class)]
final class RegistrySanitizedPost extends PostType
{
    #[MetaSchema(type: MetaValueType::String, sanitize: [self::class, 'trimNote'])]
    public const META_NOTE = 'sanitized_note';

    #[MetaSchema(type: MetaValueType::String)]
    public const META_PLAIN = 'plain_note';

    public static function trimNote(mixed $value): string
    {
        return \is_string($value) ? trim($value) : '';
    }

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('registry_sanitized');
    }
}

#[AsPostType]
final class RegistryMissingSanitizePost extends PostType
{
    #[MetaSchema(type: MetaValueType::String, sanitize: [self::class, 'missingSanitize'])]
    public const META_CODE = 'code';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('registry_sanitize');
    }
}

#[AsPostType]
#[WithMetaRevisions]
final class RegistryRevisionlessPost extends PostType
{
    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('registry_revlss')->supports('title');
    }
}

#[AsPostType]
#[WithMetaRevisions]
final class RegistryRevisionedPost extends PostType
{
    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('registry_rev')->supports('title', 'revisions');
    }
}

final class RegistryArrayMetaStorage implements MetaStorageInterface
{
    /** @var array<string, mixed> */
    private array $values = [];

    public function has(ObjectType $type, int $objectId, string $key): bool
    {
        return \array_key_exists($this->storageKey($type, $objectId, $key), $this->values);
    }

    public function get(ObjectType $type, int $objectId, string $key): mixed
    {
        return $this->values[$this->storageKey($type, $objectId, $key)] ?? null;
    }

    public function getAll(ObjectType $type, int $objectId, string $key): array
    {
        $value = $this->get($type, $objectId, $key);

        return \is_array($value) && array_is_list($value) ? $value : [];
    }

    public function getMultiple(ObjectType $type, int $objectId, array $keys): array
    {
        $values = [];

        foreach ($keys as $key) {
            if ($this->has($type, $objectId, $key)) {
                $values[$key] = $this->get($type, $objectId, $key);
            }
        }

        return $values;
    }

    public function set(ObjectType $type, int $objectId, string $key, mixed $value): bool
    {
        $changed = !$this->has($type, $objectId, $key) || $this->get($type, $objectId, $key) !== $value;
        $this->values[$this->storageKey($type, $objectId, $key)] = $value;

        return $changed;
    }

    public function setAll(ObjectType $type, int $objectId, string $key, array $values): bool
    {
        return $this->set($type, $objectId, $key, $values);
    }

    public function delete(ObjectType $type, int $objectId, string $key): bool
    {
        $existed = $this->has($type, $objectId, $key);
        unset($this->values[$this->storageKey($type, $objectId, $key)]);

        return $existed;
    }

    private function storageKey(ObjectType $type, int $objectId, string $key): string
    {
        return $type->value . ':' . $objectId . ':' . $key;
    }
}
