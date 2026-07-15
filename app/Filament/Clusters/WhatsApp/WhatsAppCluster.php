<?php

namespace App\Filament\Clusters\WhatsApp;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Support\Icons\Heroicon;

class WhatsAppCluster extends Cluster
{
    protected static ?string $slug = 'whatsapp';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $navigationLabel = 'WhatsApp';

    protected static ?string $clusterBreadcrumb = 'WhatsApp';

    protected static ?int $navigationSort = 2;
}
