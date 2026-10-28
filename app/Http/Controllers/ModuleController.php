<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Serves modules that are registered in config/modules.php but not yet built.
 */
class ModuleController extends Controller
{
    public function __invoke(Request $request, string $module): Response
    {
        $definition = collect(config('modules'))->firstWhere('key', $module);

        abort_unless($definition, 404);
        abort_unless($request->user()->can($definition['permission']), 403);

        return Inertia::render('modules/Unavailable', [
            'module' => [
                'title' => $definition['title'],
                'icon' => $definition['icon'],
                'phase' => $definition['phase'] ?? null,
            ],
        ]);
    }
}
