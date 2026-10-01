<?php

namespace App\Enums;

enum AnswerStatus: string
{
    case Ok = 'ok';
    case Partial = 'partial';
    case Error = 'error';
}
