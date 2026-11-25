<?php

namespace Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Liberu\Foundation\Tenancy\Concerns\IsTenantModel;

final class TenantContractRecord extends Model
{
    use IsTenantModel;

    protected $table = 'tenant_contract_records';

    protected $guarded = [];

    public $timestamps = false;
}
