<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Exception\RuntimeException;
use TMSWP\Core\Kernel\DiscoveryFile;
use TMSWP\Core\Kernel\DiscoveryResult;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Log\LogChannel;
use TMSWP\Core\Meta\MetaStorageInterface;
use TMSWP\Core\Meta\ObjectType;
use TMSWP\Core\Meta\WpMetaStorage;
use TMSWP\Core\Tax\Taxonomy;
use TMSWP\Core\User\UserRole;
use TMSWP\Testing\BootsKernelBeforeInit;
use TMSWP\Testing\IntegrationTestCase;
use TMSWP\Testing\RecordingLogger;
use TMSWP\Testing\UsesTempDirectories;
use WP_Term;
use WP_User;

/**
 * タームとユーザーのメタ(ObjectType::Term・ObjectType::User)の、WordPressへの登録・WpMetaStorageの読み取り・
 * Taxonomy::meta()・UserRole::meta()の対象の検査を確かめる。投稿のメタの同じ主張はMetaIntegrationTestにある。
 */
final class TermUserMetaIntegrationTest extends IntegrationTestCase
{
    use BootsKernelBeforeInit;
    use UsesTempDirectories;

    private const TERM_COLOR = 'tmswp_term_color';

    private const MEMBER_NO = 'tmswp_member_no';

    public function tear_down(): void
    {
        $this->unregisterAppAutoloaders();
        $this->cleanupTempDirectories();
        parent::tear_down();
    }

    /**
     * docs/04の「#[MetaSchema]の6パラメータ…がすべてregister_*_meta引数へ正しくマップされる」を、fixtureのテーマの
     * タームとユーザーのエンティティで確かめる。製品カテゴリのtmswp_term_colorはregister_term_meta()で
     * タクソノミーに、会員のtmswp_member_noはregister_meta('user')でユーザーに、宣言どおり登録される。
     */
    public function testFixtureTermAndUserMetaSchemasAreRegisteredOnInit(): void
    {
        $term = get_registered_meta_keys('term', 'tmswp_product_cat');
        self::assertArrayHasKey(self::TERM_COLOR, $term);
        self::assertSame('string', $term[self::TERM_COLOR]['type']);
        self::assertSame('gray', $term[self::TERM_COLOR]['default']);
        self::assertTrue($term[self::TERM_COLOR]['single']);
        self::assertFalse($term[self::TERM_COLOR]['show_in_rest']);
        self::assertSame('', $term[self::TERM_COLOR]['description']);
        self::assertNull($term[self::TERM_COLOR]['sanitize_callback']);
        // タクソノミーに限って登録され、ほかのタクソノミーのタームには登録されない
        self::assertArrayNotHasKey(self::TERM_COLOR, get_registered_meta_keys('term', 'tmswp_product_tag'));

        $user = get_registered_meta_keys('user');
        self::assertArrayHasKey(self::MEMBER_NO, $user);
        self::assertSame('integer', $user[self::MEMBER_NO]['type']);
        self::assertSame(0, $user[self::MEMBER_NO]['default']);
        self::assertTrue($user[self::MEMBER_NO]['single']);
        self::assertFalse($user[self::MEMBER_NO]['show_in_rest']);
        self::assertSame('', $user[self::MEMBER_NO]['description']);
        self::assertNull($user[self::MEMBER_NO]['sanitize_callback']);
    }

    /**
     * docs/04の「#[MetaSchema]の6パラメータ(type/default/single/showInRest/description/sanitize)がすべて
     * register_*_meta引数へ正しくマップされる」を、タームとユーザーの分岐で、6つとも既定でない値で確かめる。
     * 一時のKernelのタクソノミーのメタはregister_term_meta()に、ユーザーのロールのメタはregister_meta('user')に、
     * 宣言した6つの値で登録される。
     */
    public function testEverySchemaArgumentReachesTermAndUserMetaRegistration(): void
    {
        $root = $this->createTempDirectory('integration-term-user-schema');
        self::assertTrue(mkdir($root . '/app'));
        $this->writeFile($root . '/plugin.php', "<?php\n");
        $config = KernelConfig::plugin($root . '/plugin.php', $root . '/app', 'TmswpIntegrationSchema' . uniqid(), true);
        $namespace = $config->appNamespace;
        $suffix = substr(md5($namespace), 0, 8);
        $taxonomy = 'tmswp_sc_' . $suffix;
        $role = 'tmswp_sr_' . $suffix;
        $this->writeFile($config->appDir . '/Tax/SchemaTaxonomy.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Tax;

use TMSWP\\Core\\Attribute\\AsTaxonomy;
use TMSWP\\Core\\Attribute\\MetaSchema;
use TMSWP\\Core\\Meta\\MetaValueType;
use TMSWP\\Core\\Tax\\Taxonomy;
use TMSWP\\Core\\Tax\\TaxonomyDefinition;

#[AsTaxonomy]
final class SchemaTaxonomy extends Taxonomy
{
    #[MetaSchema(type: MetaValueType::String, default: 'navy', showInRest: true, description: 'Term color', sanitize: [self::class, 'sanitizeColor'])]
    public const META_COLOR = 'tmswp_schema_term_color';

    #[MetaSchema(type: MetaValueType::String, single: false)]
    public const META_TAGS = 'tmswp_schema_term_tags';

    public static function sanitizeColor(mixed \$value): string
    {
        return \\is_string(\$value) ? trim(\$value) : '';
    }

    public static function definition(): TaxonomyDefinition
    {
        return TaxonomyDefinition::create('{$taxonomy}')->label('Schema', 'Schemas')->objectType('post');
    }
}
PHP);
        $this->writeFile($config->appDir . '/User/SchemaRole.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\User;

use TMSWP\\Core\\Attribute\\AsUserRole;
use TMSWP\\Core\\Attribute\\MetaSchema;
use TMSWP\\Core\\Meta\\MetaValueType;
use TMSWP\\Core\\User\\UserRole;
use TMSWP\\Core\\User\\UserRoleDefinition;

#[AsUserRole]
final class SchemaRole extends UserRole
{
    #[MetaSchema(type: MetaValueType::Integer, default: 3, showInRest: true, description: 'Member level', sanitize: [self::class, 'sanitizeLevel'])]
    public const META_LEVEL = 'tmswp_schema_member_level';

    #[MetaSchema(type: MetaValueType::String, single: false)]
    public const META_CODES = 'tmswp_schema_member_codes';

    public static function sanitizeLevel(mixed \$value): int
    {
        return \\is_numeric(\$value) ? (int) \$value : 0;
    }

    public static function definition(): UserRoleDefinition
    {
        return UserRoleDefinition::create('{$role}')->label('Schema')->capabilities('read');
    }
}
PHP);
        $this->registerAppAutoloader($config);

        try {
            self::fireInit($this->bootBeforeInit($config));
            $taxonomyClass = self::fixtureClass($namespace . '\\Tax\\SchemaTaxonomy', Taxonomy::class);
            $roleClass = self::fixtureClass($namespace . '\\User\\SchemaRole', UserRole::class);

            $term = get_registered_meta_keys('term', $taxonomy);
            self::assertArrayHasKey('tmswp_schema_term_color', $term);
            $color = $term['tmswp_schema_term_color'];
            self::assertSame('string', $color['type']);
            self::assertSame('navy', $color['default']);
            self::assertTrue($color['single']);
            self::assertTrue($color['show_in_rest']);
            self::assertSame('Term color', $color['description']);
            self::assertSame([$taxonomyClass, 'sanitizeColor'], $color['sanitize_callback']);
            self::assertArrayHasKey('tmswp_schema_term_tags', $term);
            self::assertFalse($term['tmswp_schema_term_tags']['single']);
            self::assertArrayNotHasKey('default', $term['tmswp_schema_term_tags']);

            $user = get_registered_meta_keys('user');
            self::assertArrayHasKey('tmswp_schema_member_level', $user);
            $level = $user['tmswp_schema_member_level'];
            self::assertSame('integer', $level['type']);
            self::assertSame(3, $level['default']);
            self::assertTrue($level['single']);
            self::assertTrue($level['show_in_rest']);
            self::assertSame('Member level', $level['description']);
            self::assertSame([$roleClass, 'sanitizeLevel'], $level['sanitize_callback']);
            self::assertArrayHasKey('tmswp_schema_member_codes', $user);
            self::assertFalse($user['tmswp_schema_member_codes']['single']);
            self::assertArrayNotHasKey('default', $user['tmswp_schema_member_codes']);

            // 登録の先の確認: タームのメタはそのタクソノミーだけ、ユーザーのメタは投稿・タームに登録されない
            self::assertArrayNotHasKey('tmswp_schema_term_color', get_registered_meta_keys('term', 'category'));
            self::assertArrayNotHasKey('tmswp_schema_member_level', get_registered_meta_keys('post', 'post'));
        } finally {
            unregister_taxonomy($taxonomy);
            remove_role($role);
        }
    }

    /**
     * docs/04の「register_metaのdefaultが設定されたキーでも、WpMetaStorage::get()/getAll()はdefaultを介在させず
     * 『行なし』をnull/[]として返す」を、タームとユーザーのメタで確かめる。WordPressのget_term_meta()・
     * get_user_meta()はdefaultを返す。
     */
    public function testWpMetaStorageReadsOfTermAndUserMetaIgnoreTheRegisteredDefault(): void
    {
        $storage = new WpMetaStorage(self::themeKernel());
        $termId = self::createTerm('tmswp_product_cat');
        $userId = self::createUser();
        // FWの宣言はsingle=falseとdefaultを併用できないため、行のlistのdefaultはWordPressに直接登録する
        register_term_meta('tmswp_product_cat', 'tmswp_raw_term_rows', ['type' => 'string', 'single' => false, 'default' => 'row-default']);
        register_meta('user', 'tmswp_raw_user_rows', ['type' => 'string', 'single' => false, 'default' => 'row-default']);

        // 確かめ方の確認: WordPressの読み取りはdefaultを返す
        self::assertSame('gray', get_term_meta($termId, self::TERM_COLOR, true));
        self::assertSame(['row-default'], get_term_meta($termId, 'tmswp_raw_term_rows'));
        self::assertSame(0, get_user_meta($userId, self::MEMBER_NO, true));
        self::assertSame(['row-default'], get_user_meta($userId, 'tmswp_raw_user_rows'));

        foreach ([[ObjectType::Term, $termId, self::TERM_COLOR, 'tmswp_raw_term_rows'], [ObjectType::User, $userId, self::MEMBER_NO, 'tmswp_raw_user_rows']] as [$type, $id, $single, $rows]) {
            self::assertFalse($storage->has($type, $id, $single), $type->value);
            self::assertNull($storage->get($type, $id, $single), $type->value);
            self::assertSame([], $storage->getAll($type, $id, $single), $type->value);
            self::assertFalse($storage->has($type, $id, $rows), $type->value);
            self::assertSame([], $storage->getAll($type, $id, $rows), $type->value);
            self::assertSame([], $storage->getMultiple($type, $id, [$single, $rows]), $type->value);
        }
    }

    /**
     * docs/04の「公開の読み取り…を単独で呼んだときのデータベースのエラーが、debugのKernelでは読み取りの対象と
     * last_errorをメッセージに含むRuntimeExceptionになる」と「読み取り…のデータベースのエラーの後、失敗した読み込みで
     * WPが書いたキャッシュ(メタの{$meta_type}_meta)が残らず、エラーを外して同じ対象を読み直すとデータベースの値が
     * 返る」を、タームのメタ(term_meta)とユーザーのメタ(user_meta)で確かめる。
     */
    public function testStandaloneTermAndUserMetaReadFailureThrowsInDebugAndLeavesNoEmptyCache(): void
    {
        self::assertTrue(self::themeKernel()->config()->debug);
        $storage = new WpMetaStorage(self::themeKernel());

        foreach (self::storedObjects($storage, 'tmswp_read_alone') as [$type, $id, $table]) {
            $group = $type->metaType() . '_meta';

            foreach (self::standaloneReads($storage, $type, $id, 'tmswp_read_alone') as $name => $read) {
                $label = "{$type->value} {$name}";
                // メタのキャッシュを消し、読み取りがメタの読み込みのSELECTを発行するようにする
                wp_cache_delete($id, $group);

                self::withBrokenQueries($table, 'SELECT', static function () use ($read, $label, $type, $id): void {
                    try {
                        $read();
                        self::fail("RuntimeException was not thrown for {$label}");
                    } catch (RuntimeException $exception) {
                        self::assertStringStartsWith("failed to read {$type->metaType()} meta 'tmswp_read_alone' for object {$id}: ", $exception->getMessage(), $label);
                        self::assertStringContainsString('tmswp_missing_table', $exception->getMessage(), $label);
                    }
                });

                self::assertFalse(wp_cache_get($id, $group), $label);
                self::assertSame('stored', $storage->get($type, $id, 'tmswp_read_alone'), $label);
            }
        }
    }

    /**
     * docs/04の「本番のKernelではそのKernelのロガーにerrorで記録されて、行・オプションが無いときと同じ結果
     * (false・null・空配列)が返る」と、失敗した読み込みのキャッシュが残らないことを、タームのメタとユーザーのメタで
     * 確かめる。
     */
    public function testStandaloneTermAndUserMetaReadFailureIsLoggedAndReturnsTheRowlessResultInProduction(): void
    {
        $logger = new RecordingLogger();
        $storage = $this->productionKernel('TermUserMetaReadFailure', $logger)->container()->get(MetaStorageInterface::class);
        self::assertInstanceOf(WpMetaStorage::class, $storage);
        $failures = [];

        foreach (self::storedObjects($storage, 'tmswp_read_logged') as [$type, $id, $table]) {
            $group = $type->metaType() . '_meta';
            $results = [];

            foreach (self::standaloneReads($storage, $type, $id, 'tmswp_read_logged') as $name => $read) {
                wp_cache_delete($id, $group);

                self::withBrokenQueries($table, 'SELECT', static function () use ($read, $name, &$results): void {
                    $results[$name] = $read();
                });

                self::assertFalse(wp_cache_get($id, $group), "{$type->value} {$name}");
                self::assertSame('stored', $storage->get($type, $id, 'tmswp_read_logged'), "{$type->value} {$name}");
                $failures[] = "failed to read {$type->metaType()} meta 'tmswp_read_logged' for object {$id}";
            }

            self::assertSame(['has' => false, 'get' => null, 'getAll' => [], 'getMultiple' => []], $results, $type->value);
        }

        self::assertCount(8, $logger->records);

        foreach ($logger->records as $index => $record) {
            self::assertSame('error', $record['level']);
            self::assertSame('tmswp.storage: {failure}: {error}', $record['message']);
            self::assertSame($failures[$index], $record['context']['failure']);
            self::assertIsString($record['context']['error']);
            self::assertStringContainsString('tmswp_missing_table', $record['context']['error']);
        }
    }

    /**
     * docs/03の「meta()が、無い対象・スコープ外の対象でInvalidArgumentExceptionを投げる」のうち、スコープ外の対象を、
     * タームとユーザーで確かめる。別のタクソノミーのターム・会員のロールを持たないユーザーを、IDでもオブジェクトでも
     * 渡すと、Taxonomy::meta()・UserRole::meta()はInvalidArgumentExceptionを投げる。
     */
    public function testMetaForTermOfAnotherTaxonomyOrUserOfAnotherRoleIsInvalidArgument(): void
    {
        $category = self::fixtureClass(self::THEME_NAMESPACE . '\\Tax\\ProductCategoryTaxonomy', Taxonomy::class);
        $member = self::fixtureClass(self::THEME_NAMESPACE . '\\User\\MemberRole', UserRole::class);
        $tagId = self::createTerm('tmswp_product_tag');
        $tag = get_term($tagId, 'tmswp_product_tag');
        self::assertInstanceOf(WP_Term::class, $tag);
        $subscriberId = self::createUser('subscriber');
        $subscriber = get_user_by('id', $subscriberId);
        self::assertInstanceOf(WP_User::class, $subscriber);

        // 確かめ方の確認: 同じタクソノミーのターム・会員のロールのユーザーならMetaBagを作れる
        $category::meta(self::createTerm('tmswp_product_cat'));
        $member::meta(self::createUser($member::role()));

        $cases = [
            'term id of another taxonomy' => [static fn(): mixed => $category::meta($tagId), 'term'],
            'term object of another taxonomy' => [static fn(): mixed => $category::meta($tag), 'term'],
            'user id of another role' => [static fn(): mixed => $member::meta($subscriberId), 'user'],
            'user object of another role' => [static fn(): mixed => $member::meta($subscriber), 'user'],
        ];

        foreach ($cases as $name => [$call, $kind]) {
            try {
                $call();
                self::fail("InvalidArgumentException was not thrown for {$name}");
            } catch (InvalidArgumentException $exception) {
                self::assertSame("cannot create MetaBag for missing or mismatched {$kind}", $exception->getMessage(), $name);
            }
        }
    }

    /** タクソノミー$taxonomyのタームを作り、そのIDを返す。 */
    private static function createTerm(string $taxonomy): int
    {
        $term = wp_insert_term('Integration ' . uniqid(), $taxonomy);
        self::assertIsArray($term);

        return (int) $term['term_id'];
    }

    /** ロール$roleのユーザーを作り、そのIDを返す。 */
    private static function createUser(string $role = 'subscriber'): int
    {
        $userId = self::factory()->user->create(['role' => $role]);
        self::assertIsInt($userId);

        return $userId;
    }

    /**
     * タームとユーザーを1つずつ作り、それぞれのメタ$keyに'stored'を保存する。
     *
     * @return list<array{ObjectType, int, string}> 種別・ID・メタのテーブル名
     */
    private static function storedObjects(MetaStorageInterface $storage, string $key): array
    {
        $objects = [
            [ObjectType::Term, self::createTerm('tmswp_product_cat'), self::wpdb()->termmeta],
            [ObjectType::User, self::createUser(), self::wpdb()->usermeta],
        ];

        foreach ($objects as [$type, $id]) {
            self::assertTrue($storage->set($type, $id, $key, 'stored'));
        }

        return $objects;
    }

    /**
     * 公開の読み取りを1つずつ呼ぶクロージャを返す。
     *
     * @return array{has: callable(): bool, get: callable(): mixed, getAll: callable(): list<mixed>, getMultiple: callable(): array<string, mixed>}
     */
    private static function standaloneReads(MetaStorageInterface $storage, ObjectType $type, int $id, string $key): array
    {
        return [
            'has' => static fn(): bool => $storage->has($type, $id, $key),
            'get' => static fn(): mixed => $storage->get($type, $id, $key),
            'getAll' => static fn(): array => $storage->getAll($type, $id, $key),
            'getMultiple' => static fn(): array => $storage->getMultiple($type, $id, [$key]),
        ];
    }

    /**
     * 本番相当(debug=false)のプラグイン形態の、コンポーネントの無いKernelをinitより前にbootする。ログは$loggerに
     * 記録する。
     */
    private function productionKernel(string $name, RecordingLogger $logger): Kernel
    {
        $root = $this->createTempDirectory('integration-' . strtolower($name));
        self::assertTrue(mkdir($root . '/app'));
        $this->writeFile($root . '/plugin.php', "<?php\n");
        $config = KernelConfig::plugin(
            $root . '/plugin.php',
            $root . '/app',
            'TmswpIntegration' . $name . uniqid(),
            false,
            logChannels: static fn(): array => ['integration' => new LogChannel($logger)],
        );
        (new DiscoveryFile($config))->write(new DiscoveryResult([], []));
        $this->bootBeforeInit($config);

        return Kernel::get($config->appNamespace);
    }

    /**
     * $needleを含むSQLのうち$verbsの文を存在しないテーブルへのSQLに書き換え、DBのエラーにした状態で$callbackを実行する。
     *
     * @param callable(): void $callback
     */
    private static function withBrokenQueries(string $needle, string $verbs, callable $callback): void
    {
        $wpdb = self::wpdb();
        $broken = 'UPDATE ' . $wpdb->prefix . 'tmswp_missing_table SET meta_value = 1';
        $breakQueries = static fn(string $query): string => str_contains($query, $needle) && preg_match('/^\s*(' . $verbs . ')\b/i', $query) === 1 ? $broken : $query;
        $suppressed = $wpdb->suppress_errors(true);
        add_filter('query', $breakQueries);

        try {
            $callback();
        } finally {
            remove_filter('query', $breakQueries);
            $wpdb->suppress_errors($suppressed);
        }
    }
}
