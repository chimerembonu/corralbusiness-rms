<?php

namespace App\Modules\Orders\Exceptions;

use RuntimeException;

/** The chosen table cannot take this order (occupied, merged, switched off…). */
class TableUnavailableException extends RuntimeException {}
