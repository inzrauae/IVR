<?php

namespace App\Http\Controllers;

use App\Authorization\Permission;
use App\Models\Call;
use App\Models\Contact;
use App\Support\Audit;
use App\Support\CallRows;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContactController extends Controller
{
    private const CSV_COLUMNS = ['name', 'company', 'phone', 'mobile', 'email', 'notes'];

    public function index(Request $request): Response
    {
        Gate::authorize(Permission::CONTACTS_VIEW);

        $search = $request->string('search')->trim()->limit(100, '')->value();

        $contacts = Contact::query()
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('company', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', '%'.(Contact::normalizePhone($search) ?? $search).'%')
                ->orWhere('mobile', 'like', '%'.(Contact::normalizePhone($search) ?? $search).'%')))
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('contacts/Index', [
            'contacts' => $contacts,
            'filters' => ['search' => $search],
            'can' => [
                'manage' => $request->user()->can(Permission::CONTACTS_MANAGE),
                'call' => $request->user()->can(Permission::WEBPHONE_USE),
                'history' => $request->user()->can(Permission::CALL_HISTORY_VIEW),
            ],
        ]);
    }

    /**
     * Recent calls with a contact, for the detail drawer.
     */
    public function calls(Request $request, int $contact): array
    {
        Gate::authorize(Permission::CALL_HISTORY_VIEW);

        $record = Contact::findOrFail($contact);
        $numbers = array_filter([$record->phone, $record->mobile]);

        return CallRows::map(
            Call::visibleTo($request->user())
                ->where(fn ($q) => $q->whereIn('from_number', $numbers)->orWhereIn('to_number', $numbers))
                ->with(['fromExtension:id,name,number', 'toExtension:id,name,number'])
                ->latest('started_at')->limit(20)->get()
        );
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::CONTACTS_MANAGE);

        $contact = Contact::create($this->validated($request));
        Audit::record('contact.created', $contact, ['name' => $contact->name]);

        return back()->with('success', "{$contact->name} added.");
    }

    public function update(Request $request, int $contact): RedirectResponse
    {
        Gate::authorize(Permission::CONTACTS_MANAGE);

        $record = Contact::findOrFail($contact);
        $record->update($this->validated($request));

        return back()->with('success', "{$record->name} saved.");
    }

    public function destroy(int $contact): RedirectResponse
    {
        Gate::authorize(Permission::CONTACTS_MANAGE);

        $record = Contact::findOrFail($contact);
        Audit::record('contact.deleted', $record, ['name' => $record->name]);
        $record->delete();

        return back()->with('success', "{$record->name} deleted.");
    }

    public function export(): StreamedResponse
    {
        Gate::authorize(Permission::CONTACTS_VIEW);

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fputcsv($out, self::CSV_COLUMNS);
            Contact::orderBy('name')->chunk(500, function ($contacts) use ($out) {
                foreach ($contacts as $contact) {
                    fputcsv($out, array_map(fn ($c) => $contact->{$c}, self::CSV_COLUMNS));
                }
            });
            fclose($out);
        }, 'contacts-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * CSV with a header row; columns are matched by name (name is required). Existing contacts
     * with the same phone number are updated instead of duplicated.
     */
    public function import(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::CONTACTS_MANAGE);

        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = array_map(fn ($h) => strtolower(trim((string) $h, " \t\n\r\0\x0B\xEF\xBB\xBF")), fgetcsv($handle) ?: []);

        if (! in_array('name', $header, true)) {
            throw ValidationException::withMessages(['file' => 'The first row must be a header that includes a "name" column.']);
        }

        $created = $updated = $skipped = 0;

        DB::transaction(function () use ($handle, $header, &$created, &$updated, &$skipped) {
            while (($row = fgetcsv($handle)) !== false && $created + $updated + $skipped < 5000) {
                $data = collect($header)->combine(array_pad(array_slice($row, 0, count($header)), count($header), null))
                    ->only(self::CSV_COLUMNS)->map(fn ($v) => is_string($v) && trim($v) !== '' ? trim($v) : null);

                $data['phone'] = Contact::normalizePhone($data['phone'] ?? null);
                $data['mobile'] = Contact::normalizePhone($data['mobile'] ?? null);

                if (! $data['name'] || ($data['email'] && ! filter_var($data['email'], FILTER_VALIDATE_EMAIL))) {
                    $skipped++;

                    continue;
                }

                $existing = $data['phone'] ? Contact::where('phone', $data['phone'])->first() : null;
                $existing ? $existing->update($data->all()) : Contact::create($data->all());
                $existing ? $updated++ : $created++;
            }
        });

        fclose($handle);
        Audit::record('contact.imported', null, compact('created', 'updated', 'skipped'));

        return back()->with('success', "Imported: {$created} new, {$updated} updated".($skipped ? ", {$skipped} skipped (missing name or invalid email)" : '').'.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $request->merge([
            'phone' => Contact::normalizePhone($request->input('phone')),
            'mobile' => Contact::normalizePhone($request->input('mobile')),
        ]);

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'regex:/^\+?\d{3,20}$/'],
            'mobile' => ['nullable', 'string', 'regex:/^\+?\d{3,20}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
