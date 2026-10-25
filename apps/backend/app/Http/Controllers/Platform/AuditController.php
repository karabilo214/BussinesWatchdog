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
        /** @var PlatformStaff $staff */
        $staff = $request->attributes->get('platform_staff');

        return response()->json($this->journal->page($this->filters($request, true), $staff, $staff->user?->locale ?? 'en'));
    }

    public function export(Request $request, PlatformAudit $audit): StreamedResponse
    {
        $filters = $this->filters($request, false);
        /** @var PlatformStaff $staff */
        $staff = $request->attributes->get('platform_staff');
        $audit->record($staff->id, 'audit.exported', 'platform_audit_log', 'export', PlatformAudit::RESULT_SUCCEEDED, ['filters' => $filters]);
        $locale = $staff->user?->locale ?? 'en';
        $rows = $this->journal->export($filters, $staff, $locale);

        return response()->streamDownload(function () use ($rows, $locale): void {
            $out = fopen('php://output', 'w');
            $headers = [
                'ru' => ['Время', 'Кто', 'Действие', 'Объект', 'Организация', 'Результат', 'Описание'],
                'en' => ['Time', 'Actor', 'Action', 'Object', 'Organization', 'Result', 'Description'],
                'de' => ['Zeit', 'Person', 'Aktion', 'Objekt', 'Organisation', 'Ergebnis', 'Beschreibung'],
            ];
            fputcsv($out, $headers[$locale] ?? $headers['en'], escape: '');

            foreach ($rows as $row) {
                fputcsv($out, array_map(fn (mixed $value): string => $this->cell($value), [
                    $row['created_at'], $row['actor']['name'] ?? match ($locale) {
                        'ru' => 'Система', 'de' => 'System', default => 'System'
                    }, $row['presentation']['title'], $row['presentation']['object']['label'],
                    $row['tenant']['name'] ?? '', $row['presentation']['result_label'], $row['presentation']['description'],
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
