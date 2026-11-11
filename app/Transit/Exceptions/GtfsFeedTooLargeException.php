<?php

namespace App\Transit\Exceptions;

use RuntimeException;

/** A GTFS feed exceeds transit.max_download_mb or transit.max_uncompressed_mb; retrying cannot help. */
class GtfsFeedTooLargeException extends RuntimeException {}
