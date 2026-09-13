<?php

namespace App\Services\Tickets;

/**
 * Base type for a deterministic, non-retryable allocation failure — the
 * queued job catches this specifically to fail immediately instead of
 * burning through retry attempts for an outcome retrying can't change.
 * See docs/TICKET_ENGINE_DESIGN_P7.md "What happens when a paid order
 * can't be allocated".
 */
abstract class TicketAllocationException extends \RuntimeException {}
