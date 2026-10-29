<?php

declare(strict_types=1);

namespace TMSWP\Core\Form;

enum FormStep: string
{
    case Input = 'input';
    case Confirm = 'confirm';
    case Complete = 'complete';
}
