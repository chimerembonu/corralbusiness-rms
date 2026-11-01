<?php

namespace App\Modules\Settings\Http;

use App\Modules\Settings\Services\BackupService;
use App\Modules\Staff\Enums\Permission;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Download a backup zip (Back office → Backups → Download). */
class BackupDownloadController
{
    public function __invoke(Request $request, BackupService $backups): BinaryFileResponse
    {
        abort_unless($request->user()->can(Permission::SettingsManage->value), 403);

        try {
            $path = $backups->resolve((string) $request->query('file'));
        } catch (\RuntimeException) {
            abort(404);
        }

        return response()->download($path);
    }
}
