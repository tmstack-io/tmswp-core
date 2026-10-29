<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Fixtures\PHPStan;

use TMSWP\Core\Tests\Unit\PHPStanProductPost;
use TMSWP\Core\Tests\Unit\PHPStanSideEffectPost;

function side_effect_meta_key_rule_fixture(): void
{
    PHPStanSideEffectPost::meta(1)->int('count');
    PHPStanProductPost::meta(1)->int(PHPStanSideEffectPost::META_COUNT);
    PHPStanSideEffectPost::meta(1)->int(PHPStanSideEffectPost::META_COUNT);
}
