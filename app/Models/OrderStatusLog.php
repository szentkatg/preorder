<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderStatusLog extends Model
{
    public const DISPLAY_TIMEZONE = 'Europe/Budapest';

    public const EVENT_LABELS = ['submitted' => 'Beküldés', 'reopened' => 'Visszanyitás'];

    public const SOURCE_LABELS = ['single' => 'Egyedi művelet', 'bulk' => 'Tömeges beküldés'];

    public const STATUS_LABELS = ['draft' => 'Piszkozat', 'editing' => 'Szerkesztés alatt', 'submitted' => 'Beküldve'];

    public const EMAIL_LABELS = [
        'not_requested' => 'Nem kért e-mailt', 'not_applicable' => 'Nincs e-mail-küldés',
        'pending' => 'Folyamatban / eredmény nem ismert', 'sent' => 'Küldési művelet sikeres',
        'failed' => 'Küldési hiba', 'no_recipient' => 'Nincs címzett',
    ];

    public $timestamps = false;

    protected $fillable = [
        'order_id', 'reference_number', 'partner_code', 'partner_name', 'address_code', 'season_code',
        'partner_user_id', 'user_name', 'user_email', 'ip_address', 'event', 'source',
        'from_status', 'to_status', 'occurred_at', 'email_requested', 'email_status', 'email_finished_at', 'email_error_type',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime', 'email_requested' => 'boolean', 'email_finished_at' => 'immutable_datetime'];
    }
}
