<?php

namespace App\Http\Controllers;

use App\Authorization\Permission;
use App\Models\Department;
use App\Models\Extension;
use App\Support\Audit;
use App\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DepartmentController extends Controller
{
    public function __construct(private readonly Tenant $tenant) {}

    public function index(Request $request): Response
    {
        Gate::authorize(Permission::DEPARTMENTS_VIEW);

        return Inertia::render('departments/Index', [
            'departments' => Department::withCount('extensions')
                ->with(['extensions' => fn ($q) => $q->orderBy('number')->select(['id', 'department_id', 'number', 'name', 'registered', 'status'])])
                ->orderBy('name')
                ->get(['id', 'name', 'description']),
            'extensions' => Extension::orderBy('number')->get(['id', 'number', 'name', 'department_id']),
            'can' => ['manage' => $request->user()->can(Permission::DEPARTMENTS_MANAGE)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::DEPARTMENTS_MANAGE);

        $validated = $this->validated($request);
        $department = DB::transaction(function () use ($validated) {
            $department = Department::create(['name' => $validated['name'], 'description' => $validated['description'] ?? null]);
            $this->assignMembers($department, $validated['extension_ids'] ?? []);

            return $department;
        });

        Audit::record('department.created', $department, ['name' => $department->name]);

        return back()->with('success', "{$department->name} created.");
    }

    public function update(Request $request, int $department): RedirectResponse
    {
        Gate::authorize(Permission::DEPARTMENTS_MANAGE);

        $record = Department::findOrFail($department);
        $validated = $this->validated($request, $record);

        DB::transaction(function () use ($record, $validated) {
            $record->update(['name' => $validated['name'], 'description' => $validated['description'] ?? null]);
            $this->assignMembers($record, $validated['extension_ids'] ?? []);
        });

        Audit::record('department.updated', $record, ['name' => $record->name]);

        return back()->with('success', "{$record->name} saved.");
    }

    public function destroy(int $department): RedirectResponse
    {
        Gate::authorize(Permission::DEPARTMENTS_MANAGE);

        $record = Department::findOrFail($department);
        Audit::record('department.deleted', $record, ['name' => $record->name]);
        $record->delete();

        return back()->with('success', "{$record->name} deleted. Its extensions are now unassigned.");
    }

    /**
     * @return array{name: string, description?: string|null, extension_ids?: list<int>}
     */
    private function validated(Request $request, ?Department $current = null): array
    {
        $organizationId = $this->tenant->id();

        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique(Department::class)->where('organization_id', $organizationId)->ignore($current?->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'extension_ids' => ['array'],
            'extension_ids.*' => ['integer', 'distinct', Rule::exists(Extension::class, 'id')->where('organization_id', $organizationId)],
        ]);
    }

    /**
     * Make exactly these extensions the department's members. An extension belongs to one department.
     *
     * @param  list<int>  $extensionIds
     */
    private function assignMembers(Department $department, array $extensionIds): void
    {
        Extension::where('department_id', $department->id)->whereNotIn('id', $extensionIds)->update(['department_id' => null]);
        Extension::whereIn('id', $extensionIds)->update(['department_id' => $department->id]);
    }
}
