<?php

namespace App\Support\Errors;

use RuntimeException;

/** An error whose message was written for the person using the screen, so it may be shown to them as it is. */
class UserFacingException extends RuntimeException {}
