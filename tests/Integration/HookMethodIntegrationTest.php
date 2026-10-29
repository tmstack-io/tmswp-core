<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use TMSWP\Testing\IntegrationTestCase;

/**
 * fixture の ThemeProbeHook / PluginProbeHook は、生成時と呼び出し時に観測用アクションを発火する。
 * その発火回数(did_action)で、生成の遅延と呼び出しを確かめる。
 */
final class HookMethodIntegrationTest extends IntegrationTestCase
{
    public function testThemeBootRunsMethodHooksOnWordPressHooks(): void
    {
        $this->assertMethodHooksRun('tmswp_fixture_theme');
    }

    public function testPluginBootRunsMethodHooksOnWordPressHooks(): void
    {
        $this->assertMethodHooksRun('tmswp_fixture_plugin');
    }

    private function assertMethodHooksRun(string $prefix): void
    {
        self::assertSame(0, did_action("{$prefix}/probe_constructed"));
        self::assertNotFalse(has_action("{$prefix}/action"));
        self::assertNotFalse(has_filter("{$prefix}/filter"));

        do_action("{$prefix}/action");

        self::assertSame(1, did_action("{$prefix}/probe_constructed"));
        self::assertSame(1, did_action("{$prefix}/probe_handled"));
        self::assertSame('value-filtered', apply_filters("{$prefix}/filter", 'value'));
        // 2つ目のフックでも Container の共有インスタンスが使われ、生成は1回のまま
        self::assertSame(1, did_action("{$prefix}/probe_constructed"));
    }
}
