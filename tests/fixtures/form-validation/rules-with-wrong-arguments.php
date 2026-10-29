<?php

declare(strict_types=1);

// 引数の欠落・型の違いで規則を作る関数(名前 => 関数)。PHPStan の対象外の場所に置き、strict_types のファイルからの
// 呼び出しで TypeError になることを確かめる。

use TMSWP\Core\Form\Rule\Callback;
use TMSWP\Core\Form\Rule\Choice;
use TMSWP\Core\Form\Rule\MaxLength;
use TMSWP\Core\Form\Rule\MinLength;
use TMSWP\Core\Form\Rule\Range;
use TMSWP\Core\Form\Rule\Regex;
use TMSWP\Core\Form\Rule\RequiredIf;
use TMSWP\Core\Form\Rule\Retype;
use TMSWP\Core\Form\Rule\Url;

return [
    'MaxLength without length' => static fn(): MaxLength => new MaxLength(),
    'MaxLength with a numeric string' => static fn(): MaxLength => new MaxLength('10'),
    'MinLength with a float' => static fn(): MinLength => new MinLength(1.5),
    'Choice with a string' => static fn(): Choice => new Choice('a b'),
    'Regex without pattern' => static fn(): Regex => new Regex(),
    'Retype with an int field' => static fn(): Retype => new Retype(10),
    'Url with a string protocol' => static fn(): Url => new Url('https'),
    'Range with a numeric string' => static fn(): Range => new Range('1', 2),
    'Callback with a function name' => static fn(): Callback => new Callback('strlen'),
    'RequiredIf without the value' => static fn(): RequiredIf => RequiredIf::equals('type'),
];
