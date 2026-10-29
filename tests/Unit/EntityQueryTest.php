<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use Brain\Monkey\Functions;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use TMSWP\Core\Entity\EntityReference;
use TMSWP\Core\Exception\EntityDefinitionException;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Exception\InvalidQueryException;
use TMSWP\Core\Post\PostQuery;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\PostTypeDefinition;
use TMSWP\Core\Query\CastType;
use TMSWP\Core\Query\Compare;
use TMSWP\Core\Query\PageResult;
use TMSWP\Core\Query\SortOrder;
use TMSWP\Core\Query\TermField;
use TMSWP\Core\Query\TermOperator;
use TMSWP\Core\Query\UserSearchColumn;
use TMSWP\Core\Tax\Taxonomy;
use TMSWP\Core\Tax\TaxonomyDefinition;
use TMSWP\Core\Tax\TermQuery;
use TMSWP\Core\User\UserQuery;
use TMSWP\Core\User\UserRole;
use TMSWP\Core\User\UserRoleDefinition;
use TMSWP\Testing\UnitTestCase;

/**
 * エンティティのクエリ3種の引数の組み立て・値の検証・矛盾する指定・空の IN 指定を、WordPress を読み込まずに確かめる。
 *
 * ユニット層には WP_Query・WP_User_Query が無いため、終端メソッドが WordPress を呼べば失敗する。空の IN 指定の
 * テストが空の結果を返せることが、WordPress を呼ばないことの確認になる(get_terms は呼ばれないことを明示的に確かめる)。
 */
final class EntityQueryTest extends UnitTestCase
{
    public function testPostQueryBuildsScopedArgsFromEnums(): void
    {
        $args = PostQuery::of(QueryTestProductPost::class)
            ->status('draft')
            ->whereMeta('price', 100, Compare::Gte, CastType::Numeric)
            ->whereTerm('product_category', ['news'])
            ->whereTerm(QueryTestCategoryTaxonomy::class, [3, 4], TermField::TermId, TermOperator::And)
            ->orderBy('date', SortOrder::Desc)
            ->limit(5)
            ->withArgs(['post_type' => 'ignored'])
            ->toArgs();

        self::assertSame('product', $args['post_type']);
        self::assertSame(['draft'], $args['post_status']);
        self::assertSame(5, $args['posts_per_page']);
        self::assertSame(
            ['relation' => 'AND', ['key' => 'price', 'value' => 100, 'compare' => '>=', 'type' => 'NUMERIC']],
            $args['meta_query'],
        );
        self::assertSame(
            [
                'relation' => 'AND',
                ['taxonomy' => 'product_category', 'field' => 'slug', 'terms' => ['news'], 'operator' => 'IN'],
                ['taxonomy' => 'product_category', 'field' => 'term_id', 'terms' => [3, 4], 'operator' => 'AND'],
            ],
            $args['tax_query'],
        );
        self::assertSame(['date' => 'DESC'], $args['orderby']);
    }

    /**
     * 既定値: PostQuery は post_status=['publish']・ignore_sticky_posts=true・posts_per_page=-1、TermQuery は hide_empty=false、
     * UserQuery は count_total=false。
     */
    public function testQueriesStartFromTheDocumentedDefaults(): void
    {
        self::assertSame(
            ['post_status' => ['publish'], 'ignore_sticky_posts' => true, 'posts_per_page' => -1, 'post_type' => 'product'],
            PostQuery::of(QueryTestProductPost::class)->toArgs(),
        );
        self::assertSame(['hide_empty' => false, 'taxonomy' => 'product_category'], TermQuery::of(QueryTestCategoryTaxonomy::class)->toArgs());
        self::assertSame(['fields' => 'all', 'count_total' => false, 'role' => 'member'], UserQuery::of(QueryTestMemberRole::class)->toArgs());
    }

    /** スコープ(post_type・taxonomy・role)は、withArgs() で渡しても上書きできない(呼ぶ順に関係ない)。 */
    public function testScopeCannotBeOverriddenByWithArgs(): void
    {
        self::assertSame('product', PostQuery::of(QueryTestProductPost::class)->withArgs(['post_type' => 'page'])->toArgs()['post_type']);
        self::assertSame('product', PostQuery::of(QueryTestProductPost::class)->withArgs(['post_type' => ['page', 'post']])->status('draft')->toArgs()['post_type']);
        self::assertSame('product_category', TermQuery::of(QueryTestCategoryTaxonomy::class)->withArgs(['taxonomy' => 'category'])->toArgs()['taxonomy'] ?? null);
        self::assertSame('member', UserQuery::of(QueryTestMemberRole::class)->withArgs(['role' => 'administrator'])->toArgs()['role'] ?? null);
    }

    /** @return iterable<string, array{Closure(PostQuery): PostQuery, array<string, mixed>, list<string>}> */
    public static function postQueryMappings(): iterable
    {
        yield 'status' => [static fn(PostQuery $q): PostQuery => $q->status('draft', 'pending'), ['post_status' => ['draft', 'pending']], []];
        yield 'status empty' => [static fn(PostQuery $q): PostQuery => $q->status(), ['post__in' => [0], 'ignore_sticky_posts' => true], []];
        yield 'anyStatus' => [static fn(PostQuery $q): PostQuery => $q->anyStatus(), ['post_status' => 'any'], []];
        yield 'search' => [static fn(PostQuery $q): PostQuery => $q->search('keyword'), ['s' => 'keyword'], []];
        yield 'author' => [static fn(PostQuery $q): PostQuery => $q->author(5), ['author' => 5], []];
        yield 'author zero' => [static fn(PostQuery $q): PostQuery => $q->author(0), ['post__in' => [0], 'ignore_sticky_posts' => true], ['author']];
        yield 'author after zero' => [static fn(PostQuery $q): PostQuery => $q->author(0)->author(5), ['author' => 5], ['post__in']];
        yield 'parent' => [static fn(PostQuery $q): PostQuery => $q->parent(7), ['post_parent' => 7], []];
        yield 'slugIn' => [static fn(PostQuery $q): PostQuery => $q->slugIn('a', 'b'), ['post_name__in' => ['a', 'b']], []];
        yield 'slugIn empty' => [static fn(PostQuery $q): PostQuery => $q->slugIn(), ['post__in' => [0]], ['post_name__in']];
        yield 'idIn' => [static fn(PostQuery $q): PostQuery => $q->idIn(3, 1), ['post__in' => [3, 1]], ['post__not_in']];
        yield 'idNotIn' => [static fn(PostQuery $q): PostQuery => $q->idNotIn(4, 5), ['post__not_in' => [4, 5]], ['post__in']];
        yield 'idIn minus idNotIn' => [static fn(PostQuery $q): PostQuery => $q->idNotIn(2)->idIn(1, 2), ['post__in' => [1]], ['post__not_in']];
        yield 'idNotIn empty' => [static fn(PostQuery $q): PostQuery => $q->idNotIn(), [], ['post__in', 'post__not_in']];
        yield 'whereMeta' => [
            static fn(PostQuery $q): PostQuery => $q->whereMeta('price', 100, Compare::Gte, CastType::Numeric),
            ['meta_query' => ['relation' => 'AND', ['key' => 'price', 'value' => 100, 'compare' => '>=', 'type' => 'NUMERIC']]],
            [],
        ];
        yield 'whereMeta defaults' => [
            static fn(PostQuery $q): PostQuery => $q->whereMeta('color', 'red'),
            ['meta_query' => ['relation' => 'AND', ['key' => 'color', 'value' => 'red', 'compare' => '=', 'type' => 'CHAR']]],
            [],
        ];
        yield 'whereMeta IN empty' => [static fn(PostQuery $q): PostQuery => $q->whereMeta('color', [], Compare::In), ['post__in' => [0]], ['meta_query']];
        yield 'whereMeta NOT IN empty' => [static fn(PostQuery $q): PostQuery => $q->whereMeta('color', [], Compare::NotIn), [], ['meta_query', 'post__in']];
        yield 'whereMetaExists' => [static fn(PostQuery $q): PostQuery => $q->whereMetaExists('featured'), ['meta_query' => ['relation' => 'AND', ['key' => 'featured', 'compare' => 'EXISTS']]], []];
        yield 'whereMetaNotExists' => [static fn(PostQuery $q): PostQuery => $q->whereMetaNotExists('hidden'), ['meta_query' => ['relation' => 'AND', ['key' => 'hidden', 'compare' => 'NOT EXISTS']]], []];
        yield 'whereTerm' => [
            static fn(PostQuery $q): PostQuery => $q->whereTerm('category', [3, 4], TermField::TermId, TermOperator::And),
            ['tax_query' => ['relation' => 'AND', ['taxonomy' => 'category', 'field' => 'term_id', 'terms' => [3, 4], 'operator' => 'AND']]],
            [],
        ];
        yield 'whereTerm scalar defaults' => [
            static fn(PostQuery $q): PostQuery => $q->whereTerm('category', 'news'),
            ['tax_query' => ['relation' => 'AND', ['taxonomy' => 'category', 'field' => 'slug', 'terms' => ['news'], 'operator' => 'IN']]],
            [],
        ];
        yield 'whereTerm IN empty' => [static fn(PostQuery $q): PostQuery => $q->whereTerm('category', []), ['post__in' => [0]], ['tax_query']];
        yield 'whereTerm AND empty' => [static fn(PostQuery $q): PostQuery => $q->whereTerm('category', [], TermField::Slug, TermOperator::And), ['post__in' => [0]], ['tax_query']];
        yield 'whereTerm NOT IN empty' => [static fn(PostQuery $q): PostQuery => $q->whereTerm('category', [], TermField::Slug, TermOperator::NotIn), [], ['tax_query', 'post__in']];
        yield 'template' => [
            static fn(PostQuery $q): PostQuery => $q->template('templates/landing.php'),
            ['meta_query' => ['relation' => 'AND', ['key' => '_wp_page_template', 'value' => 'templates/landing.php', 'compare' => '=', 'type' => 'CHAR']]],
            [],
        ];
        yield 'hasThumbnail' => [static fn(PostQuery $q): PostQuery => $q->hasThumbnail(), ['meta_query' => ['relation' => 'AND', ['key' => '_thumbnail_id', 'compare' => 'EXISTS']]], []];
        yield 'hasThumbnail false' => [static fn(PostQuery $q): PostQuery => $q->hasThumbnail(false), ['meta_query' => ['relation' => 'AND', ['key' => '_thumbnail_id', 'compare' => 'NOT EXISTS']]], []];
        yield 'orderBy accumulates and overwrites the same field' => [
            static fn(PostQuery $q): PostQuery => $q->orderBy('date', SortOrder::Desc)->orderBy('title')->orderBy('date'),
            ['orderby' => ['date' => 'ASC', 'title' => 'ASC']],
            ['order'],
        ];
        yield 'orderByDesc' => [static fn(PostQuery $q): PostQuery => $q->orderByDesc('date'), ['orderby' => ['date' => 'DESC']], ['order']];
        yield 'withArgs is merged after orderBy' => [static fn(PostQuery $q): PostQuery => $q->withArgs(['order' => 'DESC'])->orderBy('title'), ['orderby' => ['title' => 'ASC'], 'order' => 'DESC'], []];
        yield 'orderByMeta' => [
            static fn(PostQuery $q): PostQuery => $q->orderByMeta('price', SortOrder::Desc, CastType::Numeric),
            [
                'meta_query' => ['relation' => 'AND', ['relation' => 'OR', 'tms_order_0' => ['key' => 'price', 'compare' => 'NOT EXISTS', 'type' => 'NUMERIC'], ['key' => 'price', 'compare' => 'EXISTS']]],
                'orderby' => ['tms_order_0' => 'DESC'],
            ],
            [],
        ];
        yield 'limit' => [static fn(PostQuery $q): PostQuery => $q->limit(5), ['posts_per_page' => 5], ['offset', 'nopaging']];
        yield 'offset' => [static fn(PostQuery $q): PostQuery => $q->limit(5)->offset(10), ['posts_per_page' => 5, 'offset' => 10], []];
        yield 'noPaging' => [static fn(PostQuery $q): PostQuery => $q->noPaging(), ['nopaging' => true, 'posts_per_page' => -1], []];
        yield 'ignoreSticky' => [static fn(PostQuery $q): PostQuery => $q->ignoreSticky(false), ['ignore_sticky_posts' => false], []];
        yield 'withArgs' => [static fn(PostQuery $q): PostQuery => $q->withArgs(['post_status' => 'private', 'menu_order' => 3]), ['post_status' => 'private', 'menu_order' => 3], []];
    }

    /**
     * PostQuery::toArgs() が docs/03 §7.3 の対応表どおりの引数を作る(表の行ごとに1件)。
     *
     * @param Closure(PostQuery): PostQuery $build
     * @param array<string, mixed> $expected
     * @param list<string> $absent
     */
    #[DataProvider('postQueryMappings')]
    public function testPostQueryToArgsFollowsTheMappingTable(Closure $build, array $expected, array $absent): void
    {
        $args = $build(PostQuery::of(QueryTestProductPost::class))->toArgs();

        foreach ($expected as $key => $value) {
            self::assertArrayHasKey($key, $args);
            self::assertSame($value, $args[$key], $key);
        }

        foreach ($absent as $key) {
            self::assertArrayNotHasKey($key, $args);
        }

        self::assertSame('product', $args['post_type']);
    }

    /** whereTerm() にタクソノミーのクラスを渡すと、そのスラッグに解決する。 */
    public function testWhereTermResolvesTaxonomyClassToItsSlug(): void
    {
        $args = PostQuery::of(QueryTestProductPost::class)->whereTerm(QueryTestCategoryTaxonomy::class, 'news')->toArgs();

        self::assertSame(
            ['relation' => 'AND', ['taxonomy' => 'product_category', 'field' => 'slug', 'terms' => ['news'], 'operator' => 'IN']],
            $args['tax_query'],
        );
    }

    /**
     * 3つのクエリは、比較演算子・キャスト型・並び順・whereTerm() の field・operator を enum で受け、文字列で受ける引数を
     * 持たない。EXISTS は Compare に含めない。
     */
    public function testQueriesAcceptOperatorsCastsAndOrdersOnlyAsEnums(): void
    {
        $enumParameters = ['compare' => Compare::class, 'type' => CastType::class, 'direction' => SortOrder::class, 'operator' => TermOperator::class];
        $checked = 0;

        foreach ([PostQuery::class, TermQuery::class, UserQuery::class] as $queryClass) {
            foreach ((new ReflectionClass($queryClass))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getParameters() as $parameter) {
                    $expected = $enumParameters[$parameter->getName()] ?? null;

                    if ($method->getName() === 'whereTerm' && $parameter->getName() === 'field') {
                        $expected = TermField::class;
                    }

                    if ($queryClass === UserQuery::class && $method->getName() === 'search' && $parameter->getName() === 'columns') {
                        $expected = UserSearchColumn::class;
                    }

                    if ($expected === null) {
                        continue;
                    }

                    $type = $parameter->getType();
                    self::assertInstanceOf(ReflectionNamedType::class, $type, "{$queryClass}::{$method->getName()}() \${$parameter->getName()}");
                    self::assertSame($expected, $type->getName(), "{$queryClass}::{$method->getName()}() \${$parameter->getName()}");
                    ++$checked;
                }
            }
        }

        // PostQuery の whereMeta・orderBy・orderByDesc 以外にも検査が及んでいる(空の検査にしない)
        self::assertGreaterThanOrEqual(15, $checked);
        self::assertSame([], array_values(array_filter(Compare::cases(), static fn(Compare $compare): bool => str_contains($compare->value, 'EXISTS'))));
    }

    /** PostQuery::author() は負の ID を受け付けない(WP_Query は負の author を除外として扱うため)。 */
    public function testPostQueryAuthorRejectsNegativeIds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('PostQuery::author() must be 0 or greater, -3 given');

        PostQuery::of(QueryTestProductPost::class)->author(-3);
    }

    /** UserSearchColumn のケースは、WP 7.0 の WP_User_Query が search_columns で受け付ける列とちょうど一致する。 */
    public function testUserSearchColumnCoversTheColumnsWpUserQueryAccepts(): void
    {
        self::assertSame(
            ['ID', 'user_login', 'user_email', 'user_url', 'user_nicename', 'display_name'],
            array_map(static fn(UserSearchColumn $column): string => $column->value, UserSearchColumn::cases()),
        );
    }

    /** 件数を指定しない PostQuery は posts_per_page=-1 を明示し、サイトの設定(既定10件)に依存しない。 */
    public function testPostQueryWithoutLimitRequestsAllPosts(): void
    {
        $args = PostQuery::of(QueryTestProductPost::class)->toArgs();

        self::assertSame(-1, $args['posts_per_page']);
        self::assertArrayNotHasKey('nopaging', $args);
    }

    /** TermQuery・UserQuery は件数を指定しないと number を渡さず、WordPress の既定(全件)になる。 */
    public function testTermAndUserQueriesWithoutLimitDoNotPassNumber(): void
    {
        self::assertArrayNotHasKey('number', TermQuery::of(QueryTestCategoryTaxonomy::class)->toArgs());
        self::assertArrayNotHasKey('number', UserQuery::of(QueryTestMemberRole::class)->toArgs());
    }

    public function testTermQueryBuildsScopedArgsFromEnums(): void
    {
        $args = TermQuery::of(QueryTestCategoryTaxonomy::class)
            ->hideEmpty()
            ->whereMeta('rank', [1, 2], Compare::In, CastType::Unsigned)
            ->whereMetaExists('featured')
            ->orderBy('name', SortOrder::Desc)
            ->limit(3)
            ->offset(6)
            ->withArgs(['taxonomy' => 'ignored'])
            ->toArgs();

        self::assertSame('product_category', $args['taxonomy'] ?? null);
        self::assertTrue($args['hide_empty'] ?? null);
        self::assertSame(
            [
                'relation' => 'AND',
                ['key' => 'rank', 'value' => [1, 2], 'compare' => 'IN', 'type' => 'UNSIGNED'],
                ['key' => 'featured', 'compare' => 'EXISTS'],
            ],
            $args['meta_query'] ?? null,
        );
        self::assertSame('name', $args['orderby'] ?? null);
        self::assertSame('DESC', $args['order'] ?? null);
        self::assertSame(3, $args['number'] ?? null);
        self::assertSame(6, $args['offset'] ?? null);
    }

    public function testUserQueryBuildsScopedArgsFromEnums(): void
    {
        $args = UserQuery::of(QueryTestMemberRole::class)
            ->search('tanaka', UserSearchColumn::UserLogin, UserSearchColumn::DisplayName)
            ->whereMetaNotExists('disabled_at')
            ->orderBy('registered', SortOrder::Desc)
            ->limit(10)
            ->withArgs(['role' => 'ignored'])
            ->toArgs();

        self::assertSame('member', $args['role'] ?? null);
        self::assertSame('*tanaka*', $args['search'] ?? null);
        self::assertSame(['user_login', 'display_name'], $args['search_columns'] ?? null);
        self::assertSame(['relation' => 'AND', ['key' => 'disabled_at', 'compare' => 'NOT EXISTS']], $args['meta_query'] ?? null);
        self::assertSame(['registered' => 'DESC'], $args['orderby'] ?? null);
        self::assertSame(10, $args['number'] ?? null);
    }

    /**
     * orderByMeta() は、名前付きの NOT EXISTS の句と EXISTS の句を OR で結んだグループを足し、名前付きの句で並び替える
     * (絞り込みにならず、メタを持たないレコードも残る)。
     */
    public function testOrderByMetaAddsNamedNotExistsClauseOredWithExistsForAllQueries(): void
    {
        $group = static fn(string $name, string $key, string $type): array => [
            'relation' => 'OR',
            $name => ['key' => $key, 'compare' => 'NOT EXISTS', 'type' => $type],
            ['key' => $key, 'compare' => 'EXISTS'],
        ];

        $postArgs = PostQuery::of(QueryTestProductPost::class)
            ->whereMeta('color', 'red')
            ->orderByMeta('price', SortOrder::Desc, CastType::Numeric)
            ->orderByMeta('code')
            ->toArgs();
        self::assertSame(
            [
                'relation' => 'AND',
                ['key' => 'color', 'value' => 'red', 'compare' => '=', 'type' => 'CHAR'],
                $group('tms_order_0', 'price', 'NUMERIC'),
                $group('tms_order_1', 'code', 'CHAR'),
            ],
            $postArgs['meta_query'],
        );
        self::assertSame(['tms_order_0' => 'DESC', 'tms_order_1' => 'ASC'], $postArgs['orderby']);

        $termArgs = TermQuery::of(QueryTestCategoryTaxonomy::class)->orderByMeta('rank', SortOrder::Desc, CastType::Numeric)->toArgs();
        self::assertSame(['relation' => 'AND', $group('tms_order_0', 'rank', 'NUMERIC')], $termArgs['meta_query'] ?? null);
        self::assertSame('tms_order_0', $termArgs['orderby'] ?? null);
        self::assertSame('DESC', $termArgs['order'] ?? null);

        $userArgs = UserQuery::of(QueryTestMemberRole::class)->orderByMeta('kana')->toArgs();
        self::assertSame(['relation' => 'AND', $group('tms_order_0', 'kana', 'CHAR')], $userArgs['meta_query'] ?? null);
        self::assertSame(['tms_order_0' => 'ASC'], $userArgs['orderby'] ?? null);
    }

    public function testTermQueryRejectsSecondOrderByIncludingOrderByMeta(): void
    {
        try {
            TermQuery::of(QueryTestCategoryTaxonomy::class)->orderBy('name')->orderByDesc('slug');
            self::fail('InvalidQueryException was not thrown for orderBy');
        } catch (InvalidQueryException $exception) {
            self::assertSame('TermQuery supports only one orderBy clause', $exception->getMessage());
        }

        $this->expectException(InvalidQueryException::class);
        TermQuery::of(QueryTestCategoryTaxonomy::class)->orderByMeta('rank')->orderBy('name');
    }

    /**
     * 空の IN 指定は結果を0件に確定させ、終端メソッドは WordPress を呼ばずに空の結果を返す。
     *
     * @param Closure(): (PostQuery|TermQuery|UserQuery) $build
     */
    #[DataProvider('emptyInQueries')]
    public function testEmptyInReturnsEmptyResultsWithoutCallingWordPress(Closure $build): void
    {
        Functions\expect('get_terms')->never();
        Functions\expect('get_query_var')->never();

        $query = $build();

        self::assertSame([], $query->get());
        self::assertNull($query->first());
        self::assertSame([], $query->ids());
        self::assertSame(0, $query->count());
        self::assertFalse($query->exists());
        self::assertNull($query->url());

        if (!$query instanceof TermQuery) {
            $page = $query->paginate(10, 2);
            self::assertSame([], $page->items);
            self::assertSame(0, $page->total);
            self::assertSame(10, $page->perPage);
            self::assertSame(2, $page->currentPage);
            // page を省略しても現在のページを解決せず(get_query_var を呼ばず)、1ページ目にする
            self::assertSame(1, $query->paginate(10)->currentPage);
        }
    }

    /** @return iterable<string, array{Closure(): (PostQuery|TermQuery|UserQuery)}> */
    public static function emptyInQueries(): iterable
    {
        $post = static fn(): PostQuery => PostQuery::of(QueryTestProductPost::class);
        $term = static fn(): TermQuery => TermQuery::of(QueryTestCategoryTaxonomy::class);
        $user = static fn(): UserQuery => UserQuery::of(QueryTestMemberRole::class);

        yield 'post idIn' => [static fn(): PostQuery => $post()->idIn()];
        yield 'post slugIn' => [static fn(): PostQuery => $post()->slugIn()];
        yield 'post status' => [static fn(): PostQuery => $post()->status()];
        yield 'post whereTerm IN' => [static fn(): PostQuery => $post()->whereTerm('category', [])];
        yield 'post whereTerm AND' => [static fn(): PostQuery => $post()->whereTerm('category', [], TermField::TermId, TermOperator::And)];
        yield 'post whereMeta IN' => [static fn(): PostQuery => $post()->whereMeta('color', [], Compare::In)];
        yield 'post idIn after values' => [static fn(): PostQuery => $post()->idIn(1, 2)->idIn()];
        yield 'post author zero' => [static fn(): PostQuery => $post()->author(0)];
        yield 'post author zero after values' => [static fn(): PostQuery => $post()->author(5)->author(0)];
        yield 'post idIn all excluded by idNotIn' => [static fn(): PostQuery => $post()->idIn(1, 2)->idNotIn(2, 1)];
        yield 'term idIn' => [static fn(): TermQuery => $term()->idIn()];
        yield 'term slugIn' => [static fn(): TermQuery => $term()->slugIn()];
        yield 'term forPost' => [static fn(): TermQuery => $term()->forPost()];
        yield 'term whereMeta IN' => [static fn(): TermQuery => $term()->whereMeta('rank', [], Compare::In)];
        yield 'term idIn all excluded by idNotIn' => [static fn(): TermQuery => $term()->idNotIn(3)->idIn(3)];
        yield 'user idIn' => [static fn(): UserQuery => $user()->idIn()];
        yield 'user whereMeta IN' => [static fn(): UserQuery => $user()->whereMeta('kana', [], Compare::In)];
        yield 'user idIn all excluded by idNotIn' => [static fn(): UserQuery => $user()->idIn(4)->idNotIn(4, 5)];
    }

    /** 空の IN 指定のクエリの toArgs() は、WordPress に渡しても0件になる引数(存在しない ID 0 への絞り込み)を含む。 */
    public function testEmptyInArgsMatchNothingEvenWhenPassedToWordPress(): void
    {
        $postArgs = PostQuery::of(QueryTestProductPost::class)->idIn()->ignoreSticky(false)->withArgs(['post__in' => [5]])->toArgs();
        self::assertSame([0], $postArgs['post__in']);
        self::assertTrue($postArgs['ignore_sticky_posts']);

        self::assertSame([0], TermQuery::of(QueryTestCategoryTaxonomy::class)->forPost()->toArgs()['include'] ?? null);
        self::assertSame([0], UserQuery::of(QueryTestMemberRole::class)->idIn()->toArgs()['include'] ?? null);
    }

    /** 値のある IN 指定は、前の空の指定による0件の確定を取り消す(同じ引数の指定は後勝ち)。 */
    public function testNonEmptyInOverridesEarlierEmptyIn(): void
    {
        $args = PostQuery::of(QueryTestProductPost::class)->idIn()->idIn(7)->toArgs();
        self::assertSame([7], $args['post__in']);

        $args = PostQuery::of(QueryTestProductPost::class)->status()->anyStatus()->toArgs();
        self::assertSame('any', $args['post_status']);
        self::assertArrayNotHasKey('post__in', $args);
    }

    /**
     * idIn() と idNotIn() を併用すると、include(post__in)から exclude(post__not_in)を差し引いた ID だけを渡す
     * (WordPress は include を指定すると exclude を無視するため)。idNotIn() だけなら exclude を渡す。
     */
    public function testIdInMinusIdNotInIsPassedAsInclusionOnly(): void
    {
        $postArgs = PostQuery::of(QueryTestProductPost::class)->idIn(3, 1, 2)->idNotIn(1, 9)->toArgs();
        self::assertSame([3, 2], $postArgs['post__in']);
        self::assertArrayNotHasKey('post__not_in', $postArgs);
        self::assertSame([4, 5], PostQuery::of(QueryTestProductPost::class)->idNotIn(4, 5)->toArgs()['post__not_in']);

        $termArgs = TermQuery::of(QueryTestCategoryTaxonomy::class)->idNotIn(1)->idIn(1, 2)->toArgs();
        self::assertSame([2], $termArgs['include'] ?? null);
        self::assertArrayNotHasKey('exclude', $termArgs);
        self::assertSame([4], TermQuery::of(QueryTestCategoryTaxonomy::class)->idNotIn(4)->toArgs()['exclude'] ?? null);

        $userArgs = UserQuery::of(QueryTestMemberRole::class)->idIn(1, 2)->idNotIn(2)->toArgs();
        self::assertSame([1], $userArgs['include'] ?? null);
        self::assertArrayNotHasKey('exclude', $userArgs);
        self::assertSame([4], UserQuery::of(QueryTestMemberRole::class)->idNotIn(4)->toArgs()['exclude'] ?? null);
    }

    /** 空の除外(idNotIn()・NOT IN の空の配列)は何もしない(条件なし)。 */
    public function testEmptyExclusionAddsNoCondition(): void
    {
        $postArgs = PostQuery::of(QueryTestProductPost::class)
            ->idNotIn(3)
            ->idNotIn()
            ->whereMeta('color', [], Compare::NotIn)
            ->whereTerm('category', [], TermField::Slug, TermOperator::NotIn)
            ->toArgs();
        self::assertArrayNotHasKey('post__not_in', $postArgs);
        self::assertArrayNotHasKey('meta_query', $postArgs);
        self::assertArrayNotHasKey('tax_query', $postArgs);
        self::assertArrayNotHasKey('post__in', $postArgs);

        $termArgs = TermQuery::of(QueryTestCategoryTaxonomy::class)->idNotIn()->whereMeta('rank', [], Compare::NotIn)->toArgs();
        self::assertArrayNotHasKey('exclude', $termArgs);
        self::assertArrayNotHasKey('meta_query', $termArgs);
        self::assertArrayNotHasKey('include', $termArgs);

        $userArgs = UserQuery::of(QueryTestMemberRole::class)->idNotIn()->whereMeta('kana', [], Compare::NotIn)->toArgs();
        self::assertArrayNotHasKey('exclude', $userArgs);
        self::assertArrayNotHasKey('meta_query', $userArgs);
        self::assertArrayNotHasKey('include', $userArgs);
    }

    /** @param Closure(): mixed $call */
    #[DataProvider('invalidPagingValues')]
    public function testInvalidPagingValuesAreRejected(Closure $call, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $call();
    }

    /** @return iterable<string, array{Closure(): mixed, string}> */
    public static function invalidPagingValues(): iterable
    {
        $post = static fn(): PostQuery => PostQuery::of(QueryTestProductPost::class);
        $term = static fn(): TermQuery => TermQuery::of(QueryTestCategoryTaxonomy::class);
        $user = static fn(): UserQuery => UserQuery::of(QueryTestMemberRole::class);

        yield 'post limit 0' => [static fn(): mixed => $post()->limit(0), 'PostQuery::limit() must be 1 or greater, 0 given'];
        yield 'post limit -1' => [static fn(): mixed => $post()->limit(-1), 'PostQuery::limit() must be 1 or greater, -1 given'];
        yield 'post offset -1' => [static fn(): mixed => $post()->offset(-1), 'PostQuery::offset() must be 0 or greater, -1 given'];
        yield 'post perPage 0' => [static fn(): mixed => $post()->paginate(0, 1), 'PostQuery::paginate() perPage must be 1 or greater, 0 given'];
        yield 'post page 0' => [static fn(): mixed => $post()->paginate(10, 0), 'PostQuery::paginate() page must be 1 or greater, 0 given'];
        yield 'term limit 0' => [static fn(): mixed => $term()->limit(0), 'TermQuery::limit() must be 1 or greater, 0 given'];
        yield 'term offset -1' => [static fn(): mixed => $term()->offset(-1), 'TermQuery::offset() must be 0 or greater, -1 given'];
        yield 'user limit -1' => [static fn(): mixed => $user()->limit(-1), 'UserQuery::limit() must be 1 or greater, -1 given'];
        yield 'user offset -1' => [static fn(): mixed => $user()->offset(-1), 'UserQuery::offset() must be 0 or greater, -1 given'];
        yield 'user perPage 0' => [static fn(): mixed => $user()->paginate(0, 1), 'UserQuery::paginate() perPage must be 1 or greater, 0 given'];
        yield 'user page -1' => [static fn(): mixed => $user()->paginate(10, -1), 'UserQuery::paginate() page must be 1 or greater, -1 given'];
        yield 'page result perPage 0' => [static fn(): mixed => new PageResult([], 0, 0, 1), 'PageResult perPage must be 1 or greater, 0 given'];
        yield 'page result currentPage 0' => [static fn(): mixed => new PageResult([], 0, 10, 0), 'PageResult currentPage must be 1 or greater, 0 given'];
        yield 'page result total -1' => [static fn(): mixed => new PageResult([], -1, 10, 1), 'PageResult total must be 0 or greater, -1 given'];
    }

    /** @param Closure(): mixed $call */
    #[DataProvider('contradictoryPagingSpecifications')]
    public function testContradictoryPagingSpecificationsAreRejected(Closure $call, string $message): void
    {
        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage($message);

        $call();
    }

    /** @return iterable<string, array{Closure(): mixed, string}> */
    public static function contradictoryPagingSpecifications(): iterable
    {
        $post = static fn(): PostQuery => PostQuery::of(QueryTestProductPost::class);
        $term = static fn(): TermQuery => TermQuery::of(QueryTestCategoryTaxonomy::class);
        $user = static fn(): UserQuery => UserQuery::of(QueryTestMemberRole::class);

        yield 'post noPaging then paginate' => [static fn(): mixed => $post()->noPaging()->paginate(10, 1), 'PostQuery::paginate() cannot be combined with noPaging()'];
        yield 'post offset then paginate' => [static fn(): mixed => $post()->limit(5)->offset(5)->paginate(10, 1), 'PostQuery::paginate() cannot be combined with offset()'];
        yield 'post noPaging then limit' => [static fn(): mixed => $post()->noPaging()->limit(5), 'PostQuery::limit() cannot be combined with noPaging()'];
        yield 'post limit then noPaging' => [static fn(): mixed => $post()->limit(5)->noPaging(), 'PostQuery::noPaging() cannot be combined with limit()'];
        yield 'post noPaging then offset' => [static fn(): mixed => $post()->noPaging()->offset(5), 'PostQuery::offset() cannot be combined with noPaging()'];
        yield 'post offset then noPaging' => [static fn(): mixed => $post()->offset(5)->noPaging(), 'PostQuery::noPaging() cannot be combined with offset()'];
        yield 'post offset without limit' => [static fn(): mixed => $post()->offset(5)->toArgs(), 'PostQuery::offset() requires limit()'];
        yield 'post offset without limit on get' => [static fn(): mixed => $post()->idIn()->offset(5)->get(), 'PostQuery::offset() requires limit()'];
        yield 'term offset without limit' => [static fn(): mixed => $term()->offset(5)->toArgs(), 'TermQuery::offset() requires limit()'];
        yield 'user offset without limit' => [static fn(): mixed => $user()->offset(5)->toArgs(), 'UserQuery::offset() requires limit()'];
        yield 'user offset then paginate' => [static fn(): mixed => $user()->offset(5)->paginate(10, 1), 'UserQuery::paginate() cannot be combined with offset()'];
        yield 'post limit then paginate' => [static fn(): mixed => $post()->limit(5)->paginate(10, 1), 'PostQuery::paginate() cannot be combined with limit()'];
        yield 'user limit then paginate' => [static fn(): mixed => $user()->limit(5)->paginate(10, 1), 'UserQuery::paginate() cannot be combined with limit()'];
    }

    /** noPaging() は nopaging と全件の posts_per_page を渡す。 */
    public function testNoPagingRequestsAllPosts(): void
    {
        $args = PostQuery::of(QueryTestProductPost::class)->noPaging()->toArgs();

        self::assertTrue($args['nopaging']);
        self::assertSame(-1, $args['posts_per_page']);
    }

    public function testPageResultCalculatesPaginationState(): void
    {
        $page = new PageResult(['a', 'b'], 25, 10, 2);

        self::assertSame(3, $page->totalPages());
        self::assertTrue($page->hasPrev());
        self::assertTrue($page->hasNext());
        self::assertFalse($page->isEmpty());
        self::assertCount(2, $page);
        self::assertSame(0, (new PageResult([], 0, 10, 1))->totalPages());
    }

    /** `\` を含む文字列はクラスの参照とみなし、存在しないクラスは例外にする。`\` を含まない文字列はスラッグのまま。 */
    public function testEntityReferenceTreatsStringsWithBackslashAsClassReferences(): void
    {
        self::assertSame('product_category', EntityReference::taxonomySlug(QueryTestCategoryTaxonomy::class));
        self::assertSame('product', EntityReference::postTypeSlug(QueryTestProductPost::class));
        self::assertSame('category', EntityReference::taxonomySlug('category'));
        self::assertSame('page', EntityReference::postTypeSlug('page'));

        $cases = [
            'missing taxonomy class' => [static fn(): string => EntityReference::taxonomySlug('App\Tax\MissingTax'), "class 'App\\Tax\\MissingTax' does not exist"],
            'missing post type class' => [static fn(): string => EntityReference::postTypeSlug('App\Post\MissingPost'), "class 'App\\Post\\MissingPost' does not exist"],
            'whereTerm with missing class' => [static fn(): PostQuery => PostQuery::of(QueryTestProductPost::class)->whereTerm('App\Tax\MissingTax', 'news'), "class 'App\\Tax\\MissingTax' does not exist"],
            'class of another base' => [static fn(): string => EntityReference::taxonomySlug(QueryTestProductPost::class), "class '" . QueryTestProductPost::class . "' must extend " . Taxonomy::class],
        ];

        foreach ($cases as $name => [$call, $message]) {
            try {
                $call();
                self::fail("EntityDefinitionException was not thrown for {$name}");
            } catch (EntityDefinitionException $exception) {
                self::assertSame($message, $exception->getMessage(), $name);
            }
        }
    }
}

final class QueryTestProductPost extends PostType
{
    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('product')->label('Product', 'Products');
    }
}

final class QueryTestCategoryTaxonomy extends Taxonomy
{
    public static function definition(): TaxonomyDefinition
    {
        return TaxonomyDefinition::create('product_category')->label('Category', 'Categories');
    }
}

final class QueryTestMemberRole extends UserRole
{
    public static function definition(): UserRoleDefinition
    {
        return UserRoleDefinition::create('member')->label('Member');
    }
}
