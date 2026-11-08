<?php

namespace App\Support\Filament;

use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;

/**
 * Back-office list of records that belong to one branch (Complete Edition E3.1, security review D2).
 *
 * The back office (/admin) works in ONE branch: the till's, else the staff member's own, else the store
 * server's (SetTenantFromUser). Lists, record pages, search and record links only reach rows of that branch,
 * so a manager of one branch can never see or change another branch's tills, cash-ups or stock — opening
 * another branch's record by its address gives "not found". Across branches is head office (/hq).
 *
 * tests/Feature/BranchSeparationTest.php fails if a list of branch records does not extend this class.
 */
abstract class BranchResource extends Resource
{
    /** Also show rows that belong to the whole restaurant (branch_id empty), e.g. the owner on the staff list. */
    protected static bool $includeRestaurantWide = false;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        return static::$includeRestaurantWide ? $query->forBranchOrTenant() : $query->forBranch();
    }
}
