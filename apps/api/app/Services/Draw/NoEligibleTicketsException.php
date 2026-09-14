<?php

namespace App\Services\Draw;

/**
 * A competition reached draw_pending with zero paid, eligible tickets
 * (e.g. every order was refunded after allocation). Deterministic —
 * retrying the same competition changes nothing until its eligible
 * pool changes, which nothing in this phase can do automatically. See
 * docs/DRAW_ENGINE_P9.md "Failure handling".
 */
class NoEligibleTicketsException extends DrawException {}
