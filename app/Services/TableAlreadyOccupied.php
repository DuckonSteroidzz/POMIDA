<?php

namespace App\Services;

use RuntimeException;

/**
 * Thrown when the unique index on table_sessions.active_lock catches a race that
 * slipped past the row lock — two customers claiming the same table in the same
 * instant. Callers turn this into the ordinary "table is busy" message rather
 * than letting it surface as a 500.
 */
class TableAlreadyOccupied extends RuntimeException
{
}
