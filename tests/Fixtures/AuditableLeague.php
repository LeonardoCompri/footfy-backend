<?php

namespace Tests\Fixtures;

use App\Models\Concerns\Auditable;
use App\Models\League;

class AuditableLeague extends League
{
    use Auditable;

    protected $table = 'leagues';
}
