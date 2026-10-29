<?php

declare(strict_types=1);

// rules の型(list<RuleInterface>)を破る定義。PHPStan の対象外の場所に置き、実行時の検査を確かめる。

use TMSWP\Core\Tests\Unit\Form\ValidationTestCodeField;

return static fn(): ValidationTestCodeField => new ValidationTestCodeField('code', 'Code', [new stdClass()]);
