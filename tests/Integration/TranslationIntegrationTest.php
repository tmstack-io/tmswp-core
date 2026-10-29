<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use Closure;
use ReflectionFunction;
use TMSWP\Core\Form\Rule\Required;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\JaToolkit\JaToolkit;
use TMSWP\Testing\IntegrationTestCase;
use WP_Hook;

/**
 * FW の同梱訳が、実物の WordPress で日本語を返すことを確かめる。
 *
 * fixture のプラグインが boot した Kernel と、そのKernelConfigのextensionsに登録したja-toolkit(JaToolkit)は、
 * それぞれinit(priority 1)に翻訳ローダーを登録している。WordPress の読み込み時の init はテストのサイトの既定ロケール
 * (en_US)で走るため、ja に切り替えた後に登録済みのローダーそのものを実行し、load_textdomain() が
 * packages/<pkg>/languages/ の訳を読むことを確かめる。
 */
final class TranslationIntegrationTest extends IntegrationTestCase
{
    private const CORE_LANGUAGES = __DIR__ . '/../../languages';

    private const JA_TOOLKIT_LANGUAGES = __DIR__ . '/../../../ja-toolkit/languages';

    public function testFrameworkLoadersProvideJapaneseTranslationsAfterSwitchingToJa(): void
    {
        self::assertSame('{label} is required.', __('{label} is required.', 'tmswp'));

        $loadedFiles = [];
        $recordLoadedFile = static function (string $file, string $domain, string $locale) use (&$loadedFiles): string {
            if ($domain === 'tmswp') {
                $loadedFiles[] = [realpath($file), $locale];
            }

            return $file;
        };

        self::assertTrue(switch_to_locale('ja'));
        add_filter('load_translation_file', $recordLoadedFile, 10, 3);

        try {
            foreach (self::textdomainLoaders() as $loader) {
                $loader();
            }

            // core(Kernel のローダー)
            self::assertSame('{label}を入力してください。', __('{label} is required.', 'tmswp'));
            self::assertSame('{label}を入力してください。', (new Required())->defaultMessage());
            self::assertSame('、', __(', ', 'tmswp'));
            // ja-toolkit(JaToolkitのregister()が予約するローダー)
            self::assertSame('{label}は全角カタカナで入力してください。', __('Please enter {label} in katakana.', 'tmswp'));
            self::assertSame('{label}はひらがなで入力してください。', __('Please enter {label} in hiragana.', 'tmswp'));
        } finally {
            remove_filter('load_translation_file', $recordLoadedFile, 10);
            restore_previous_locale();
        }

        // load_textdomain() は .mo のパスから同名の .l10n.php を先に試し、読めた時点で .mo には進まない
        self::assertSame(
            [
                [realpath(self::CORE_LANGUAGES . '/tmswp-ja.l10n.php'), 'ja'],
                [realpath(self::JA_TOOLKIT_LANGUAGES . '/tmswp-ja.l10n.php'), 'ja'],
            ],
            $loadedFiles,
        );

        // 元のロケールに戻すと訳は外れる(後続のテストに ja の訳が残らない)
        self::assertSame('{label} is required.', __('{label} is required.', 'tmswp'));
    }

    /**
     * init(priority 1)に登録された Kernel と ja-toolkit の翻訳ローダーを、Kernel・ja-toolkit の順で返す。
     *
     * @return list<Closure>
     */
    private static function textdomainLoaders(): array
    {
        $filters = $GLOBALS['wp_filter'] ?? null;
        self::assertIsArray($filters);
        $init = $filters['init'] ?? null;
        self::assertInstanceOf(WP_Hook::class, $init);
        $callbacks = $init->callbacks[1] ?? null;
        self::assertIsArray($callbacks);

        $loaders = [];

        foreach ($callbacks as $callback) {
            self::assertIsArray($callback);
            $function = $callback['function'] ?? null;

            if (!$function instanceof Closure) {
                continue;
            }

            $scope = (new ReflectionFunction($function))->getClosureScopeClass()?->getName();

            if ($scope === Kernel::class || $scope === JaToolkit::class) {
                self::assertArrayNotHasKey($scope, $loaders, "{$scope} registered more than one init(1) closure");
                $loaders[$scope] = $function;
            }
        }

        self::assertSame([Kernel::class, JaToolkit::class], array_keys($loaders));

        return array_values($loaders);
    }
}
