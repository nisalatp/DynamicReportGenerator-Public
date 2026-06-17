<?php

namespace Nisalatp\DynamicReportGenerator\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $table = 'products';
    protected $guarded = [];

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
