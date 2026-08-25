<?php

declare(strict_types=1);

namespace App\Domains\Content\Policies;

use App\Domains\Content\Models\UrlProfile;
use App\Domains\Organization\Models\Organization;
use App\Domains\Workspace\Services\OrganizationPermission;
use App\Models\User;

/**
 * دسترسی به نمایه‌های URL (دادهٔ هوش SEO / محتوا).
 *
 * این پالیسی شکافِ عدم‌وجود مجوز در لایهٔ هوش SEO را می‌بندد:
 * پیش از این، UrlProfileController تنها با اسکوپِ سازمانی (site_ids) کار می‌کرد
 * و هر عضو سازمان — حتی نقش‌های حداقلی — به همهٔ دادهٔ هوش SEO دسترسی داشت.
 */
class UrlProfilePolicy
{
    public function __construct(private readonly OrganizationPermission $organizationPermission) {}

    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->organizationPermission->allows($user, $organization, 'intelligence.view.assigned')
            || $this->organizationPermission->allows($user, $organization, 'site.view.organization');
    }

    public function view(User $user, UrlProfile $urlProfile): bool
    {
        return $this->viewAny($user, $urlProfile->site->organization);
    }
}
