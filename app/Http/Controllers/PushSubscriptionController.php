<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Models\UserNotification;
use App\Services\WebPushService;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushSubscriptionController extends Controller
{
    public function __construct(
        private readonly WebPushService $webPushService
    ) {}

    /**
     * Return the authenticated user's latest 20 notifications, unread count, and VAPID public key.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $notifications = UserNotification::withoutGlobalScopes()
            ->where('company_id', $user->company_id)
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (UserNotification $item): array => $this->formatNotification($item))
            ->values()
            ->all();

        $unreadCount = UserNotification::withoutGlobalScopes()
            ->where('company_id', $user->company_id)
            ->where('user_id', $user->id)
            ->unread()
            ->count();

        return response()->json([
            'notifications' => $notifications,
            'unread_count' => $unreadCount,
            'vapid_public_key' => $this->webPushService->getVapidPublicKey(),
        ])->header('Cache-Control', 'private, no-cache');
    }

    /**
     * Register or idempotently update a Web Push subscription for the authenticated user.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => [
                'required',
                'string',
                'url:https',
                'starts_with:https://',
                'max:2000',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $parsed = parse_url((string) $value);
                    $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
                    $host = strtolower(trim((string) ($parsed['host'] ?? ''), '[]'));

                    if ($scheme !== 'https' || $host === '') {
                        $fail('The :attribute must use a valid public HTTPS endpoint.');

                        return;
                    }

                    if (
                        $host === 'localhost'
                        || str_ends_with($host, '.localhost')
                        || str_ends_with($host, '.local')
                        || str_ends_with($host, '.internal')
                    ) {
                        $fail('The :attribute host is not allowed.');

                        return;
                    }

                    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
                        $isPublicIp = filter_var(
                            $host,
                            FILTER_VALIDATE_IP,
                            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
                        ) !== false;

                        if (! $isPublicIp) {
                            $fail('The :attribute must not target a private or loopback IP address.');
                        }
                    }
                },
            ],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['nullable', 'string', 'in:aes128gcm,aesgcm'],
        ]);

        $user = $request->user();
        $endpoint = (string) $validated['endpoint'];
        $endpointHash = hash('sha256', $endpoint);

        $subscription = PushSubscription::withoutGlobalScopes()->updateOrCreate(
            ['endpoint_hash' => $endpointHash],
            [
                'company_id' => $user->company_id,
                'user_id' => $user->id,
                'endpoint' => $endpoint,
                'public_key' => (string) $validated['keys']['p256dh'],
                'auth_token' => (string) $validated['keys']['auth'],
                'content_encoding' => (string) ($validated['content_encoding'] ?? 'aes128gcm'),
                'user_agent' => substr((string) $request->userAgent(), 0, 500) ?: null,
                'last_used_at' => Carbon::now('Asia/Jakarta'),
            ]
        );

        return response()->json([
            'success' => true,
            'subscription_uuid' => $subscription->uuid,
        ], $subscription->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Unsubscribe / remove a Web Push endpoint owned by the authenticated user.
     */
    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string'],
        ]);

        $user = $request->user();
        $endpointHash = hash('sha256', (string) $validated['endpoint']);

        $deleted = PushSubscription::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('endpoint_hash', $endpointHash)
            ->delete();

        return response()->json([
            'success' => true,
            'deleted' => $deleted,
        ]);
    }

    /**
     * Dispatch a test Web Push & in-app notification to the authenticated user.
     */
    public function testPush(Request $request): JsonResponse
    {
        $user = $request->user();

        $notification = $this->webPushService->notifyUser(
            $user,
            'push_test',
            __('pwa.push_test_title'),
            __('pwa.push_test_body'),
            route('dashboard', [], false),
            ['source' => 'manual_test']
        );

        return response()->json([
            'success' => true,
            'notification' => $this->formatNotification($notification),
        ]);
    }

    /**
     * Mark a single notification as read after verifying ownership & tenant isolation.
     */
    public function markRead(Request $request, UserNotification $userNotification): JsonResponse
    {
        $user = $request->user();

        if ((int) $userNotification->user_id !== (int) $user->id || (int) $userNotification->company_id !== (int) $user->company_id) {
            abort(403);
        }

        if ($userNotification->read_at === null) {
            $userNotification->update([
                'read_at' => Carbon::now('Asia/Jakarta'),
            ]);
        }

        $unreadCount = UserNotification::withoutGlobalScopes()
            ->where('company_id', $user->company_id)
            ->where('user_id', $user->id)
            ->unread()
            ->count();

        return response()->json([
            'success' => true,
            'unread_count' => $unreadCount,
            'notification' => $this->formatNotification($userNotification->fresh()),
        ]);
    }

    /**
     * Mark all unread notifications for the authenticated user as read.
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $user = $request->user();

        UserNotification::withoutGlobalScopes()
            ->where('company_id', $user->company_id)
            ->where('user_id', $user->id)
            ->unread()
            ->update([
                'read_at' => Carbon::now('Asia/Jakarta'),
            ]);

        return response()->json([
            'success' => true,
            'unread_count' => 0,
        ]);
    }

    /**
     * Serialize a UserNotification safely without exposing internal sensitive identifiers.
     *
     * @return array<string, mixed>
     */
    private function formatNotification(UserNotification $item): array
    {
        $createdWib = $item->created_at?->timezone('Asia/Jakarta');

        return [
            'id' => $item->id,
            'uuid' => $item->uuid,
            'category' => $item->category,
            'title' => $item->title,
            'body' => $item->body,
            'action_url' => $item->action_url,
            'meta' => $item->meta,
            'read_at' => $item->read_at?->timezone('Asia/Jakarta')->toIso8601String(),
            'pushed_at' => $item->pushed_at?->timezone('Asia/Jakarta')->toIso8601String(),
            'created_at' => $createdWib?->toIso8601String(),
            'created_at_formatted' => $createdWib?->locale(app()->getLocale())->translatedFormat('d M Y, H:i').' WIB',
        ];
    }
}
