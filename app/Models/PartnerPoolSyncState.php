<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerPoolSyncState extends Model
{
    protected $table = 'partnerpool_sync_state';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['source_extracted_at' => 'datetime', 'synced_at' => 'datetime'];
    }
}
