<?php

namespace App\Modules\Orders\Exceptions;

use RuntimeException;

/** A delivery action cannot be done (missing address, not a rider, already delivered…). */
class DeliveryException extends RuntimeException {}
