<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use RuntimeException;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\Rule\Required;
use TMSWP\Core\View\Template;
use TMSWP\Core\View\TemplateLocator;
use TMSWP\Testing\IntegrationTestCase;
use TMSWP\Testing\UsesTempDirectories;

final class ViewIntegrationTest extends IntegrationTestCase
{
    use UsesTempDirectories;

    private ?TemplateLocator $originalLocator = null;

    public function tear_down(): void
    {
        if ($this->originalLocator instanceof TemplateLocator) {
            Template::setLocator($this->originalLocator);
            $this->originalLocator = null;
        }

        $this->cleanupTempDirectories();
        parent::tear_down();
    }

    public function testThemePartRendersFormPassedThroughArgs(): void
    {
        $form = new Form(FormDefinition::create('contact')->text('full_name', 'お名前', [new Required()]));

        self::assertStringContainsString(
            'name="full_name"',
            Template::render('parts/contact-form', ['form' => $form]),
        );
    }

    public function testPluginBootRegistersTemplateFallbackDirectory(): void
    {
        self::assertSame($this->pluginTemplatesDir() . '/plugin/card.php', Template::locate('plugin/card'));
        self::assertSame('plugin fallback', Template::render('plugin/card'));
    }

    /**
     * プラグインのtemplates/のテンプレートは、テーマに同じslugのファイルを置くと上書きされる。プラグインのファイルは
     * 候補には残る(テーマの後に探す)。
     */
    public function testThemeFileOverridesPluginTemplateOfSameSlug(): void
    {
        $themeFile = get_stylesheet_directory() . DIRECTORY_SEPARATOR . 'plugin/override.php';
        $pluginFile = $this->pluginTemplatesDir() . DIRECTORY_SEPARATOR . 'plugin/override.php';

        self::assertFileExists($pluginFile);
        self::assertSame($themeFile, Template::locate('plugin/override'));
        self::assertSame('theme override', Template::render('plugin/override'));
        $candidates = Template::locator()->candidatePaths('plugin/override');
        self::assertSame($themeFile, $candidates[0]);
        self::assertContains($pluginFile, $candidates);
    }

    public function testPluginTemplateFallbackCanRenderFormControl(): void
    {
        $html = Template::render('plugin/form');

        self::assertStringContainsString('class="plugin-form-name"', $html);
        self::assertStringContainsString('name="full_name"', $html);
    }

    public function testTemplatePartFiresGetTemplatePartHooksLikeCoreGetTemplatePart(): void
    {
        $form = new Form(FormDefinition::create('contact')->text('full_name', 'お名前', [new Required()]));
        $args = ['form' => $form];

        $coreCalls = $this->recordGetTemplatePartHooks('parts/contact-form', static function () use ($args): void {
            get_template_part('parts/contact-form', null, $args);
        });
        $templateCalls = $this->recordGetTemplatePartHooks('parts/contact-form', static function () use ($args): void {
            Template::part('parts/contact-form', $args);
        });

        self::assertSame(
            [
                ['get_template_part_parts/contact-form', ['parts/contact-form', null, $args]],
                ['get_template_part', ['parts/contact-form', '', ['parts/contact-form.php'], $args]],
            ],
            $coreCalls,
        );
        self::assertSame($coreCalls, $templateCalls);
    }

    public function testTemplatePartFiresGetTemplatePartHooksBeforeIncludingPluginTemplate(): void
    {
        add_action('get_template_part_plugin/card', static function (): void {
            echo '[slug]';
        });
        add_action('get_template_part', static function (): void {
            echo '[generic]';
        });

        self::assertSame('[slug][generic]plugin fallback', Template::render('plugin/card'));
    }

    public function testTemplatePartLocatesTemplateCreatedByGetTemplatePartHook(): void
    {
        $fallback = $this->createTempDirectory('view-hook-created');
        $locator = new TemplateLocator();
        $locator->addFallbackDir($fallback);
        $this->originalLocator = Template::locator();
        Template::setLocator($locator);

        add_action('get_template_part_hook-created/card', static function () use ($fallback): void {
            $created = mkdir($fallback . '/hook-created', 0775)
                && file_put_contents($fallback . '/hook-created/card.php', '<?php echo \'created by hook\';') !== false;

            if (!$created) {
                throw new RuntimeException('failed to create the template in the hook');
            }
        });

        self::assertSame('created by hook', Template::render('hook-created/card'));
    }

    /**
     * get_template_part 系の2つのフックを記録しながら $load を実行し、発火の順の [フック名, 引数] を返す。
     *
     * @param callable(): void $load
     *
     * @return list<array{string, list<mixed>}>
     */
    private function recordGetTemplatePartHooks(string $slug, callable $load): array
    {
        $calls = [];
        $slugHook = "get_template_part_{$slug}";
        $recordSlugHook = static function (mixed ...$hookArgs) use (&$calls, $slugHook): void {
            $calls[] = [$slugHook, array_values($hookArgs)];
        };
        $recordGenericHook = static function (mixed ...$hookArgs) use (&$calls): void {
            $calls[] = ['get_template_part', array_values($hookArgs)];
        };

        add_action($slugHook, $recordSlugHook, 10, 3);
        add_action('get_template_part', $recordGenericHook, 10, 4);

        ob_start();

        try {
            $load();
        } finally {
            ob_end_clean();
            remove_action($slugHook, $recordSlugHook, 10);
            remove_action('get_template_part', $recordGenericHook, 10);
        }

        return $calls;
    }

    private function pluginTemplatesDir(): string
    {
        $pluginFile = self::pluginKernel()->config()->pluginFile;
        self::assertNotNull($pluginFile);

        return \dirname($pluginFile) . '/templates';
    }
}
