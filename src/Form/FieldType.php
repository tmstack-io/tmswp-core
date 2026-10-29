<?php

declare(strict_types=1);

namespace TMSWP\Core\Form;

enum FieldType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Password = 'password';
    case Hidden = 'hidden';
    case Select = 'select';
    case Radios = 'radios';
    case Checkboxes = 'checkboxes';
    case Checkbox = 'checkbox';
    case File = 'file';
}
