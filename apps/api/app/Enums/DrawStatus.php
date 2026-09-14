<?php

namespace App\Enums;

/**
 * Internal execution status of a single draw ATTEMPT (one row in
 * `draws`) — deliberately independent of `CompetitionStatus`. A
 * competition's status only ever shows draw_pending / draw_in_progress
 * / drawn (the customer-visible lifecycle); this enum tracks whether
 * one specific attempt to actually run that draw succeeded, is still
 * running, or failed and needs recovery. See docs/DRAW_ENGINE_P9.md
 * "Status model".
 *
 * Deliberately lean (same convention as PaymentStatus/the P8 transition
 * matrix): no "pending" case, because a `draws` row is only ever
 * created at the moment an attempt has already acquired the
 * competition and is about to run — there's no queued-but-not-started
 * state for a draw attempt to occupy.
 */
enum DrawStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
