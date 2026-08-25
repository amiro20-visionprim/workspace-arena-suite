<?php

declare(strict_types=1);

namespace App\Domains\Workspace\Actions;

use App\Domains\Audit\Actions\RecordAuditLog;
use App\Domains\Workspace\Models\Site;
use App\Domains\Workspace\Services\CanonicalUrl;
use Illuminate\Validation\ValidationException;

class UpdateSite
{
    public function __construct(private readonly CanonicalUrl $url, private readonly RecordAuditLog $audit) {}

    public function handle(Site $site, array $data): Site
    {
        $canonical = $this->url->normalize($data['canonical_url']);
        // یکتایی نشانی باید در محدودهٔ «سازمانِ خودِ سایت» سنجیده شود، نه سازمانِ جاری
        // (چون کاربر چندسازمانی ممکن است روی سایتی خارج از سازمانِ جاری کار کند).
        if (Site::query()->where('organization_id', $site->organization_id)->where('canonical_url', $canonical)->whereKeyNot($site->id)->exists()) {
            throw ValidationException::withMessages(['canonical_url' => 'این نشانی قبلاً در فضای کاری ثبت شده است.']);
        }
        $before = ['name' => $site->name, 'canonical_url' => $site->canonical_url, 'project_id' => $site->project_id];
        $site->update(['project_id' => $data['project_id'], 'name' => $data['name'], 'canonical_url' => $canonical, 'locale' => $data['locale'], 'timezone' => $data['timezone'], 'business_importance' => $data['business_importance']]);
        $this->audit->handle(action: 'site.updated', subject: $site, before: $before, after: ['name' => $site->name, 'canonical_url' => $site->canonical_url, 'project_id' => $site->project_id]);

        return $site->refresh();
    }
}
