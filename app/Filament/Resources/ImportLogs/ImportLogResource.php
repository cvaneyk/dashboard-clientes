<?php

namespace App\Filament\Resources\ImportLogs;

use App\Filament\Resources\ImportLogs\Pages\CreateImportLog;
use App\Filament\Resources\ImportLogs\Pages\EditImportLog;
use App\Filament\Resources\ImportLogs\Pages\ListImportLogs;
use App\Filament\Resources\ImportLogs\Schemas\ImportLogForm;
use App\Filament\Resources\ImportLogs\Tables\ImportLogsTable;
use App\Models\ImportLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ImportLogResource extends Resource
{
    protected static ?string $model = ImportLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'ImportLog';

    public static function form(Schema $schema): Schema
    {
        return ImportLogForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ImportLogsTable::configure($table);
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
            'index' => ListImportLogs::route('/'),
            'create' => CreateImportLog::route('/create'),
            'edit' => EditImportLog::route('/{record}/edit'),
        ];
    }
}
