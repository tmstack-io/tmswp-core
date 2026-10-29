<?php

declare(strict_types=1);

use PHPStan\Testing\PHPStanTestCase;

require dirname(__DIR__) . '/vendor/autoload.php';

/*
 * Brain Monkeyが読み込むPatchworkは、phar://のストリームを自前のラッパーに差し替える。PHPStan 2.3のpharは、
 * 内部のクラスを最初に読み込むときに、同じファイルを`vendor/composer/../`を含むパスと含まないパスの両方から
 * 読み込む。差し替えた後ではPHPが同じファイルと判定できず、PHPStanの拡張のテストがクラスの再宣言で止まる。
 * Patchworkが差し替える前に、PHPStanのテストのコンテナを作り、pharの読み込みを済ませておく。
 */
PHPStanTestCase::getContainer();
