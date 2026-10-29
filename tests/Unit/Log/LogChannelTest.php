<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Log;

use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Log\LogChannel;
use TMSWP\Testing\UnitTestCase;

/**
 * LogChannelの設定の検査(docs/07 §5)。
 */
final class LogChannelTest extends UnitTestCase
{
    /**
     * 受理するレベルはPSR-3のレベルに限り、受理するスコープは空にできない。
     *
     * @return iterable<string, array{list<string>, list<string>, string}>
     */
    public static function invalidSettings(): iterable
    {
        yield 'unknown level' => [['fatal'], [], 'unknown log level: fatal'];
        yield 'empty scope' => [[], [''], 'log scope must not be empty'];
    }

    /**
     * @param list<string> $levels
     * @param list<string> $scopes
     */
    #[DataProvider('invalidSettings')]
    public function testLogChannelRejectsInvalidSettings(array $levels, array $scopes, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new LogChannel(new NullLogger(), levels: $levels, scopes: $scopes);
    }
}
