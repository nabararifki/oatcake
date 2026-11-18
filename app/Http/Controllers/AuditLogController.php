<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    /**
     * Display the tenant-scoped immutable JSONB audit log forensic console.
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', AuditLog::class);

        $user = $request->user();
        $activeCompanyId = $user->isSuperAdmin()
            ? (session('active_company_id') ? (int) session('active_company_id') : null)
            : (int) $user->company_id;

        $baseQuery = AuditLog::withoutGlobalScopes()
            ->when($activeCompanyId !== null, fn (Builder $q) => $q->where('company_id', $activeCompanyId));

        $filterModule = trim((string) $request->query('auditable_type', ''));
        $filterEvent = trim((string) $request->query('event_type', ''));
        $filterActorId = trim((string) $request->query('actor_id', ''));
        $filterDateFrom = trim((string) $request->query('date_from', ''));
        $filterDateTo = trim((string) $request->query('date_to', ''));
        $filterJson = trim((string) $request->query('json_filter', ''));
        $filterQuery = trim((string) $request->query('q', ''));

        $logsQuery = (clone $baseQuery)
            ->when($filterModule !== '', fn (Builder $q) => $q->where('auditable_type', $filterModule))
            ->when($filterEvent !== '', fn (Builder $q) => $q->where('event_type', $filterEvent))
            ->when($filterActorId !== '' && is_numeric($filterActorId), fn (Builder $q) => $q->where('actor_id', (int) $filterActorId));

        if ($filterDateFrom !== '') {
            try {
                $fromDt = Carbon::parse($filterDateFrom, 'Asia/Jakarta')->startOfDay()->utc();
                $logsQuery->where('created_at', '>=', $fromDt);
            } catch (\Throwable) {
                // Ignore invalid date filter format
            }
        }

        if ($filterDateTo !== '') {
            try {
                $toDt = Carbon::parse($filterDateTo, 'Asia/Jakarta')->endOfDay()->utc();
                $logsQuery->where('created_at', '<=', $toDt);
            } catch (\Throwable) {
                // Ignore invalid date filter format
            }
        }

        if ($filterJson !== '') {
            $this->applyJsonbContainmentFilter($logsQuery, $filterJson);
        }

        if ($filterQuery !== '') {
            $this->applyKeywordFilter($logsQuery, $filterQuery);
        }

        $logs = $logsQuery
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        // Summary KPI cards (tenant-scoped, aggregated in 1 SQL query)
        $todayStartUtc = Carbon::today('Asia/Jakarta')->startOfDay()->utc()->toDateTimeString();
        $statsRow = (clone $baseQuery)
            ->selectRaw(
                "COUNT(*) as total_logs, "
                ."SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as today_logs, "
                ."SUM(CASE WHEN auditable_type IN ('company','department','shift','employee','public_holiday','smart_department_weight','company_leave_type_setting') THEN 1 ELSE 0 END) as master_config_changes, "
                ."SUM(CASE WHEN event_type IN ('approved_tier1','approved_tier2','rejected','ledger_adjusted','published','verified') THEN 1 ELSE 0 END) as approval_ledger_actions",
                [$todayStartUtc]
            )
            ->first();

        $stats = [
            'total_logs' => (int) ($statsRow->total_logs ?? 0),
            'today_logs' => (int) ($statsRow->today_logs ?? 0),
            'master_config_changes' => (int) ($statsRow->master_config_changes ?? 0),
            'approval_ledger_actions' => (int) ($statsRow->approval_ledger_actions ?? 0),
        ];

        $moduleOptions = (clone $baseQuery)
            ->select('auditable_type')
            ->distinct()
            ->orderBy('auditable_type')
            ->pluck('auditable_type')
            ->all();

        $eventOptions = (clone $baseQuery)
            ->select('event_type')
            ->distinct()
            ->orderBy('event_type')
            ->pluck('event_type')
            ->all();

        $actors = User::query()
            ->when($activeCompanyId !== null, fn (Builder $q) => $q->where('company_id', $activeCompanyId))
            ->orderBy('name')
            ->get(['id', 'name', 'role']);

        return view('audit-logs.index', compact(
            'logs',
            'stats',
            'moduleOptions',
            'eventOptions',
            'actors',
            'filterModule',
            'filterEvent',
            'filterActorId',
            'filterDateFrom',
            'filterDateTo',
            'filterJson',
            'filterQuery'
        ));
    }

    /**
     * Return structured JSONB payload and side-by-side diff rows for forensic inspection.
     */
    public function show(Request $request, AuditLog $auditLog): JsonResponse
    {
        Gate::authorize('view', $auditLog);

        $createdWib = $auditLog->created_at?->timezone('Asia/Jakarta');

        return response()->json([
            'id' => $auditLog->id,
            'uuid' => $auditLog->uuid,
            'company_id' => $auditLog->company_id,
            'actor_id' => $auditLog->actor_id,
            'actor_name' => $auditLog->actor_name ?? 'System',
            'actor_role' => $auditLog->actor_role ?? 'system',
            'event_type' => $auditLog->event_type,
            'auditable_type' => $auditLog->auditable_type,
            'auditable_id' => $auditLog->auditable_id,
            'description' => $auditLog->description,
            'created_at' => $createdWib?->toIso8601String(),
            'created_at_formatted' => $createdWib?->locale(app()->getLocale())->translatedFormat('d M Y, H:i:s').' WIB',
            'old_values' => $auditLog->old_values,
            'new_values' => $auditLog->new_values,
            'metadata' => $auditLog->metadata,
            'diff_rows' => $auditLog->getDiffRows(),
        ]);
    }

    /**
     * Apply PostgreSQL native JSONB containment (@>) filter using GIN indexes (with SQLite fallback for tests).
     */
    private function applyJsonbContainmentFilter(Builder $query, string $rawFilter): void
    {
        $parsedPairs = [];

        if (str_starts_with($rawFilter, '{')) {
            $decoded = json_decode($rawFilter, true);
            if (is_array($decoded)) {
                $parsedPairs = $decoded;
            }
        } elseif (str_contains($rawFilter, ':')) {
            [$rawKey, $rawVal] = explode(':', $rawFilter, 2);
            $key = trim($rawKey);
            $val = trim($rawVal);
            if ($key !== '') {
                $parsedPairs[$key] = $this->castFilterValue($val);
            }
        }

        if ($parsedPairs === []) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $payload = json_encode($parsedPairs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($payload)) {
                $query->whereRaw(
                    '(new_values @> ?::jsonb OR old_values @> ?::jsonb OR metadata @> ?::jsonb)',
                    [$payload, $payload, $payload]
                );
            }

            return;
        }

        // Portable JSON fallback for SQLite test runner
        $query->where(function (Builder $sub) use ($parsedPairs): void {
            foreach ($parsedPairs as $key => $value) {
                $safeKey = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $key);
                if ($safeKey === '') {
                    continue;
                }
                $jsonPath = '$.'.$safeKey;
                $compareVal = is_bool($value) ? ($value ? 1 : 0) : $value;

                if ($compareVal === null) {
                    $sub->orWhereRaw("json_type(new_values, ?) = 'null'", [$jsonPath])
                        ->orWhereRaw("json_type(old_values, ?) = 'null'", [$jsonPath])
                        ->orWhereRaw("json_type(metadata, ?) = 'null'", [$jsonPath]);
                } else {
                    $sub->orWhereRaw('json_extract(new_values, ?) = ?', [$jsonPath, $compareVal])
                        ->orWhereRaw('json_extract(old_values, ?) = ?', [$jsonPath, $compareVal])
                        ->orWhereRaw('json_extract(metadata, ?) = ?', [$jsonPath, $compareVal]);
                }
            }
        });
    }

    /**
     * Search free-text keywords across description, actor_name, old_values, and new_values.
     */
    private function applyKeywordFilter(Builder $query, string $keyword): void
    {
        $like = '%'.$keyword.'%';
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $query->where(function (Builder $sub) use ($like): void {
                $sub->where('description', 'ilike', $like)
                    ->orWhere('actor_name', 'ilike', $like)
                    ->orWhereRaw('COALESCE(old_values::text, \'\') ilike ?', [$like])
                    ->orWhereRaw('COALESCE(new_values::text, \'\') ilike ?', [$like]);
            });

            return;
        }

        $query->where(function (Builder $sub) use ($like): void {
            $sub->where('description', 'like', $like)
                ->orWhere('actor_name', 'like', $like)
                ->orWhere('old_values', 'like', $like)
                ->orWhere('new_values', 'like', $like);
        });
    }

    private function castFilterValue(string $rawValue): mixed
    {
        $lower = strtolower($rawValue);
        if ($lower === 'true') {
            return true;
        }
        if ($lower === 'false') {
            return false;
        }
        if ($lower === 'null') {
            return null;
        }
        if (is_numeric($rawValue)) {
            return str_contains($rawValue, '.') ? (float) $rawValue : (int) $rawValue;
        }

        return $rawValue;
    }
}
