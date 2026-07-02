<?php

namespace VisioSoft\LaraAnsible\Helpers;

use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;

class TableHelper
{
    public static function configure(Table $table): Table
    {
        return $table->actionsPosition(RecordActionsPosition::BeforeColumns);
    }

    /**
     * @param  array<int, mixed>  $extra  extra bulk actions to prepend
     * @return array<int, BulkActionGroup>
     */
    public static function bulkActions(array $extra = []): array
    {
        return [
            BulkActionGroup::make([
                ...$extra,
                DeleteBulkAction::make(),
            ]),
        ];
    }

    /**
     * @param  array<int, mixed>  $actions
     * @return array<int, ActionGroup>
     */
    public static function actionGroup(array $actions): array
    {
        return [
            ActionGroup::make($actions)
                ->label(__('laraansible::laraansible.actions'))
                ->icon('heroicon-m-ellipsis-vertical')
                ->color('primary')
                ->button(),
        ];
    }
}
