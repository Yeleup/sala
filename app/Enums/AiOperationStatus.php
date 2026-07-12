<?php

namespace App\Enums;

enum AiOperationStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
