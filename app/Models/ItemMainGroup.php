<?php

namespace App\Models;

use App\Traits\HasTranslations;
use Illuminate\Database\Eloquent\Model;

class ItemMainGroup extends Model
{
    use HasTranslations;

    protected $fillable = [
        'code',
        'name',
        'active',
    ];

    public function __toString(): string
    {
        return $this->translate('name');
    }
}
