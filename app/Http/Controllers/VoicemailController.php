<?php

namespace App\Http\Controllers;

use App\Authorization\Permission;
use App\Models\Contact;
use App\Models\Extension;
use App\Models\User;
use App\Models\VoicemailMessage;
use App\Pbx\Exceptions\PbxException;
use App\Pbx\PbxManager;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Everyone hears their own mailbox; voicemail.manage opens every mailbox in the organization.
 */
class VoicemailController extends Controller
{
    public function __construct(private readonly PbxManager $pbx) {}

    public function index(Request $request): Response
    {
        Gate::authorize(Permission::VOICEMAIL_VIEW);

        $user = $request->user();
        $manager = $user->can(Permission::VOICEMAIL_MANAGE);
        $mailbox = $manager ? $request->integer('mailbox') ?: null : null;

        $messages = $this->visible($user)
            ->with('extension:id,number,name')
            ->when($mailbox, fn ($q, $id) => $q->where('extension_id', $id))
            ->when($request->query('filter') === 'new', fn ($q) => $q->whereNull('heard_at'))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        $names = Contact::namesFor($messages->getCollection()->pluck('caller_number'));

        return Inertia::render('voicemail/Index', [
            'messages' => [...$messages->toArray(), 'data' => $messages->getCollection()->map(fn (VoicemailMessage $m) => [
                'id' => $m->id,
                'caller_number' => $m->caller_number,
                'caller_name' => $m->caller_name ?? $names[$m->caller_number] ?? null,
                'duration' => $m->duration,
                'heard' => $m->heard_at !== null,
                'created_at' => $m->created_at->toIso8601String(),
                'mailbox' => $m->extension?->only(['id', 'number', 'name']),
            ])],
            'unheard' => $this->visible($user)->whereNull('heard_at')->count(),
            'mailboxes' => $manager ? Extension::where('voicemail_enabled', true)->orderBy('number')->get(['id', 'number', 'name']) : [],
            'myMailbox' => $user->extension?->only(['id', 'number', 'name', 'voicemail_enabled']),
            'filters' => ['mailbox' => $mailbox, 'filter' => $request->query('filter') === 'new' ? 'new' : null],
            'can' => ['manage' => $manager, 'call' => $user->can(Permission::WEBPHONE_USE)],
        ]);
    }

    public function play(Request $request, int $message): HttpResponse
    {
        Gate::authorize(Permission::VOICEMAIL_VIEW);

        $record = $this->visible($request->user())->with('pbxServer')->findOrFail($message);
        abort_unless($record->pbxServer, 404);

        try {
            $audio = $this->pbx->driver($record->pbxServer)->recordingAudio($record->name);
        } catch (PbxException $e) {
            abort(502, $e->getMessage());
        }

        if (! $record->heard_at) {
            $record->forceFill(['heard_at' => now()])->save();
        }

        return response($audio, 200, [
            'Content-Type' => 'audio/wav',
            'Content-Disposition' => "inline; filename=\"voicemail-{$record->id}.wav\"",
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    public function update(Request $request, int $message): RedirectResponse
    {
        Gate::authorize(Permission::VOICEMAIL_VIEW);

        $record = $this->visible($request->user())->findOrFail($message);
        $record->forceFill(['heard_at' => $request->boolean('heard') ? ($record->heard_at ?? now()) : null])->save();

        return back();
    }

    public function destroy(Request $request, int $message): RedirectResponse
    {
        Gate::authorize(Permission::VOICEMAIL_VIEW);

        $record = $this->visible($request->user())->with('pbxServer')->findOrFail($message);

        try {
            if ($record->pbxServer) {
                $this->pbx->driver($record->pbxServer)->deleteRecording($record->name);
            }
        } catch (PbxException $e) {
            return back()->with('error', 'The PBX could not delete the message: '.$e->getMessage());
        }

        Audit::record('voicemail.deleted', $record, ['extension_id' => $record->extension_id]);
        $record->delete();

        return back()->with('success', 'Voicemail deleted.');
    }

    /**
     * @return Builder<VoicemailMessage>
     */
    private function visible(User $user): Builder
    {
        return VoicemailMessage::query()->when(
            ! $user->can(Permission::VOICEMAIL_MANAGE),
            fn ($q) => $q->where('extension_id', $user->extension?->id ?? 0),
        );
    }
}
