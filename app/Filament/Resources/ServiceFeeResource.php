<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ServiceFeeResource\Pages;
use App\Models\ServiceFee;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Filters\SelectFilter;

class ServiceFeeResource extends Resource
{
    protected static ?string $model = ServiceFee::class;

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->columnSpan('full'),
                Forms\Components\Textarea::make('description')
                    ->columnSpan('full'),
                Forms\Components\Select::make('platform')
                    ->options([
                        'shopee' => 'Shopee',
                        'tiktok' => 'TikTok',
                        'tokopedia' => 'Tokopedia',
                    ])
                    ->required(),
                Forms\Components\Select::make('seller_type')
                    ->options([
                        'all' => 'Semua Tipe',
                        'non_star' => 'Non-Star & Star Seller',
                        'mall' => 'Mall',
                    ])
                    ->required(),
                Forms\Components\Select::make('fee_type')
                    ->options([
                        'admin_fee' => 'Biaya Admin (Kategori)',
                        'program_fee' => 'Biaya Program Layanan',
                    ])
                    ->required(),
                Forms\Components\TextInput::make('value')
                    ->required()
                    ->numeric()
                    ->prefix('%'),
                Forms\Components\TextInput::make('max_cap')
                    ->label('Max Cap (Rp)')
                    ->numeric()
                    ->prefix('Rp'),
                Forms\Components\Toggle::make('is_active')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('platform')->badge(),
                Tables\Columns\TextColumn::make('seller_type'),
                Tables\Columns\TextColumn::make('fee_type'),
                Tables\Columns\TextColumn::make('value')->suffix('%')->sortable(),
                Tables\Columns\TextColumn::make('max_cap')->money('IDR'),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
            ])
            ->filters([
                SelectFilter::make('platform')
                    ->options([
                        'shopee' => 'Shopee',
                        'tiktok' => 'TikTok',
                        'tokopedia' => 'Tokopedia',
                    ]),
                SelectFilter::make('seller_type')
                    ->options([
                        'non_star' => 'Non-Star & Star Seller',
                        'mall' => 'Mall',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
    
    public static function getRelations(): array
    {
        return [
            //
        ];
    }
    
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListServiceFees::route('/'),
            'create' => Pages\CreateServiceFee::route('/create'),
            'edit' => Pages\EditServiceFee::route('/{record}/edit'),
        ];
    }    
}