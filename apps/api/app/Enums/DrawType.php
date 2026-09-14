<?php

namespace App\Enums;

/**
 * Which draw strategy executed a given `draws` attempt. Only one case
 * exists today — P9 implements AutomatedDrawStrategy only, per the
 * phase boundary (no live-video/social draw, no instant-win). The
 * strategy interface (App\Services\Draw\Strategies\DrawStrategy) is
 * already written to make adding `LiveDraw`/`InstantWin` cases later a
 * pure addition (new enum case + new strategy class + a binding
 * decision), never a redesign. See docs/DRAW_ENGINE_P9.md "Future draw
 * types".
 *
 * Deliberately NOT stored on `competitions` — see docs/DRAW_ENGINE_P9.md
 * "Why no competitions.draw_type column yet": nothing selects between
 * strategies per-competition today, so there is nothing for such a
 * column to configure.
 */
enum DrawType: string
{
    case Automated = 'automated';
}
