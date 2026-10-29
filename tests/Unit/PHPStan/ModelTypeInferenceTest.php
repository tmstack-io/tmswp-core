<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\PHPStan;

use PHPStan\Testing\TypeInferenceTestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Model の find() と QueryBuilder の get()/first() の行の型が、rowClass() の宣言型と as() の引数から絞られることを
 * 確かめる(docs/06 §4)。
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ModelTypeInferenceTest extends TypeInferenceTestCase
{
    private const FIXTURE = __DIR__ . '/../../fixtures/phpstan/model-type-inference.php';

    private const FIXTURE_NAMESPACE = 'TMSWP\\Core\\Tests\\Fixtures\\PHPStan\\Model\\';

    protected function setUp(): void
    {
        parent::setUp();

        // fixture のクラスを、利用者のプロジェクトの Composer と同じく autoload で見つかるようにする。PHPStan は
        // テストでは autoload によるファイルの読み込みを横取りし、中身を実行せずにクラスを静的に解析する。
        spl_autoload_register(static function (string $class): void {
            if (str_starts_with($class, self::FIXTURE_NAMESPACE)) {
                require_once self::FIXTURE;
            }
        });
    }

    /** @return list<string> */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../../../phpstan/extension.neon'];
    }

    public function testModelRowTypesAreInferredFromRowClassAndAs(): void
    {
        $asserts = self::gatherAssertTypes(self::FIXTURE);
        self::assertNotSame([], $asserts);

        foreach ($asserts as $assert) {
            $this->assertFileAsserts(...$assert);
        }
    }
}
