<?php

namespace Nisalatp\DynamicReportGenerator\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Model
{
    protected $table = 'users';
    protected $guarded = [];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
