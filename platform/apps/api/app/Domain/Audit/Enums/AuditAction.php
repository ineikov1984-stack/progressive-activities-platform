<?php

namespace App\Domain\Audit\Enums;

enum AuditAction: string
{
    case IdentityLoginSucceeded = 'identity.login_succeeded';
    case OrganizationContextSelected = 'organization.context_selected';
    case OrganizationInvitationCreated = 'organization.invitation_created';
    case OrganizationApplicationSubmitted = 'organization.application_submitted';
    case OrganizationRoleChanged = 'organization.role_changed';
    case CatalogActivitySubmitted = 'catalog.activity_submitted';
}
