<?php

namespace App\Support;

use RuntimeException;

/**
 * Raised when a persistence operation cannot be completed.
 *
 * A missing record is represented by a null return value from the calling
 * repository/model method; it is never represented by this exception.
 */
final class DatabaseOperationException extends RuntimeException
{
}
