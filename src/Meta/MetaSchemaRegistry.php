<?php

declare(strict_types=1);

namespace TMSWP\Core\Meta;

use Psr\Container\ContainerInterface;
use ReflectionClass;
use TMSWP\Core\Attribute\MetaSchema;
use TMSWP\Core\Attribute\UseMetaStorage;
use TMSWP\Core\Attribute\WithMetaRevisions;
use TMSWP\Core\Exception\DuplicateMetaKeyException;
use TMSWP\Core\Exception\EntityNotRegisteredException;
use TMSWP\Core\Exception\InvalidSchemaException;
use TMSWP\Core\Exception\LogicException;
use TMSWP\Core\Post\PostType;
use WP_Post;
use WP_Term;
use WP_User;

final class MetaSchemaRegistry
{
    /**
     * @var array<class-string, array{
     *     objectType: ObjectType,
     *     subtype: string,
     *     schemas: MetaSchemaSet,
     *     storageClass: class-string<MetaStorageInterface>,
     *     storageImplementation: class-string<MetaStorageInterface>,
     *     revisions: bool
     * }>
     */
    private array $entities = [];

    /** @var array<string, class-string> */
    private array $declaredScopes = [];

    /**
     * @var array<string, array{storageClass: class-string<MetaStorageInterface>, origin: string}> (ObjectType, subtype, key)の組
     */
    private array $externalScopes = [];

    /**
     * @var array<string, array{storageClass: class-string<MetaStorageInterface>, origin: string}> 全サブタイプで外部管理する(ObjectType, key)の組
     */
    private array $externalAllSubtypeScopes = [];

    private bool $registered = false;

    public function __construct(
        private readonly ContainerInterface $container,
    ) {}

    /**
     * エンティティ1クラスのメタスキーマを、定数と属性(#[MetaSchema]・#[UseMetaStorage]・#[WithMetaRevisions])の
     * リフレクションで集めて登録する。エンティティの Initializer が Kernel::boot() の中で呼ぶ。
     *
     * @param class-string $entityClass
     *
     * @throws LogicException registerAll() の後に呼んだ場合(WordPress に登録されないスキーマになるため)
     * @throws InvalidSchemaException スキーマ・ストレージ・#[WithMetaRevisions] の宣言の誤り
     * @throws DuplicateMetaKeyException キーの重複、または外部管理のキーを、申告と違うストレージのエンティティで宣言した場合
     */
    public function registerEntity(string $entityClass, ObjectType $objectType, string $subtype): void
    {
        if ($this->registered) {
            throw new LogicException("MetaSchemaRegistry::registerEntity() must be called before registerAll(): {$entityClass}");
        }

        $reflection = new ReflectionClass($entityClass);
        $schemas = MetaSchemaSet::fromList($this->definitionsFor($reflection));
        $storageClass = $this->storageClassFor($reflection);
        $storageImplementation = $this->storageImplementation($storageClass, $entityClass);
        $revisions = $this->revisionsEnabled($reflection, $objectType);

        $this->registerEntry($entityClass, $objectType, $subtype, $schemas, $storageClass, $storageImplementation, $revisions);
    }

    public function schemasFor(string $entityClass): MetaSchemaSet
    {
        if (!\array_key_exists($entityClass, $this->entities)) {
            return MetaSchemaSet::empty();
        }

        return $this->entities[$entityClass]['schemas'];
    }

    public function objectTypeOf(string $entityClass): ObjectType
    {
        if (!\array_key_exists($entityClass, $this->entities)) {
            throw new EntityNotRegisteredException("{$entityClass} is not registered. Did you forget an entity attribute?");
        }

        return $this->entities[$entityClass]['objectType'];
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $entityClass
     *
     * @return MetaBag<T>
     */
    public function bagFor(string $entityClass, WP_Post|WP_Term|WP_User|int $object): MetaBag
    {
        if (!\array_key_exists($entityClass, $this->entities)) {
            throw new EntityNotRegisteredException("{$entityClass} is not registered. Did you forget an entity attribute?");
        }

        $entry = $this->entities[$entityClass];
        $storage = $this->container->get($entry['storageClass']);

        if (!$storage instanceof MetaStorageInterface) {
            throw new InvalidSchemaException("meta storage '{$entry['storageClass']}' must implement MetaStorageInterface");
        }

        return new MetaBag(
            $entityClass,
            $entry['objectType'],
            $entry['objectType']->resolveId($object),
            $storage,
            $entry['schemas'],
            fn(): bool => $this->registered,
        );
    }

    /**
     * 外部(拡張パッケージ等)が管理するキーを申告する。申告したキーを#[MetaSchema]で宣言できるのは、読み書きの
     * ストレージ(#[UseMetaStorage]か既定のストレージを解決したインスタンスのクラス)が$storageClassと同じ
     * エンティティだけで、そのときは同じ値を同じ経路で読み書きするため、宣言どおりにregister_*_metaする。
     * 宣言の無いキーはregister_*_metaしない。#[MetaSchema]はbootの中で登録済みのため、拡張がinitで申告した時点で
     * 重複を検出する。
     *
     * @param class-string<MetaStorageInterface> $storageClass このキーを読み書きするストレージのクラス
     * @param string $origin 申告元の説明(例: 外部のフィールド定義のグループのkey)。重複の例外のメッセージに使う
     *
     * @throws DuplicateMetaKeyException 同じ(ObjectType, subtype, key)を、ストレージが$storageClassでない
     *                                   エンティティの#[MetaSchema]が宣言済み
     */
    public function markExternallyManaged(ObjectType $objectType, string $subtype, string $key, string $storageClass, string $origin): void
    {
        $scope = $this->scopeKey($objectType, $subtype, $key);
        $declaredClass = $this->declaredScopes[$scope] ?? null;

        if ($declaredClass !== null && $this->entities[$declaredClass]['storageImplementation'] !== $storageClass) {
            throw new DuplicateMetaKeyException("externally managed meta key '{$key}' for {$objectType->value}:{$subtype} is already declared by "
                . $this->conflictDescription($declaredClass, $key, $storageClass, $origin));
        }

        $this->externalScopes[$scope] = ['storageClass' => $storageClass, 'origin' => $origin];
    }

    /**
     * ObjectTypeのすべてのサブタイプについて、外部が管理するキーを申告する。宣言との関係はmarkExternallyManaged()と同じ。
     *
     * @param class-string<MetaStorageInterface> $storageClass このキーを読み書きするストレージのクラス
     * @param string $origin 申告元の説明(例: 外部のフィールド定義のグループのkey)。重複の例外のメッセージに使う
     *
     * @throws DuplicateMetaKeyException 同じ(ObjectType, key)を、いずれかのサブタイプの、ストレージが$storageClassでない
     *                                   エンティティの#[MetaSchema]が宣言済み
     */
    public function markExternallyManagedInAllSubtypes(ObjectType $objectType, string $key, string $storageClass, string $origin): void
    {
        foreach ($this->entities as $entityClass => $entry) {
            if ($entry['objectType'] === $objectType && $entry['schemas']->has($key) && $entry['storageImplementation'] !== $storageClass) {
                throw new DuplicateMetaKeyException("externally managed meta key '{$key}' for all {$objectType->value} subtypes is already declared by "
                    . $this->conflictDescription($entityClass, $key, $storageClass, $origin, " ({$objectType->value}:{$entry['subtype']})"));
            }
        }

        $this->externalAllSubtypeScopes[$objectType->value . "\0" . $key] = ['storageClass' => $storageClass, 'origin' => $origin];
    }

    public function isExternallyManaged(ObjectType $objectType, string $subtype, string $key): bool
    {
        return $this->externalMark($objectType, $subtype, $key) !== null;
    }

    /**
     * @return list<array{entityClass: class-string, objectType: ObjectType, subtype: string, schema: MetaSchemaDefinition}>
     */
    public function schemas(): array
    {
        $schemas = [];

        foreach ($this->entities as $entityClass => $entry) {
            foreach ($entry['schemas']->all() as $schema) {
                $schemas[] = [
                    'entityClass' => $entityClass,
                    'objectType' => $entry['objectType'],
                    'subtype' => $entry['subtype'],
                    'schema' => $schema,
                ];
            }
        }

        return $schemas;
    }

    /**
     * 登録済みのスキーマを register_*_meta で WordPress に登録する。Kernel が init 20 で1回だけ呼ぶ。
     *
     * @throws LogicException 2回目の呼び出し
     * @throws InvalidSchemaException sanitize が呼び出せない、または WordPress が登録を拒否した場合
     */
    public function registerAll(): void
    {
        if ($this->registered) {
            throw new LogicException('MetaSchemaRegistry::registerAll() must only be called once');
        }

        foreach ($this->entities as $entry) {
            foreach ($entry['schemas']->all() as $definition) {
                $args = [
                    'type' => $definition->type->value,
                    'description' => $definition->description,
                    'single' => $definition->single,
                    'show_in_rest' => $definition->showInRest,
                ];

                if ($definition->hasDefault()) {
                    $args['default'] = $definition->default;
                }

                if ($definition->sanitize !== null) {
                    if (!\is_callable($definition->sanitize)) {
                        throw new InvalidSchemaException("sanitize callback is not callable: {$definition->ownerClass}::{$definition->constantName}");
                    }

                    $args['sanitize_callback'] = $definition->sanitize;
                }

                if ($entry['objectType'] === ObjectType::Post && $entry['revisions']) {
                    $args['revisions_enabled'] = true;
                }

                $registered = match ($entry['objectType']) {
                    ObjectType::Post => register_post_meta($entry['subtype'], $definition->key, $args),
                    ObjectType::Term => register_term_meta($entry['subtype'], $definition->key, $args),
                    ObjectType::User => register_meta('user', $definition->key, $args),
                };

                if ($registered !== true) {
                    throw new InvalidSchemaException("WordPress rejected the meta registration of '{$definition->key}' for {$entry['objectType']->value}:{$entry['subtype']}: {$definition->ownerClass}::{$definition->constantName}");
                }
            }
        }

        $this->registered = true;
    }

    /**
     * @param ReflectionClass<object> $reflection
     *
     * @return list<MetaSchemaDefinition>
     */
    private function definitionsFor(ReflectionClass $reflection): array
    {
        $definitions = [];

        foreach ($reflection->getReflectionConstants() as $constant) {
            if ($constant->getDeclaringClass()->getName() !== $reflection->getName()) {
                continue;
            }

            $attributes = $constant->getAttributes(MetaSchema::class);

            if ($attributes === []) {
                continue;
            }

            if (!$constant->isPublic()) {
                throw new InvalidSchemaException("#[MetaSchema] requires a public constant: {$reflection->getName()}::{$constant->getName()}");
            }

            $value = $constant->getValue();

            if (!\is_string($value)) {
                throw new InvalidSchemaException("meta schema constant must be a string: {$reflection->getName()}::{$constant->getName()}");
            }

            $attribute = $attributes[0]->newInstance();

            $definitions[] = MetaSchemaDefinition::fromAttribute(
                $reflection->getName(),
                $constant->getName(),
                $value,
                $attribute,
            );
        }

        return $definitions;
    }

    /**
     * @param ReflectionClass<object> $reflection
     *
     * @return class-string<MetaStorageInterface>
     */
    private function storageClassFor(ReflectionClass $reflection): string
    {
        $attributes = $reflection->getAttributes(UseMetaStorage::class);

        // #[UseMetaStorage] が無ければ、Kernel が既定のストレージを登録した interface の id で解決する
        if ($attributes === []) {
            return MetaStorageInterface::class;
        }

        $attribute = $attributes[0]->newInstance();

        return $attribute->storageClass;
    }

    /**
     * ストレージを解決し、そのインスタンスのクラスを返す。外部管理の申告とストレージが同じかの判定に使う。
     *
     * @param class-string<MetaStorageInterface> $storageClass
     *
     * @throws InvalidSchemaException 解決したものがMetaStorageInterfaceを実装していない場合
     *
     * @return class-string<MetaStorageInterface>
     */
    private function storageImplementation(string $storageClass, string $entityClass): string
    {
        $storage = $this->container->get($storageClass);

        if (!$storage instanceof MetaStorageInterface) {
            throw new InvalidSchemaException("meta storage '{$storageClass}' for {$entityClass} must implement MetaStorageInterface");
        }

        return $storage::class;
    }

    /** @param ReflectionClass<object> $reflection */
    private function revisionsEnabled(ReflectionClass $reflection, ObjectType $objectType): bool
    {
        if ($reflection->getAttributes(WithMetaRevisions::class) === []) {
            return false;
        }

        if ($objectType !== ObjectType::Post) {
            throw new InvalidSchemaException("#[WithMetaRevisions] is only allowed on post type entities: {$reflection->getName()}");
        }

        if (!is_subclass_of($reflection->getName(), PostType::class)) {
            throw new InvalidSchemaException("#[WithMetaRevisions] requires a PostType subclass: {$reflection->getName()}");
        }

        if (!\in_array('revisions', $reflection->getName()::definition()->supportsList(), true)) {
            throw new InvalidSchemaException("#[WithMetaRevisions] requires supports('revisions'): {$reflection->getName()}");
        }

        return true;
    }

    /**
     * @param class-string $entityClass
     * @param class-string<MetaStorageInterface> $storageClass
     * @param class-string<MetaStorageInterface> $storageImplementation
     */
    private function registerEntry(
        string $entityClass,
        ObjectType $objectType,
        string $subtype,
        MetaSchemaSet $schemas,
        string $storageClass,
        string $storageImplementation,
        bool $revisions,
    ): void {
        foreach ($schemas->all() as $definition) {
            $mark = $this->externalMark($objectType, $subtype, $definition->key);

            if ($mark !== null && $mark['storageClass'] !== $storageImplementation) {
                throw new DuplicateMetaKeyException("meta key '{$definition->key}' for {$objectType->value}:{$subtype} is externally managed by {$mark['origin']} with meta storage {$mark['storageClass']}"
                    . " and cannot be declared by {$entityClass}::{$definition->constantName} with meta storage {$storageImplementation}; "
                    . self::conflictResolution($entityClass, $definition->constantName, $mark['storageClass'], $mark['origin']));
            }

            $scope = $this->scopeKey($objectType, $subtype, $definition->key);

            if (\array_key_exists($scope, $this->declaredScopes)) {
                throw new DuplicateMetaKeyException("duplicate meta key '{$definition->key}' for {$objectType->value}:{$subtype}");
            }

            $this->declaredScopes[$scope] = $entityClass;
        }

        $this->entities[$entityClass] = [
            'objectType' => $objectType,
            'subtype' => $subtype,
            'schemas' => $schemas,
            'storageClass' => $storageClass,
            'storageImplementation' => $storageImplementation,
            'revisions' => $revisions,
        ];
    }

    /**
     * (ObjectType, subtype, key)への外部管理の申告。サブタイプへの申告を、全サブタイプへの申告より優先する。
     *
     * @return array{storageClass: class-string<MetaStorageInterface>, origin: string}|null
     */
    private function externalMark(ObjectType $objectType, string $subtype, string $key): ?array
    {
        return $this->externalScopes[$this->scopeKey($objectType, $subtype, $key)]
            ?? $this->externalAllSubtypeScopes[$objectType->value . "\0" . $key]
            ?? null;
    }

    /**
     * 申告より先に宣言済みのキーとの衝突の説明。「{宣言クラス}::{定数名} with meta storage ...」から始まる。
     *
     * @param class-string $entityClass
     * @param class-string<MetaStorageInterface> $storageClass
     */
    private function conflictDescription(string $entityClass, string $key, string $storageClass, string $origin, string $scope = ''): string
    {
        $entry = $this->entities[$entityClass];
        $constantName = $entry['schemas']->get($key)->constantName ?? '';

        return "{$entityClass}::{$constantName}{$scope} with meta storage {$entry['storageImplementation']}; {$origin} manages it with meta storage {$storageClass}. "
            . self::conflictResolution($entityClass, $constantName, $storageClass, $origin);
    }

    /**
     * 衝突の解消方法(ストレージを揃える、#[MetaSchema]の定数を消す、外部の定義からキーを消す)。
     *
     * @param class-string<MetaStorageInterface> $storageClass
     */
    private static function conflictResolution(string $entityClass, string $constantName, string $storageClass, string $origin): string
    {
        return "Add #[UseMetaStorage(\\{$storageClass}::class)] to {$entityClass}, remove the #[MetaSchema] constant {$constantName}, or remove the key from {$origin}";
    }

    private function scopeKey(ObjectType $objectType, string $subtype, string $key): string
    {
        return $objectType->value . "\0" . $subtype . "\0" . $key;
    }
}
