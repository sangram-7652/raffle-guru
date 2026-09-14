<?php

namespace App\Services\Draw;

/**
 * Base type for deterministic draw-domain failures — see
 * App\Jobs\DrawCompetitionJob, which treats any DrawException as
 * permanent (retrying changes nothing) versus an unexpected/transient
 * exception (let Laravel's normal queue retry/backoff handle it). Same
 * pattern as App\Services\Tickets\TicketAllocationException (P7).
 */
class DrawException extends \RuntimeException {}
