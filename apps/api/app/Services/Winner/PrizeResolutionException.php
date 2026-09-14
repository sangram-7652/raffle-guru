<?php

namespace App\Services\Winner;

/**
 * Thrown by PrizeResolutionService when a competition's prize cannot be
 * safely resolved automatically — see docs/WINNER_CLAIM_P10.md "Prize
 * resolution" for the documented schema limitation this reflects.
 */
class PrizeResolutionException extends WinnerException {}
