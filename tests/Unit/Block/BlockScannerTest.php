<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Block;

use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\Core\Block\BlockCategory;
use TMSWP\Core\Block\BlockScanner;
use TMSWP\Core\Block\DiscoveredBlock;
use TMSWP\Core\Exception\DiscoveryException;
use TMSWP\Core\Exception\KernelException;
use TMSWP\Core\Kernel\Discovery;
use TMSWP\Core\Kernel\DiscoveryFile;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Testing\UnitTestCase;
use UnexpectedValueException;

/**
 * ビルド出力(`build/blocks/`)のblock.jsonの走査と、その検査(名前の重複・未登録のカテゴリ等)。
 */
final class BlockScannerTest extends UnitTestCase
{
    /**
     * 入れ子を含むbuild/blocks/のblock.jsonを、ブロック名の昇順で、ルートからの相対パスとともに返す。ソース(blocks/)
     * と、build/blocks/の外のblock.jsonは走査しない。
     */
    public function testItScansNestedBuildOutputOnly(): void
    {
        $config = $this->config();
        $root = $config->rootDir();
        $this->writeJson($root . '/build/blocks/zeta/block.json', ['name' => 'example/zeta', 'category' => 'text']);
        $this->writeJson($root . '/build/blocks/group/alpha/block.json', ['name' => 'example/alpha']);
        $this->writeJson($root . '/build/blocks/group/alpha/inner/block.json', ['name' => 'example/inner', 'category' => 'example']);
        // ソースとbuild/blocks/の外は、同じ名前でも重複にならない(走査しない)
        $this->writeJson($root . '/blocks/zeta/block.json', ['name' => 'example/zeta']);
        $this->writeJson($root . '/build/other/block.json', ['name' => 'example/zeta']);
        $this->writeJson($root . '/build/blocks/.cache/block.json', ['name' => 'example/zeta']);

        $blocks = (new BlockScanner($config))->scan();

        self::assertSame(
            [['example/alpha', 'build/blocks/group/alpha'], ['example/inner', 'build/blocks/group/alpha/inner'], ['example/zeta', 'build/blocks/zeta']],
            array_map(static fn(DiscoveredBlock $block): array => [$block->name, $block->path], $blocks),
        );
        self::assertSame(hash('sha256', '{"name":"example/alpha"}'), $blocks[0]->metadataHash);
    }

    public function testItReturnsNoBlocksWithoutBuildDirectory(): void
    {
        self::assertSame([], (new BlockScanner($this->config()))->scan());
    }

    /** Discovery::scan()は、app/の走査の結果に加えてブロックを返す。 */
    public function testDiscoveryScanIncludesBlocks(): void
    {
        $config = $this->config();
        $this->writeJson($config->rootDir() . '/build/blocks/card/block.json', ['name' => 'example/card']);

        $result = (new Discovery($config, new DiscoveryFile($config)))->scan();

        self::assertSame(['example/card'], array_map(static fn(DiscoveredBlock $block): string => $block->name, $result->blocks));
    }

    /** ハッシュはJSONとして読み直した内容から求めるため、空白・改行の違いでは変わらず、値を変えると変わる。 */
    public function testMetadataHashFollowsContentsButNotFormatting(): void
    {
        $config = $this->config();
        $file = $config->rootDir() . '/build/blocks/card/block.json';
        $this->write($file, "{\n  \"name\": \"example/card\",\n  \"title\": \"Card\"\n}\n");
        $pretty = (new BlockScanner($config))->scan()[0]->metadataHash;

        $this->write($file, '{"name":"example/card","title":"Card"}');
        self::assertSame($pretty, (new BlockScanner($config))->scan()[0]->metadataHash);

        $this->write($file, '{"name":"example/card","title":"Card 2"}');
        self::assertNotSame($pretty, (new BlockScanner($config))->scan()[0]->metadataHash);
    }

    /** Kernelの中の名前の重複は、両方のblock.jsonを示して探索のエラーにする。 */
    public function testItRejectsDuplicateBlockNamesInKernel(): void
    {
        $config = $this->config();
        $root = $config->rootDir();
        $this->writeJson($root . '/build/blocks/a/block.json', ['name' => 'example/card']);
        $this->writeJson($root . '/build/blocks/nested/b/block.json', ['name' => 'example/card']);

        $this->expectException(DiscoveryException::class);
        $this->expectExceptionMessage("block name 'example/card' is declared by both {$root}/build/blocks/a/block.json and {$root}/build/blocks/nested/b/block.json; block names must be unique");

        (new BlockScanner($config))->scan();
    }

    /** categoryは、WordPressの既定のカテゴリかこのKernelのKernelConfigのカテゴリでなければ探索のエラーにする。 */
    #[DataProvider('unregisteredCategories')]
    public function testItRejectsUnregisteredCategory(mixed $category, string $shown): void
    {
        $config = $this->config();
        $file = $config->rootDir() . '/build/blocks/card/block.json';
        $this->writeJson($file, ['name' => 'example/card', 'category' => $category]);

        $this->expectException(DiscoveryException::class);
        $this->expectExceptionMessage("block 'example/card' in {$file} uses the unregistered category {$shown}; use one of WordPress's categories (text, media, design, widgets, theme, embed, reusable) or register it with the blockCategories of KernelConfig");

        (new BlockScanner($config))->scan();
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function unregisteredCategories(): iterable
    {
        yield 'unknown slug' => ['unknown', "'unknown'"];
        yield 'not a string' => [['text'], 'array'];
    }

    public function testItAcceptsCoreAndKernelCategoriesAndOmittedCategory(): void
    {
        $config = $this->config();
        $root = $config->rootDir();

        foreach ([...BlockCategory::CORE_SLUGS, 'example'] as $category) {
            $this->writeJson("{$root}/build/blocks/{$category}/block.json", ['name' => "example/{$category}", 'category' => $category]);
        }

        $this->writeJson("{$root}/build/blocks/none/block.json", ['name' => 'example/none']);

        self::assertCount(\count(BlockCategory::CORE_SLUGS) + 2, (new BlockScanner($config))->scan());
    }

    #[DataProvider('invalidMetadata')]
    public function testItRejectsInvalidMetadata(string $contents, string $message): void
    {
        $config = $this->config();
        $file = $config->rootDir() . '/build/blocks/card/block.json';
        $this->write($file, $contents);

        $this->expectException(DiscoveryException::class);
        $this->expectExceptionMessage(str_replace('{file}', $file, $message));

        (new BlockScanner($config))->scan();
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidMetadata(): iterable
    {
        $nameMessage = "{file} must have a 'name' in the form 'namespace/name' (lowercase letters, digits and '-')";

        yield 'broken json' => ['{"name": ', 'invalid JSON in {file}: Syntax error'];
        yield 'json list' => ['["example/card"]', '{file} must contain a JSON object'];
        yield 'empty object' => ['{}', '{file} must contain a JSON object'];
        yield 'missing name' => ['{"title": "Card"}', $nameMessage];
        yield 'name without namespace' => ['{"name": "card"}', $nameMessage];
        yield 'uppercase name' => ['{"name": "Example/Card"}', $nameMessage];
        yield 'name not string' => ['{"name": 1}', $nameMessage];
    }

    public function testItRejectsBuildBlocksThatIsNotDirectory(): void
    {
        $config = $this->config();
        $this->write($config->rootDir() . '/build/blocks', '');

        $this->expectException(DiscoveryException::class);
        $this->expectExceptionMessage("{$config->rootDir()}/build/blocks must be a directory of built blocks");

        (new BlockScanner($config))->scan();
    }

    /**
     * build/blocks/か、その下のディレクトリを読めない場合は、元の例外をpreviousに持つDiscoveryExceptionにし、走査を
     * 続けない。権限はfinallyで戻す(一時ディレクトリを後始末で消せるようにする)。
     */
    #[DataProvider('unreadableDirectories')]
    public function testItRejectsUnreadableDirectories(string $unreadable): void
    {
        $config = $this->config();
        $buildDir = $config->rootDir() . '/build/blocks';
        $this->writeJson($buildDir . '/card/block.json', ['name' => 'example/card']);
        $this->writeJson($buildDir . '/group/inner/block.json', ['name' => 'example/inner']);
        $directory = $buildDir . $unreadable;
        self::assertTrue(chmod($directory, 0o000));

        try {
            // rootで動かすと権限が効かず、この対照が成り立たない
            self::assertFalse(is_readable($directory));
            (new BlockScanner($config))->scan();
            self::fail('DiscoveryException was not thrown');
        } catch (DiscoveryException $exception) {
            self::assertStringStartsWith("failed to read the built blocks under {$buildDir}: ", $exception->getMessage());
            self::assertStringContainsString($directory, $exception->getMessage());
            self::assertStringEndsWith("; make the directories readable, then run 'wp tmswp discover' again", $exception->getMessage());
            self::assertInstanceOf(UnexpectedValueException::class, $exception->getPrevious());
        } finally {
            chmod($directory, 0o755);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function unreadableDirectories(): iterable
    {
        yield 'build/blocks itself' => [''];
        yield 'nested directory' => ['/group'];
    }

    #[DataProvider('invalidCategories')]
    public function testBlockCategoryRejectsInvalidArguments(string $slug, string $title, string $message): void
    {
        $this->expectException(KernelException::class);
        $this->expectExceptionMessage($message);

        new BlockCategory($slug, $title);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidCategories(): iterable
    {
        yield 'uppercase slug' => ['Example', 'Example', "invalid block category slug 'Example'"];
        yield 'empty slug' => ['', 'Example', "invalid block category slug ''"];
        yield 'empty title' => ['example', '', "block category 'example' requires a non-empty title"];
    }

    public function testBlockCategoryToArrayMatchesBlockCategoriesAllEntry(): void
    {
        self::assertSame(['slug' => 'example', 'title' => 'Example', 'icon' => 'star-filled'], (new BlockCategory('example', 'Example', 'star-filled'))->toArray());
    }

    /** KernelConfigのblockCategoriesは、Kernelの中のスラッグの重複と、WordPressの既定のカテゴリのスラッグを拒否する。 */
    public function testKernelConfigRejectsDuplicateAndCoreBlockCategories(): void
    {
        $appDir = $this->createTempDirectory('block-category-app');

        try {
            KernelConfig::theme($appDir, 'Example\\Blocks', blockCategories: [new BlockCategory('example', 'A'), new BlockCategory('example', 'B')]);
            self::fail('KernelException was not thrown for the duplicate');
        } catch (KernelException $exception) {
            self::assertSame("block category 'example' is registered more than once in the same kernel", $exception->getMessage());
        }

        $this->expectException(KernelException::class);
        $this->expectExceptionMessage("block category 'text' is a WordPress core category; use it in block.json without registering it");

        KernelConfig::plugin(__FILE__, $appDir, 'Example\\Blocks', blockCategories: [new BlockCategory('text', 'Text')]);
    }

    private function config(): KernelConfig
    {
        $root = $this->createTempDirectory('block-scanner');
        self::assertTrue(mkdir($root . '/app'));

        return KernelConfig::theme($root . '/app', 'Example\\Blocks' . uniqid(), true, blockCategories: [new BlockCategory('example', 'Example')]);
    }

    /** @param array<string, mixed> $metadata */
    private function writeJson(string $file, array $metadata): void
    {
        $this->write($file, json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function write(string $file, string $contents): void
    {
        $dir = \dirname($file);

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            self::fail("ディレクトリを作成できません: {$dir}");
        }

        if (file_put_contents($file, $contents) === false) {
            self::fail("ファイルを書き込めません: {$file}");
        }
    }
}
