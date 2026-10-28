<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\PackageFeature;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PackageController extends Controller
{
    public const FEATURES = [
        PackageFeature::BASIC_IVR => 'Basic IVR',
        PackageFeature::CALL_TRANSFERS => 'Call transfers',
        PackageFeature::CALL_RECORDING => 'Call recording',
        PackageFeature::LIVE_MONITORING => 'Live monitoring',
        PackageFeature::MANAGER_REPORTS => 'Manager reports',
        PackageFeature::DEDICATED_DID => 'Dedicated DID support',
    ];

    public function index(): Response
    {
        return Inertia::render('platform/Packages', [
            'packages' => Package::with('features')->withCount(['features'])->orderBy('monthly_price')->get()->map(fn (Package $p) => [
                ...$p->only(['id', 'name', 'slug', 'description', 'currency', 'monthly_price', 'annual_price', 'max_users', 'max_extensions', 'max_concurrent_calls', 'recording_storage_mb', 'trial_days', 'overage_per_extension', 'is_active']),
                'features' => $p->features->pluck('feature'),
                'subscribers' => DB::table('subscriptions')->where('package_id', $p->id)->whereIn('status', ['trialing', 'active', 'past_due'])->count(),
            ]),
            'featureOptions' => self::FEATURES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $package = DB::transaction(function () use ($request) {
            $validated = $this->validated($request);
            $package = Package::create(collect($validated)->except('features')->all());
            $this->syncFeatures($package, $validated['features']);

            return $package;
        });

        Audit::record('package.created', $package, ['slug' => $package->slug]);

        return back()->with('success', "{$package->name} created.");
    }

    public function update(Request $request, Package $package): RedirectResponse
    {
        DB::transaction(function () use ($request, $package) {
            $validated = $this->validated($request, $package);
            $package->update(collect($validated)->except('features')->all());
            $this->syncFeatures($package, $validated['features']);
        });

        Audit::record('package.updated', $package, ['changed' => array_keys($package->getChanges())]);

        return back()->with('success', "{$package->name} saved. Existing subscriptions keep their price until they change plan.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Package $package = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'alpha_dash', 'max:50', Rule::unique(Package::class)->ignore($package)],
            'description' => ['nullable', 'string', 'max:500'],
            'currency' => ['required', 'string', 'size:3'],
            'monthly_price' => ['required', 'numeric', 'min:0'],
            'annual_price' => ['nullable', 'numeric', 'min:0'],
            'max_users' => ['nullable', 'integer', 'min:1'],
            'max_extensions' => ['nullable', 'integer', 'min:1'],
            'max_concurrent_calls' => ['nullable', 'integer', 'min:1'],
            'recording_storage_mb' => ['nullable', 'integer', 'min:0'],
            'trial_days' => ['required', 'integer', 'between:0,90'],
            'overage_per_extension' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['required', 'boolean'],
            'features' => ['present', 'array'],
            'features.*' => [Rule::in(array_keys(self::FEATURES))],
        ]);
    }

    /**
     * @param  list<string>  $features
     */
    private function syncFeatures(Package $package, array $features): void
    {
        $package->features()->whereNotIn('feature', $features)->delete();

        foreach ($features as $feature) {
            $package->features()->firstOrCreate(['feature' => $feature]);
        }
    }
}
