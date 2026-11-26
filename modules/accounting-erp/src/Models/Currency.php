<?php

declare(strict_types=1);

namespace Liberu\Accounting\Erp\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Liberu\Accounting\Erp\Database\Factories\CurrencyFactory;

#[UseFactory(CurrencyFactory::class)]
class Currency extends Model
{
    use HasFactory;

    #[\Override]
    protected $primaryKey = 'currency_id';

    #[\Override]
    protected $fillable = [
        'code',
        'name',
        'symbol',
        'is_default',
    ];

    #[\Override]
    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class, 'currency_id', 'currency_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'currency_id', 'currency_id');
    }
}
