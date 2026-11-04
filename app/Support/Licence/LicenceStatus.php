<?php

namespace App\Support\Licence;

enum LicenceStatus: string
{
    /** No licence installed: the till and back office work, extra (Complete Edition) features stay off. */
    case None = 'none';

    /** Within its dates: everything the plan includes can be used. */
    case Active = 'active';

    /** Ended less than the grace period ago: everything still works, with a warning. */
    case Grace = 'grace';

    /** Grace period over: reports only, no new orders. Takes effect at a quiet hour, never mid-service. */
    case ReadOnly = 'read_only';
}
