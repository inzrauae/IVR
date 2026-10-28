<?php

namespace App\Http\Controllers;

use App\Authorization\Permission;
use App\Jobs\PushRouting;
use App\Models\IvrMenu;
use App\Pbx\Routing\Destination;
use App\Pbx\Routing\NumberPlan;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * IVR menus are drafts until someone with ivr.publish puts them live; changing a live menu
 * also needs ivr.publish, so ivr.manage alone can never alter what callers hear.
 */
class IvrController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize(Permission::IVR_VIEW);

        return Inertia::render('ivr/Index', [
            'menus' => IvrMenu::orderBy('number')->get()->map(fn (IvrMenu $m) => [
                ...$m->only(['id', 'number', 'name', 'timeout', 'max_retries']),
                'options_count' => count($m->options ?? []),
                'has_greeting' => (bool) $m->greeting_path,
                'published_at' => $m->published_at?->toIso8601String(),
                'updated_at' => $m->updated_at->toIso8601String(),
            ]),
            'nextNumber' => NumberPlan::next(900),
            'can' => $this->abilities($request),
        ]);
    }

    public function edit(Request $request, int $ivr): Response
    {
        Gate::authorize(Permission::IVR_VIEW);

        $menu = IvrMenu::findOrFail($ivr);

        return Inertia::render('ivr/Edit', [
            'menu' => [
                ...$menu->only(['id', 'number', 'name', 'timeout', 'max_retries', 'options', 'timeout_destination', 'invalid_destination']),
                'has_greeting' => (bool) $menu->greeting_path,
                'greeting_version' => $menu->greeting_path ? md5($menu->greeting_path) : null,
                'published_at' => $menu->published_at?->toIso8601String(),
            ],
            'digits' => IvrMenu::DIGITS,
            'destinations' => array_values(array_filter(Destination::options(), fn ($o) => $o['value'] !== "ivr:{$menu->id}")),
            'can' => $this->abilities($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::IVR_MANAGE);

        $validated = $request->validate([
            'number' => NumberPlan::rules(),
            'name' => ['required', 'string', 'max:100'],
        ]);

        $menu = IvrMenu::create([...$validated, 'options' => [], 'timeout' => 5, 'max_retries' => 2]);
        Audit::record('ivr.created', $menu, ['name' => $menu->name]);

        return to_route('ivr.edit', ['ivr' => $menu->id])->with('success', "{$menu->name} created as a draft. Add a greeting and options, then publish it.");
    }

    public function update(Request $request, int $ivr): RedirectResponse
    {
        $menu = IvrMenu::findOrFail($ivr);
        Gate::authorize($menu->published_at ? Permission::IVR_PUBLISH : Permission::IVR_MANAGE);

        $validated = $request->validate([
            'number' => NumberPlan::rules($menu),
            'name' => ['required', 'string', 'max:100'],
            'timeout' => ['required', 'integer', 'between:1,30'],
            'max_retries' => ['required', 'integer', 'between:0,5'],
            'options' => ['array', 'max:12'],
            'options.*.digit' => ['required', 'distinct', Rule::in(IvrMenu::DIGITS)],
            'options.*.label' => ['nullable', 'string', 'max:60'],
            'options.*.destination' => ['required', 'string', Destination::rule()],
            'timeout_destination' => ['nullable', 'string', Destination::rule()],
            'invalid_destination' => ['nullable', 'string', Destination::rule()],
        ]);

        // A menu may not send callers straight back into itself.
        foreach (['timeout_destination', 'invalid_destination'] as $field) {
            if (($validated[$field] ?? null) === "ivr:{$menu->id}") {
                throw ValidationException::withMessages([$field => 'Choose a destination other than this menu.']);
            }
        }

        $validated['options'] = collect($validated['options'] ?? [])
            ->map(fn ($o) => ['digit' => (string) $o['digit'], 'label' => $o['label'] ?? null, 'destination' => $o['destination']])
            ->sortBy(fn ($o) => array_search($o['digit'], IvrMenu::DIGITS, true))
            ->values()->all();

        $menu->update($validated);
        $this->pushIfLive($menu);
        Audit::record('ivr.updated', $menu, ['name' => $menu->name]);

        return back()->with('success', $menu->published_at ? "{$menu->name} saved and updated on the PBX." : "{$menu->name} saved as a draft.");
    }

    public function publish(Request $request, int $ivr): RedirectResponse
    {
        Gate::authorize(Permission::IVR_PUBLISH);

        $menu = IvrMenu::findOrFail($ivr);
        $live = $request->boolean('live', true);

        if ($live && ! $menu->greeting_path && empty($menu->options)) {
            return back()->with('error', 'Add a greeting or at least one option before publishing.');
        }

        $menu->forceFill(['published_at' => $live ? now() : null])->save();
        PushRouting::forCurrentTenant();
        Audit::record($live ? 'ivr.published' : 'ivr.unpublished', $menu, ['name' => $menu->name]);

        return back()->with('success', $live ? "{$menu->name} is live. Dial {$menu->number} or point a number at it." : "{$menu->name} is offline and back to draft.");
    }

    public function destroy(int $ivr): RedirectResponse
    {
        $menu = IvrMenu::findOrFail($ivr);
        Gate::authorize($menu->published_at ? Permission::IVR_PUBLISH : Permission::IVR_MANAGE);

        Audit::record('ivr.deleted', $menu, ['name' => $menu->name]);
        $menu->delete();

        if ($menu->greeting_path) {
            Storage::disk('local')->delete($menu->greeting_path);
        }

        PushRouting::forCurrentTenant();

        return to_route('ivr.index')->with('success', "{$menu->name} deleted. Anything that pointed to it now hangs up.");
    }

    public function uploadGreeting(Request $request, int $ivr): RedirectResponse
    {
        $menu = IvrMenu::findOrFail($ivr);
        Gate::authorize($menu->published_at ? Permission::IVR_PUBLISH : Permission::IVR_MANAGE);

        $request->validate(['greeting' => ['required', 'file', 'max:5120']]);
        $file = $request->file('greeting');

        // Asterisk plays WAV directly; check the header rather than trusting the extension.
        $head = (string) file_get_contents($file->getRealPath(), false, null, 0, 12);
        if (! str_starts_with($head, 'RIFF') || substr($head, 8, 4) !== 'WAVE') {
            throw ValidationException::withMessages(['greeting' => 'Upload a WAV file (8 kHz or 16 kHz mono works best).']);
        }

        $old = $menu->greeting_path;
        $menu->forceFill(['greeting_path' => $file->storeAs("organizations/{$menu->organization_id}/ivr", "{$menu->id}-".now()->timestamp.'.wav', 'local')])->save();

        if ($old) {
            Storage::disk('local')->delete($old);
        }

        $this->pushIfLive($menu);
        Audit::record('ivr.greeting_uploaded', $menu);

        return back()->with('success', 'Greeting uploaded.');
    }

    /**
     * Preview for logged-in users.
     */
    public function greeting(int $ivr): StreamedResponse
    {
        Gate::authorize(Permission::IVR_VIEW);

        $menu = IvrMenu::findOrFail($ivr);
        abort_unless($menu->greeting_path && Storage::disk('local')->exists($menu->greeting_path), 404);

        return Storage::disk('local')->response($menu->greeting_path, "ivr-{$menu->number}.wav", ['Content-Type' => 'audio/wav']);
    }

    /**
     * Fetched by Asterisk (no session); the path token proves the URL came from the compiler.
     */
    public function media(int $ivr, string $token): StreamedResponse
    {
        $menu = IvrMenu::withoutGlobalScopes()->find($ivr);

        abort_unless($menu && $menu->greeting_path && hash_equals($menu->greetingToken(), $token), 404);
        abort_unless(Storage::disk('local')->exists($menu->greeting_path), 404);

        return Storage::disk('local')->response($menu->greeting_path, null, ['Content-Type' => 'audio/wav', 'Cache-Control' => 'public, max-age=86400']);
    }

    private function pushIfLive(IvrMenu $menu): void
    {
        if ($menu->published_at) {
            PushRouting::forCurrentTenant();
        }
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(Request $request): array
    {
        return [
            'manage' => $request->user()->can(Permission::IVR_MANAGE),
            'publish' => $request->user()->can(Permission::IVR_PUBLISH),
        ];
    }
}
