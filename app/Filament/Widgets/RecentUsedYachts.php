<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\UsedYachtResource;
use App\Models\UsedYacht;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class RecentUsedYachts extends BaseWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                UsedYachtResource::getEloquentQuery()
                    ->latest()
                    ->limit(5)
            )
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('brand.name')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->date('d.m.Y')
                    ->sortable()
                    ->label('Created'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->url(fn(UsedYacht $record): string => UsedYachtResource::getUrl('edit', ['record' => $record])),
            ]);
    }
}
