<?php

namespace App\Modules\Inventory\Filament\Resources\ExtraRecipes;

use App\Modules\Inventory\Filament\Resources\ExtraRecipes\Pages\EditExtraRecipe;
use App\Modules\Inventory\Filament\Resources\ExtraRecipes\Pages\ListExtraRecipes;
use App\Modules\Inventory\Filament\Resources\Recipes\RecipeResource;
use App\Modules\Inventory\Services\RecipeService;
use App\Modules\Menu\Models\Modifier;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Back office → Inventory → Recipes for extras (E5.1 follow-up): what one of an extra uses, e.g. Chicken:
 * 150 g chicken. Used once per extra chosen on a plate. The extras belong to the whole restaurant; the lines
 * shown and saved here are this branch's. Same rules as dish recipes (switch, permission).
 */
class ExtraRecipeResource extends Resource
{
    protected static ?string $model = Modifier::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPlusCircle;

    protected static ?int $navigationSort = 43;

    protected static ?string $slug = 'extra-recipes';

    public static function canAccess(): bool
    {
        return RecipeResource::canAccess();
    }

    public static function canCreate(): bool
    {
        return false; // extras are added on the Menu pages (modifier groups)
    }

    public static function getNavigationGroup(): ?string
    {
        return __('inventory::inventory.navigation_group');
    }

    public static function getModelLabel(): string
    {
        return __('inventory::inventory.recipes.extra');
    }

    public static function getPluralModelLabel(): string
    {
        return __('inventory::inventory.recipes.extras_title');
    }

    public static function getNavigationLabel(): string
    {
        return __('inventory::inventory.recipes.extras_title');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('inventory::inventory.recipes.extra_lines'))->description(__('inventory::inventory.recipes.extra_lines_help'))->columnSpanFull()->schema([
                Repeater::make('recipeLines')->label('')
                    ->relationship('recipeLines', modifyQueryUsing: fn (Builder $query) => $query->forBranch())
                    ->orderColumn('sort_order')->defaultItems(0)->columns(10)
                    ->addActionLabel(__('inventory::inventory.recipes.add_line'))
                    ->schema(RecipeResource::lineFields(withSizes: false)),
            ]),
            Section::make(__('inventory::inventory.recipes.extra_cost'))->columnSpanFull()->schema([
                Placeholder::make('extra_cost')->hiddenLabel()->content(fn (?Modifier $record) => $record ? money(app(RecipeService::class)->modifierCost($record)) : '—'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $c = fn (string $k) => __('inventory::inventory.recipes.columns.'.$k);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('group')->withCount(['recipeLines' => fn (Builder $l) => $l->forBranch()]))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label($c('extra'))->searchable()->sortable(),
                TextColumn::make('group.name')->label($c('group'))->sortable(),
                TextColumn::make('price')->label($c('price'))->formatStateUsing(fn ($state) => money($state))->alignRight(),
                TextColumn::make('recipe_lines_count')->label($c('ingredients'))->badge()
                    ->color(fn ($state) => $state ? 'success' : 'gray')
                    ->formatStateUsing(fn ($state) => $state ?: __('inventory::inventory.recipes.no_recipe')),
                TextColumn::make('extra_cost')->label($c('extra_cost'))->alignRight()
                    ->state(fn (Modifier $record) => $record->recipe_lines_count ? money(app(RecipeService::class)->modifierCost($record)) : '—'),
            ])
            ->filters([
                SelectFilter::make('modifier_group_id')->label($c('group'))->relationship('group', 'name'),
                TernaryFilter::make('has_recipe')->label(__('inventory::inventory.recipes.has_recipe'))
                    ->queries(
                        true: fn (Builder $query) => $query->whereHas('recipeLines', fn (Builder $l) => $l->forBranch()),
                        false: fn (Builder $query) => $query->whereDoesntHave('recipeLines', fn (Builder $l) => $l->forBranch()),
                    ),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExtraRecipes::route('/'),
            'edit' => EditExtraRecipe::route('/{record}/edit'),
        ];
    }
}
