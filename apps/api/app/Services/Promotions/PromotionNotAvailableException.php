<?php

namespace App\Services\Promotions;

use RuntimeException;

/**
 * The promotion itself refused the claim — disabled, outside its
 * window, its own capacity is exhausted, or its competition is no
 * longer purchasable. Distinct from CapacityExceededException/
 * PerUserLimitExceededException (App\Services\Tickets), which mean the
 * *competition's* real capacity/per-user limit was hit — a different
 * failure a promotional claim can also surface, caught separately by
 * PromotionClaimService so the two are never confused in the audit
 * trail.
 */
class PromotionNotAvailableException extends RuntimeException {}
