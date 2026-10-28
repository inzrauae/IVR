<?php

namespace App\Http\Controllers;

use App\Authorization\Permission;
use App\Models\Recording;
use App\Pbx\Exceptions\PbxException;
use App\Pbx\PbxManager;
use App\Support\Audit;
use App\Support\CallRows;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Recordings stay on the PBX; the app streams them through ARI so access is checked here and
 * the PBX never needs to be reachable from browsers.
 */
class RecordingController extends Controller
{
    public function __construct(private readonly PbxManager $pbx) {}

    public function index(Request $request): Response
    {
        Gate::authorize(Permission::RECORDINGS_VIEW);

        $search = $request->string('search')->trim()->limit(50, '')->value();
        $date = $request->date('date');

        $recordings = Recording::with(['call' => fn ($q) => $q->with(['fromExtension:id,name,number', 'toExtension:id,name,number'])])
            ->when($search !== '', fn ($q) => $q->whereHas('call', fn ($q) => $q->where(fn ($q) => $q
                ->where('from_number', 'like', "%{$search}%")->orWhere('to_number', 'like', "%{$search}%")->orWhere('from_name', 'like', "%{$search}%"))))
            ->when($date, fn ($q, Carbon $d) => $q->whereBetween('created_at', [$d->copy()->startOfDay(), $d->copy()->endOfDay()]))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        $calls = collect(CallRows::map($recordings->getCollection()->pluck('call')->filter()))->keyBy('id');

        return Inertia::render('recordings/Index', [
            'recordings' => [...$recordings->toArray(), 'data' => $recordings->getCollection()->map(fn (Recording $r) => [
                'id' => $r->id,
                'duration' => $r->duration,
                'created_at' => $r->created_at->toIso8601String(),
                'call' => $r->call_id ? $calls[$r->call_id] ?? null : null,
            ])],
            'filters' => ['search' => $search, 'date' => $date?->toDateString()],
            'mode' => $request->user()->organization->setting('call.recording_mode'),
            'can' => [
                'download' => $request->user()->can(Permission::RECORDINGS_DOWNLOAD),
                'delete' => $request->user()->can(Permission::RECORDINGS_DELETE),
                'settings' => $request->user()->can(Permission::SETTINGS_MANAGE),
            ],
        ]);
    }

    public function play(int $recording): HttpResponse
    {
        Gate::authorize(Permission::RECORDINGS_VIEW);

        return $this->audio(Recording::findOrFail($recording), false);
    }

    public function download(int $recording): HttpResponse
    {
        Gate::authorize(Permission::RECORDINGS_DOWNLOAD);

        $record = Recording::findOrFail($recording);
        Audit::record('recording.downloaded', $record, ['call_id' => $record->call_id]);

        return $this->audio($record, true);
    }

    public function destroy(int $recording): RedirectResponse
    {
        Gate::authorize(Permission::RECORDINGS_DELETE);

        $record = Recording::with('pbxServer')->findOrFail($recording);

        try {
            if ($record->pbxServer) {
                $this->pbx->driver($record->pbxServer)->deleteRecording($record->name);
            }
        } catch (PbxException $e) {
            return back()->with('error', 'The PBX could not delete the file: '.$e->getMessage());
        }

        Audit::record('recording.deleted', $record, ['call_id' => $record->call_id]);
        $record->delete();

        return back()->with('success', 'Recording deleted.');
    }

    private function audio(Recording $recording, bool $attachment): HttpResponse
    {
        abort_unless($recording->pbxServer, 404);

        try {
            $audio = $this->pbx->driver($recording->pbxServer)->recordingAudio($recording->name);
        } catch (PbxException $e) {
            abort(502, $e->getMessage());
        }

        return response($audio, 200, [
            'Content-Type' => 'audio/wav',
            'Content-Disposition' => ($attachment ? 'attachment' : 'inline')."; filename=\"recording-{$recording->id}.wav\"",
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
