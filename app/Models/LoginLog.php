<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoginLog extends Model
{
    public const DISPLAY_TIMEZONE = 'Europe/Budapest';

    public const GUARD_LABELS = ['web' => 'Admin', 'partner' => 'Partnerportál'];

    public $timestamps = false;

    protected $fillable = ['guard', 'user_id', 'user_name', 'user_email', 'ip_address', 'logged_in_at'];

    protected function casts(): array
    {
        return ['logged_in_at' => 'immutable_datetime'];
    }
}
