<?php

namespace App\Filament\Clusters\Marketplace;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Support\Icons\Heroicon;

class MarketplaceCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $navigationLabel = 'Маркетплейс';

    protected static ?string $clusterBreadcrumb = 'Маркетплейс';

    protected static ?int $navigationSort = 1;
}
