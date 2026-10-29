<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;
use TMSWP\Core\Exception\EntityDefinitionException;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Label\LabelLanguage;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\PostTypeDefinition;
use TMSWP\Core\Tax\Taxonomy;
use TMSWP\Core\Tax\TaxonomyDefinition;
use TMSWP\Core\User\UserRoleDefinition;
use TMSWP\Testing\UnitTestCase;

final class EntityDefinitionTest extends UnitTestCase
{
    /**
     * label()・hasArchive() だけの定義の toArgs() は、FW の既定の public=true、生成した英語の labels、has_archive=true を含み、
     * 呼ばなかったセッターのキーを含まない。
     */
    public function testToArgsContainsOnlyTheDefaultAndTheSpecifiedArgs(): void
    {
        $args = PostTypeDefinition::create('product')->label('Product', 'Products')->hasArchive()->toArgs(LabelLanguage::En);

        self::assertSame(['public', 'has_archive', 'labels', 'label'], array_keys($args));
        self::assertArrayHasKey('public', $args);
        self::assertArrayHasKey('has_archive', $args);
        self::assertArrayHasKey('label', $args);
        self::assertTrue($args['public']);
        self::assertTrue($args['has_archive']);
        self::assertSame('Products', $args['label']);
        self::assertArrayHasKey('labels', $args);
        self::assertSame('Products', $args['labels']['name']);
        self::assertSame('Product', $args['labels']['singular_name']);
        self::assertSame('Add Product', $args['labels']['add_new_item']);
    }

    public function testPostTypeDefinitionBuildsArgsWithGeneratedLabels(): void
    {
        $args = PostTypeDefinition::create('product')
            ->label('Product', 'Products')
            ->labels(['menu_name' => 'Product Catalog'])
            ->hasArchive()
            ->supports('title', 'thumbnail')
            ->taxonomies(DefinitionTaxonomy::class)
            ->toArgs(LabelLanguage::En);

        self::assertArrayHasKey('labels', $args);
        self::assertArrayHasKey('label', $args);
        self::assertArrayHasKey('public', $args);
        self::assertArrayHasKey('has_archive', $args);
        self::assertArrayHasKey('supports', $args);
        self::assertArrayHasKey('taxonomies', $args);
        $labels = $args['labels'];

        self::assertTrue($args['public']);
        self::assertSame('Products', $args['label']);
        self::assertSame('Product', $labels['singular_name']);
        self::assertSame('Add Product', $labels['add_new_item']);
        self::assertSame('All Products', $labels['all_items']);
        self::assertSame('Product Catalog', $labels['menu_name']);
        // コアが非階層の投稿タイプで null にする parent_item_colon は渡さない(WordPress が同じ null で補う)
        self::assertArrayNotHasKey('parent_item_colon', $labels);
        self::assertTrue($args['has_archive']);
        self::assertSame(['title', 'thumbnail'], $args['supports']);
        self::assertSame(['product_category'], $args['taxonomies']);
    }

    /** labelLanguage(LabelLanguage::Ja) で日本語の labels になり、labels() はキー単位で上書きする。 */
    public function testPostTypeDefinitionCanGenerateJapaneseLabelsWhenExplicit(): void
    {
        $args = PostTypeDefinition::create('case')
            ->label('事例')
            ->labelLanguage(LabelLanguage::Ja)
            ->labels(['menu_name' => '導入事例'])
            ->toArgs(LabelLanguage::En);

        self::assertArrayHasKey('labels', $args);
        self::assertArrayHasKey('label', $args);
        $labels = $args['labels'];

        self::assertSame('事例', $args['label']);
        self::assertSame('事例', $labels['singular_name']);
        self::assertSame('新規事例を追加', $labels['add_new_item']);
        self::assertSame('事例が見つかりませんでした', $labels['not_found']);
        self::assertSame('導入事例', $labels['menu_name']);
        // 上書きしないキーは生成した値のまま
        self::assertSame('事例', $labels['name']);
    }

    /** label() も labels() の name も無い定義の toArgs() は、EntityDefinitionException を投げる。 */
    public function testDefinitionWithoutLabelOrLabelsNameIsRejectedAtArgsBuild(): void
    {
        $cases = [
            'post type' => [PostTypeDefinition::create('book'), "post type 'book' requires label() or labels(['name' => ...])"],
            'post type with labels but without name' => [PostTypeDefinition::create('book')->labels(['menu_name' => 'Books']), "post type 'book' requires label() or labels(['name' => ...])"],
            'taxonomy' => [TaxonomyDefinition::create('genre'), "taxonomy 'genre' requires label() or labels(['name' => ...])"],
            'taxonomy with labels but without name' => [TaxonomyDefinition::create('genre')->labels(['menu_name' => 'Genres']), "taxonomy 'genre' requires label() or labels(['name' => ...])"],
        ];

        foreach ($cases as $name => [$definition, $message]) {
            try {
                $definition->toArgs(LabelLanguage::Ja);
                self::fail("EntityDefinitionException was not thrown for {$name}");
            } catch (EntityDefinitionException $exception) {
                self::assertSame($message, $exception->getMessage(), $name);
            }
        }
    }

    public function testEnglishPostTypeLabelWithoutPluralIsRejectedAtArgsBuild(): void
    {
        $definition = PostTypeDefinition::create('book')->label('Book');

        $this->expectException(EntityDefinitionException::class);
        $this->expectExceptionMessage("post type 'book' requires a plural label for LabelLanguage::En: pass it as label('Book', '<plural>')");

        $definition->toArgs(LabelLanguage::En);
    }

    public function testEnglishTaxonomyLabelWithoutPluralIsRejectedAtArgsBuild(): void
    {
        $definition = TaxonomyDefinition::create('genre')->label('Genre');

        $this->expectException(EntityDefinitionException::class);
        $this->expectExceptionMessage("taxonomy 'genre' requires a plural label for LabelLanguage::En: pass it as label('Genre', '<plural>')");

        $definition->toArgs(LabelLanguage::En);
    }

    /** @return iterable<string, array{PostTypeDefinition|TaxonomyDefinition, string}> */
    public static function definitionsWithEmptyPlural(): iterable
    {
        foreach (LabelLanguage::cases() as $language) {
            yield "post type {$language->value}" => [PostTypeDefinition::create('person')->label('Person', '')->labelLanguage($language), "post type 'person'"];
            yield "taxonomy {$language->value}" => [TaxonomyDefinition::create('person_tag')->label('Person', '')->labelLanguage($language), "taxonomy 'person_tag'"];
        }
    }

    #[DataProvider('definitionsWithEmptyPlural')]
    public function testEmptyPluralLabelIsRejectedRegardlessOfLanguage(PostTypeDefinition|TaxonomyDefinition $definition, string $subject): void
    {
        $this->expectException(EntityDefinitionException::class);
        $this->expectExceptionMessage("{$subject} got an empty plural label: pass a non-empty plural, or omit the second argument of label() (null) to omit it");

        $definition->toArgs(LabelLanguage::En);
    }

    /** @return iterable<string, array{PostTypeDefinition|TaxonomyDefinition, string}> */
    public static function definitionsWithEmptySingular(): iterable
    {
        yield 'post type' => [PostTypeDefinition::create('book')->label('', 'Books'), "post type 'book'"];
        yield 'post type with labels name' => [PostTypeDefinition::create('book')->label('', 'Books')->labels(['name' => 'X']), "post type 'book'"];
        yield 'taxonomy' => [TaxonomyDefinition::create('genre')->label('', 'Books'), "taxonomy 'genre'"];
        yield 'taxonomy with labels name' => [TaxonomyDefinition::create('genre')->label('', 'Books')->labels(['name' => 'X']), "taxonomy 'genre'"];
    }

    #[DataProvider('definitionsWithEmptySingular')]
    public function testEmptySingularLabelIsRejectedEvenWithLabelsName(PostTypeDefinition|TaxonomyDefinition $definition, string $subject): void
    {
        $this->expectException(EntityDefinitionException::class);
        $this->expectExceptionMessage("{$subject} got an empty singular label: pass a non-empty singular to label()");

        $definition->toArgs(LabelLanguage::En);
    }

    public function testJapaneseTaxonomyLabelUsesSingularWhenPluralIsOmitted(): void
    {
        $args = TaxonomyDefinition::create('genre')
            ->label('ジャンル')
            ->labelLanguage(LabelLanguage::Ja)
            ->labels(['back_to_items' => '← ジャンルへ'])
            ->toArgs(LabelLanguage::En);

        self::assertArrayHasKey('labels', $args);
        $labels = $args['labels'];

        self::assertSame('ジャンル', $labels['name']);
        self::assertSame('ジャンル一覧', $labels['all_items']);
        self::assertSame('ジャンルを編集', $labels['edit_item']);
        self::assertSame('← ジャンルへ', $labels['back_to_items']);
        // コアが非階層のタクソノミーで null にする親に関するキーは渡さない(WordPress が同じ null で補う)
        self::assertArrayNotHasKey('parent_item', $labels);
        self::assertSame('よく使われているジャンル', $labels['popular_items']);
    }

    /** @return iterable<string, array{PostTypeDefinition|TaxonomyDefinition, string}> */
    public static function definitionsWithoutLabelLanguage(): iterable
    {
        yield 'post type' => [PostTypeDefinition::create('case')->label('事例'), '新規事例を追加'];
        yield 'taxonomy' => [TaxonomyDefinition::create('genre')->label('ジャンル'), '新規ジャンルを追加'];
    }

    #[DataProvider('definitionsWithoutLabelLanguage')]
    public function testUnspecifiedLabelLanguageUsesKernelDefault(PostTypeDefinition|TaxonomyDefinition $definition, string $addNewItem): void
    {
        // labelLanguage() を呼ばない定義は、渡された Kernel の既定(Ja)でラベルを作り、複数形を省略できる
        $args = $definition->toArgs(LabelLanguage::Ja);

        self::assertArrayHasKey('labels', $args);
        self::assertSame($addNewItem, $args['labels']['add_new_item']);
    }

    public function testExplicitLabelLanguageTakesPrecedenceOverKernelDefault(): void
    {
        $postType = PostTypeDefinition::create('book')->label('Book', 'Books')->labelLanguage(LabelLanguage::En)->toArgs(LabelLanguage::Ja);
        $taxonomy = TaxonomyDefinition::create('genre')->label('Genre', 'Genres')->labelLanguage(LabelLanguage::En)->toArgs(LabelLanguage::Ja);

        self::assertArrayHasKey('labels', $postType);
        self::assertSame('Add Book', $postType['labels']['add_new_item']);
        self::assertArrayHasKey('labels', $taxonomy);
        self::assertSame('Add Genre', $taxonomy['labels']['add_new_item']);
    }

    public function testEnglishKernelDefaultStillRequiresPluralForUnspecifiedDefinition(): void
    {
        $definition = PostTypeDefinition::create('case')->label('事例');

        $this->expectException(EntityDefinitionException::class);
        $this->expectExceptionMessage("or select LabelLanguage::Ja with labelLanguage() or KernelConfig's labelLanguage");

        $definition->toArgs(LabelLanguage::En);
    }

    public function testLabelsWithNameAloneAreUsedWithoutGeneration(): void
    {
        $args = PostTypeDefinition::create('book')
            ->labels(['name' => 'Books', 'singular_name' => 'Book'])
            ->toArgs(LabelLanguage::En);

        self::assertArrayHasKey('label', $args);
        self::assertArrayHasKey('labels', $args);
        self::assertSame('Books', $args['label']);
        self::assertSame(['name' => 'Books', 'singular_name' => 'Book'], $args['labels']);
    }

    public function testArgsSetsArgumentsWithoutDedicatedSetters(): void
    {
        $postType = PostTypeDefinition::create('book')
            ->label('Book', 'Books')
            ->args(['rest_namespace' => 'shop/v1', 'public' => false])
            ->toArgs(LabelLanguage::En);
        $taxonomy = TaxonomyDefinition::create('genre')
            ->label('Genre', 'Genres')
            ->args(['meta_box_cb' => false])
            ->toArgs(LabelLanguage::En);

        self::assertArrayHasKey('rest_namespace', $postType);
        self::assertArrayHasKey('public', $postType);
        self::assertSame('shop/v1', $postType['rest_namespace']);
        // FW の既定値 public => true も、args() で上書きできる
        self::assertFalse($postType['public']);
        self::assertArrayHasKey('meta_box_cb', $taxonomy);
        self::assertFalse($taxonomy['meta_box_cb']);
    }

    /** @return iterable<string, array{Closure(): mixed, string}> */
    public static function invalidSlugs(): iterable
    {
        yield 'post type not matching the pattern' => [static fn(): mixed => PostTypeDefinition::create('Invalid Slug!'), 'invalid post type slug: Invalid Slug!'];
        yield 'post type over 20 characters' => [static fn(): mixed => PostTypeDefinition::create(str_repeat('a', 21)), 'post type slug must be 20 characters or fewer: ' . str_repeat('a', 21)];
        yield 'builtin post type not matching the pattern' => [static fn(): mixed => PostTypeDefinition::builtin('Page'), 'invalid post type slug: Page'];
        yield 'taxonomy not matching the pattern' => [static fn(): mixed => TaxonomyDefinition::create('Invalid Slug!'), 'invalid taxonomy slug: Invalid Slug!'];
        yield 'taxonomy over 32 characters' => [static fn(): mixed => TaxonomyDefinition::create(str_repeat('a', 33)), 'taxonomy slug must be 32 characters or fewer: ' . str_repeat('a', 33)];
        yield 'user role not matching the pattern' => [static fn(): mixed => UserRoleDefinition::create('Invalid Role!'), 'invalid user role: Invalid Role!'];
    }

    /**
     * スラッグの形式の違反(英小文字・数字・_・- 以外)と、WordPress の上限(投稿タイプ20文字・タクソノミー32文字)を超える
     * スラッグは EntityDefinitionException になる。
     *
     * @param Closure(): mixed $create
     */
    #[DataProvider('invalidSlugs')]
    public function testInvalidSlugIsRejected(Closure $create, string $message): void
    {
        $this->expectException(EntityDefinitionException::class);
        $this->expectExceptionMessage($message);

        $create();
    }

    /** 上限ちょうどの長さのスラッグは受け付け、builtin() には長さの上限を適用しない。 */
    public function testSlugsUpToTheLimitAreAccepted(): void
    {
        self::assertSame(str_repeat('a', 20), PostTypeDefinition::create(str_repeat('a', 20))->slug());
        self::assertSame(str_repeat('a', 32), TaxonomyDefinition::create(str_repeat('a', 32))->slug());
        self::assertSame(str_repeat('a', 21), PostTypeDefinition::builtin(str_repeat('a', 21))->slug());
        self::assertSame(str_repeat('a', 33), TaxonomyDefinition::builtin(str_repeat('a', 33))->slug());
    }

    /** @return iterable<string, array{Closure(): (PostTypeDefinition|TaxonomyDefinition|UserRoleDefinition), string, list<string>}> */
    public static function builtinDefinitions(): iterable
    {
        yield 'post type' => [static fn(): PostTypeDefinition => PostTypeDefinition::builtin('page'), "is not allowed for builtin post type 'page'", ['supports', 'blockEditor']];
        yield 'taxonomy' => [static fn(): TaxonomyDefinition => TaxonomyDefinition::builtin('category'), "is not allowed for builtin taxonomy 'category'", ['objectType']];
        yield 'user role' => [static fn(): UserRoleDefinition => UserRoleDefinition::builtin('editor'), "is not allowed for builtin role 'editor'", ['capabilities']];
    }

    /**
     * builtin() の定義は、許可されたセッター(投稿タイプは supports()・blockEditor()、タクソノミーは objectType()、ロールは
     * capabilities())以外のセッターを呼んだ時点で EntityDefinitionException を投げる。セッターは定義クラスの公開メソッドの
     * うち自身を返すものをすべて対象にする(セッターを足したときも検査から漏れない)。
     *
     * @param Closure(): (PostTypeDefinition|TaxonomyDefinition|UserRoleDefinition) $builtin
     * @param list<string> $allowed
     */
    #[DataProvider('builtinDefinitions')]
    public function testBuiltinDefinitionRejectsEverySetterExceptTheAllowedOnes(Closure $builtin, string $message, array $allowed): void
    {
        $class = new ReflectionClass($builtin());
        $setters = [];

        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $returnType = $method->getReturnType();

            // 戻り値の型 self の名前は、PHP の版により self か解決したクラス名になる
            if (!$method->isStatic() && $returnType instanceof ReflectionNamedType && \in_array($returnType->getName(), ['self', $class->getName()], true)) {
                $setters[] = $method;
            }
        }

        $names = array_map(static fn(ReflectionMethod $method): string => $method->getName(), $setters);
        // 許可されたセッターがすべてあり、それ以外のセッターもある(空の検査にしない)
        self::assertSame([], array_values(array_diff($allowed, $names)));
        self::assertNotSame([], array_values(array_diff($names, $allowed)));

        foreach ($setters as $method) {
            $definition = $builtin();
            $arguments = self::sampleArguments($method);

            if (\in_array($method->getName(), $allowed, true)) {
                self::assertSame($definition, $method->invokeArgs($definition, $arguments), $method->getName());
                continue;
            }

            try {
                $method->invokeArgs($definition, $arguments);
                self::fail("EntityDefinitionException was not thrown for {$method->getName()}()");
            } catch (EntityDefinitionException $exception) {
                self::assertSame("{$class->getShortName()}::{$method->getName()}() {$message}", $exception->getMessage());
            }
        }
    }

    /** allowedBlocks()は重複を除いて渡した順に保ち、呼ばなければnull(WordPressの既定のまま)。register_post_typeの引数には入れない。 */
    public function testAllowedBlocksKeepsUniqueBlockNamesInOrder(): void
    {
        $definition = PostTypeDefinition::create('product')->label('製品', '製品');
        self::assertNull($definition->allowedBlockNames());

        $definition->allowedBlocks('my-theme/card', 'core/paragraph', 'my-theme/card');

        self::assertSame(['my-theme/card', 'core/paragraph'], $definition->allowedBlockNames());
        self::assertArrayNotHasKey('allowed_block_types', $definition->toArgs(LabelLanguage::Ja));
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function invalidAllowedBlocks(): iterable
    {
        yield 'no block names' => [[], "post type 'product' got no block names in allowedBlocks(); pass at least one block name, or use blockEditor(false) to disable the block editor"];
        yield 'without namespace' => [['paragraph'], "post type 'product' got an invalid block name 'paragraph' in allowedBlocks(); use the form 'namespace/name'"];
        yield 'uppercase' => [['core/paragraph', 'Core/Heading'], "post type 'product' got an invalid block name 'Core/Heading' in allowedBlocks(); use the form 'namespace/name'"];
    }

    /** @param list<string> $blockNames */
    #[DataProvider('invalidAllowedBlocks')]
    public function testAllowedBlocksRejectsMissingOrInvalidBlockNames(array $blockNames, string $message): void
    {
        $this->expectException(EntityDefinitionException::class);
        $this->expectExceptionMessage($message);

        PostTypeDefinition::create('product')->allowedBlocks(...$blockNames);
    }

    public function testTaxonomyDefinitionResolvesPostTypeClassReferences(): void
    {
        $args = TaxonomyDefinition::create('product_category')
            ->label('製品カテゴリ')
            ->objectType(DefinitionPostType::class, DefinitionPostType::class)
            ->hierarchical()
            ->toArgs(LabelLanguage::Ja);

        self::assertArrayHasKey('hierarchical', $args);
        self::assertTrue($args['hierarchical']);
        self::assertSame(['product'], TaxonomyDefinition::create('product_category')->objectType(DefinitionPostType::class, DefinitionPostType::class)->resolvedObjectTypes());
    }

    /** objectType() に PostType 以外のクラス(Taxonomy のサブクラス等)の FQCN を渡すと、解決の時点で EntityDefinitionException になる。 */
    public function testObjectTypeRejectsClassesOtherThanPostTypes(): void
    {
        foreach ([DefinitionTaxonomy::class, Kernel::class] as $class) {
            try {
                TaxonomyDefinition::create('product_category')->objectType($class)->resolvedObjectTypes();
                self::fail("EntityDefinitionException was not thrown for {$class}");
            } catch (EntityDefinitionException $exception) {
                self::assertSame("class '{$class}' must extend " . PostType::class, $exception->getMessage());
            }
        }
    }

    public function testUserRoleDefinitionDeduplicatesCapabilities(): void
    {
        $definition = UserRoleDefinition::create('member')
            ->label('会員')
            ->capabilities('read', 'read', 'upload_files');

        self::assertSame('member', $definition->role());
        self::assertSame('会員', $definition->roleLabel());
        self::assertSame(['read', 'upload_files'], $definition->capabilityList());
    }

    /**
     * セッターの必須の引数に、その型の見本の値を渡す配列を返す(builtin の検査は値を使う前に例外にする)。
     *
     * @return list<mixed>
     */
    private static function sampleArguments(ReflectionMethod $method): array
    {
        $arguments = [];

        foreach ($method->getParameters() as $parameter) {
            if ($parameter->isOptional()) {
                break;
            }

            $type = $parameter->getType();
            $named = $type instanceof ReflectionUnionType ? $type->getTypes()[0] : $type;
            self::assertInstanceOf(ReflectionNamedType::class, $named);
            $arguments[] = match ($named->getName()) {
                'string' => 'sample',
                'int' => 1,
                'bool' => true,
                'array' => [],
                LabelLanguage::class => LabelLanguage::Ja,
                default => self::fail("no sample value for {$named->getName()} of {$method->getName()}()"),
            };
        }

        return $arguments;
    }
}

final class DefinitionPostType extends PostType
{
    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('product')->label('製品', '製品');
    }
}

final class DefinitionTaxonomy extends Taxonomy
{
    public static function definition(): TaxonomyDefinition
    {
        return TaxonomyDefinition::create('product_category')->label('製品カテゴリ', '製品カテゴリ');
    }
}
