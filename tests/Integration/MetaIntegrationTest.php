<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use ArrayObject;
use stdClass;
use Stringable;
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
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Tax\Taxonomy;
use TMSWP\Core\User\UserRole;
use TMSWP\Testing\BootsKernelBeforeInit;
use TMSWP\Testing\IntegrationTestCase;
use TMSWP\Testing\RecordingLogger;
use TMSWP\Testing\UsesTempDirectories;
use WP_Post;

final class MetaIntegrationTest extends IntegrationTestCase
{
    use BootsKernelBeforeInit;
    use UsesTempDirectories;

    private const PRICE = 'tmswp_price';

    private const CODES = 'tmswp_codes';

    private const CUSTOM = 'tmswp_custom';

    public function tear_down(): void
    {
        $this->unregisterAppAutoloaders();
        $this->cleanupTempDirectories();
        parent::tear_down();
    }

    /** #[MetaSchema] の6つの引数(type・default・single・showInRest・description・sanitize)が register_post_meta() に渡る。 */
    public function testMetaSchemaIsRegisteredOnInit(): void
    {
        $postClass = self::fixtureClass(self::THEME_NAMESPACE . '\\Post\\MetaPost', PostType::class);
        $registered = get_registered_meta_keys('post', 'tmswp_meta_post');

        self::assertArrayHasKey(self::PRICE, $registered);
        self::assertSame('integer', $registered[self::PRICE]['type']);
        self::assertSame(1200, $registered[self::PRICE]['default']);
        self::assertTrue($registered[self::PRICE]['single']);
        self::assertTrue($registered[self::PRICE]['show_in_rest']);
        self::assertSame('価格', $registered[self::PRICE]['description']);
        self::assertSame([$postClass, 'sanitizePrice'], $registered[self::PRICE]['sanitize_callback']);
        self::assertArrayHasKey(self::CODES, $registered);
        self::assertFalse($registered[self::CODES]['single']);
        self::assertSame('string', $registered[self::CODES]['type']);
        self::assertSame('', $registered[self::CODES]['description']);
        self::assertNull($registered[self::CODES]['sanitize_callback']);
    }

    /** #[WithMetaRevisions] を付けたエンティティのメタは revisions_enabled => true で、付けないエンティティは false で登録される。 */
    public function testWithMetaRevisionsRegistersMetaWithRevisionsEnabled(): void
    {
        $revised = get_registered_meta_keys('post', 'tmswp_meta_post');
        self::assertTrue($revised[self::PRICE]['revisions_enabled']);
        self::assertTrue($revised[self::CODES]['revisions_enabled']);

        $stored = get_registered_meta_keys('post', 'tmswp_stored_post');
        self::assertArrayHasKey(self::CUSTOM, $stored);
        self::assertFalse($stored[self::CUSTOM]['revisions_enabled']);
    }

    /**
     * #[WithMetaRevisions] のエンティティのプレビュー(WordPress の _set_preview() が足す _wp_preview_meta_filter)では、
     * オートセーブの値が偽('0')でも、行があるものとして読む。Integer のメタ(default 100)をオートセーブで 0 にすると、
     * int()・has()・get() と all() のどれもプレビューの値を返す。
     */
    public function testPreviewOfFalsyAutosaveValueIsReadByEveryGetterAndAll(): void
    {
        $root = $this->createTempDirectory('integration-preview');
        self::assertTrue(mkdir($root . '/app'));
        $this->writeFile($root . '/plugin.php', "<?php\n");
        $config = KernelConfig::plugin($root . '/plugin.php', $root . '/app', 'TmswpIntegrationPreview' . uniqid(), true);
        $namespace = $config->appNamespace;
        $postType = 'tmswp_pv_' . substr(md5($namespace), 0, 8);
        $this->writeFile($config->appDir . '/Post/PreviewPost.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Post;

use TMSWP\\Core\\Attribute\\AsPostType;
use TMSWP\\Core\\Attribute\\MetaSchema;
use TMSWP\\Core\\Attribute\\WithMetaRevisions;
use TMSWP\\Core\\Meta\\MetaValueType;
use TMSWP\\Core\\Post\\PostType;
use TMSWP\\Core\\Post\\PostTypeDefinition;

#[AsPostType]
#[WithMetaRevisions]
final class PreviewPost extends PostType
{
    #[MetaSchema(type: MetaValueType::Integer, default: 100)]
    public const META_STOCK = 'tmswp_preview_stock';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('{$postType}')->label('Preview', 'Previews')->supports('title', 'revisions');
    }
}
PHP);
        $this->registerAppAutoloader($config);
        $previewFilters = [['get_the_terms', '_wp_preview_terms_filter'], ['get_post_metadata', '_wp_preview_post_thumbnail_filter'], ['get_post_metadata', '_wp_preview_meta_filter']];

        try {
            self::fireInit($this->bootBeforeInit($config));
            $previewPost = self::fixtureClass($namespace . '\\Post\\PreviewPost', PostType::class);
            $key = 'tmswp_preview_stock';
            $postId = self::createPost($postType);
            self::assertTrue($previewPost::meta($postId)->set($key, 500));
            $autosaveId = _wp_put_post_revision(['ID' => $postId, 'post_type' => $postType, 'post_title' => 'Autosave', 'post_content' => '', 'post_excerpt' => ''], true);
            self::assertIsInt($autosaveId);
            // オートセーブのリビジョンのメタは、WordPress の wp_autosave_post_revisioned_meta_fields() と同じく update_metadata() で書く
            // (update_post_meta() はリビジョンの ID を親の投稿の ID に置き換えるため)
            self::assertNotFalse(update_metadata('post', $autosaveId, $key, wp_slash('0')));

            $post = get_post($postId);
            self::assertInstanceOf(WP_Post::class, $post);
            $GLOBALS['post'] = $post;
            _set_preview($post);
            $bag = $previewPost::meta($postId);

            // 確かめ方の確認: プレビューのフィルタは、偽のオートセーブの値を返す
            self::assertSame('0', get_post_meta($postId, $key, true));
            self::assertTrue($bag->has($key));
            self::assertSame(0, $bag->int($key));
            self::assertSame('0', $bag->get($key));
            self::assertSame([$key => '0'], $bag->all());
        } finally {
            foreach ($previewFilters as [$hook, $callback]) {
                remove_filter($hook, $callback);
            }

            unset($GLOBALS['post']);
            unregister_post_type($postType);
        }
    }

    /**
     * Boolean のスキーマのキーに false を書くと、WordPress のメタには空文字で保存され、get() は空文字、bool() は false を
     * 返す(default の true には戻らない)。true は '1' で保存され、bool() は true を返す。
     */
    public function testBooleanSchemaRoundTripsFalseThroughWpMetaStorage(): void
    {
        $postClass = self::fixtureClass(self::THEME_NAMESPACE . '\\Post\\MetaPost', PostType::class);
        $postId = self::createPost('tmswp_meta_post');
        $bag = $postClass::meta($postId);
        $key = 'tmswp_featured';

        // 確かめ方の確認: 行が無ければスキーマの default(true)になる
        self::assertTrue($bag->bool($key));

        self::assertTrue($bag->set($key, false));
        self::assertTrue($bag->has($key));
        self::assertSame('', get_post_meta($postId, $key, true));
        self::assertSame('', $bag->get($key));
        self::assertFalse($bag->bool($key));

        self::assertTrue($bag->set($key, true));
        self::assertSame('1', $bag->get($key));
        self::assertTrue($bag->bool($key));
    }

    /**
     * WordPress のサムネイルのプレビュー(_set_preview() が足す _wp_preview_post_thumbnail_filter)は、single が false でも
     * サムネイルの ID を文字列で返す。WpMetaStorage はその値を1行として扱い、has()・get()・getMultiple() と MetaBag の
     * get() がプレビューの ID を返す。
     */
    public function testThumbnailPreviewValueIsReadAsARow(): void
    {
        $postId = self::createPost('tmswp_meta_post');
        $storage = new WpMetaStorage(self::themeKernel());
        $previewFilters = [['get_the_terms', '_wp_preview_terms_filter'], ['get_post_metadata', '_wp_preview_post_thumbnail_filter'], ['get_post_metadata', '_wp_preview_meta_filter']];
        self::assertTrue($storage->set(ObjectType::Post, $postId, '_thumbnail_id', '123'));
        $post = get_post($postId);
        self::assertInstanceOf(WP_Post::class, $post);

        try {
            // プレビューの画面の要求(post.php の preview で、アイキャッチを 987 に変えた状態)
            $_REQUEST['preview_id'] = (string) $postId;
            $_REQUEST['_thumbnail_id'] = '987';
            $GLOBALS['post'] = $post;
            _set_preview($post);

            // 確かめ方の確認: WordPress の読み取りはプレビューの ID を返す
            self::assertSame('987', get_post_meta($postId, '_thumbnail_id', true));
            self::assertSame('987', get_metadata_raw('post', $postId, '_thumbnail_id', false));

            self::assertTrue($storage->has(ObjectType::Post, $postId, '_thumbnail_id'));
            self::assertSame('987', $storage->get(ObjectType::Post, $postId, '_thumbnail_id'));
            self::assertSame(['_thumbnail_id' => '987'], $storage->getMultiple(ObjectType::Post, $postId, ['_thumbnail_id']));
            self::assertSame('987', self::fixtureClass(self::THEME_NAMESPACE . '\\Post\\MetaPost', PostType::class)::meta($postId)->get('_thumbnail_id'));
        } finally {
            foreach ($previewFilters as [$hook, $callback]) {
                remove_filter($hook, $callback);
            }

            unset($_REQUEST['preview_id'], $_REQUEST['_thumbnail_id'], $GLOBALS['post']);
        }

        self::assertSame('123', $storage->get(ObjectType::Post, $postId, '_thumbnail_id'));
    }

    /**
     * アイキャッチを外すプレビュー(_thumbnail_id が0以下)では、_wp_preview_post_thumbnail_filter がスカラーの '' を返す。
     * WordPress と同じく行なしと扱い、保存済みの値(123)があっても has() は false、get() は null、getMultiple() は
     * そのキーを含まない。
     */
    public function testThumbnailRemovalPreviewIsReadAsNoRow(): void
    {
        $postId = self::createPost('tmswp_meta_post');
        $storage = new WpMetaStorage(self::themeKernel());
        $previewFilters = [['get_the_terms', '_wp_preview_terms_filter'], ['get_post_metadata', '_wp_preview_post_thumbnail_filter'], ['get_post_metadata', '_wp_preview_meta_filter']];
        self::assertTrue($storage->set(ObjectType::Post, $postId, '_thumbnail_id', '123'));
        $post = get_post($postId);
        self::assertInstanceOf(WP_Post::class, $post);

        try {
            $_REQUEST['preview_id'] = (string) $postId;
            $_REQUEST['_thumbnail_id'] = '-1';
            $GLOBALS['post'] = $post;
            _set_preview($post);

            // 確かめ方の確認: WordPress はスカラーの '' を受け取り、行もアイキャッチも無いと扱う
            self::assertSame('', get_metadata_raw('post', $postId, '_thumbnail_id', false));
            self::assertFalse(metadata_exists('post', $postId, '_thumbnail_id'));
            self::assertFalse(has_post_thumbnail($postId));

            self::assertFalse($storage->has(ObjectType::Post, $postId, '_thumbnail_id'));
            self::assertNull($storage->get(ObjectType::Post, $postId, '_thumbnail_id'));
            self::assertSame([], $storage->getMultiple(ObjectType::Post, $postId, ['_thumbnail_id']));
            self::assertNull(self::fixtureClass(self::THEME_NAMESPACE . '\\Post\\MetaPost', PostType::class)::meta($postId)->get('_thumbnail_id'));
        } finally {
            foreach ($previewFilters as [$hook, $callback]) {
                remove_filter($hook, $callback);
            }

            unset($_REQUEST['preview_id'], $_REQUEST['_thumbnail_id'], $GLOBALS['post']);
        }

        self::assertSame('123', $storage->get(ObjectType::Post, $postId, '_thumbnail_id'));
    }

    /**
     * タームとユーザーのメタも、エンティティの #[MetaSchema] の定数で MetaBag から読み書きできる(スキーマの default、
     * 書き込み、型の検査、削除)。WordPress のタームメタ・ユーザーメタに保存される。
     */
    public function testTermAndUserMetaAreReadAndWrittenThroughMetaBag(): void
    {
        $category = self::fixtureClass(self::THEME_NAMESPACE . '\\Tax\\ProductCategoryTaxonomy', Taxonomy::class);
        $member = self::fixtureClass(self::THEME_NAMESPACE . '\\User\\MemberRole', UserRole::class);
        $termId = $category::create('Meta term');
        $userId = self::factory()->user->create(['role' => $member::role()]);
        self::assertIsInt($userId);
        $color = 'tmswp_term_color';
        $memberNo = 'tmswp_member_no';

        $termMeta = $category::meta($termId);
        self::assertFalse($termMeta->has($color));
        self::assertSame('gray', $termMeta->string($color));
        self::assertTrue($termMeta->set($color, 'C:\\blue'));
        self::assertSame('C:\\blue', $termMeta->string($color));
        self::assertSame('C:\\blue', get_term_meta($termId, $color, true));
        self::assertSame([$color => 'C:\\blue'], $termMeta->all());
        $this->assertRejectedValue(static fn(): bool => $termMeta->set($color, ['blue']), "meta '{$color}' expects string, got array");
        self::assertTrue($termMeta->delete($color));
        self::assertSame('gray', $termMeta->string($color));

        $userMeta = $member::meta($userId);
        self::assertFalse($userMeta->has($memberNo));
        self::assertSame(0, $userMeta->int($memberNo));
        self::assertTrue($userMeta->set($memberNo, 1024));
        self::assertSame(1024, $userMeta->int($memberNo));
        self::assertSame('1024', get_user_meta($userId, $memberNo, true));
        self::assertSame([$memberNo => '1024'], $userMeta->all());
        $this->assertRejectedValue(static fn(): bool => $userMeta->set($memberNo, '1024'), "meta '{$memberNo}' expects integer, got string");
        self::assertTrue($userMeta->delete($memberNo));
        self::assertSame(0, $userMeta->int($memberNo));
    }

    /** String のスキーマは Stringable のオブジェクトを受け付けない(オプションと同じ規則。WpMetaStorage まで渡さない)。 */
    public function testStringSchemaRejectsStringableObjects(): void
    {
        $postClass = self::fixtureClass(self::THEME_NAMESPACE . '\\Post\\MetaPost', PostType::class);
        $postId = self::createPost('tmswp_meta_post');
        $bag = $postClass::meta($postId);

        $this->assertRejectedValue(
            static fn(): bool => $bag->set(self::CODES, ['code', new MetaStringable()]),
            "meta 'tmswp_codes' expects string, got " . MetaStringable::class,
        );
        self::assertFalse($bag->has(self::CODES));
    }

    /**
     * register_meta の default があるキーでも、WpMetaStorage の get()・getAll() は default を介さず、行が無ければ null・空配列を
     * 返す(get_metadata_raw() の経路)。WordPress の get_post_meta() は default を返す。
     */
    public function testWpMetaStorageReadsIgnoreTheRegisteredDefault(): void
    {
        $postId = self::createPost('tmswp_meta_post');
        $storage = new WpMetaStorage(self::themeKernel());
        // FW の宣言は single=false と default を併用できないため、行の list の default は WordPress に直接登録する
        register_post_meta('tmswp_meta_post', 'tmswp_raw_rows', ['type' => 'string', 'single' => false, 'default' => 'row-default']);

        self::assertSame(1200, get_post_meta($postId, self::PRICE, true));
        self::assertSame(['row-default'], get_post_meta($postId, 'tmswp_raw_rows'));

        self::assertNull($storage->get(ObjectType::Post, $postId, self::PRICE));
        self::assertFalse($storage->has(ObjectType::Post, $postId, self::PRICE));
        self::assertSame([], $storage->getAll(ObjectType::Post, $postId, 'tmswp_raw_rows'));
        self::assertSame([], $storage->getMultiple(ObjectType::Post, $postId, [self::PRICE, 'tmswp_raw_rows']));
    }

    /** MetaBag::all() の single=false のキーの値は、get() と同じ全行の list になる(行が無ければ null)。 */
    public function testMetaBagAllReturnsAllRowsOfSingleFalseKeys(): void
    {
        $postClass = self::fixtureClass(self::THEME_NAMESPACE . '\\Post\\MetaPost', PostType::class);
        $bag = $postClass::meta(self::createPost('tmswp_meta_post'));

        self::assertNull($bag->all()[self::CODES]);

        self::assertTrue($bag->set(self::CODES, ['alpha', 'beta', 'gamma']));

        self::assertSame(['alpha', 'beta', 'gamma'], $bag->get(self::CODES));
        self::assertSame($bag->get(self::CODES), $bag->all()[self::CODES]);
    }

    public function testMetaBagReadsDefaultsAndWritesThroughWpMetaStorage(): void
    {
        $postClass = self::fixtureClass(self::THEME_NAMESPACE . '\\Post\\MetaPost', PostType::class);
        $postId = self::createPost('tmswp_meta_post');
        $bag = $postClass::meta($postId);
        $storage = new WpMetaStorage(self::themeKernel());

        self::assertNull($storage->get(ObjectType::Post, $postId, self::PRICE));
        self::assertSame([], $storage->getAll(ObjectType::Post, $postId, self::CODES));
        self::assertSame([], $storage->getMultiple(ObjectType::Post, $postId, [self::PRICE, self::CODES]));
        self::assertSame(1200, $bag->int(self::PRICE));
        self::assertTrue($bag->set(self::PRICE, 2500));
        self::assertSame(2500, $bag->int(self::PRICE));
        self::assertTrue($bag->set(self::CODES, ['alpha', 'beta']));
        self::assertSame(['alpha', 'beta'], $bag->list(self::CODES));
        self::assertSame(['alpha', 'beta'], $bag->all()[self::CODES]);

        $storedValues = $storage->getMultiple(ObjectType::Post, $postId, [self::PRICE, self::CODES]);
        self::assertArrayHasKey(self::PRICE, $storedValues);
        self::assertArrayHasKey(self::CODES, $storedValues);
        self::assertSame('alpha', $storedValues[self::CODES]);
    }

    public function testBackslashesAndJsonStringsRoundTripThroughMetaWrites(): void
    {
        $postClass = self::fixtureClass(self::THEME_NAMESPACE . '\\Post\\MetaPost', PostType::class);
        $postId = self::createPost('tmswp_meta_post');
        $bag = $postClass::meta($postId);
        $decoded = ['path' => 'C:\\Users\\tmswp', 'pattern' => '/^\\d+\\.\\d+$/', 'quote' => '"quoted"', 'text' => "改行\nあり"];
        $json = json_encode($decoded, JSON_THROW_ON_ERROR);
        $windowsPath = 'C:\\Program Files\\tmswp\\new';

        self::assertStringContainsString('\\\\', $json);
        self::assertTrue($bag->set('tmswp_json', $json));
        self::assertTrue($bag->set('tmswp_path', $windowsPath));
        self::assertTrue($bag->set('tmswp_paths', ['share' => '\\\\server\\share', 'nested' => ['regex' => '\\w+']]));
        self::assertTrue($bag->set(self::CODES, ['a\\b', '{"k":"v\\\\n"}']));

        self::assertSame($json, $bag->get('tmswp_json'));
        self::assertSame($decoded, $bag->json('tmswp_json'));
        self::assertSame($windowsPath, $bag->string('tmswp_path'));
        self::assertSame(['share' => '\\\\server\\share', 'nested' => ['regex' => '\\w+']], $bag->get('tmswp_paths'));
        self::assertSame(['a\\b', '{"k":"v\\\\n"}'], $bag->list(self::CODES));
        // WP の読み出し関数からも同じ値になる(DB に slash の外れた値が書かれていない)
        self::assertSame($json, get_post_meta($postId, 'tmswp_json', true));
        self::assertSame(['a\\b', '{"k":"v\\\\n"}'], get_post_meta($postId, self::CODES));
    }

    public function testWritingTheSameValueReturnsFalseWithoutFailing(): void
    {
        $postClass = self::fixtureClass(self::THEME_NAMESPACE . '\\Post\\MetaPost', PostType::class);
        $postId = self::createPost('tmswp_meta_post');
        $bag = $postClass::meta($postId);
        $storage = new WpMetaStorage(self::themeKernel());

        // 同じ値: update_metadata() が保存済みの値と === で一致して false を返す経路
        self::assertTrue($bag->set('tmswp_same', 'same'));
        self::assertFalse($bag->set('tmswp_same', 'same'));

        // 同じ値: 保存済みの '2500' と int 2500 は === で一致せず、DB の更新が 0 行で false を返す経路
        self::assertTrue($bag->set(self::PRICE, 2500));
        self::assertFalse($bag->set(self::PRICE, 2500));
        self::assertSame(['2500'], get_post_meta($postId, self::PRICE));

        // 同じ値: サニタイズ(MetaPost::sanitizePrice の int 変換)後に保存済みの値と一致する経路
        self::assertNotFalse(has_filter('sanitize_post_meta_' . self::PRICE . '_for_tmswp_meta_post'));
        self::assertFalse($storage->set(ObjectType::Post, $postId, self::PRICE, '2500.0'));
        self::assertTrue($storage->set(ObjectType::Post, $postId, self::PRICE, '2600.0'));
        self::assertSame(['2600'], get_post_meta($postId, self::PRICE));

        self::assertTrue($bag->set(self::CODES, ['alpha', 'beta']));
        self::assertFalse($bag->set(self::CODES, ['alpha', 'beta']));
        self::assertTrue($bag->set(self::CODES, ['beta', 'alpha']));
        self::assertSame(['beta', 'alpha'], $bag->list(self::CODES));

        self::assertTrue($bag->delete(self::CODES));
        self::assertFalse($bag->delete(self::CODES));
        self::assertFalse($bag->has(self::CODES));
    }

    public function testSameValueAfterSanitizingReturnsFalseAndSanitizesOnce(): void
    {
        $postId = self::createPost('tmswp_meta_post');
        $storage = new WpMetaStorage(self::themeKernel());
        $sanitized = new ArrayObject();
        add_filter('sanitize_post_meta_tmswp_counted_for_tmswp_meta_post', static function (mixed $value) use ($sanitized): mixed {
            $sanitized->append($value);

            return \is_string($value) ? trim($value) : $value;
        });
        $storage->set(ObjectType::Post, $postId, 'tmswp_counted', 'kept');
        $sanitized->exchangeArray([]);

        // サニタイズ後に保存済みの値と同じになる入力は「同じ値」。サニタイズは WP の update_metadata() の1回だけ
        self::assertFalse($storage->set(ObjectType::Post, $postId, 'tmswp_counted', ' kept '));
        self::assertSame([' kept '], $sanitized->getArrayCopy());
        self::assertSame(['kept'], get_post_meta($postId, 'tmswp_counted'));
    }

    public function testReplacingRowsWithValuesThatSanitizeToTheStoredRowsReturnsFalse(): void
    {
        $postId = self::createPost('tmswp_meta_post');
        $storage = new WpMetaStorage(self::themeKernel());
        add_filter('sanitize_post_meta_' . self::CODES . '_for_tmswp_meta_post', static fn(mixed $value): mixed => \is_string($value) ? trim($value) : $value);

        self::assertTrue($storage->setAll(ObjectType::Post, $postId, self::CODES, ['alpha', 'beta']));
        self::assertFalse($storage->setAll(ObjectType::Post, $postId, self::CODES, [' alpha', 'beta ']));
        self::assertTrue($storage->setAll(ObjectType::Post, $postId, self::CODES, ['alpha']));
        self::assertSame(['alpha'], $storage->getAll(ObjectType::Post, $postId, self::CODES));
    }

    public function testWritesStoppedByAnotherFilterReturnFalseAndKeepTheStoredValue(): void
    {
        $postId = self::createPost('tmswp_meta_post');
        $storage = new WpMetaStorage(self::themeKernel());
        $storage->set(ObjectType::Post, $postId, 'tmswp_guarded', 'before');
        $storage->setAll(ObjectType::Post, $postId, 'tmswp_guarded_rows', ['one', 'two']);
        $stop = static fn(mixed $check, int $objectId, string $key): mixed => str_starts_with($key, 'tmswp_guarded') ? false : $check;
        add_filter('update_post_metadata', $stop, 10, 3);
        add_filter('add_post_metadata', $stop, 10, 3);
        add_filter('delete_post_metadata', $stop, 10, 3);

        // 短絡フィルタが書き込みを止めた場合は、保存値が変わっていないので false(例外にしない)
        self::assertFalse($storage->set(ObjectType::Post, $postId, 'tmswp_guarded', 'after'));
        self::assertFalse($storage->set(ObjectType::Post, $postId, 'tmswp_guarded_new', 'value'));
        self::assertFalse($storage->setAll(ObjectType::Post, $postId, 'tmswp_guarded_rows', ['three']));
        self::assertFalse($storage->delete(ObjectType::Post, $postId, 'tmswp_guarded'));

        self::assertSame(['before'], get_post_meta($postId, 'tmswp_guarded'));
        self::assertFalse($storage->has(ObjectType::Post, $postId, 'tmswp_guarded_new'));
        self::assertSame(['one', 'two'], get_post_meta($postId, 'tmswp_guarded_rows'));
    }

    public function testDatabaseErrorsDuringWritesThrowAndKeepTheStoredValue(): void
    {
        $postId = self::createPost('tmswp_meta_post');
        $storage = new WpMetaStorage(self::themeKernel());
        $storage->set(ObjectType::Post, $postId, 'tmswp_blocked', 'before');
        $storage->setAll(ObjectType::Post, $postId, 'tmswp_blocked_rows', ['one', 'two']);

        self::withBrokenQueries("'tmswp_blocked", 'UPDATE|INSERT', function () use ($storage, $postId): void {
            $this->assertDatabaseWriteFails(
                static fn(): bool => $storage->set(ObjectType::Post, $postId, 'tmswp_blocked', 'after'),
                "failed to update post meta 'tmswp_blocked' for object {$postId}",
            );
            // 行の無いキーへの set() は update_metadata() から add_metadata() に進み、INSERT が失敗する
            $this->assertDatabaseWriteFails(
                static fn(): bool => $storage->set(ObjectType::Post, $postId, 'tmswp_blocked_new', 'value'),
                "failed to update post meta 'tmswp_blocked_new' for object {$postId}",
            );
        });

        // delete_metadata() の DELETE は meta_id で行を指すため、メタのテーブルへの DELETE を失敗させる
        self::withBrokenQueries(self::wpdb()->postmeta, 'DELETE', function () use ($storage, $postId): void {
            $this->assertDatabaseWriteFails(
                static fn(): bool => $storage->setAll(ObjectType::Post, $postId, 'tmswp_blocked_rows', ['three']),
                "failed to delete post meta 'tmswp_blocked_rows' for object {$postId} before replacing it",
            );
            $this->assertDatabaseWriteFails(
                static fn(): bool => $storage->delete(ObjectType::Post, $postId, 'tmswp_blocked'),
                "failed to delete post meta 'tmswp_blocked' for object {$postId}",
            );
        });

        self::assertSame(['before'], get_post_meta($postId, 'tmswp_blocked'));
        self::assertFalse($storage->has(ObjectType::Post, $postId, 'tmswp_blocked_new'));
        self::assertSame(['one', 'two'], get_post_meta($postId, 'tmswp_blocked_rows'));

        // setAll() の削除の後の追加が失敗した場合も例外にする(巻き戻さないため、行は削除された状態で残る)
        self::withBrokenQueries("'tmswp_blocked", 'INSERT', function () use ($storage, $postId): void {
            $this->assertDatabaseWriteFails(
                static fn(): bool => $storage->setAll(ObjectType::Post, $postId, 'tmswp_blocked_rows', ['three']),
                "failed to add post meta 'tmswp_blocked_rows' for object {$postId}",
            );
        });
    }

    public function testReadFailureBeforeDeletingMetaThrowsAndKeepsTheStoredValue(): void
    {
        $postId = self::createPost('tmswp_meta_post');
        $storage = new WpMetaStorage(self::themeKernel());
        $storage->set(ObjectType::Post, $postId, 'tmswp_read_delete', 'old');
        wp_cache_delete($postId, 'post_meta');

        // 存在の確認(メタキャッシュの読み込みの SELECT)を DB のエラーにする
        self::withBrokenQueries(self::wpdb()->postmeta, 'SELECT', function () use ($storage, $postId): void {
            $this->assertDatabaseWriteFails(
                static fn(): bool => $storage->delete(ObjectType::Post, $postId, 'tmswp_read_delete'),
                "failed to read post meta 'tmswp_read_delete' for object {$postId} before deleting it",
            );
        });

        // 失敗した読み込みで WP が書いた空のメタのキャッシュは消えており、データベースの値が返る
        self::assertSame(['old'], get_post_meta($postId, 'tmswp_read_delete'));
    }

    public function testReadFailureBeforeReplacingRowsThrowsAndKeepsTheStoredRows(): void
    {
        $postId = self::createPost('tmswp_meta_post');
        $storage = new WpMetaStorage(self::themeKernel());
        $storage->setAll(ObjectType::Post, $postId, 'tmswp_read_before', ['old']);
        wp_cache_delete($postId, 'post_meta');

        self::withBrokenQueries(self::wpdb()->postmeta, 'SELECT', function () use ($storage, $postId): void {
            $this->assertDatabaseWriteFails(
                static fn(): bool => $storage->setAll(ObjectType::Post, $postId, 'tmswp_read_before', ['new']),
                "failed to read post meta 'tmswp_read_before' for object {$postId} before replacing it",
            );
        });

        self::assertSame(['old'], get_post_meta($postId, 'tmswp_read_before'));
    }

    public function testReadFailureAfterReplacingRowsThrows(): void
    {
        $postId = self::createPost('tmswp_meta_post');
        $storage = new WpMetaStorage(self::themeKernel());
        // 置き換えの前の読み取りはキャッシュから行い、追加の後の読み直し(SELECT)だけを DB のエラーにする
        self::assertSame([], $storage->getAll(ObjectType::Post, $postId, 'tmswp_read_after'));

        self::withBrokenQueries(self::wpdb()->postmeta, 'SELECT', function () use ($storage, $postId): void {
            $this->assertDatabaseWriteFails(
                static fn(): bool => $storage->setAll(ObjectType::Post, $postId, 'tmswp_read_after', ['new']),
                "failed to read post meta 'tmswp_read_after' for object {$postId} after replacing it",
            );
        });

        self::assertSame(['new'], get_post_meta($postId, 'tmswp_read_after'));
    }

    public function testNestedWriteToTheSameMetaDoesNotHideTheOuterDatabaseError(): void
    {
        $postId = self::createPost('tmswp_meta_post');
        $storage = new WpMetaStorage(self::themeKernel());
        $storage->set(ObjectType::Post, $postId, 'tmswp_nested', 'old-value');
        $nested = new ArrayObject();
        // 外側の UPDATE の直前(update_post_meta アクション)に、同じメタへ保存済みの値を書く
        add_action('update_post_meta', static function (mixed $metaId, mixed $objectId, mixed $metaKey) use ($storage, $postId, $nested): void {
            if ($metaKey === 'tmswp_nested' && $nested->count() === 0) {
                $nested->append($storage->set(ObjectType::Post, $postId, 'tmswp_nested', 'old-value'));
            }
        }, 10, 3);

        self::withBrokenQueries("'new-value'", 'UPDATE', function () use ($storage, $postId): void {
            $this->assertDatabaseWriteFails(
                static fn(): bool => $storage->set(ObjectType::Post, $postId, 'tmswp_nested', 'new-value'),
                "failed to update post meta 'tmswp_nested' for object {$postId}",
            );
        });

        self::assertSame([false], $nested->getArrayCopy());
        wp_cache_delete($postId, 'post_meta');
        self::assertSame(['old-value'], get_post_meta($postId, 'tmswp_nested'));
    }

    public function testEarlierUnrelatedDatabaseErrorIsNotReportedForAWriteWithoutQueries(): void
    {
        $postId = self::createPost('tmswp_meta_post');
        $storage = new WpMetaStorage(self::themeKernel());
        $storage->set(ObjectType::Post, $postId, 'tmswp_stale', 'same');
        self::assertSame('same', $storage->get(ObjectType::Post, $postId, 'tmswp_stale'));
        $wpdb = self::wpdb();
        $suppressed = $wpdb->suppress_errors(true);

        try {
            $wpdb->query('SELECT * FROM tmswp_missing_table_for_stale_error');
            self::assertNotSame('', $wpdb->last_error);
            add_filter('update_post_metadata', static fn(mixed $check, int $objectId, string $key): mixed => $key === 'tmswp_stale_stopped' ? false : $check, 10, 3);

            // クエリを発行しない false(同じ値・短絡)は、前の無関係なエラーを拾わない
            self::assertFalse($storage->set(ObjectType::Post, $postId, 'tmswp_stale', 'same'));
            self::assertFalse($storage->set(ObjectType::Post, $postId, 'tmswp_stale_stopped', 'value'));
            self::assertStringContainsString('tmswp_missing_table_for_stale_error', $wpdb->last_error);
        } finally {
            $wpdb->suppress_errors($suppressed);
        }
    }

    public function testWpMetaStorageKeepsStoredFalseAndEmptyValuesAndRejectsNull(): void
    {
        $postId = self::createPost('tmswp_meta_post');
        $storage = new WpMetaStorage(self::themeKernel());

        self::assertTrue($storage->set(ObjectType::Post, $postId, 'tmswp_false', false));
        self::assertTrue($storage->set(ObjectType::Post, $postId, 'tmswp_empty', ''));
        self::assertTrue($storage->has(ObjectType::Post, $postId, 'tmswp_false'));
        self::assertTrue($storage->has(ObjectType::Post, $postId, 'tmswp_empty'));
        // WordPress のメタは false を空文字で保存する(WP 固有の保存の形。抽象の契約テストでは強制しない)
        self::assertSame('', $storage->get(ObjectType::Post, $postId, 'tmswp_false'));
        self::assertSame('', $storage->get(ObjectType::Post, $postId, 'tmswp_empty'));
        self::assertTrue($storage->delete(ObjectType::Post, $postId, 'tmswp_empty'));
        self::assertFalse($storage->delete(ObjectType::Post, $postId, 'tmswp_empty'));

        $this->assertRejectedValue(
            static fn(): bool => $storage->set(ObjectType::Post, $postId, 'tmswp_null', null),
            "meta 'tmswp_null' cannot store null; use delete() to remove it",
        );
        $this->assertRejectedValue(
            static fn(): bool => $storage->setAll(ObjectType::Post, $postId, 'tmswp_null', ['a', null]),
            "meta 'tmswp_null' cannot store null; use delete() to remove it",
        );
        $this->assertRejectedValue(
            static fn(): bool => $storage->set(ObjectType::Post, $postId, 'tmswp_object', ['nested' => new stdClass()]),
            "meta 'tmswp_object' cannot store an object (stdClass)",
        );
        self::assertFalse($storage->has(ObjectType::Post, $postId, 'tmswp_null'));
        self::assertFalse($storage->has(ObjectType::Post, $postId, 'tmswp_object'));
    }

    public function testUseMetaStorageRoutesMetaBagToConfiguredStorage(): void
    {
        $postClass = self::fixtureClass(self::THEME_NAMESPACE . '\\Post\\StoredPost', PostType::class);
        $postId = self::createPost('tmswp_stored_post');
        $bag = $postClass::meta($postId);

        self::assertTrue($bag->set(self::CUSTOM, 'custom-storage'));
        self::assertSame('custom-storage', $bag->string(self::CUSTOM));
        self::assertTrue($bag->set('custom_false', false));
        self::assertTrue($bag->set('custom_empty', ''));
        self::assertTrue($bag->has('custom_false'));
        self::assertTrue($bag->has('custom_empty'));
        self::assertFalse($bag->get('custom_false'));
        self::assertSame('', $bag->get('custom_empty'));
        // null は差し替え先に関係なく MetaBag が拒否する
        $this->assertRejectedValue(static fn(): bool => $bag->set('custom_null', null), "meta 'custom_null' cannot be set to null; use delete() to remove it");
        self::assertFalse($bag->has('custom_null'));
        // 差し替え先にだけ保存され、WP のメタには書かれていない
        self::assertFalse((new WpMetaStorage(self::themeKernel()))->has(ObjectType::Post, $postId, self::CUSTOM));
    }

    /**
     * 公開の読み取り(has・get・getAll・getMultiple)を単独で呼んだときのデータベースのエラーは、debug の Kernel では、
     * 読み取りの対象とデータベースのエラーをメッセージに含む RuntimeException になる。失敗した読み込みで WP が書いた
     * 空のメタのキャッシュは消えており、エラーを外して読み直すとデータベースの値が返る。
     */
    public function testStandaloneReadFailureThrowsInDebug(): void
    {
        self::assertTrue(self::themeKernel()->config()->debug);
        $storage = new WpMetaStorage(self::themeKernel());
        $postId = self::createPost('tmswp_meta_post');
        $storage->set(ObjectType::Post, $postId, 'tmswp_read_alone', 'stored');

        foreach (self::standaloneReads($storage, $postId, 'tmswp_read_alone') as $name => $read) {
            // メタのキャッシュを消し、読み取りがメタの読み込みの SELECT を発行するようにする
            wp_cache_delete($postId, 'post_meta');

            self::withBrokenQueries(self::wpdb()->postmeta, 'SELECT', static function () use ($read, $name, $postId): void {
                try {
                    $read();
                    self::fail("RuntimeException was not thrown for {$name}");
                } catch (RuntimeException $exception) {
                    self::assertStringStartsWith("failed to read post meta 'tmswp_read_alone' for object {$postId}: ", $exception->getMessage(), $name);
                    self::assertStringContainsString('tmswp_missing_table', $exception->getMessage(), $name);
                }
            });

            self::assertSame('stored', $storage->get(ObjectType::Post, $postId, 'tmswp_read_alone'), $name);
        }
    }

    /**
     * 本番の Kernel では、単独の読み取りのデータベースのエラーを、その Kernel のロガーに error で記録し、行が無いときと
     * 同じ結果(false・null・空配列)を返す。失敗した読み込みのキャッシュは消えており、エラーを外して読み直すと
     * データベースの値が返る。
     */
    public function testStandaloneReadFailureIsLoggedAndReturnsTheRowlessResultInProduction(): void
    {
        $logger = new RecordingLogger();
        $storage = $this->productionKernel('MetaReadFailure', $logger)->container()->get(MetaStorageInterface::class);
        self::assertInstanceOf(WpMetaStorage::class, $storage);
        $postId = self::createPost('tmswp_meta_post');
        $storage->set(ObjectType::Post, $postId, 'tmswp_read_logged', 'stored');
        $results = [];

        foreach (self::standaloneReads($storage, $postId, 'tmswp_read_logged') as $name => $read) {
            wp_cache_delete($postId, 'post_meta');

            self::withBrokenQueries(self::wpdb()->postmeta, 'SELECT', static function () use ($read, $name, &$results): void {
                $results[$name] = $read();
            });

            self::assertSame('stored', $storage->get(ObjectType::Post, $postId, 'tmswp_read_logged'), $name);
        }

        self::assertSame(['has' => false, 'get' => null, 'getAll' => [], 'getMultiple' => []], $results);
        self::assertCount(4, $logger->records);

        foreach ($logger->records as $record) {
            self::assertSame('error', $record['level']);
            self::assertSame('tmswp.storage: {failure}: {error}', $record['message']);
            self::assertSame("failed to read post meta 'tmswp_read_logged' for object {$postId}", $record['context']['failure']);
            self::assertIsString($record['context']['error']);
            self::assertStringContainsString('tmswp_missing_table', $record['context']['error']);
        }
    }

    /**
     * WP_Metadata_Lazyloader がキューにある複数のタームのメタをまとめて読む SELECT が失敗すると、WP はキャッシュに
     * 無かったすべてのタームを空のメタとしてキャッシュする。読み取りのエラーの後、読み取りの対象だけでなく、同時に
     * 読み込まれたほかのタームのキャッシュも消えており、エラーを外して読み直すとデータベースの値が返る。
     */
    public function testFailedLazyLoadOfQueuedTermsLeavesNoEmptyCacheForAnyTerm(): void
    {
        $storage = new WpMetaStorage(self::themeKernel());
        [$first, $second] = [self::createCategory('tmswp-lazy-first'), self::createCategory('tmswp-lazy-second')];

        foreach ([$first, $second] as $termId) {
            $storage->set(ObjectType::Term, $termId, 'tmswp_lazy', 'stored');
            wp_cache_delete($termId, 'term_meta');
        }

        wp_metadata_lazyloader()->queue_objects('term', [$first, $second]);

        self::withBrokenQueries(self::wpdb()->termmeta, 'SELECT', static function () use ($storage, $first): void {
            try {
                $storage->get(ObjectType::Term, $first, 'tmswp_lazy');
                self::fail('RuntimeException was not thrown');
            } catch (RuntimeException $exception) {
                self::assertStringStartsWith("failed to read term meta 'tmswp_lazy' for object {$first}: ", $exception->getMessage());
            }
        });

        self::assertSame('stored', $storage->get(ObjectType::Term, $second, 'tmswp_lazy'));
        self::assertSame('stored', $storage->get(ObjectType::Term, $first, 'tmswp_lazy'));
    }

    /**
     * キャッシュから読んでクエリを発行しない読み取りは、SELECT が失敗する状態でも例外にならない。呼び出しの前の
     * 無関係なデータベースのエラーが残っていても、クエリを発行しない読み取りと、成功したクエリの読み取りは例外にならない。
     */
    public function testStandaloneReadWithoutAFailedQueryDoesNotThrow(): void
    {
        $storage = new WpMetaStorage(self::themeKernel());
        $postId = self::createPost('tmswp_meta_post');
        $storage->set(ObjectType::Post, $postId, 'tmswp_read_cached', 'cached');
        $expected = ['has' => true, 'get' => 'cached', 'getAll' => ['cached'], 'getMultiple' => ['tmswp_read_cached' => 'cached']];
        $readAll = static function () use ($storage, $postId): array {
            $results = [];

            foreach (self::standaloneReads($storage, $postId, 'tmswp_read_cached') as $name => $read) {
                $results[$name] = $read();
            }

            return $results;
        };
        // メタのキャッシュに載せる
        self::assertSame('cached', $storage->get(ObjectType::Post, $postId, 'tmswp_read_cached'));

        self::withBrokenQueries(self::wpdb()->postmeta, 'SELECT', static function () use ($readAll, $expected): void {
            self::assertSame($expected, $readAll());
        });

        $wpdb = self::wpdb();
        $suppressed = $wpdb->suppress_errors(true);

        try {
            $wpdb->query('SELECT * FROM tmswp_missing_table_for_stale_read');
            self::assertNotSame('', $wpdb->last_error);
            self::assertSame($expected, $readAll());

            wp_cache_delete($postId, 'post_meta');
            self::assertSame($expected, $readAll());
        } finally {
            $wpdb->suppress_errors($suppressed);
        }
    }

    public function testObjectTypeRejectsInvalidIdsAndMismatchedObjects(): void
    {
        $this->assertInvalidMetaObject(static fn(): int => ObjectType::Post->resolveId(0));
        $this->assertInvalidMetaObject(static fn(): int => ObjectType::User->resolveId(-1));
        $this->assertInvalidMetaObject(static fn(): int => ObjectType::Post->resolveId(new WP_Post((object) ['ID' => 0])));
        $this->assertInvalidMetaObject(static fn(): int => ObjectType::Term->resolveId(new WP_Post((object) ['ID' => 1])));
    }

    /** カテゴリーのタームを作り、その ID を返す。 */
    private static function createCategory(string $name): int
    {
        $term = wp_insert_term($name, 'category');
        self::assertIsArray($term);

        return (int) $term['term_id'];
    }

    /**
     * 公開の読み取りを1つずつ呼ぶクロージャを返す。
     *
     * @return array{has: callable(): bool, get: callable(): mixed, getAll: callable(): list<mixed>, getMultiple: callable(): array<string, mixed>}
     */
    private static function standaloneReads(MetaStorageInterface $storage, int $postId, string $key): array
    {
        return [
            'has' => static fn(): bool => $storage->has(ObjectType::Post, $postId, $key),
            'get' => static fn(): mixed => $storage->get(ObjectType::Post, $postId, $key),
            'getAll' => static fn(): array => $storage->getAll(ObjectType::Post, $postId, $key),
            'getMultiple' => static fn(): array => $storage->getMultiple(ObjectType::Post, $postId, [$key]),
        ];
    }

    /**
     * 本番相当(debug=false)のプラグイン形態の、コンポーネントの無い Kernel を init より前に boot する。ログは $logger に
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
     * DB の書き込みのエラーで例外になり、メッセージにデータベースのエラーが入ることを確かめる。
     *
     * @param callable(): bool $write
     */
    private function assertDatabaseWriteFails(callable $write, string $failure): void
    {
        try {
            $write();
        } catch (RuntimeException $exception) {
            self::assertStringStartsWith("{$failure}: ", $exception->getMessage());
            self::assertStringContainsString('tmswp_missing_table', $exception->getMessage());

            return;
        }

        self::fail("データベースのエラーが例外になりませんでした: {$failure}");
    }

    /**
     * $needle を含む SQL のうち $verbs の文を存在しないテーブルへの SQL に書き換え、DB のエラーにした状態で $callback を実行する。
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

    /** @param callable(): bool $write */
    private function assertRejectedValue(callable $write, string $message): void
    {
        try {
            $write();
        } catch (InvalidArgumentException $exception) {
            self::assertSame($message, $exception->getMessage());

            return;
        }

        self::fail("保存できない値が拒否されませんでした: {$message}");
    }

    /** @param callable(): mixed $callback */
    private function assertInvalidMetaObject(callable $callback): void
    {
        try {
            $callback();
        } catch (InvalidArgumentException $exception) {
            self::assertSame(InvalidArgumentException::class, $exception::class);

            return;
        }

        self::fail('InvalidArgumentException が送出されませんでした');
    }
}

final class MetaStringable implements Stringable
{
    public function __toString(): string
    {
        return 'stringable';
    }
}
