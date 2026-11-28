<?php

declare(strict_types=1);

namespace Tests\Support;

use Liberu\Accounting\Erp\Models\Team;
use Liberu\Foundation\Organizations\Database\Factories\TeamFactory;

class ErpTeamFactory extends TeamFactory
{
    protected $model = Team::class;
}
