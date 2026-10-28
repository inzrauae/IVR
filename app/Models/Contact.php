<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'name', 'company', 'phone', 'mobile', 'email', 'notes'];

    public static function normalizePhone(?string $phone): ?string
    {
        $clean = $phone === null ? '' : preg_replace('/[^\d+]/', '', $phone);

        return $clean === '' ? null : $clean;
    }

    /**
     * Contact names for a set of phone numbers, for labelling call lists.
     *
     * @param  iterable<string|null>  $numbers
     * @return array<string, string>
     */
    public static function namesFor(iterable $numbers): array
    {
        $numbers = collect($numbers)->filter()->unique()->values();

        if ($numbers->isEmpty()) {
            return [];
        }

        $names = [];
        static::where(fn ($q) => $q->whereIn('phone', $numbers)->orWhereIn('mobile', $numbers))
            ->get(['name', 'phone', 'mobile'])
            ->each(function (Contact $contact) use (&$names) {
                foreach ([$contact->phone, $contact->mobile] as $number) {
                    if ($number) {
                        $names[$number] ??= $contact->name;
                    }
                }
            });

        return $names;
    }
}
