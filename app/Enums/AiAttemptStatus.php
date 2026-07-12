<?php

namespace App\Enums;

enum AiAttemptStatus: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
