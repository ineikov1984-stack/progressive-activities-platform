<?php

namespace App\Domain\Audit\Enums;

enum AuditAction: string
{
    case IdentityLoginSucceeded = 'identity.login_succeeded';
    case IdentityContactUpdated = 'identity.contact_updated';
    case OrganizationContextSelected = 'organization.context_selected';
    case OrganizationInvitationCreated = 'organization.invitation_created';
    case OrganizationApplicationSubmitted = 'organization.application_submitted';
    case OrganizationApplicantCreated = 'organization.applicant_created';
    case OrganizationApplicationReviewed = 'organization.application_reviewed';
    case OrganizationRoleChanged = 'organization.role_changed';
    case CatalogActivitySubmitted = 'catalog.activity_submitted';
    case CatalogActivityPublished = 'catalog.activity_published';
    case CatalogVisitOfferCreated = 'catalog.visit_offer_created';
    case CatalogVisitOfferUpdated = 'catalog.visit_offer_updated';
    case CatalogVisitOfferPublished = 'catalog.visit_offer_published';
    case CatalogVisitOfferClosed = 'catalog.visit_offer_closed';
    case CatalogVisitOfferCancelled = 'catalog.visit_offer_cancelled';
    case CatalogVisitOfferCapacityChanged = 'catalog.visit_offer_capacity_changed';
    case CatalogVisitOfferPriceChanged = 'catalog.visit_offer_price_changed';
    case CatalogVisitOfferDeadlineChanged = 'catalog.visit_offer_deadline_changed';
    case CatalogVisitOfferPolicyChanged = 'catalog.visit_offer_policy_changed';
    case CatalogOpenDayCampaignCreated = 'catalog.open_day_campaign_created';
    case CatalogOpenDayCampaignUpdated = 'catalog.open_day_campaign_updated';
    case CatalogOpenDayCampaignPublished = 'catalog.open_day_campaign_published';
    case CatalogOpenDayCampaignClosed = 'catalog.open_day_campaign_closed';
    case CatalogOpenDayCampaignCancelled = 'catalog.open_day_campaign_cancelled';
    // Who leads or assists a group (docs/product/team.md, т. 5).
    case CatalogAssignmentAdded = 'catalog.assignment_added';
    case CatalogAssignmentEnded = 'catalog.assignment_ended';
    case CatalogAssignmentRemoved = 'catalog.assignment_removed';
    case TeamMemberAdded = 'team.member_added';
    case TeamMemberUpdated = 'team.member_updated';
    case TeamMemberArchived = 'team.member_archived';
    case TeamProfileLinked = 'team.profile_linked';
    case TeamRoleChanged = 'team.role_changed';
    case TeamInvitationSent = 'team.invitation_sent';
    case TeamInvitationAccepted = 'team.invitation_accepted';
    // Rights in the team (docs/product/team-rights.md, т. 4–6): identifiers and keys only.
    case TeamAccessGrantAdded = 'team.access_grant_added';
    case TeamAccessGrantChanged = 'team.access_grant_changed';
    case TeamAccessGrantRevoked = 'team.access_grant_revoked';
    case TeamAccessScopeChanged = 'team.access_scope_changed';
    case TeamAccessGroupAdded = 'team.access_group_added';
    case TeamAccessGroupRemoved = 'team.access_group_removed';
    case TeamAccessTemplateChanged = 'team.access_template_changed';
    case EnrollmentApplicationReviewed = 'enrollment.application_reviewed';
    case EnrollmentTransferred = 'enrollment.transferred';
    case EnrollmentPaused = 'enrollment.paused';
    case EnrollmentPauseEnded = 'enrollment.pause_ended';
    case EnrollmentEnded = 'enrollment.ended';
    case EnrollmentReopened = 'enrollment.reopened';
    case EnrollmentSessionExcluded = 'enrollment.session_excluded';
    case EnrollmentSessionRestored = 'enrollment.session_restored';
    case EnrollmentGroupApprovalChanged = 'enrollment.group_approval_changed';
    case EnrollmentContactViewed = 'enrollment.contact_viewed';
    case PaymentTermsCreated = 'payment_terms.created';
    case PaymentTermsUpdated = 'payment_terms.updated';
}
