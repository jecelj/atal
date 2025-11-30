<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\NewsResource;
use App\Models\News;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class RecentNews extends BaseWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                NewsResource::getEloquentQuery()
                    ->latest()
                    ->limit(5)
            )
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->label('Created'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->url(fn(News $record): string => NewsResource::getUrl('edit', ['record' => $record])),
            ]);
    }
}
