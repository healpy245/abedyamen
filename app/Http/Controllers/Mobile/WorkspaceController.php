<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Enums\Door;
use App\Http\Controllers\AgentsWorkspaceController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\DesignConceptController;
use App\Services\Finance\RestaurantFinance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceController extends Controller
{
    /**
     * Who is signed in and which doors they can open, labelled in the session's language.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        $doors = [];
        foreach (Door::cases() as $door) {
            $subs = [];
            foreach ($door->subDoors() as $key => $sub) {
                if ($sub['route'] === null) {
                    continue;
                }
                $subs[] = [
                    'key' => $key,
                    'label' => $sub['label'],
                    'icon' => $sub['icon'],
                    'open' => $user->canOpenSubDoor($door, $key),
                ];
            }
            $doors[] = [
                'id' => $door->value,
                'label' => $door->label(),
                'icon' => $door->icon(),
                'open' => $user->canOpenDoor($door) && ($subs === [] || collect($subs)->contains('open', true)),
                'subs' => $subs,
            ];
        }

        return response()->json([
            'id' => $user->id,
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'is_admin' => (bool) $user->is_admin,
            'manages_users' => $user->managesUsers(),
            'chatbot_only' => $user->isChatbotOnly(),
            'mowahada' => false,
            'locale' => app()->getLocale(),
            'restaurant_finance' => $user->canSeeRestaurantSection(Door::Finance),
            'restaurant_support' => $user->canSeeRestaurantSection(Door::Support),
            'doors' => $doors,
        ]);
    }

    /**
     * What the last web route flashed. The web routes answer a form with a redirect, even when they
     * refused it, so this is how the app learns the outcome. Reading it clears it.
     */
    public function flash(Request $request): JsonResponse
    {
        $session = $request->session();

        return response()->json([
            'success' => $session->get('success') ?? $session->get('status'),
            'error' => $session->get('error'),
            'info' => $session->get('info'),
        ]);
    }

    public function overview(Request $request, DesignConceptController $overview, AgentsWorkspaceController $catalog): JsonResponse
    {
        $user = $request->user();
        abort_if($user->isChatbotOnly(), 403);

        $restaurants = $user->canOpenSubDoor(Door::Overview, 'restaurants') ? $catalog->catalog()['companies'] : [];

        return response()->json($overview->summary($user, $restaurants));
    }

    public function restaurants(Request $request, AgentsWorkspaceController $catalog, RestaurantFinance $finance): JsonResponse
    {
        $user = $request->user();
        abort_if($user->isChatbotOnly(), 403);

        $data = $catalog->catalog();
        $money = $finance->forUser($user);

        return response()->json([
            'cities' => $data['cities'],
            'restaurants' => array_map(fn (array $company): array => $company + [
                'finance' => $money[$company['id']] ?? null,
            ], $data['companies']),
        ]);
    }
}
