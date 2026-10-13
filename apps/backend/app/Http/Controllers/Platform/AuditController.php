<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformStaff;
use App\Support\Platform\AuditJournal;
use App\Support\Platform\PlatformAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditController extends Controller
{
    public function __construct(private readonly AuditJournal $journal) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->journal->page($this->filters($request, true)));
    }

    public function export(Request $request, PlatformAudit $audit): StreamedResponse
    {
        $filters = $this->filters($request, false);
        /** @var PlatformStaff $staff */
        $staff = $request->attributes->get('platform_staff');
        $audit->record($staff->id, 'audit.exported', 'platform_audit_log', 'export', PlatformAudit::RESULT_SUCCEEDED, ['filters' => $filters]);
        $rows = $this->journal->export($filters);

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['time', 'actor', 'actor_email', 'action', 'target_type', 'target_id', 'tenant', 'result', 'reason', 'changes', 'request_id'], escape: '');

            foreach ($rows as $row) {
                fputcsv($out, array_map(fn (mixed $value): string => $this->cell($value), [
                    $row['created_at'], $row['actor']['name'] ?? 'system', $row['actor']['email'] ?? '', $row['action'], $row['target_type'], $row['target_id'],
                    $row['tenant']['name'] ?? '', $row['result'], $row['reason'] ?? '', json_encode($row['changes'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $row['request_id'],
                ]), escape: '');
            }

            fclose($out);
        }, 'platform-audit-'.Carbon::now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /**
     * @return array<string, string>
     */
    private function filters(Request $request, bool $paged): array
    {
        $validated = $request->validate([
            'actor' => ['sometimes', 'string', 'max:64'],
            'action' => ['sometimes', 'string', 'max:100'],
            'target_type' => ['sometimes', 'string', 'max:64'],
            'target_id' => ['sometimes', 'string', 'max:128'],
            'tenant' => ['sometimes', 'uuid'],
            'result' => ['sometimes', 'string', 'in:requested,succeeded,failed,denied,unknown'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
        ] + ($paged ? ['cursor' => ['sometimes', 'uuid']] : []));

        return array_filter($validated, fn (mixed $value): bool => is_string($value) && $value !== '');
    }

    private function cell(mixed $value): string
    {
        $text = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $text) === 1 ? "'".$text : $text;
    }
}
