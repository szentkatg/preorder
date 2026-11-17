<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerPortalSetting extends Model
{
    protected $fillable = ['is_enabled', 'message', 'revision', 'updated_by'];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'revision' => 'integer'];
    }

    public static function current(): self
    {
        return static::findOrFail(1);
    }
}
