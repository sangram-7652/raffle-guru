<?php

namespace App\Services\Admin;

use DomainException;

/**
 * A rule the admin-management domain refused (last-SuperAdmin invariant,
 * self-management, duplicate email, no-op change). Authorization refusals
 * are AuthorizationException instead, so callers can tell "not allowed"
 * from "not possible in this state".
 */
class AdminManagementException extends DomainException {}
