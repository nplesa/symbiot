<?php

namespace App\Transit\Exceptions;

use RuntimeException;

/** The feed itself is unusable (missing files, over the size limits); retrying the import cannot help. */
class GtfsFeedInvalidException extends RuntimeException {}
