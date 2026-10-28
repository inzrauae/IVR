<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PackageFeature extends Model
{
    public const BASIC_IVR = 'ivr.basic';

    public const CALL_TRANSFERS = 'calls.transfer';

    public const CALL_RECORDING = 'recordings';

    public const LIVE_MONITORING = 'calls.live_monitoring';

    public const MANAGER_REPORTS = 'reports.manager';

    public const DEDICATED_DID = 'dids.dedicated';

    protected $fillable = ['feature'];

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }
}
