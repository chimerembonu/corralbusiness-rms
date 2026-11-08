<?php

namespace App\Modules\Settings\Http;

use App\Modules\Settings\Services\Import\ImportTemplates;
use App\Modules\Settings\Services\SetupService;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** The menu / staff spreadsheet templates for the setup wizard; only while the server is not set up yet. */
class SetupTemplateController
{
    public function __invoke(string $type, SetupService $setup): BinaryFileResponse
    {
        abort_unless($setup->isOpen() && in_array($type, ['menu', 'staff'], true), 404);

        return Excel::download(ImportTemplates::export($type), ImportTemplates::fileName($type));
    }
}
