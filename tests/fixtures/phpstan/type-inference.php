<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Fixtures\PHPStan;

use TMSWP\Core\Tests\Unit\PHPStanOverriddenGetOption;
use TMSWP\Core\Tests\Unit\PHPStanProductPost;
use TMSWP\Core\Tests\Unit\PHPStanSiteOption;

use function PHPStan\Testing\assertType;

assertType('int', PHPStanProductPost::meta(1)->get(PHPStanProductPost::META_PRICE));
assertType('string|null', PHPStanProductPost::meta(1)->get(PHPStanProductPost::META_CODE));
assertType('string', PHPStanProductPost::meta(1)->get(PHPStanProductPost::META_CODE, ''));
assertType('list<int>|null', PHPStanProductPost::meta(1)->get(PHPStanProductPost::META_RELATED_IDS));
assertType('list<int>', PHPStanProductPost::meta(1)->get(PHPStanProductPost::META_RELATED_IDS, []));
assertType('mixed', PHPStanProductPost::meta(1)->get('undeclared'));
assertType('string', PHPStanSiteOption::ContactEmail->get());
assertType('bool', PHPStanSiteOption::Maintenance->get());

// レシーバが enum ケースの定数型に定まる一般の式でも絞り込む
$option = PHPStanSiteOption::ContactEmail;
assertType('string', $option->get());

/** @param PHPStanSiteOption::Maintenance $option */
function option_case_parameter(PHPStanSiteOption $option): void
{
    assertType('bool', $option->get());
    assertType('0|bool', $option->get(0));
}

// ケースが定まらない enum 型は宣言型のまま
function option_enum_parameter(PHPStanSiteOption $option): void
{
    assertType('mixed', $option->get());
}

// enum 自身が定義した get() は OptionAccess の get() ではないため、絞り込まずにその宣言型を返す
assertType('int', PHPStanOverriddenGetOption::Text->get());
