<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Form\Rule;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\Testing\UnitTestCase;
use TypeError;

/**
 * 同梱の規則のコンストラクタの引数の検査。旧iwf2bのRuleTraitTestの必須パラメータの欠落・型の違いの移植先
 * (docs/10 §8.1の9)。複数の規則を同じ観点で確かめるため、規則のディレクトリに置く。
 */
final class ConstructorArgumentsTest extends UnitTestCase
{
    /**
     * 旧 RuleTrait の必須パラメータの欠落・型の違いの実行時の検査は、コンストラクタの型宣言(strict_types)に置き換えた。
     * 残すのは TypeError になることの確認だけ(欠落は TypeError の子の ArgumentCountError)。
     *
     * @return iterable<string, array{Closure(): mixed}>
     */
    public static function constructorsWithWrongArguments(): iterable
    {
        $constructors = require __DIR__ . '/../../../fixtures/form-validation/rules-with-wrong-arguments.php';
        self::assertIsArray($constructors);

        foreach ($constructors as $name => $construct) {
            self::assertIsString($name);
            self::assertInstanceOf(Closure::class, $construct);

            yield $name => [$construct];
        }
    }

    /** @param Closure(): mixed $construct */
    #[DataProvider('constructorsWithWrongArguments')]
    public function testMissingOrWronglyTypedRuleArgumentsAreTypeErrors(Closure $construct): void
    {
        $this->expectException(TypeError::class);

        $construct();
    }
}
