<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\NewYachtResource;
use App\Models\NewYacht;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class RecentNewYachts extends BaseWidget
{
    protected static ?int $sort = 3;

    public function table(Table $table): Table
    {
        return $table
            ->query(
                NewYachtResource::getEloquentQuery()
                    ->latest()
                    ->limit(5)
            )
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('yachtModel.name')
                    ->label('Model')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->label('Created'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->url(fn(NewYacht $record): string => NewYachtResource::getUrl('edit', ['record' => $record])),
            ]);
    }
}
