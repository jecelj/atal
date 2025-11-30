<?php

namespace App\Filament\Widgets;

use App\Models\NewYacht;
use App\Models\News;
use App\Models\SyncSite;
use App\Models\UsedYacht;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        return [
            Stat::make('New Yachts', NewYacht::count())
                ->description('Total new yachts in database')
                ->descriptionIcon('heroicon-m-trophy')
                ->color('primary'),

            Stat::make('Used Yachts', UsedYacht::count())
                ->description('Total used yachts in database')
                ->descriptionIcon('heroicon-m-lifebuoy')
                ->color('warning'),

            Stat::make('News Articles', News::count())
                ->description('Total news articles')
                ->descriptionIcon('heroicon-m-newspaper')
                ->color('success'),

            Stat::make('Sync Sites', SyncSite::count())
                ->description('Connected WordPress sites')
                ->descriptionIcon('heroicon-m-globe-alt')
                ->color('info'),
        ];
    }
}
