<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Fixtures\Model;

/*
 * QueryBuilderAcceptanceTestの、後ろの引数の型(LateEnum)がテストの途中まで定義されない行クラス。LateEnumは
 * late-enum.phpに置き、テストの途中で読み込む。ファイル名がPSR-4のパスに合わないため、どちらもautoloadでは読み込まれない。
 * 定義されていないクラスを型に使うため、PHPStanの解析対象の外(tests/fixtures)に置く。
 */
final readonly class LateEnumRow
{
    public function __construct(
        public int $id,
        public ?LateEnum $state = null,
    ) {}
}
