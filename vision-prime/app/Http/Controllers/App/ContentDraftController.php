<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Domains\Content\Models\ContentDraft;
use App\Domains\Organization\Contracts\CurrentOrganization;
use App\Http\Controllers\App\Concerns\InteractsWithContentApi;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * مدیریت پیش‌نویس‌های محتوا.
 *
 * (از تفکیک ContentApiController ۱۴۱۴خطی — رفتار و مسیرها بدون تغییر)
 */
class ContentDraftController extends Controller
{
    use InteractsWithContentApi;

    /**
     * Save or update a content draft.
     * POST /api/content/drafts
     */
    public function saveDraft(Request $request, CurrentOrganization $org): JsonResponse
    {
        $data = $request->validate([
            'draft_id' => 'nullable|integer',
            'site_id' => 'required|integer',
            'title' => 'required|string|max:200',
            'content' => 'nullable|string',
            'meta_title' => 'nullable|string|max:100',
            'meta_description' => 'nullable|string|max:300',
            'subtype' => 'nullable|string',
            'quality_score' => 'nullable|integer',
            'expert_analysis' => 'nullable|array',
            'audit_log' => 'nullable|array',
        ]);

        if (! empty($data['draft_id'])) {
            $draft = ContentDraft::whereHas('site', fn ($q) => $q->where('organization_id', $org->id()))
                ->findOrFail($data['draft_id']);
            $draft->update(array_filter($data, fn ($v) => $v !== null));

            return response()->json(['id' => $draft->id, 'updated' => true]);
        }

        $draft = ContentDraft::create([
            'site_id' => (int) $data['site_id'],
            'title' => $data['title'],
            'slug' => str()->slug($data['title']),
            'content' => $data['content'] ?? '',
            'meta_title' => $data['meta_title'] ?? '',
            'meta_description' => $data['meta_description'] ?? '',
            'subtype' => $data['subtype'] ?? 'tutorial',
            'quality_score' => $data['quality_score'] ?? 0,
            'expert_analysis' => $data['expert_analysis'] ?? null,
            'audit_log' => $data['audit_log'] ?? [],
            'status' => 'draft',
        ]);

        return response()->json(['id' => $draft->id, 'created' => true]);
    }

    /**
     * List content drafts with filters.
     * GET /api/content/drafts
     */
    public function listDrafts(Request $request, CurrentOrganization $org): JsonResponse
    {
        $query = ContentDraft::query()
            ->whereHas('site', fn ($q) => $q->where('organization_id', $org->id()));

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($siteId = $request->query('site_id')) {
            $query->where('site_id', (int) $siteId);
        }
        if ($search = $request->query('search')) {
            $query->where('title', 'LIKE', '%'.$search.'%');
        }

        $drafts = $query->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'title', 'slug', 'status', 'quality_score', 'model_used', 'subtype', 'created_at', 'updated_at']);

        return response()->json(['drafts' => $drafts]);
    }

    /**
     * Get single draft with full content.
     * GET /api/content/drafts/{id}
     */
    public function getDraft(int $id, CurrentOrganization $org): JsonResponse
    {
        $draft = ContentDraft::whereHas('site', fn ($q) => $q->where('organization_id', $org->id()))
            ->findOrFail($id);

        return response()->json($draft);
    }

    /**
     * Delete a draft.
     * DELETE /api/content/drafts/{id}
     */
    public function deleteDraft(int $id, CurrentOrganization $org): JsonResponse
    {
        $this->authorizeSuperAdmin();
        ContentDraft::whereHas('site', fn ($q) => $q->where('organization_id', $org->id()))
            ->findOrFail($id)
            ->delete();

        return response()->json(['ok' => true]);
    }
}
