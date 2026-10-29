<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use Closure;
use TMSWP\Core\Exception\EntityDefinitionException;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Post\PostQuery;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Query\CastType;
use TMSWP\Core\Query\Compare;
use TMSWP\Core\Query\SortOrder;
use TMSWP\Core\Query\UserSearchColumn;
use TMSWP\Core\Tax\Taxonomy;
use TMSWP\Core\Tax\TermQuery;
use TMSWP\Core\User\UserQuery;
use TMSWP\Core\User\UserRole;
use TMSWP\Testing\IntegrationTestCase;
use WP_Post;
use WP_Query;
use WP_Term;
use WP_User;
use WP_User_Query;

/**
 * エンティティのクエリ3種(PostQuery・TermQuery・UserQuery)の、空の IN 指定・件数の既定・orderByMeta の並び・
 * 終端メソッドの件数の扱いを、実物の WordPress で確かめる。
 */
final class EntityQueryIntegrationTest extends IntegrationTestCase
{
    /**
     * WordPress は空の IN 指定を「条件なし」と扱って全件を返す。FW のクエリは空の IN 指定で0件を返し、WordPress の
     * クエリを実行しない。
     */
    public function testEmptyInReturnsNothingWhereWordPressWouldReturnEverything(): void
    {
        $productPost = self::productPost();
        $category = self::productCategory();
        $member = self::memberRole();
        $postIds = [self::createPost($productPost::slug()), self::createPost($productPost::slug())];
        $termIds = [self::createTerm($category::slug(), 'Empty in A'), self::createTerm($category::slug(), 'Empty in B')];
        $userIds = [self::createUser($member::role()), self::createUser($member::role())];

        // WordPress に空の IN 指定を渡すと、全件が返る
        $wpPosts = new WP_Query(['post_type' => $productPost::slug(), 'post__in' => [], 'posts_per_page' => -1, 'fields' => 'ids']);
        self::assertEqualsCanonicalizing($postIds, $wpPosts->posts);
        self::assertEqualsCanonicalizing($termIds, get_terms(['taxonomy' => $category::slug(), 'hide_empty' => false, 'include' => [], 'fields' => 'ids']));
        self::assertEqualsCanonicalizing($termIds, get_terms(['taxonomy' => $category::slug(), 'hide_empty' => false, 'object_ids' => [], 'fields' => 'ids']));
        self::assertEqualsCanonicalizing($userIds, (new WP_User_Query(['role' => $member::role(), 'include' => [], 'fields' => 'ID']))->get_results());

        self::assertTerminalsReturnEmptyWithoutWordPress(
            static fn(): PostQuery => $productPost::query()->idIn(),
            static fn(): TermQuery => $category::query()->forPost(),
            static fn(): UserQuery => $member::query()->idIn(),
        );
        self::assertTerminalsReturnEmptyWithoutWordPress(
            static fn(): PostQuery => $productPost::query()->slugIn(),
            static fn(): TermQuery => $category::query()->idIn(),
            static fn(): UserQuery => $member::query()->whereMeta('tmswp_rank', [], Compare::In),
        );
    }

    /**
     * WordPress は author の 0 を「条件なし」、負の値を除外として扱う。PostQuery::author() は 0(未ログインの
     * wp_get_current_user() のように ID が 0 の WP_User を含む)で0件を返して WordPress のクエリを実行せず、
     * 負の値は InvalidArgumentException にする。
     */
    public function testAuthorZeroMatchesNothingAndNegativeIsRejected(): void
    {
        $productPost = self::productPost();
        $category = self::productCategory();
        $member = self::memberRole();
        $authorId = self::createUser($member::role());
        $ownPost = self::createAuthoredPost($productPost::slug(), $authorId);
        $otherPost = self::createAuthoredPost($productPost::slug(), self::createUser($member::role()));
        wp_set_current_user(0);
        $guest = wp_get_current_user();
        self::assertSame(0, $guest->ID);

        // WordPress に author=0 を渡すと全件、負の値を渡すとその投稿者を除いた全件が返る
        $wpAll = new WP_Query(['post_type' => $productPost::slug(), 'author' => 0, 'posts_per_page' => -1, 'fields' => 'ids']);
        self::assertEqualsCanonicalizing([$ownPost, $otherPost], $wpAll->posts);
        $wpExcluded = new WP_Query(['post_type' => $productPost::slug(), 'author' => -$authorId, 'posts_per_page' => -1, 'fields' => 'ids']);
        self::assertSame([$otherPost], $wpExcluded->posts);

        self::assertSame([$ownPost], $productPost::query()->author($authorId)->ids());
        self::assertSame([$ownPost], $productPost::query()->author(0)->author($authorId)->ids());
        self::assertTerminalsReturnEmptyWithoutWordPress(
            static fn(): PostQuery => $productPost::query()->author($guest),
            static fn(): TermQuery => $category::query()->idIn(),
            static fn(): UserQuery => $member::query()->idIn(),
        );
        self::assertTerminalsReturnEmptyWithoutWordPress(
            static fn(): PostQuery => $productPost::query()->author($authorId)->author(0),
            static fn(): TermQuery => $category::query()->idIn(),
            static fn(): UserQuery => $member::query()->idIn(),
        );

        try {
            $productPost::query()->author(-$authorId);
            self::fail('InvalidArgumentException was not thrown');
        } catch (InvalidArgumentException $exception) {
            self::assertSame("PostQuery::author() must be 0 or greater, -{$authorId} given", $exception->getMessage());
        }
    }

    /** UserQuery::search() の UserSearchColumn のすべての列を、WP_User_Query が検索の条件に使う(黙って捨てない)。 */
    public function testUserSearchUsesEveryColumnOfUserSearchColumn(): void
    {
        $member = self::memberRole();

        foreach (UserSearchColumn::cases() as $column) {
            $where = $member::query()->search('tmswp', $column)->wpUserQuery()->query_where;
            self::assertIsString($where);
            $condition = $column === UserSearchColumn::Id ? 'ID = ' : "{$column->value} LIKE ";

            self::assertStringContainsString($condition, $where, $column->name);
        }

        $userId = self::factory()->user->create(['role' => $member::role(), 'display_name' => 'Tmswp Searchable Display']);
        self::assertIsInt($userId);
        self::assertSame([$userId], $member::query()->search('Searchable Display', UserSearchColumn::DisplayName)->ids());
        self::assertSame([], $member::query()->search('Searchable Display', UserSearchColumn::UserLogin)->ids());
    }

    /**
     * WordPress は include(post__in)を指定すると exclude(post__not_in)を無視する。FW のクエリは idIn() から
     * idNotIn() を差し引いた ID に絞り、差し引いて空なら WordPress のクエリを実行せずに0件を返す。
     */
    public function testIdInCombinedWithIdNotInExcludesIds(): void
    {
        $productPost = self::productPost();
        $category = self::productCategory();
        $member = self::memberRole();
        [$postA, $postB, $postC] = [self::createPost($productPost::slug()), self::createPost($productPost::slug()), self::createPost($productPost::slug())];
        [$termA, $termB, $termC] = [self::createTerm($category::slug(), 'Exclude A'), self::createTerm($category::slug(), 'Exclude B'), self::createTerm($category::slug(), 'Exclude C')];
        [$userA, $userB, $userC] = [self::createUser($member::role()), self::createUser($member::role()), self::createUser($member::role())];

        // WordPress に include と exclude を両方渡すと、exclude が無視される
        $wpPosts = new WP_Query(['post_type' => $productPost::slug(), 'post__in' => [$postA, $postB, $postC], 'post__not_in' => [$postB], 'posts_per_page' => -1, 'fields' => 'ids']);
        self::assertEqualsCanonicalizing([$postA, $postB, $postC], $wpPosts->posts);
        self::assertEqualsCanonicalizing([$termA, $termB, $termC], get_terms(['taxonomy' => $category::slug(), 'hide_empty' => false, 'include' => [$termA, $termB, $termC], 'exclude' => [$termB], 'fields' => 'ids']));
        self::assertEqualsCanonicalizing([$userA, $userB, $userC], (new WP_User_Query(['role' => $member::role(), 'include' => [$userA, $userB, $userC], 'exclude' => [$userB], 'fields' => 'ID']))->get_results());

        self::assertEqualsCanonicalizing([$postA, $postC], $productPost::query()->idIn($postA, $postB, $postC)->idNotIn($postB)->ids());
        self::assertEqualsCanonicalizing([$termA, $termC], $category::query()->idIn($termA, $termB, $termC)->idNotIn($termB)->ids());
        self::assertEqualsCanonicalizing([$userA, $userC], $member::query()->idIn($userA, $userB, $userC)->idNotIn($userB)->ids());
        self::assertSame(2, $productPost::query()->idIn($postA, $postB, $postC)->idNotIn($postB)->count());
        self::assertSame(2, $member::query()->idIn($userA, $userB, $userC)->idNotIn($userB)->count());

        self::assertTerminalsReturnEmptyWithoutWordPress(
            static fn(): PostQuery => $productPost::query()->idIn($postA)->idNotIn($postA),
            static fn(): TermQuery => $category::query()->idIn($termA)->idNotIn($termA),
            static fn(): UserQuery => $member::query()->idIn($userA)->idNotIn($userA),
        );
    }

    /** 件数を指定しない get() は、サイトの posts_per_page の設定に関係なく全件を返す。 */
    public function testGetWithoutLimitReturnsAllPostsRegardlessOfPostsPerPageOption(): void
    {
        $productPost = self::productPost();
        update_option('posts_per_page', 2);
        $postIds = [self::createPost($productPost::slug()), self::createPost($productPost::slug()), self::createPost($productPost::slug())];

        // WordPress は posts_per_page を指定しないとサイトの設定を使う
        self::assertCount(2, (new WP_Query(['post_type' => $productPost::slug()]))->posts);

        self::assertEqualsCanonicalizing($postIds, array_map(static fn(WP_Post $post): int => $post->ID, $productPost::query()->get()));
        self::assertEqualsCanonicalizing($postIds, $productPost::query()->ids());
        self::assertCount(2, $productPost::query()->limit(2)->get());
    }

    /**
     * orderByMeta() は絞り込まず、メタを持たないレコードも結果に残す。メタを持たないレコードは ASC では先頭、DESC では
     * 末尾に並ぶ。メタを持たないレコードがほかのメタを持っていても、その値で並びが変わらない。
     */
    public function testOrderByMetaKeepsRecordsWithoutTheMetaAtAFixedPosition(): void
    {
        $productPost = self::productPost();
        $category = self::productCategory();
        $member = self::memberRole();

        $nine = self::createPost($productPost::slug());
        $ten = self::createPost($productPost::slug());
        $none = self::createPost($productPost::slug());
        add_post_meta($nine, 'tmswp_rank', '9');
        add_post_meta($ten, 'tmswp_rank', '10');
        add_post_meta($none, 'tmswp_other', '5');

        $ids = static fn(SortOrder $direction): array => array_map(
            static fn(WP_Post $post): int => $post->ID,
            $productPost::query()->orderByMeta('tmswp_rank', $direction, CastType::Numeric)->get(),
        );
        self::assertSame([$none, $nine, $ten], $ids(SortOrder::Asc));
        self::assertSame([$ten, $nine, $none], $ids(SortOrder::Desc));
        self::assertSame(3, $productPost::query()->orderByMeta('tmswp_rank')->count());

        $termNine = self::createTerm($category::slug(), 'Rank nine');
        $termTen = self::createTerm($category::slug(), 'Rank ten');
        $termNone = self::createTerm($category::slug(), 'Rank none');
        add_term_meta($termNine, 'tmswp_rank', '9');
        add_term_meta($termTen, 'tmswp_rank', '10');
        add_term_meta($termNone, 'tmswp_other', '5');

        $termIds = static fn(SortOrder $direction): array => array_map(
            static fn(WP_Term $term): int => $term->term_id,
            $category::query()->orderByMeta('tmswp_rank', $direction, CastType::Numeric)->get(),
        );
        self::assertSame([$termNone, $termNine, $termTen], $termIds(SortOrder::Asc));
        self::assertSame([$termTen, $termNine, $termNone], $termIds(SortOrder::Desc));

        $userNine = self::createUser($member::role());
        $userTen = self::createUser($member::role());
        $userNone = self::createUser($member::role());
        add_user_meta($userNine, 'tmswp_rank', '9');
        add_user_meta($userTen, 'tmswp_rank', '10');
        add_user_meta($userNone, 'tmswp_other', '5');

        $userIds = static fn(SortOrder $direction): array => array_map(
            static fn(WP_User $user): int => $user->ID,
            $member::query()->orderByMeta('tmswp_rank', $direction, CastType::Numeric)->get(),
        );
        self::assertSame([$userNone, $userNine, $userTen], $userIds(SortOrder::Asc));
        self::assertSame([$userTen, $userNine, $userNone], $userIds(SortOrder::Desc));
    }

    /**
     * orderByMeta() は件数を変えない。並び替えのメタが無く、ほかのメタを複数行持つレコードがあっても、count() は
     * 重複して数えない。whereMeta() の絞り込みは count() にも効く。
     */
    public function testOrderByMetaDoesNotChangeCount(): void
    {
        $productPost = self::productPost();
        $category = self::productCategory();
        $member = self::memberRole();
        // A: 順位9と印、B: 順位10、C: 順位なしで、印とほかのメタ(順位と関係ないメタが2行)
        $posts = [self::createPost($productPost::slug()), self::createPost($productPost::slug()), self::createPost($productPost::slug())];
        $terms = [self::createTerm($category::slug(), 'Count A'), self::createTerm($category::slug(), 'Count B'), self::createTerm($category::slug(), 'Count C')];
        $users = [self::createUser($member::role()), self::createUser($member::role()), self::createUser($member::role())];
        $metas = [['tmswp_rank' => '9', 'tmswp_flag' => '1'], ['tmswp_rank' => '10'], ['tmswp_flag' => '1', 'tmswp_other' => 'x']];

        foreach ($metas as $index => $meta) {
            foreach ($meta as $key => $value) {
                add_post_meta($posts[$index], $key, $value);
                add_term_meta($terms[$index], $key, $value);
                add_user_meta($users[$index], $key, $value);
            }
        }

        $queries = [
            'post' => [static fn(): PostQuery => $productPost::query()->idIn(...$posts), $posts],
            'term' => [static fn(): TermQuery => $category::query()->idIn(...$terms), $terms],
            'user' => [static fn(): UserQuery => $member::query()->idIn(...$users), $users],
        ];

        foreach ($queries as $name => [$query, $ids]) {
            self::assertSame(3, $query()->orderByMeta('tmswp_rank', SortOrder::Asc, CastType::Numeric)->count(), $name);
            self::assertSame(3, $query()->orderByMeta('tmswp_rank', SortOrder::Desc, CastType::Numeric)->count(), $name);
            self::assertSame(2, $query()->whereMeta('tmswp_flag', '1')->orderByMeta('tmswp_rank', SortOrder::Asc, CastType::Numeric)->count(), $name);
            self::assertSame([$ids[2], $ids[0]], $query()->whereMeta('tmswp_flag', '1')->orderByMeta('tmswp_rank', SortOrder::Asc, CastType::Numeric)->ids(), $name);
        }
    }

    /** first() は noPaging()・limit() に関係なく1件だけを取得し、全件を読み込まない。 */
    public function testFirstFetchesASingleRowEvenWithNoPagingOrLimit(): void
    {
        $productPost = self::productPost();
        self::createPost($productPost::slug());
        self::createPost($productPost::slug());
        self::createPost($productPost::slug());
        $requests = [];
        add_filter('posts_request', static function (string $sql) use (&$requests): string {
            $requests[] = $sql;

            return $sql;
        });

        self::assertInstanceOf(WP_Post::class, $productPost::query()->noPaging()->first());
        self::assertInstanceOf(WP_Post::class, $productPost::query()->limit(3)->first());

        self::assertCount(2, $requests);

        foreach ($requests as $sql) {
            self::assertStringContainsString('LIMIT 0, 1', $sql);
        }
    }

    /** paginate() は PageResult を返し、投稿25件・1ページ10件で total・totalPages()・hasNext() が正しい。 */
    public function testPaginateReturnsPageResultWithTotals(): void
    {
        $productPost = self::productPost();
        $postIds = [];

        for ($i = 0; $i < 25; ++$i) {
            $postIds[] = self::createPost($productPost::slug());
        }

        $first = $productPost::query()->orderBy('ID')->paginate(10, 1);
        self::assertSame([25, 3, true, 1], [$first->total, $first->totalPages(), $first->hasNext(), $first->currentPage]);
        self::assertSame(\array_slice($postIds, 0, 10), array_map(static fn(WP_Post $post): int => $post->ID, $first->items));

        $last = $productPost::query()->orderBy('ID')->paginate(10, 3);
        self::assertSame([25, 3, false, 3], [$last->total, $last->totalPages(), $last->hasNext(), $last->currentPage]);
        self::assertSame(\array_slice($postIds, 20), array_map(static fn(WP_Post $post): int => $post->ID, $last->items));
    }

    /** limit() と offset() の組み合わせは、その範囲だけを返す。count() は limit()・offset() を無視して全体の件数を返す。 */
    public function testLimitWithOffsetReturnsTheRangeAndCountIgnoresThem(): void
    {
        $productPost = self::productPost();
        $postIds = [];

        for ($i = 0; $i < 10; ++$i) {
            $postIds[] = self::createPost($productPost::slug());
        }

        self::assertSame(\array_slice($postIds, 5, 3), $productPost::query()->orderBy('ID')->limit(3)->offset(5)->ids());
        self::assertSame(10, $productPost::query()->limit(3)->offset(5)->count());
    }

    /**
     * count() は件数だけを求め、全件を読み込まない(fields=ids・posts_per_page=1 で検索し、found_posts を返す)。
     */
    public function testCountFetchesOnlyTheNumberOfPosts(): void
    {
        $productPost = self::productPost();

        for ($i = 0; $i < 3; ++$i) {
            self::createPost($productPost::slug());
        }

        $queries = [];
        add_filter('posts_request', static function (string $sql, WP_Query $query) use (&$queries): string {
            $queries[] = ['vars' => $query->query_vars, 'sql' => $sql];

            return $sql;
        }, 10, 2);

        self::assertSame(3, $productPost::query()->noPaging()->count());

        self::assertCount(1, $queries);
        self::assertSame('ids', $queries[0]['vars']['fields']);
        self::assertSame(1, $queries[0]['vars']['posts_per_page']);
        self::assertFalse($queries[0]['vars']['no_found_rows']);
        // WP_Query は posts_per_page が -1 でなければ nopaging を false にする(noPaging() の指定は外れている)
        self::assertFalse($queries[0]['vars']['nopaging']);
        self::assertStringContainsString('LIMIT 0, 1', $queries[0]['sql']);
    }

    /** `\` を含む文字列で存在しないクラスを指定すると、スラッグとして素通しせず EntityDefinitionException にする。 */
    public function testMissingClassReferenceThrowsInsteadOfBeingUsedAsSlug(): void
    {
        $productPost = self::productPost();
        $postId = self::createPost($productPost::slug());
        $missing = self::THEME_NAMESPACE . '\\Tax\\NoSuchTaxonomy';
        $cases = [
            'whereTerm' => static fn(): mixed => $productPost::query()->whereTerm($missing, 'news'),
            'terms' => static fn(): mixed => $productPost::terms($postId, $missing),
        ];

        foreach ($cases as $name => $call) {
            try {
                $call();
                self::fail("EntityDefinitionException was not thrown for {$name}");
            } catch (EntityDefinitionException $exception) {
                self::assertSame("class '{$missing}' does not exist", $exception->getMessage(), $name);
            }
        }
    }

    /**
     * 結果が0件と確定したクエリの全終端メソッド(wpQuery()・wpTermQuery()・wpUserQuery() と、page を省略した
     * paginate() を含む)が空の結果を返し、WordPress の検索を実行しない(検索のフックが呼ばれず、現在のページも
     * 解決しない)ことを確かめる。
     *
     * @param Closure(): PostQuery $post
     * @param Closure(): TermQuery $term
     * @param Closure(): UserQuery $user
     */
    private static function assertTerminalsReturnEmptyWithoutWordPress(Closure $post, Closure $term, Closure $user): void
    {
        $hooks = ['pre_get_posts', 'posts_pre_query', 'pre_get_terms', 'terms_pre_query', 'pre_get_users', 'pre_user_query', 'users_pre_query'];
        $calls = [];
        $record = static function (mixed $value = null) use (&$calls): mixed {
            $calls[] = current_filter();

            return $value;
        };

        foreach ($hooks as $hook) {
            add_filter($hook, $record);
        }

        // 現在のページを解決すれば 3 になる。0件と確定していれば解決せず 1 にする
        set_query_var('paged', 3);

        try {
            self::assertSame([], $post()->get());
            self::assertNull($post()->first());
            self::assertSame([], $post()->ids());
            self::assertSame(0, $post()->count());
            self::assertFalse($post()->exists());
            self::assertNull($post()->url());
            $postPage = $post()->paginate(10);
            self::assertSame([[], 0, 10, 1], [$postPage->items, $postPage->total, $postPage->perPage, $postPage->currentPage]);
            $wpQuery = $post()->wpQuery();
            self::assertSame([[], 0, 0, 0, false], [$wpQuery->posts, $wpQuery->post_count, $wpQuery->found_posts, $wpQuery->max_num_pages, $wpQuery->have_posts()]);

            self::assertSame([], $term()->get());
            self::assertNull($term()->first());
            self::assertSame([], $term()->ids());
            self::assertSame(0, $term()->count());
            self::assertFalse($term()->exists());
            self::assertNull($term()->url());
            self::assertSame([], $term()->wpTermQuery()->terms);

            self::assertSame([], $user()->get());
            self::assertNull($user()->first());
            self::assertSame([], $user()->ids());
            self::assertSame(0, $user()->count());
            self::assertFalse($user()->exists());
            self::assertNull($user()->url());
            $userPage = $user()->paginate(10);
            self::assertSame([[], 0, 10, 1], [$userPage->items, $userPage->total, $userPage->perPage, $userPage->currentPage]);
            $wpUserQuery = $user()->wpUserQuery();
            self::assertSame([[], 0], [$wpUserQuery->get_results(), $wpUserQuery->get_total()]);
        } finally {
            foreach ($hooks as $hook) {
                remove_filter($hook, $record);
            }

            set_query_var('paged', 0);
        }

        self::assertSame([], $calls, 'a query that matches nothing must not run a WordPress query');

        // 返した WP のオブジェクトで検索をやり直しても、0件のままになる
        self::assertSame([], $post()->wpQuery()->get_posts());
        self::assertSame([], $term()->wpTermQuery()->get_terms());
    }

    /** @return class-string<PostType> */
    private static function productPost(): string
    {
        return self::fixtureClass(self::THEME_NAMESPACE . '\\Post\\ProductPost', PostType::class);
    }

    /** @return class-string<Taxonomy> */
    private static function productCategory(): string
    {
        return self::fixtureClass(self::THEME_NAMESPACE . '\\Tax\\ProductCategoryTaxonomy', Taxonomy::class);
    }

    /** @return class-string<UserRole> */
    private static function memberRole(): string
    {
        return self::fixtureClass(self::THEME_NAMESPACE . '\\User\\MemberRole', UserRole::class);
    }

    private static function createTerm(string $taxonomy, string $name): int
    {
        $term = wp_insert_term($name, $taxonomy);
        self::assertIsArray($term);

        return (int) $term['term_id'];
    }

    private static function createAuthoredPost(string $postType, int $authorId): int
    {
        $postId = wp_insert_post(['post_type' => $postType, 'post_status' => 'publish', 'post_title' => 'Authored', 'post_author' => $authorId]);
        self::assertIsInt($postId);
        self::assertGreaterThan(0, $postId);

        return $postId;
    }

    private static function createUser(string $role): int
    {
        $userId = self::factory()->user->create(['role' => $role]);
        self::assertIsInt($userId);

        return $userId;
    }
}
