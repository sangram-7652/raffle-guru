<?php

namespace App\Services\Winner;

/**
 * Thrown by WinnerClaimService for a deterministic claim-domain failure
 * (wrong winner state to submit, or a claim already exists) — the
 * controller converts this into a 409/422 response, never a 500.
 */
class WinnerClaimException extends WinnerException {}
