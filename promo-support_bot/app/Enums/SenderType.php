<?php

namespace App\Enums;

enum SenderType: string
{
    case User = 'user';
    case Bot = 'bot';
    case Operator = 'operator';
}
