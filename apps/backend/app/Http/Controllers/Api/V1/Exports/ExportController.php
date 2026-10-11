<?php

namespace App\Http\Controllers\Api\V1\Exports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Exports\CreateExportRequest;
use App\Models\ExportFile;
use App\Models\Store;
use App\Support\Exports\CsvExportService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function store(CreateExportRequest $request, Store $store, TenantContext $tenantContext, CsvExportService $exports): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('create export');

        abort_unless($store->tenant_id === $tenantId, 404);

        $validated = $request->validated();
        $export = $exports->create(
            $store,
            $tenantId,
            $request->user()?->id,
            $validated['kind'],
            $validated['format'],
            $validated['filters'] ?? [],
        );

        return response()->json($this->dto($export), 202);
    }

    public function show(ExportFile $export, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('read export');

        abort_unless($export->tenant_id === $tenantId, 404);

        return response()->json($this->dto($export));
    }

    public function download(ExportFile $export, TenantContext $tenantContext, CsvExportService $exports): StreamedResponse
    {
        $tenantId = $tenantContext->requireTenantId('download export');

        abort_unless($export->tenant_id === $tenantId, 404);
        abort_unless($this->isDownloadable($export), 404);

        return response()->streamDownload(
            fn () => print $exports->body($export),
            sprintf('business-watchdog-%s-%s.csv', $export->kind, $export->created_at->format('Ymd-His')),
            [
                'Content-Type' => $export->content_type ?? 'text/csv; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function dto(ExportFile $export): array
    {
        return [
            'id' => $export->id,
            'store_id' => $export->store_id,
            'kind' => $export->kind,
            'format' => $export->format,
            'filters' => $export->filters,
            'state' => $export->state,
            'content_type' => $export->content_type,
            'size_bytes' => $export->size_bytes,
            'sha256' => $export->sha256,
            'error_code' => $export->error_code,
            'download_url' => $this->isDownloadable($export) ? url("/api/v1/exports/{$export->id}/download") : null,
            'expires_at' => $export->expires_at?->toJSON(),
            'created_at' => $export->created_at?->toJSON(),
            'completed_at' => $export->completed_at?->toJSON(),
        ];
    }

    private function isDownloadable(ExportFile $export): bool
    {
        return $export->state === ExportFile::STATE_READY
            && $export->object_key !== null
            && ($export->expires_at === null || $export->expires_at->isFuture());
    }
}
