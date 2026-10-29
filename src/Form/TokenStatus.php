<?php

declare(strict_types=1);

namespace TMSWP\Core\Form;

/**
 * 署名付きトークン(ConfirmToken・SubmitToken)の検証の結果。
 */
enum TokenStatus
{
    /** 署名が正しく、期限内 */
    case Valid;

    /** 署名は正しいが期限切れ。ConfirmToken の値は信頼できるので、再表示に使ってよい */
    case Expired;

    /** 形式の不正・署名の不一致・formId の不一致。値は一切信頼しない */
    case Invalid;
}
