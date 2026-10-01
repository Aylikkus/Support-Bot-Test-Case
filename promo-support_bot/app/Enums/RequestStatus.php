<?php

namespace App\Enums;

enum RequestStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
