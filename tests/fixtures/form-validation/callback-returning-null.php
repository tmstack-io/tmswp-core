<?php

declare(strict_types=1);

// 戻り値の型(bool|string)を破るコールバック。PHPStan の対象外の場所に置き、実行時の検査を確かめる。

use TMSWP\Core\Form\Rule\Callback;

return new Callback(static fn(mixed $value): mixed => null);
