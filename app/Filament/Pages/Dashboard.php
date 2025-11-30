<?php

namespace App\Filament\Pages;

use App\Filament\Resources\NewYachtResource;
use App\Filament\Resources\NewsResource;
use Filament\Actions\Action;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected function getHeaderActions(): array
    {
        return [
            Action::make('addNewYacht')
                ->label('Add New Yacht')
                ->url(NewYachtResource::getUrl('create'))
                ->icon('heroicon-m-plus')
                ->color('primary'),

            Action::make('addNews')
                ->label('Add News')
                ->url(NewsResource::getUrl('create'))
                ->icon('heroicon-m-newspaper')
                ->color('success'),

            Action::make('syncAll')
                ->label('Sync All Sites')
                ->url(route('filament.admin.pages.sync-all'))
                ->icon('heroicon-m-arrow-path')
                ->color('warning'),
        ];
    }
}
