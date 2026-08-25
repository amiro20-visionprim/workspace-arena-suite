<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Domains\Ai\Actions\DecideReviewItem;
use App\Domains\Automation\Actions\ApproveCommand;
use App\Domains\Workspace\Contracts\CurrentClient;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\ClientQuestionNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ClientDecisionController extends Controller
{
    public function index(CurrentClient $client): Response
    {
        if (! $client->has()) {
            return Inertia::render('Client/Decisions', ['commands' => [], 'reviews' => []]);
        }

        $clientModel = $client->get();

        $commands = DB::table('commands')
            ->join('sites', 'sites.id', '=', 'commands.site_id')
            ->join('projects', 'projects.id', '=', 'sites.project_id')
            ->where('projects.client_id', $clientModel->getKey())
            ->where('commands.status', 'pending_approval')
            ->latest('commands.created_at')
            ->get([
                'commands.id',
                'commands.type',
                'commands.risk_tier',
                'commands.expires_at',
                'commands.created_at',
                'sites.name as site_name',
            ]);

        $reviews = DB::table('review_items')
            ->join('sites', 'sites.id', '=', 'review_items.site_id')
            ->join('projects', 'projects.id', '=', 'sites.project_id')
            ->where('projects.client_id', $clientModel->getKey())
            ->where('review_items.status', 'pending_review')
            ->latest('review_items.created_at')
            ->get([
                'review_items.id',
                'review_items.subject_type',
                'review_items.subject_id',
                'review_items.due_at',
                'review_items.created_at',
                'sites.name as site_name',
            ]);

        return Inertia::render('Client/Decisions', [
            'commands' => $commands,
            'reviews' => $reviews,
        ]);
    }

    public function command(
        int $command,
        Request $request,
        CurrentClient $client,
        ApproveCommand $approveCommand,
    ): RedirectResponse {
        $data = $request->validate([
            'decision' => ['required', 'string', 'in:approved,rejected'],
        ]);

        $scoped = DB::table('commands')
            ->whereIn('site_id', $this->clientSiteIds($client))
            ->where('id', $command)
            ->where('status', 'pending_approval')
            ->exists();

        abort_unless($scoped, 404);

        $approveCommand->handle($command, $request->user(), $data['decision']);

        return back();
    }

    /**
     * دکمهٔ سوم «سؤال از تیم» (تصمیم ۹): مشتری به‌جای ردکردن، دربارهٔ یک
     * پیشنهاد سؤال می‌پرسد؛ سؤال ثبت و به اعضای فعال سازمان اعلان می‌شود.
     */
    public function question(Request $request, CurrentClient $client): RedirectResponse
    {
        $data = $request->validate([
            'subject_type' => ['required', 'string', 'in:command,review'],
            'subject_id' => ['required', 'integer'],
            'question' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        if (! $client->has()) {
            abort(404);
        }

        $clientModel = $client->get();
        $siteIds = $this->clientSiteIds($client);

        $subject = $data['subject_type'] === 'command'
            ? DB::table('commands')->whereIn('site_id', $siteIds)->where('id', $data['subject_id'])->first()
            : DB::table('review_items')->whereIn('site_id', $siteIds)->where('id', $data['subject_id'])->first();

        abort_unless($subject, 404);

        $site = DB::table('sites')->where('id', $subject->site_id)->first(['id', 'organization_id']);
        abort_unless($site, 404);

        DB::table('client_questions')->insert([
            'organization_id' => $site->organization_id,
            'site_id' => $subject->site_id,
            'subject_type' => $data['subject_type'],
            'subject_id' => (int) $data['subject_id'],
            'asked_by_id' => $request->user()->getKey(),
            'question' => $data['question'],
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $userId = (int) $request->user()->getKey();
        $recipientIds = DB::table('memberships')
            ->where('organization_id', $site->organization_id)
            ->where('status', 'active')
            ->where('user_id', '!=', $userId)
            ->pluck('user_id')
            ->all();

        foreach (User::query()->whereIn('id', $recipientIds)->get() as $user) {
            $user->notify(new ClientQuestionNotification(
                (int) $subject->site_id,
                $data['subject_type'],
                (int) $data['subject_id'],
                $data['question'],
                (string) $request->user()->name,
            ));
        }

        return back()->with('status', 'سؤال شما برای تیم ارسال شد؛ به‌زودی پاسخ می‌دهیم.');
    }

    public function review(
        int $review,
        Request $request,
        CurrentClient $client,
        DecideReviewItem $decideReviewItem,
    ): RedirectResponse {
        $data = $request->validate([
            'decision' => ['required', 'string', 'in:approved,rejected,changes_requested'],
        ]);

        $scoped = DB::table('review_items')
            ->whereIn('site_id', $this->clientSiteIds($client))
            ->where('id', $review)
            ->where('status', 'pending_review')
            ->exists();

        abort_unless($scoped, 404);

        $decideReviewItem->handle($review, $request->user(), $data['decision']);

        return back();
    }

    /** @return array<int, int> */
    private function clientSiteIds(CurrentClient $client): array
    {
        if (! $client->has()) {
            return [];
        }

        return DB::table('sites')
            ->join('projects', 'projects.id', '=', 'sites.project_id')
            ->where('projects.client_id', $client->get()->getKey())
            ->pluck('sites.id')
            ->all();
    }
}
