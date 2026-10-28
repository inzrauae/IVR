<?php

namespace App\Http\Controllers\Api;

use App\Authorization\Permission;
use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Models\Contact;
use App\Models\Extension;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Public REST API v1. Authenticated with personal access tokens (Settings → Security); every
 * request runs as the token's user, inside their organization, with their permissions.
 */
class ApiController extends Controller
{
    public function calls(Request $request): JsonResponse
    {
        Gate::authorize(Permission::CALL_HISTORY_VIEW);

        $validated = $request->validate([
            'direction' => ['nullable', Rule::in([Call::DIRECTION_INBOUND, Call::DIRECTION_OUTBOUND, Call::DIRECTION_INTERNAL])],
            'status' => ['nullable', 'string', 'max:20'],
            'since' => ['nullable', 'date'],
            'number' => ['nullable', 'string', 'max:20'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $calls = Call::visibleTo($request->user())
            ->with(['fromExtension:id,number', 'toExtension:id,number'])
            ->when($validated['direction'] ?? null, fn ($q, $d) => $q->where('direction', $d))
            ->when($validated['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($validated['since'] ?? null, fn ($q, $s) => $q->where('started_at', '>=', $s))
            ->when($validated['number'] ?? null, fn ($q, $n) => $q->where(fn ($q) => $q->where('from_number', $n)->orWhere('to_number', $n)))
            ->latest('started_at')
            ->paginate($validated['per_page'] ?? 25);

        return response()->json([
            'data' => $calls->getCollection()->map->toPayload(),
            'meta' => ['current_page' => $calls->currentPage(), 'last_page' => $calls->lastPage(), 'total' => $calls->total()],
        ]);
    }

    public function call(Request $request, int $call): JsonResponse
    {
        Gate::authorize(Permission::CALL_HISTORY_VIEW);

        return response()->json(['data' => Call::visibleTo($request->user())->findOrFail($call)->toPayload()]);
    }

    public function extensions(): JsonResponse
    {
        Gate::authorize(Permission::EXTENSIONS_VIEW);

        return response()->json(['data' => Extension::orderBy('number')->get()->map(fn (Extension $e) => [
            'id' => $e->id,
            'number' => $e->number,
            'name' => $e->name,
            'status' => $e->status,
            'registered' => $e->registered,
            'do_not_disturb' => $e->do_not_disturb,
            'department_id' => $e->department_id,
        ])]);
    }

    public function contacts(Request $request): JsonResponse
    {
        Gate::authorize(Permission::CONTACTS_VIEW);

        $search = (string) $request->query('search', '');
        $contacts = Contact::when($search !== '', fn ($q) => $q->where(fn ($q) => $q
            ->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")->orWhere('mobile', 'like', "%{$search}%")))
            ->orderBy('name')
            ->paginate(min(100, max(1, (int) $request->query('per_page', 50))));

        return response()->json([
            'data' => $contacts->getCollection()->map(fn (Contact $c) => $c->only(['id', 'name', 'company', 'phone', 'mobile', 'email', 'notes'])),
            'meta' => ['current_page' => $contacts->currentPage(), 'last_page' => $contacts->lastPage(), 'total' => $contacts->total()],
        ]);
    }

    public function storeContact(Request $request): JsonResponse
    {
        Gate::authorize(Permission::CONTACTS_MANAGE);

        $request->merge([
            'phone' => Contact::normalizePhone($request->input('phone')),
            'mobile' => Contact::normalizePhone($request->input('mobile')),
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'regex:/^\+?\d{3,20}$/'],
            'mobile' => ['nullable', 'string', 'regex:/^\+?\d{3,20}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $contact = Contact::create($validated);

        return response()->json(['data' => $contact->only(['id', 'name', 'company', 'phone', 'mobile', 'email', 'notes'])], 201);
    }

    public function destroyContact(int $contact): JsonResponse
    {
        Gate::authorize(Permission::CONTACTS_MANAGE);

        Contact::findOrFail($contact)->delete();

        return response()->json(null, 204);
    }
}
