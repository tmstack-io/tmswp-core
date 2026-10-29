<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\PHPStan;

use PHPStan\Testing\TypeInferenceTestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use TMSWP\Core\Tests\Support\PHPStanFixtureAutoloader;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TypeInferenceTest extends TypeInferenceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        PHPStanFixtureAutoloader::register();
    }

    /** @return list<string> */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../../../phpstan/extension.neon'];
    }

    public function testMetaBagAndOptionGetterTypesAreInferredFromSchemas(): void
    {
        $this->assertTypesInFile(__DIR__ . '/../../fixtures/phpstan/type-inference.php');
    }

    /** PageResult の @template により、paginate() の items が list<WP_Post> と推論される(docs/03 §16)。 */
    public function testPaginateItemsAreInferredFromThePageResultTemplate(): void
    {
        $this->assertTypesInFile(__DIR__ . '/../../fixtures/phpstan/page-result-type-inference.php');
    }

    public function testContainerGetIsInferredAsTheRequestedClass(): void
    {
        $this->assertTypesInFile(__DIR__ . '/../../fixtures/phpstan/container-type-inference.php');
    }

    public function testInferenceDoesNotLoadAnalysedClasses(): void
    {
        $this->assertTypesInFile(__DIR__ . '/../../fixtures/phpstan/side-effect-type-inference.php');

        self::assertTrue(PHPStanFixtureAutoloader::sideEffectClassesAreUnloaded());
    }

    private function assertTypesInFile(string $file): void
    {
        $asserts = self::gatherAssertTypes($file);
        self::assertNotSame([], $asserts);

        foreach ($asserts as $assert) {
            $this->assertFileAsserts(...$assert);
        }
    }
}
