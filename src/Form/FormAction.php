<?php

declare(strict_types=1);

namespace TMSWP\Core\Form;

enum FormAction: string
{
    case Confirm = 'confirm';
    case Submit = 'submit';
    case Back = 'back';
}
