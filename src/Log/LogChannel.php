<?php

declare(strict_types=1);

namespace TMSWP\Core\Log;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use TMSWP\Core\Exception\InvalidArgumentException;

/**
 * ログのチャネル1つの設定。`KernelConfig` の `logChannels` で、チャネル名をキーにして宣言する。
 */
final readonly class LogChannel
{
    /** PSR-3 のログレベル */
    public const array LEVELS = [
        LogLevel::DEBUG,
        LogLevel::INFO,
        LogLevel::NOTICE,
        LogLevel::WARNING,
        LogLevel::ERROR,
        LogLevel::CRITICAL,
        LogLevel::ALERT,
        LogLevel::EMERGENCY,
    ];

    /**
     * @param LoggerInterface $logger 記録を渡すロガー
     * @param list<string> $levels 受け付けるレベル(Psr\Log\LogLevel の定数値)。空ならすべてのレベル
     * @param list<string> $scopes 受け付けるスコープ。空ならすべて(スコープの無い記録を含む)
     *
     * @throws InvalidArgumentException 未知のレベル、または空文字のスコープを渡したとき
     */
    public function __construct(
        public LoggerInterface $logger,
        public array $levels = [],
        public array $scopes = [],
    ) {
        foreach ($levels as $level) {
            if (!\in_array($level, self::LEVELS, true)) {
                throw new InvalidArgumentException("unknown log level: {$level}");
            }
        }

        foreach ($scopes as $scope) {
            if ($scope === '') {
                throw new InvalidArgumentException('log scope must not be empty');
            }
        }
    }
}
