<?php

namespace App\Modules\Settings\Services\Import;

use App\Modules\Staff\Models\User;
use App\Support\Errors\UserFacingException;

/** Picks the importer for a type name. */
final class Importers
{
    public static function preview(string $type, string $path): ImportResult
    {
        return match ($type) {
            'menu' => app(MenuImporter::class)->preview($path),
            'ingredients' => app(IngredientImporter::class)->preview($path),
            'staff' => app(StaffImporter::class)->preview($path),
            default => throw new UserFacingException(__('settings::settings.import.errors.bad_type', ['types' => implode(', ', ImportTemplates::TYPES)])),
        };
    }

    public static function import(string $type, string $path, ?User $by = null): ImportResult
    {
        return match ($type) {
            'menu' => app(MenuImporter::class)->import($path),
            'ingredients' => app(IngredientImporter::class)->import($path, $by),
            'staff' => app(StaffImporter::class)->import($path),
            default => throw new UserFacingException(__('settings::settings.import.errors.bad_type', ['types' => implode(', ', ImportTemplates::TYPES)])),
        };
    }
}
