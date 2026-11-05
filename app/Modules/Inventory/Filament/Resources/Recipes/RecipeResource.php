<?php

namespace App\Modules\Inventory\Filament\Resources\Recipes;

use App\Modules\Inventory\Enums\Unit;
use App\Modules\Inventory\Filament\Resources\Recipes\Pages\EditRecipe;
use App\Modules\Inventory\Filament\Resources\Recipes\Pages\ListRecipes;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\RecipeLine;
use App\Modules\Inventory\Services\RecipeService;
use App\Modules\Menu\Models\Item;
use App\Modules\Staff\Enums\Permission;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Back office → Stock → Recipes (Complete Edition E5.1). The menu belongs to the whole restaurant; the recipe
 * lines shown and saved here are THIS branch's, because each branch counts its own ingredients. Only there
 * when "Recipes & food cost" is switched on in Settings → Features.
 */
class RecipeResource extends Resource
{
    protected static ?string $model = Item::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static ?int $navigationSort = 42;

    protected static ?string $slug = 'recipes';

    public static function canAccess(): bool
    {
        return (auth()->user()?->can(Permission::InventoryManage->value) ?? false) && app(RecipeService::class)->enabled();
    }

    public static function canCreate(): bool
    {
        return false; // dishes are added on the Menu pages; here they only get a recipe
    }

    public static function getNavigationGroup(): ?string
    {
        return __('inventory::inventory.navigation_group');
    }

    public static function getModelLabel(): string
    {
        return __('inventory::inventory.recipes.recipe');
    }

    public static function getPluralModelLabel(): string
    {
        return __('inventory::inventory.recipes.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('inventory::inventory.recipes.title');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('inventory::inventory.recipes.lines'))->description(__('inventory::inventory.recipes.lines_help'))->columnSpanFull()->schema([
                Repeater::make('recipeLines')->label('')
                    ->relationship('recipeLines', modifyQueryUsing: fn (Builder $query) => $query->forBranch())
                    ->orderColumn('sort_order')->defaultItems(0)->columns(12)
                    ->addActionLabel(__('inventory::inventory.recipes.add_line'))
                    ->schema(self::lineFields(withSizes: true)),
            ]),
            Section::make(__('inventory::inventory.recipes.portion_cost'))->columnSpanFull()->schema([
                Placeholder::make('portion_cost')->hiddenLabel()->content(fn (?Item $record) => $record ? self::costSummary($record) : '—'),
            ]),
        ]);
    }

    /**
     * The boxes of one recipe line: ingredient, amount, unit (only ones that convert), size (dishes only), cost.
     * Shared with the recipes for extras.
     *
     * @return array<int, mixed>
     */
    public static function lineFields(bool $withSizes): array
    {
        $f = fn (string $k) => __('inventory::inventory.recipes.fields.'.$k);
        $ingredient = fn (?string $id) => $id ? Ingredient::query()->forBranch()->find($id) : null;

        return array_values(array_filter([
            Select::make('ingredient_id')->label($f('ingredient'))->columnSpan(4)->required()->searchable()->live()
                ->options(fn () => Ingredient::query()->forBranch()->active()->orderBy('name')->get()
                    ->mapWithKeys(fn (Ingredient $i) => [$i->id => $i->name.' ('.$i->unit->short().')'])->all())
                ->afterStateUpdated(fn (Set $set, $state) => $set('unit', $ingredient($state)?->unit->value)),
            TextInput::make('quantity')->label($f('quantity'))->columnSpan(2)->numeric()->required()->live(onBlur: true)
                ->minValue(0.0001)->step('any')
                ->validationMessages(['min' => __('inventory::inventory.recipes.errors.quantity')]),
            Select::make('unit')->label($f('unit'))->columnSpan(2)->required()->live()
                ->options(function (Get $get) use ($ingredient) {
                    $units = $ingredient($get('ingredient_id'))?->unit->compatible() ?? [];

                    return collect($units)->mapWithKeys(fn (Unit $u) => [$u->value => $u->short()])->all();
                })
                ->rule(fn (Get $get) => function ($attribute, $value, $fail) use ($get, $ingredient) {
                    $base = $ingredient($get('ingredient_id'))?->unit;
                    if ($base && (! Unit::tryFrom((string) $value) || Unit::from($value)->convert(1, $base) === null)) {
                        $fail(__('inventory::inventory.recipes.errors.unit', ['unit' => $base->short(), 'units' => collect($base->compatible())->map->short()->implode(' / ')]));
                    }
                }),
            $withSizes ? Select::make('variant_id')->label($f('variant'))->columnSpan(2)->placeholder($f('every_size'))->nullable()
                // Inside a line, "record" is the line: the dish is the page's record.
                ->options(fn ($livewire) => self::dish($livewire)?->variants()->pluck('name', 'id')->all() ?? [])
                ->visible(fn ($livewire) => self::dish($livewire)?->variants()->exists() ?? false) : null,
            Placeholder::make('cost')->label($f('cost'))->columnSpan(2)
                ->content(function (Get $get) use ($ingredient) {
                    $i = $ingredient($get('ingredient_id'));
                    $unit = Unit::tryFrom((string) $get('unit'));
                    if (! $i || ! $unit || ! is_numeric($get('quantity'))) {
                        return '—';
                    }
                    $line = new RecipeLine(['quantity' => (float) $get('quantity'), 'unit' => $unit]);
                    $line->setRelation('ingredient', $i);

                    return money($line->cost());
                }),
        ]));
    }

    private static function dish(mixed $livewire): ?Item
    {
        $record = method_exists($livewire, 'getRecord') ? $livewire->getRecord() : null;

        return $record instanceof Item ? $record : null;
    }

    /** "GH₵ 6.40" or "Regular: GH₵ 6.40 · Large: GH₵ 8.60", from the saved recipe. */
    public static function costSummary(Item $item): string
    {
        $recipes = app(RecipeService::class);
        $variants = $item->variants()->get();
        if ($variants->isEmpty()) {
            return money($recipes->portionCost($item));
        }

        return $variants->map(fn ($v) => __('inventory::inventory.recipes.portion_cost_size', ['size' => $v->name, 'cost' => money($recipes->portionCost($item, $v->id))]))->implode(' · ');
    }

    public static function table(Table $table): Table
    {
        $c = fn (string $k) => __('inventory::inventory.recipes.columns.'.$k);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['category', 'variants'])->withCount(['recipeLines' => fn (Builder $l) => $l->forBranch()]))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label($c('dish'))->searchable()->sortable(),
                TextColumn::make('category.name')->label($c('category'))->sortable(),
                TextColumn::make('recipe_lines_count')->label($c('ingredients'))->badge()
                    ->color(fn ($state) => $state ? 'success' : 'gray')
                    ->formatStateUsing(fn ($state) => $state ?: __('inventory::inventory.recipes.no_recipe')),
                TextColumn::make('portion_cost')->label($c('cost'))->state(fn (Item $record) => $record->recipe_lines_count ? self::costSummary($record) : '—')->wrap(),
            ])
            ->filters([
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
            'index' => ListRecipes::route('/'),
            'edit' => EditRecipe::route('/{record}/edit'),
        ];
    }
}
