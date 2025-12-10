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
                \Filament\Tables\Columns\SpatieMediaLibraryImageColumn::make('featured_image')
                    ->collection('featured_image')
                    ->label('')
                    ->height(40),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('brand.name')
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
                    ->url(fn(UsedYacht $record): string => UsedYachtResource::getUrl('edit', ['record' => $record])),
            ]);
    }
}
