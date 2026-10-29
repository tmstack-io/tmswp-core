<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use stdClass;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\PostTypeDefinition;
use TMSWP\Core\Tax\Taxonomy;
use TMSWP\Core\User\UserRole;
use TMSWP\Testing\IntegrationTestCase;
use WP_Term;
use WP_User;

/**
 * エンティティの契約(ブロックエディタのフィルタの値の扱い・TermQueryとUserQueryのfirst()の件数・ユーザーの
 * create()のmeta_inputの検査)を、実物のWordPressで確かめる(docs/03-entities.mdの受け入れ基準)。
 */
final class EntityContractIntegrationTest extends IntegrationTestCase
{
    /**
     * docs/03-entities.mdの「`blockEditor()`の`use_block_editor_for_post_type`のフィルタが、bool以外(null等)の値を
     * 受けても落ちず、対象外の投稿タイプの値を受け取ったまま返す」を確かめる。他のプラグインのフィルタが返しうる
     * bool以外の値(数値文字列・数値・配列・object)を、対象外の投稿タイプには型も含めてそのまま返し(objectは
     * 同じインスタンス)、対象の投稿タイプには宣言した値を返す。WordPressのuse_block_editor_for_post_type()も
     * 宣言した値になる。
     */
    public function testBlockEditorFilterPassesNonBoolValuesOfOtherPostTypesThroughAsTheyAre(): void
    {
        EntityContractBlockEditorOffPage::register(self::themeKernel());
        $values = ['1', '0', '', 'yes', 0, 1, 0.0, [], ['enabled'], new stdClass(), null];

        foreach ($values as $index => $value) {
            self::assertSame($value, apply_filters('use_block_editor_for_post_type', $value, 'post'), "value #{$index} for post");
            self::assertFalse(apply_filters('use_block_editor_for_post_type', $value, 'page'), "value #{$index} for page");
        }

        // 投稿タイプの引数がstring以外でも落ちず、値をそのまま返す
        $object = new stdClass();
        self::assertSame($object, apply_filters('use_block_editor_for_post_type', $object, null));
        self::assertSame('1', apply_filters('use_block_editor_for_post_type', '1', 0));

        self::assertFalse(use_block_editor_for_post_type('page'));
        self::assertTrue(use_block_editor_for_post_type('post'));
    }

    /**
     * docs/03-entities.mdの「`first()`が`noPaging()`・`limit()`に関係なく1件だけ取得する(統合テストでSQLの
     * `LIMIT 0, 1`を確認)」を、TermQueryで確かめる(TermQueryにnoPaging()は無い。docs/03 §10.3)。階層の無い
     * タクソノミーでは、WordPressはタームのSQLに`LIMIT 1`(offset()があれば`LIMIT {offset},1`)を付ける。
     * limit()を指定しても1件だけを取得し、offset()は効く。
     */
    public function testTermQueryFirstFetchesASingleRowEvenWithLimit(): void
    {
        $tag = self::productTag();
        $termIds = [];

        foreach (['First A', 'First B', 'First C'] as $name) {
            $term = wp_insert_term($name, $tag::slug());
            self::assertIsArray($term);
            $termIds[] = (int) $term['term_id'];
        }

        $requests = $this->recordMainQueries('terms_pre_query');

        $first = $tag::query()->orderBy('term_id')->limit(3)->first();
        $second = $tag::query()->orderBy('term_id')->limit(3)->offset(1)->first();

        self::assertInstanceOf(WP_Term::class, $first);
        self::assertSame($termIds[0], $first->term_id);
        self::assertInstanceOf(WP_Term::class, $second);
        self::assertSame($termIds[1], $second->term_id);
        self::assertCount(2, $requests->sql);
        self::assertMatchesRegularExpression('/\sLIMIT 1\s*\z/', $requests->sql[0]);
        self::assertMatchesRegularExpression('/\sLIMIT 1,1\s*\z/', $requests->sql[1]);
    }

    /**
     * docs/03-entities.mdの「`first()`が`noPaging()`・`limit()`に関係なく1件だけ取得する(統合テストでSQLの
     * `LIMIT 0, 1`を確認)」を、UserQueryで確かめる(UserQueryにnoPaging()は無い。docs/03 §12)。limit()を指定しても
     * ユーザーのSQLは`LIMIT 0, 1`で、offset()は効く(`LIMIT 1, 1`)。
     */
    public function testUserQueryFirstFetchesASingleRowEvenWithLimit(): void
    {
        $member = self::memberRole();
        $userIds = [];

        for ($i = 0; $i < 3; ++$i) {
            $userId = self::factory()->user->create(['role' => $member::role()]);
            self::assertIsInt($userId);
            $userIds[] = $userId;
        }

        $requests = $this->recordMainQueries('users_pre_query');

        $first = $member::query()->orderBy('ID')->limit(3)->first();
        $second = $member::query()->orderBy('ID')->limit(3)->offset(1)->first();

        self::assertInstanceOf(WP_User::class, $first);
        self::assertSame($userIds[0], $first->ID);
        self::assertInstanceOf(WP_User::class, $second);
        self::assertSame($userIds[1], $second->ID);
        self::assertCount(2, $requests->sql);
        self::assertMatchesRegularExpression('/\sLIMIT 0, 1\s*\z/', $requests->sql[0]);
        self::assertMatchesRegularExpression('/\sLIMIT 1, 1\s*\z/', $requests->sql[1]);
    }

    /**
     * docs/03-entities.mdの「`meta_input`の`null`・objectは`InvalidArgumentException`になり、何も作らない(統合テスト)」
     * のうち、ユーザーのcreate()のmeta_inputのnullを確かめる(投稿のnullとユーザーのobjectは
     * EntityFacadeIntegrationTestが確かめる)。WordPressを呼ぶ前に例外にし、ユーザーもメタも作らない。
     */
    public function testUserCreateRejectsNullInMetaInputAndCreatesNothing(): void
    {
        $member = self::memberRole();
        $login = 'tmswp_null_meta_user';
        $usersBefore = (int) self::wpdb()->get_var('SELECT COUNT(*) FROM ' . self::wpdb()->users);

        try {
            $member::create($login, 'password', ['meta_input' => ['tmswp_valid' => 'kept', 'tmswp_null' => null]]);
            self::fail('InvalidArgumentException was not thrown');
        } catch (InvalidArgumentException $exception) {
            self::assertSame("meta 'tmswp_null' cannot store null; use delete() to remove it", $exception->getMessage());
        }

        self::assertFalse(get_user_by('login', $login));
        self::assertSame($usersBefore, (int) self::wpdb()->get_var('SELECT COUNT(*) FROM ' . self::wpdb()->users));
        self::assertSame('0', self::wpdb()->get_var('SELECT COUNT(*) FROM ' . self::wpdb()->usermeta . " WHERE meta_key = 'tmswp_valid'"));
    }

    /**
     * `$preQueryFilter`(terms_pre_query・users_pre_query)の直後にwpdbが発行するSQL(そのクエリの本体の検索)を控える。
     * pre_queryの後にキャッシュから結果を返した場合はSQLを発行しないため、控える件数が減る。
     */
    private function recordMainQueries(string $preQueryFilter): EntityContractRecordedQueries
    {
        $recorded = new EntityContractRecordedQueries();
        add_filter($preQueryFilter, static function (mixed $result) use ($recorded): mixed {
            $recorded->capturing = true;

            return $result;
        });
        add_filter('query', static function (string $sql) use ($recorded): string {
            if ($recorded->capturing) {
                $recorded->sql[] = $sql;
                $recorded->capturing = false;
            }

            return $sql;
        });

        return $recorded;
    }

    /** @return class-string<Taxonomy> */
    private static function productTag(): string
    {
        return self::fixtureClass(self::THEME_NAMESPACE . '\\Tax\\ProductTagTaxonomy', Taxonomy::class);
    }

    /** @return class-string<UserRole> */
    private static function memberRole(): string
    {
        return self::fixtureClass(self::THEME_NAMESPACE . '\\User\\MemberRole', UserRole::class);
    }
}

/** pre_queryのフィルタの直後に発行されたSQLの控え。*/
final class EntityContractRecordedQueries
{
    /** 次に発行されるSQLを控えるか */
    public bool $capturing = false;

    /** @var list<string> */
    public array $sql = [];
}

/** 固定ページのブロックエディタを無効にする、builtinのアダプタ。*/
final class EntityContractBlockEditorOffPage extends PostType
{
    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::builtin('page')->blockEditor(false);
    }
}
