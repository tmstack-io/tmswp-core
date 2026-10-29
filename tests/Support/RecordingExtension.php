<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Support;

use TMSWP\Core\Kernel\ExtensionInterface;
use TMSWP\Core\Kernel\Kernel;

/**
 * 呼ばれた Kernel と、そのときの debug を記録するだけの拡張(ユニットテストと fixture のテーマ・プラグインが使う)。
 */
final class RecordingExtension implements ExtensionInterface
{
    /** @var list<array{kernel: Kernel, debug: bool}> */
    public array $registrations = [];

    public function register(Kernel $kernel): void
    {
        $this->registrations[] = ['kernel' => $kernel, 'debug' => $kernel->config()->debug];
    }
}
