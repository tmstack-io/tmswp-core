<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Form\Rule;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\Rule\FileExtension;
use TMSWP\Core\Form\StoredFile;
use TMSWP\Core\Form\UploadedFile;
use TMSWP\Core\Form\Validator;
use TMSWP\Testing\UnitTestCase;

/**
 * 同梱の規則FileExtensionの仕様(docs/05 §同梱ルール一覧)。クライアントのファイル名の拡張子を小文字にして比べ、
 * アップロードしたファイル(UploadedFile)は、さらに実体のMIMEがその拡張子と矛盾しないことを
 * wp_check_filetype_and_ext()で確かめる。確認画面から戻ったStoredFileは、保管の前に確かめ済みのため拡張子だけを比べる。
 */
final class FileExtensionTest extends UnitTestCase
{
    /** @return iterable<string, array{string, string, bool}> */
    public static function clientNames(): iterable
    {
        yield 'allowed extension' => ['document.pdf', 'application/pdf', true];
        yield 'other extension' => ['document.txt', 'text/plain', false];
        yield 'upper case extension' => ['DOCUMENT.PDF', 'application/pdf', true];
        yield 'no extension' => ['document', 'application/pdf', false];
        yield 'allowed extension before the last one' => ['document.pdf.txt', 'text/plain', false];
        yield 'allowed extension as the last one' => ['document.txt.pdf', 'application/pdf', true];
    }

    #[DataProvider('clientNames')]
    public function testFileExtensionComparesTheLowerCasedExtensionOfTheClientName(string $clientName, string $mime, bool $valid): void
    {
        $path = $this->createTempDirectory('stored-file') . '/document.pdf';
        file_put_contents($path, 'pdf');
        $definition = FormDefinition::create('upload')->file('file', 'File', [new FileExtension(['pdf'])]);

        self::assertSame(
            $valid ? [] : ['file' => ['Please upload File as one of these file types: pdf.']],
            (new Validator())->validate($definition, ['file' => new StoredFile('0123456789abcdef0123456789abcdef', $path, $clientName, $mime, 3)]),
        );
    }

    /**
     * アップロードしたファイルは、拡張子が許されても、wp_check_filetype_and_ext()が実体を拒否した(extがfalse)なら違反。
     * クライアントが申告したMIMEは、許可にも拒否にも使わない。
     *
     * @return iterable<string, array{array{ext: string|false, type: string|false}, string, bool}>
     */
    public static function serverChecksOfUploadedFiles(): iterable
    {
        yield 'server accepts, client declares an image' => [['ext' => 'jpg', 'type' => 'image/jpeg'], 'image/jpeg', true];
        yield 'server accepts, client declares PHP' => [['ext' => 'jpg', 'type' => 'image/jpeg'], 'text/x-php', true];
        yield 'server rejects, client declares an image' => [['ext' => false, 'type' => false], 'image/jpeg', false];
        yield 'server rejects, client declares PHP' => [['ext' => false, 'type' => false], 'text/x-php', false];
    }

    /** @param array{ext: string|false, type: string|false} $check */
    #[DataProvider('serverChecksOfUploadedFiles')]
    public function testUploadedFilePassesOnlyWhenWordPressAcceptsItsContent(array $check, string $clientMime, bool $valid): void
    {
        $path = $this->createTempDirectory('uploaded-file') . '/php-upload';
        file_put_contents($path, 'contents');
        Functions\expect('wp_check_filetype_and_ext')->once()->with($path, 'photo.jpg')->andReturn($check + ['proper_filename' => false]);
        $definition = FormDefinition::create('upload')->file('file', 'File', [new FileExtension(['jpg'])]);

        self::assertSame(
            $valid ? [] : ['file' => ['Please upload File as one of these file types: jpg.']],
            (new Validator())->validate($definition, ['file' => new UploadedFile($path, 'photo.jpg', $clientMime, 8, UPLOAD_ERR_OK)]),
        );
    }

    /**
     * 拡張子が許されないアップロードは、実体を確かめる前に違反になる(wp_check_filetype_and_ext()を呼ばない)。大文字の拡張子は
     * 小文字にして比べる。
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function clientNamesOfUploadedFiles(): iterable
    {
        yield 'other extension' => ['photo.php', false];
        yield 'no extension' => ['photo', false];
        yield 'upper case allowed extension' => ['PHOTO.JPG', true];
    }

    #[DataProvider('clientNamesOfUploadedFiles')]
    public function testUploadedFileExtensionIsCheckedBeforeItsContent(string $clientName, bool $checksContent): void
    {
        $path = $this->createTempDirectory('uploaded-file') . '/php-upload';
        file_put_contents($path, 'contents');
        Functions\expect('wp_check_filetype_and_ext')->times($checksContent ? 1 : 0)->with($path, $clientName)->andReturn(['ext' => 'jpg', 'type' => 'image/jpeg', 'proper_filename' => false]);
        $definition = FormDefinition::create('upload')->file('file', 'File', [new FileExtension(['jpg'])]);

        self::assertSame(
            $checksContent ? [] : ['file' => ['Please upload File as one of these file types: jpg.']],
            (new Validator())->validate($definition, ['file' => new UploadedFile($path, $clientName, 'image/jpeg', 8, UPLOAD_ERR_OK)]),
        );
    }

    /** ファイルでない値は違反 */
    public function testFileExtensionRejectsValuesOtherThanFiles(): void
    {
        $definition = FormDefinition::create('upload')->file('file', 'File', [new FileExtension(['pdf'])]);

        self::assertSame(
            ['file' => ['Please upload File as one of these file types: pdf.']],
            (new Validator())->validate($definition, ['file' => 'document.pdf']),
        );
    }
}
