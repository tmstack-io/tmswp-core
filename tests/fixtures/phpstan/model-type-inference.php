<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Fixtures\PHPStan\Model;

use TMSWP\Core\Model\Connection;
use TMSWP\Core\Model\Model;
use TMSWP\Core\Model\QueryBuilder;
use TMSWP\Core\Model\TableDefinition;

use function PHPStan\Testing\assertType;

final readonly class InquiryRow
{
    public function __construct(
        public int $id,
    ) {}
}

final readonly class SummaryRow
{
    public function __construct(
        public int $total,
    ) {}
}

/** rowClass() を上書きしない: 行は stdClass */
final class PlainModel extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('plain')->id();
    }

    public static function latest(): void
    {
        assertType('stdClass|null', self::find(1));
        assertType('stdClass|null', static::find(1));
        assertType('list<stdClass>', static::query()->get());
    }
}

/** rowClass() で行の DTO を宣言する */
final class InquiryModel extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('inquiries')->id();
    }

    /** @return class-string<InquiryRow> */
    public static function rowClass(): string
    {
        return InquiryRow::class;
    }
}

/** null を返しうる rowClass(): 行は DTO か stdClass */
final class MaybeRowModel extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('maybe')->id();
    }

    /** @return class-string<InquiryRow>|null */
    public static function rowClass(): ?string
    {
        return InquiryRow::class;
    }
}

/** 宣言型が class-string のままの rowClass(): 行のクラスが定まらないため絞り込まない */
final class UndeclaredRowModel extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('undeclared')->id();
    }

    public static function rowClass(): string
    {
        return InquiryRow::class;
    }
}

/** final でないクラスの static:: と self::(静的束縛を引き継ぐ)は、サブクラスが rowClass() を上書きしうるため絞り込まない */
abstract class BaseModel extends Model
{
    public static function first(): void
    {
        assertType('object|null', static::find(1));
        assertType('list<object>', static::query()->get());
        assertType('object|null', self::find(1));
    }
}

assertType('stdClass|null', PlainModel::find(1));
assertType('list<stdClass>', PlainModel::query()->get());
assertType('stdClass|null', PlainModel::query()->where('id', 1)->orderByDesc('id')->first());

assertType('TMSWP\Core\Tests\Fixtures\PHPStan\Model\InquiryRow|null', InquiryModel::find(1));
assertType('list<TMSWP\Core\Tests\Fixtures\PHPStan\Model\InquiryRow>', InquiryModel::query()->where('id', 1)->limit(10)->get());
assertType('TMSWP\Core\Tests\Fixtures\PHPStan\Model\InquiryRow|null', InquiryModel::query()->first());

assertType('stdClass|TMSWP\Core\Tests\Fixtures\PHPStan\Model\InquiryRow|null', MaybeRowModel::find(1));

assertType('object|null', UndeclaredRowModel::find(1));
assertType('list<object>', UndeclaredRowModel::query()->get());

// as() はチェーンの起点の rowClass() より優先し、チェーンの最後の as() が効く
assertType('list<TMSWP\Core\Tests\Fixtures\PHPStan\Model\SummaryRow>', InquiryModel::query()->as(SummaryRow::class)->orderByDesc('id')->get());
assertType('TMSWP\Core\Tests\Fixtures\PHPStan\Model\InquiryRow|null', PlainModel::query()->as(SummaryRow::class)->where('id', 1)->as(InquiryRow::class)->first());
assertType('TMSWP\Core\Tests\Fixtures\PHPStan\Model\SummaryRow|null', (new QueryBuilder(Connection::fromGlobal(), 'plain'))->as(SummaryRow::class)->first());

/** @param class-string<SummaryRow> $rowClass */
function rows_of(string $rowClass): void
{
    assertType('list<TMSWP\Core\Tests\Fixtures\PHPStan\Model\SummaryRow>', PlainModel::query()->as($rowClass)->get());
}

/** @param class-string $rowClass */
function rows_of_unknown_class(string $rowClass): void
{
    assertType('list<object>', PlainModel::query()->as($rowClass)->get());
}

// 変数に入れたビルダは as() で書き換わりうるため、チェーンの外では絞り込まない
$query = InquiryModel::query();
$query->as(SummaryRow::class);
assertType('list<object>', $query->get());
assertType('object|null', $query->first());
assertType('list<object>', (new QueryBuilder(Connection::fromGlobal(), 'plain'))->get());

// 起点が変数のチェーンは、as() があっても絞り込まない(チェーンの途中のコールバックが同じビルダの as() を書き換えうる)
$query = InquiryModel::query();
$row = $query->as(InquiryRow::class)
    ->where(static function (QueryBuilder $where) use ($query): void {
        $query->as(SummaryRow::class);
        $where->where('id', 7);
    })
    ->first();
assertType('object|null', $row);
assertType('list<object>', $query->as(SummaryRow::class)->get());

// 起点がメソッドの戻り値(Model::query() 以外)のチェーンも絞り込まない
function builder(): QueryBuilder
{
    return PlainModel::query();
}

assertType('object|null', builder()->as(InquiryRow::class)->first());

// new QueryBuilder(...) は新しく作られたビルダのため、as() で絞り込む
assertType('list<TMSWP\Core\Tests\Fixtures\PHPStan\Model\InquiryRow>', (new QueryBuilder(Connection::fromGlobal(), 'plain'))->where('id', 1)->as(InquiryRow::class)->get());
