<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Support;

/**
 * PHPStan 拡張のテストの fixture(tests/fixtures/phpstan/)のクラスを、利用者のプロジェクトの Composer と同じく
 * 実行時の autoload で見つかるようにする。
 *
 * PHPStan はテストでは autoload を、ファイルの読み込みを横取りして中身を実行しない形で使い、クラスを静的に解析する。
 * side-effect-classes.php は読み込むと例外を投げるため、PHPStan 拡張が解析中に class_exists 等で
 * 利用者のコードを読み込むと、この autoload 経由で例外になる。
 */
final class PHPStanFixtureAutoloader
{
    /** 読み込むと例外を投げる fixture のクラス */
    public const SIDE_EFFECT_CLASSES = [
        'TMSWP\Core\Tests\Unit\PHPStanSideEffectPost',
        'TMSWP\Core\Tests\Unit\PHPStanSideEffectOption',
    ];

    private const SCHEMA_CLASSES = [
        'TMSWP\Core\Tests\Unit\PHPStanProductPost',
        'TMSWP\Core\Tests\Unit\PHPStanOtherPost',
        'TMSWP\Core\Tests\Unit\PHPStanSiteOption',
        'TMSWP\Core\Tests\Unit\PHPStanOverriddenGetOption',
    ];

    private const KERNEL_RESET_CLASSES = [
        'TMSWP\Core\Tests\Unit\PHPStanKernelResetProductionService',
        'TMSWP\Core\Tests\Unit\PHPStanKernelResetDirectTest',
        'TMSWP\Core\Tests\Unit\PHPStanKernelResetIndirectTest',
        'TMSWP\Core\Tests\Unit\PHPStanKernelResetOtherReset',
    ];

    private function __construct() {}

    public static function register(): void
    {
        spl_autoload_register(static function (string $class): void {
            if (\in_array($class, self::SCHEMA_CLASSES, true)) {
                require __DIR__ . '/../fixtures/phpstan/schema-classes.php';
            }

            if (\in_array($class, self::SIDE_EFFECT_CLASSES, true)) {
                require __DIR__ . '/../fixtures/phpstan/side-effect-classes.php';
            }

            if (\in_array($class, self::KERNEL_RESET_CLASSES, true)) {
                require __DIR__ . '/../fixtures/phpstan/kernel-reset-rule.php';
            }
        });
    }

    /** 読み込むと例外を投げる fixture のクラスが、実行時に 1 つも読み込まれていないか。 */
    public static function sideEffectClassesAreUnloaded(): bool
    {
        // enum も get_declared_classes() に含まれる
        return array_intersect(self::SIDE_EFFECT_CLASSES, get_declared_classes()) === [];
    }
}
