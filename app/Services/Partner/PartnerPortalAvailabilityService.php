<?php

namespace App\Services\Partner;

use App\Models\PartnerPortalSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PartnerPortalAvailabilityService
{
    public function update(array $input, int $expectedRevision): PartnerPortalSetting
    {
        Gate::authorize('View:PartnerPortalAvailability');
        Gate::authorize('Update:PartnerPortalAvailability');
        if (is_string($input['message'] ?? null)) {
            $input['message'] = trim($input['message']);
        }
        $data = Validator::make($input, [
            'is_enabled' => ['required', 'boolean'],
            'message' => [Rule::requiredIf(fn () => ! ($input['is_enabled'] ?? true)), 'nullable', 'string', 'max:4000'],
        ], ['message.required' => 'Lezáráskor adj meg egy üzenetet a partnereknek.'], [
            'message' => 'Partneroldali üzenet', 'is_enabled' => 'Partnerportál elérhető',
        ])->validate();

        return DB::transaction(function () use ($data, $expectedRevision): PartnerPortalSetting {
            $settings = PartnerPortalSetting::lockForUpdate()->findOrFail(1);
            if ($settings->revision !== $expectedRevision) {
                throw ValidationException::withMessages(['message' => 'A beállítást időközben más módosította. Töltsd újra az oldalt és ellenőrizd az aktuális állapotot.']);
            }
            $settings->update(['is_enabled' => (bool) $data['is_enabled'], 'message' => $data['message'] ?? null,
                'revision' => $settings->revision + 1, 'updated_by' => auth()->id()]);

            return $settings;
        }, 3);
    }
}
