<?php

namespace App\Modules\Orders\Exceptions;

use RuntimeException;

/** A discount or promo code cannot be applied (unknown code, expired, too big, missing reason…). */
class DiscountException extends RuntimeException {}
