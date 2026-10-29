<?php

declare(strict_types=1);

namespace TMSWP\Core\Log;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Stringable;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Exception\UnknownLogChannelException;

/**
 * 1つの Kernel のログのチャネルを持ち、記録をレベルとスコープで振り分ける。Kernel ごとに1つ作られる(`Kernel::logger()`)。
 */
final class LoggerRegistry implements LoggerInterface
{
    use LoggerTrait;

    /** @var array<string, LogChannel> */
    private array $channels;

    /**
     * @param array<string, LogChannel> $channels チャネル名 => 設定。空なら最初の記録の時点で default チャネル(SimpleLogger)を作る
     *
     * @throws InvalidArgumentException チャネル名が空文字のとき
     */
    public function __construct(array $channels = [])
    {
        foreach (array_keys($channels) as $name) {
            if ($name === '') {
                throw new InvalidArgumentException('log channel must not be empty');
            }
        }

        $this->channels = $channels;
    }

    public function has(string $channel): bool
    {
        return \array_key_exists($channel, $this->channels);
    }

    public function channel(string $channel): LoggerInterface
    {
        if (!\array_key_exists($channel, $this->channels)) {
            throw new UnknownLogChannelException("unknown log channel: {$channel}");
        }

        return $this->channels[$channel]->logger;
    }

    public function scope(string $scope): LoggerInterface
    {
        if ($scope === '') {
            throw new InvalidArgumentException('log scope must not be empty');
        }

        return new ScopedLog($this, $scope);
    }

    /**
     * @param mixed $level
     * @param array<mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->dispatch($level, $message, $context, null);
    }

    /**
     * @internal ScopedLog から呼ばれる振り分けの本体
     *
     * @param array<mixed> $context
     */
    public function dispatch(mixed $level, string|Stringable $message, array $context, ?string $scope): void
    {
        if (!\is_string($level) || !\in_array($level, LogChannel::LEVELS, true)) {
            throw new \Psr\Log\InvalidArgumentException('unknown log level: ' . get_debug_type($level));
        }

        // チャネルを宣言していない Kernel は、最初の記録の時点で default チャネルを作る(遅延デフォルトチャネル)
        if ($this->channels === []) {
            $this->channels['default'] = new LogChannel(new SimpleLogger());
        }

        if ($scope !== null && !\array_key_exists('_scope', $context)) {
            $context['_scope'] = $scope;
        }

        foreach ($this->channels as $channel) {
            $matchLevel = $channel->levels === [] || \in_array($level, $channel->levels, true);
            $matchScope = $channel->scopes === [] || ($scope !== null && \in_array($scope, $channel->scopes, true));

            if ($matchLevel && $matchScope) {
                $channel->logger->log($level, $message, $context);
            }
        }
    }
}
