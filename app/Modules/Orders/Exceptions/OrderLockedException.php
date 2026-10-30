<?php

namespace App\Modules\Orders\Exceptions;

use RuntimeException;

/** The order (or line) can no longer be changed this way, e.g. already sent to the kitchen or paid. */
class OrderLockedException extends RuntimeException {}
