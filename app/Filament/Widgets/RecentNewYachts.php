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

    protected int|string|array $columnSpan = 'full';

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
                Tables\Columns\TextColumn::make('brand.name')
                    ->sortable(),
                Tables\Columns\TextColumn::make('yachtModel.name')
                    ->label('Model')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->date('d.m.Y')
                    ->sortable()
                    ->label('Created'),
                Tables\Columns\ToggleColumn::make('state')
                    ->onColor('success')
                    ->offColor('danger')
                    ->onIcon('heroicon-m-check')
                    ->offIcon('heroicon-m-x-mark')
                    ->state(fn($record) => $record->state === 'published')
                    ->afterStateUpdated(function ($record, $state) {
                        $record->update([
                            'state' => $state ? 'published' : 'draft'
                        ]);
                    })
                    ->label('Published'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->url(fn(NewYacht $record): string => NewYachtResource::getUrl('edit', ['record' => $record])),
            ]);
    }
}
