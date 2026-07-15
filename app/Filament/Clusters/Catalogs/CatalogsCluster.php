<?php

namespace App\Filament\Clusters\Catalogs;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Support\Icons\Heroicon;

class CatalogsCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $navigationLabel = 'Справочники';

    protected static ?string $clusterBreadcrumb = 'Справочники';

    protected static ?int $navigationSort = 4;
}
