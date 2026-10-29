<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Form\Rule;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\Rule\MaxFileSize;
use TMSWP\Core\Form\StoredFile;
use TMSWP\Core\Form\Validator;
use TMSWP\Testing\UnitTestCase;

/**
 * 同梱の規則MaxFileSizeの仕様(docs/05 §同梱ルール一覧)。{size}はsize_format()で表示する。
 */
final class MaxFileSizeTest extends UnitTestCase
{
    /**
     * ファイルの大きさが上限以下なら合格。
     *
     * @return iterable<string, array{int, bool}>
     */
    public static function sizes(): iterable
    {
        yield 'below the limit' => [9, true];
        yield 'the limit' => [10, true];
        yield 'above the limit' => [11, false];
    }

    #[DataProvider('sizes')]
    public function testMaxFileSizeAcceptsFilesUpToTheLimit(int $size, bool $valid): void
    {
        Functions\when('size_format')->alias(static fn(int $bytes): string => "{$bytes} B");
        $path = $this->createTempDirectory('stored-file') . '/document.pdf';
        file_put_contents($path, 'pdf');
        $definition = FormDefinition::create('upload')->file('photo', 'Photo', [new MaxFileSize(10)]);

        self::assertSame(
            $valid ? [] : ['photo' => ['Photo must be 10 B or smaller.']],
            (new Validator())->validate($definition, ['photo' => new StoredFile('0123456789abcdef0123456789abcdef', $path, 'document.pdf', 'application/pdf', $size)]),
        );
    }

    public function testMaxFileSizeInMegabytesTakesACustomMessageAndFormatsTheSizeWithSizeFormat(): void
    {
        Functions\expect('size_format')->twice()->with(2 * 1024 * 1024)->andReturn('2 MB');
        $file = new StoredFile('0123456789abcdef0123456789abcdef', '/tmp/large.pdf', 'large.pdf', 'application/pdf', 3 * 1024 * 1024);
        $definition = FormDefinition::create('upload')
            ->file('default', 'Default', [MaxFileSize::mb(2)])
            ->file('custom', 'Custom', [MaxFileSize::mb(2, message: '{label} is over {size}.')]);

        self::assertSame(
            ['default' => ['Default must be 2 MB or smaller.'], 'custom' => ['Custom is over 2 MB.']],
            (new Validator())->validate($definition, ['default' => $file, 'custom' => $file]),
        );
    }
}
