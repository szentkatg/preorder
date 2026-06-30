<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ItemMainGroup extends Model
{
    protected $fillable = [
        'code',
        'name_hu',
        'name_en',
        'active',
    ];
}