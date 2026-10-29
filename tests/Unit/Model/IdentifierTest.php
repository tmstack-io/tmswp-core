<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Model;

use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\Core\Exception\SchemaException;
use TMSWP\Core\Model\Identifier;
use TMSWP\Testing\UnitTestCase;

/**
 * スキーマの識別子の予約語の判定(06-model §2)。MySQL 8.4 LTS・9.7 LTSで増えた予約語を、宣言の時点で拒否する。
 * 予約語の集合の全体は、DBのinformation_schema.KEYWORDSとの照合を統合テスト(ReservedWordIntegrationTest)で確かめる。
 */
final class IdentifierTest extends UnitTestCase
{
    /** @return iterable<string, array{string}> */
    public static function reservedWordsAddedInMysqlLts(): iterable
    {
        yield 'qualify(MySQL 8.4)' => ['qualify'];
        yield 'tablesample(MySQL 8.4)' => ['tablesample'];
        yield 'external(MySQL 9.7)' => ['external'];
        yield 'library(MySQL 9.7)' => ['library'];
        yield 'QUALIFY(大文字)' => ['QUALIFY'];
        yield 'Library(大文字と小文字の混在)' => ['Library'];
    }

    #[DataProvider('reservedWordsAddedInMysqlLts')]
    public function testSchemaSimpleRejectsReservedWordAddedInMysqlLts(string $word): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage("reserved word cannot be used as schema identifier: {$word}");

        Identifier::schemaSimple($word);
    }

    /** @return iterable<string, array{string}> */
    public static function nonReservedKeywordsAddedInMysqlLts(): iterable
    {
        yield 'bernoulli(MySQL 8.4)' => ['bernoulli'];
        yield 'parallel(MySQL 8.4)' => ['parallel'];
        yield 'absent(MySQL 9.7)' => ['absent'];
        yield 'materialized(MySQL 9.7)' => ['materialized'];
    }

    /** 同じ版で増えた予約されていないキーワードは、クォートせずにカラム名に使えるため受け付ける。 */
    #[DataProvider('nonReservedKeywordsAddedInMysqlLts')]
    public function testSchemaSimpleAcceptsNonReservedKeywordAddedInMysqlLts(string $word): void
    {
        self::assertSame($word, Identifier::schemaSimple($word));
    }
}
