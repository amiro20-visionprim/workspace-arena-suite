<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Domains\Connector\Actions\CreatePairingToken;
use App\Domains\Workspace\Models\Site;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class SiteConnectorTokenController extends Controller
{
    public function store(Site $site, CreatePairingToken $pairing): JsonResponse|RedirectResponse
    {
        Gate::authorize('update', $site);
        $result = $pairing->handle($site);

        if (request()->wantsJson()) {
            return response()->json(['token' => $result['token'], 'expires_at' => $result['expires_at']]);
        }

        return back()->with('pairingToken', $result['token'])->with('pairingTokenExpiresAt', $result['expires_at']);
    }
}
