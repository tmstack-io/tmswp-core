<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Kernel;

use Brain\Monkey\Functions;
use Closure;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use stdClass;
use TMSWP\Core\Attribute\AsOptionGroup;
use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Exception\ContainerException;
use TMSWP\Core\Exception\QueryException;
use TMSWP\Core\Kernel\DiscoveredComponent;
use TMSWP\Core\Kernel\DiscoveryFile;
use TMSWP\Core\Kernel\DiscoveryResult;
use TMSWP\Core\Kernel\ExtensionInterface;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Meta\MetaStorageInterface;
use TMSWP\Core\Meta\ObjectType;
use TMSWP\Core\Meta\WpMetaStorage;
use TMSWP\Core\Model\Migrator;
use TMSWP\Core\Option\OptionStorageInterface;
use TMSWP\Core\Option\WpOptionStorage;
use TMSWP\Testing\MemoryMetaStorage;
use TMSWP\Testing\MemoryOptionStorage;
use TMSWP\Testing\StorageExtension;
use TMSWP\Testing\UnitTestCase;
use WP_Post;

/**
 * Kernel の既定のメタ・オプションのストレージ(MetaStorageInterface・OptionStorageInterface の id)を、拡張の
 * register() で boot の前に差し替えられることを、WordPress なしで確かめる(docs/04 §4.16)。
 *
 * app のクラスは tests/fixtures/storage/swap-app.php(名前空間 TmswpStorageSwap)に置き、探索結果ファイルの経路
 * (本番の boot)で読み込ませる。
 */
final class KernelStorageTest extends UnitTestCase
{
    private const string APP_NAMESPACE = 'TmswpStorageSwap';

    protected function setUp(): void
    {
        parent::setUp();

        // boot に要るグローバルは $wp_version と $wp_actions だけで、$wpdb は置かない(Migrator の配線が
        // Connection を解決しない)。前のテストの `global $wpdb;` が残したキーも消しておく
        unset($GLOBALS['wpdb']);
        $GLOBALS['wp_version'] = '7.0';
        $GLOBALS['wp_actions'] = [];
        require_once __DIR__ . '/../../fixtures/storage/swap-app.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wp_version'], $GLOBALS['wp_actions']);
        Kernel::reset();
        parent::tearDown();
    }

    /**
     * 拡張が登録した実装を、#[UseMetaStorage] の無いエンティティの meta() とオプショングループの読み書きが使い、
     * WordPress の関数を呼ばない。#[UseMetaStorage] で明示したストレージは、そのクラスの id で解決される。
     */
    public function testRegisteredStoragesBackEntityMetaAndOptionGroups(): void
    {
        $meta = new MemoryMetaStorage();
        $options = new MemoryOptionStorage();
        $meta->set(ObjectType::Post, 7, 'price', 500);
        $options->set('swap_mode', 'before');
        $kernel = $this->boot([new StorageExtension($meta, $options)]);

        foreach (['get_metadata_raw', 'update_metadata', 'add_metadata', 'delete_metadata', 'get_option', 'add_option', 'update_option', 'delete_option'] as $function) {
            Functions\expect($function)->never();
        }

        self::assertSame(['price' => 500, 'note' => true], self::probe('exerciseMeta', self::post(7, 'tmswp_swap'), self::post(8, 'tmswp_explicit')));
        self::assertSame(['mode' => 'before', 'has' => true], self::probe('exerciseOption'));

        self::assertSame(501, $meta->get(ObjectType::Post, 7, 'price'));
        self::assertSame('after', $options->get('swap_mode'));
        self::assertSame($meta, $kernel->container()->get(MetaStorageInterface::class));
        self::assertSame($options, $kernel->container()->get(OptionStorageInterface::class));
        // 明示したストレージにだけ書かれ、差し替えた既定のストレージには書かれない
        $explicit = $kernel->container()->get(self::APP_NAMESPACE . '\ExplicitMetaStorage');
        self::assertInstanceOf(MetaStorageInterface::class, $explicit);
        self::assertSame('explicit', $explicit->get(ObjectType::Post, 8, 'note'));
        self::assertFalse($meta->has(ObjectType::Post, 8, 'note'));
    }

    /** 何も登録しなければ、既定のストレージは WpMetaStorage・WpOptionStorage になる。 */
    public function testDefaultStoragesAreWordPressStorages(): void
    {
        $kernel = $this->boot([]);

        self::assertInstanceOf(WpMetaStorage::class, $kernel->container()->get(MetaStorageInterface::class));
        self::assertInstanceOf(WpOptionStorage::class, $kernel->container()->get(OptionStorageInterface::class));
    }

    /**
     * グローバルの $wpdb が無くても boot できる。Migrator(と Connection)は使う時点で解決し、そのときに $wpdb が
     * 無ければ代わりの接続を使わずに例外にする。
     */
    public function testBootDoesNotRequireGlobalWpdbAndMigratorFailsWithoutIt(): void
    {
        $kernel = $this->boot([]);

        try {
            $kernel->container()->get(Migrator::class);
            self::fail('ContainerException was not thrown');
        } catch (ContainerException $exception) {
            // Container は factory の例外を previous に入れて投げる
            self::assertStringContainsString(QueryException::class . ': global wpdb is not available', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{Closure(Kernel): void, string}> */
    public static function storagesNotImplementingTheInterface(): iterable
    {
        yield 'メタのインスタンス(登録の時点)' => [
            static fn(Kernel $kernel) => $kernel->container()->set(MetaStorageInterface::class, new stdClass()),
            "container entry '" . MetaStorageInterface::class . "' cannot be set to stdClass, which is not an instance of '" . MetaStorageInterface::class . "'",
        ];
        yield 'オプションのクラス名(登録の時点)' => [
            static fn(Kernel $kernel) => $kernel->container()->set(OptionStorageInterface::class, MemoryMetaStorage::class),
            "container entry '" . OptionStorageInterface::class . "' cannot be bound to '" . MemoryMetaStorage::class . "', which is not '" . OptionStorageInterface::class . "' or its subtype",
        ];
        yield 'オプションの factory(boot の時点)' => [
            static fn(Kernel $kernel) => $kernel->container()->set(OptionStorageInterface::class, static fn(): MemoryMetaStorage => new MemoryMetaStorage()),
            "container entry '" . OptionStorageInterface::class . "' resolved to " . MemoryMetaStorage::class . ", which is not an instance of '" . OptionStorageInterface::class . "'",
        ];
    }

    /**
     * interface を実装しない登録は、登録の時点(インスタンス・クラス名)か boot の時点(factory)で ContainerException に
     * なり、boot は失敗する。
     *
     * @param Closure(Kernel): void $register
     */
    #[DataProvider('storagesNotImplementingTheInterface')]
    public function testStorageNotImplementingTheInterfaceFailsTheBoot(Closure $register, string $message): void
    {
        try {
            $this->boot([new KernelStorageTestExtension($register)]);
            self::fail('ContainerException was not thrown');
        } catch (ContainerException $exception) {
            self::assertSame($message, $exception->getMessage());
        }

        self::assertSame([], Kernel::all());
    }

    /**
     * boot の後は、既定のストレージを差し替えられない。エンティティもオプショングループも無く、boot の中でメタ・
     * オプションを読み書きしない app でも同じ。
     */
    public function testStoragesCannotBeReplacedAfterBoot(): void
    {
        $kernel = $this->boot([], components: false);

        foreach ([MetaStorageInterface::class => new MemoryMetaStorage(), OptionStorageInterface::class => new MemoryOptionStorage()] as $id => $storage) {
            try {
                $kernel->container()->set($id, $storage);
                self::fail("ContainerException was not thrown for {$id}");
            } catch (ContainerException $exception) {
                self::assertSame("cannot redefine resolved container entry '{$id}'", $exception->getMessage());
            }
        }
    }

    /**
     * fixture の app を、本番(debug=false)の探索結果ファイルの経路で boot する。
     *
     * @param list<ExtensionInterface> $extensions
     * @param bool $components false なら、エンティティもオプショングループも無い app にする
     */
    private function boot(array $extensions, bool $components = true): Kernel
    {
        $appDir = $this->createTempDirectory('kernel-storage') . '/app';
        self::assertTrue(mkdir($appDir));
        $config = KernelConfig::theme($appDir, self::APP_NAMESPACE, false, extensions: $extensions);
        $namespace = self::APP_NAMESPACE;
        (new DiscoveryFile($config))->write(new DiscoveryResult($components ? [
            new DiscoveredComponent("{$namespace}\\ExplicitPost", AsPostType::class, []),
            new DiscoveredComponent("{$namespace}\\SwapOption", AsOptionGroup::class, ['prefix' => 'swap_']),
            new DiscoveredComponent("{$namespace}\\SwapPost", AsPostType::class, []),
        ] : [], []));

        return Kernel::boot($config);
    }

    /** fixture の StorageSwapProbe の静的メソッドを呼ぶ。 */
    private static function probe(string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionMethod(self::APP_NAMESPACE . '\StorageSwapProbe', $method))->invoke(null, ...$arguments);
    }

    /** ユニット層には WordPress の WP_Post が無いため、ID と投稿タイプだけを持つ代役を作る。 */
    private static function post(int $id, string $postType): WP_Post
    {
        $post = Mockery::mock(WP_Post::class);
        self::assertInstanceOf(WP_Post::class, $post);
        $post->ID = $id;
        $post->post_type = $postType;

        return $post;
    }
}

/**
 * register() で、渡したクロージャに Kernel を渡す拡張。
 */
final class KernelStorageTestExtension implements ExtensionInterface
{
    /** @param Closure(Kernel): void $register */
    public function __construct(
        private readonly Closure $register,
    ) {}

    public function register(Kernel $kernel): void
    {
        ($this->register)($kernel);
    }
}
