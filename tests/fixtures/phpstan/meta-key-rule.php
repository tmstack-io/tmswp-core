<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Fixtures\PHPStan;

use TMSWP\Core\Tests\Unit\PHPStanOtherPost;
use TMSWP\Core\Tests\Unit\PHPStanProductPost;

function meta_key_rule_fixture(): void
{
    PHPStanProductPost::meta(1)->string(PHPStanOtherPost::META_OTHER);
    PHPStanProductPost::meta(1)->int('price');
    PHPStanProductPost::meta(1)->string('external_key');
    PHPStanProductPost::meta(1)->int(PHPStanProductPost::META_PRICE);
}
