<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Support;

/**
 * TableDefinition::rawColumn()が受け付ける型の定義(06-model §2)。ユニットテスト(宣言の時点で受け付けること)と
 * 統合テスト(実物のDBで作成と再適用が差分なく収束すること)が同じ定義を使う。型の別名や収束しない表記を拒否したときに
 * 案内する型(int(11)・tinyint(4)・decimal・double・float・char・varchar・mediumtext・longtext等)を含む。
 */
final class RawColumnTypes
{
    /** @var array<string, string> 説明 => 型以降の定義。AUTO_INCREMENTの列を含まない(1つのテーブルにまとめて作れる) */
    public const array ACCEPTED = [
        'int' => 'int(11) NOT NULL',
        'decimal' => 'decimal(10,2) NOT NULL',
        'double' => 'double NOT NULL',
        'tinyint(1)' => 'tinyint(1) NOT NULL',
        'char' => 'char(5) NOT NULL',
        'longtext(別名の long で始まる型名)' => 'longtext NULL',
        'double precision(DB は double と記録し、dbDelta も先頭の語だけを比べる)' => 'double precision NOT NULL',
        'decimal unsigned(別名の dec で始まる型名)' => 'decimal(12,4) unsigned NOT NULL',
        'timestamp' => 'timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'float(10,2)(精度と桁の2つの引数)' => 'float(10,2) NOT NULL',
        'int(10) unsigned' => 'int(10) unsigned NOT NULL',
        'tinyint(4)' => 'tinyint(4) NOT NULL',
        'smallint(6)' => 'smallint(6) NOT NULL',
        'mediumint(9)' => 'mediumint(9) NOT NULL',
        'bigint(20)' => 'bigint(20) NOT NULL',
        'float' => 'float NOT NULL',
        'varchar' => 'varchar(10) NOT NULL',
        'mediumtext' => 'mediumtext NULL',
    ];

    /** AUTO_INCREMENTの列の定義。主キーにする必要があり、id()と同じテーブルに置けないため分ける。 */
    public const string ACCEPTED_AUTO_INCREMENT = 'bigint(20) unsigned NOT NULL AUTO_INCREMENT';

    private function __construct() {}
}
