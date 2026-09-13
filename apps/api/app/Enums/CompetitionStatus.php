<?php

namespace App\Enums;

/**
 * Full future lifecycle (see docs/SCHEMA_DESIGN_P2.md for why this is a
 * plain string column + PHP enum rather than a MySQL ENUM type). P2 only
 * establishes the representation — the lifecycle engine itself is P8.
 */
enum CompetitionStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Active = 'active';
    case SoldOut = 'sold_out';
    case Closed = 'closed';
    case DrawPending = 'draw_pending';
    case DrawInProgress = 'draw_in_progress';
    case Drawn = 'drawn';
    case Completed = 'completed';
}
