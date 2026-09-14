<?php

namespace App\Services\Winner;

/**
 * Base type for deterministic winner-domain failures — mirrors
 * App\Services\Draw\DrawException (P9) exactly. Thrown only for
 * conditions that will not resolve themselves on retry (e.g. a drawn
 * competition with no draw result); the caller (CreateWinnerJob,
 * winners:reconcile) treats this as permanent, never retries blindly.
 */
class WinnerException extends \RuntimeException {}
