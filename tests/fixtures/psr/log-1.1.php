<?php

declare(strict_types=1);

// psr/log 1.1 の宣言。$message に型が無く戻り値の型も無いため、LoggerRegistry::log() 等の宣言と合わない
namespace Psr\Log;

interface LoggerInterface
{
    public function emergency($message, array $context = array());

    public function alert($message, array $context = array());

    public function critical($message, array $context = array());

    public function error($message, array $context = array());

    public function warning($message, array $context = array());

    public function notice($message, array $context = array());

    public function info($message, array $context = array());

    public function debug($message, array $context = array());

    public function log($level, $message, array $context = array());
}
