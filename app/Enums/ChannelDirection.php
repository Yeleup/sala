<?php

namespace App\Enums;

enum ChannelDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';
}
