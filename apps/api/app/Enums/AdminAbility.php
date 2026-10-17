<?php

namespace App\Enums;

/**
 * The complete, fixed set of things an authenticated Admin can be
 * permitted to do (P11.2). This enum is the single source of truth for
 * "what abilities exist" — role → ability wiring lives in
 * AdminRole::abilities(), and every Policy in app/Policies checks
 * abilities from here via Admin::hasAbility() rather than switching on
 * role directly. Deliberately flat (dot-namespaced strings, not a
 * resource/action matrix class) — see AdminRole's docblock for why this
 * stays a plain enum instead of a permissions package.
 *
 * An ability being grantable is not a promise that every mutation it
 * gates will succeed — the domain services (CompetitionLifecycleService,
 * DrawService, WinnerLifecycleService, WinnerClaimService,
 * PrizeFulfilmentService) still own their own transition matrices and
 * must still reject a state transition an ability check alone allowed.
 */
enum AdminAbility: string
{
    case CompetitionView = 'competition.view';
    case CompetitionCreate = 'competition.create';
    case CompetitionUpdate = 'competition.update';
    case CompetitionDelete = 'competition.delete';
    case CompetitionLifecycle = 'competition.lifecycle';

    case PrizeView = 'prize.view';
    case PrizeCreate = 'prize.create';
    case PrizeUpdate = 'prize.update';
    case PrizeDelete = 'prize.delete';

    case OrderView = 'order.view';

    case PaymentView = 'payment.view';

    case EntryView = 'entry.view';

    case TicketView = 'ticket.view';

    case DrawView = 'draw.view';
    case DrawRun = 'draw.run';

    case WinnerView = 'winner.view';
    case WinnerTransition = 'winner.transition';

    case ClaimView = 'claim.view';
    case ClaimVerify = 'claim.verify';
    case ClaimApprove = 'claim.approve';
    case ClaimReject = 'claim.reject';

    case FulfilmentView = 'fulfilment.view';
    case FulfilmentAdvance = 'fulfilment.advance';
    case FulfilmentAssignPrize = 'fulfilment.assign_prize';

    case CustomerView = 'customer.view';

    case PromotionView = 'promotion.view';
    case PromotionManage = 'promotion.manage';

    case AdminView = 'admin.view';
    case AdminManage = 'admin.manage';

    case AuditView = 'audit.view';
}
