<?php

declare(strict_types=1);

namespace TMSWP\Core\Form;

/**
 * JtiStore::claim() の結果。
 */
enum JtiClaim
{
    /** 初めての送信。jti を獲得した */
    case Claimed;

    /** 獲得済みの jti。再送(リロード・二重クリック・並行した二重の POST) */
    case Duplicate;
}
