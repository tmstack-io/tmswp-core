<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use TMSWP\Core\Post\PostQuery;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\Thumbnail;
use TMSWP\Core\Tax\Taxonomy;
use TMSWP\Core\Tax\TermQuery;
use TMSWP\Core\User\UserQuery;
use TMSWP\Core\User\UserRole;
use TMSWP\Testing\UnitTestCase;

/**
 * エンティティの取得系の戻り値の型(docs/03 §1.3の?Tの統一)を、宣言から確かめる。
 */
final class EntityFacadeSignatureTest extends UnitTestCase
{
    /** @return iterable<string, array{class-string, string, string}> */
    public static function singleGetters(): iterable
    {
        yield 'PostType::get' => [PostType::class, 'get', 'WP_Post'];
        yield 'PostType::findBySlug' => [PostType::class, 'findBySlug', 'WP_Post'];
        yield 'PostType::firstTerm' => [PostType::class, 'firstTerm', 'WP_Term'];
        yield 'PostType::thumbnail' => [PostType::class, 'thumbnail', Thumbnail::class];
        yield 'Taxonomy::get' => [Taxonomy::class, 'get', 'WP_Term'];
        yield 'Taxonomy::findBySlug' => [Taxonomy::class, 'findBySlug', 'WP_Term'];
        yield 'Taxonomy::findByName' => [Taxonomy::class, 'findByName', 'WP_Term'];
        yield 'UserRole::get' => [UserRole::class, 'get', 'WP_User'];
        yield 'UserRole::findByEmail' => [UserRole::class, 'findByEmail', 'WP_User'];
        yield 'UserRole::findByLogin' => [UserRole::class, 'findByLogin', 'WP_User'];
        yield 'UserRole::current' => [UserRole::class, 'current', 'WP_User'];
        yield 'PostQuery::first' => [PostQuery::class, 'first', 'WP_Post'];
        yield 'TermQuery::first' => [TermQuery::class, 'first', 'WP_Term'];
        yield 'UserQuery::first' => [UserQuery::class, 'first', 'WP_User'];
    }

    /**
     * 単体取得は、見つからなければnullを返す?WP_Post・?WP_Term・?WP_User・?Thumbnailを宣言する。
     *
     * @param class-string $class
     */
    #[DataProvider('singleGetters')]
    public function testSingleGettersDeclareNullableEntityReturnTypes(string $class, string $method, string $entity): void
    {
        $type = (new ReflectionMethod($class, $method))->getReturnType();

        self::assertInstanceOf(ReflectionNamedType::class, $type);
        self::assertSame($entity, $type->getName());
        self::assertTrue($type->allowsNull());
    }

    /** エンティティとクエリの公開メソッドに、falseを返し得る戻り値の型は無い。 */
    public function testNoPublicMethodReturnsFalse(): void
    {
        $checked = 0;

        foreach ([PostType::class, Taxonomy::class, UserRole::class, PostQuery::class, TermQuery::class, UserQuery::class] as $class) {
            foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $type = $method->getReturnType();
                self::assertInstanceOf(ReflectionType::class, $type, "{$class}::{$method->getName()}() has no return type");
                self::assertNotContains('false', self::typeNames($type), "{$class}::{$method->getName()}()");
                ++$checked;
            }
        }

        self::assertGreaterThan(50, $checked);
    }

    /** @return list<string> */
    private static function typeNames(ReflectionType $type): array
    {
        if ($type instanceof ReflectionUnionType) {
            $names = [];

            foreach ($type->getTypes() as $member) {
                $names = [...$names, ...self::typeNames($member)];
            }

            return $names;
        }

        self::assertInstanceOf(ReflectionNamedType::class, $type);

        return [$type->getName()];
    }
}
