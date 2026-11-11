<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An error whose message is written for end users and may be shown as-is.
 * Any other exception is shown as a generic message (details go to the log).
 */
class UserFacingException extends RuntimeException {}
