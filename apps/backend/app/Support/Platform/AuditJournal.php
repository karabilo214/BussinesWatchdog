<?php

namespace App\Support\Platform;

use App\Models\PlatformStaff;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AuditJournal
{
    public function __construct(private readonly AuditPresentation $presentation) {}

    public const PAGE = 50;

    public const EXPORT_LIMIT = 10000;

    /**
     * @param  array<string, string>  $filters
     * @return array{data: list<array<string, mixed>>, next_cursor: ?string, actions: list<string>, staff: list<array{id: string, name: ?string}>}
     */
    public function page(array $filters, PlatformStaff $viewer, string $locale): array
    {
        $rows = $this->query($filters)->when(isset($filters['cursor']), fn (Builder $query) => $query->where('platform_audit_log.id', '<', $filters['cursor']))
            ->orderByDesc('platform_audit_log.id')->limit(self::PAGE + 1)->get();
        $page = $rows->take(self::PAGE);

        return [
            'data' => $page->map(fn (object $row): array => $this->row($row, $viewer, $locale))->values()->all(),
            'next_cursor' => $rows->count() > self::PAGE ? (string) $page->last()->id : null,
            'actions' => DB::table('platform_audit_log')->distinct()->orderBy('action')->pluck('action')->all(),
            'staff' => DB::table('platform_staff')->leftJoin('users', 'users.id', '=', 'platform_staff.user_id')->orderBy('users.name')->get(['platform_staff.id', 'users.name'])
                ->map(fn (object $member): array => ['id' => (string) $member->id, 'name' => $member->name ?? match ($locale) {
                    'ru' => 'Удалён', 'de' => 'Gelöscht', default => 'Deleted'
                }])->values()->all(),
        ];
    }

    /**
     * @param  array<string, string>  $filters
     * @return iterable<array<string, mixed>>
     */
    public function export(array $filters, PlatformStaff $viewer, string $locale): iterable
    {
        foreach ($this->query($filters)->orderByDesc('platform_audit_log.id')->limit(self::EXPORT_LIMIT)->cursor() as $row) {
            yield $this->row($row, $viewer, $locale);
        }
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function query(array $filters): Builder
    {
        return DB::table('platform_audit_log')
            ->leftJoin('platform_staff', 'platform_staff.id', '=', 'platform_audit_log.actor_staff_id')
            ->leftJoin('users', 'users.id', '=', 'platform_staff.user_id')
            ->leftJoin('tenants', 'tenants.id', '=', 'platform_audit_log.target_tenant_id')
            ->when(isset($filters['actor']), fn (Builder $query) => $filters['actor'] === 'system' ? $query->whereNull('platform_audit_log.actor_staff_id') : $query->where('platform_audit_log.actor_staff_id', $filters['actor']))
            ->when(isset($filters['action']), fn (Builder $query) => $query->where('platform_audit_log.action', $filters['action']))
            ->when(isset($filters['target_type']), fn (Builder $query) => $query->where('platform_audit_log.target_type', $filters['target_type']))
            ->when(isset($filters['target_id']), fn (Builder $query) => $query->where('platform_audit_log.target_id', $filters['target_id']))
            ->when(isset($filters['tenant']), fn (Builder $query) => $query->where('platform_audit_log.target_tenant_id', $filters['tenant']))
            ->when(isset($filters['result']), fn (Builder $query) => $query->where('platform_audit_log.result', $filters['result']))
            ->when(isset($filters['from']), fn (Builder $query) => $query->where('platform_audit_log.created_at', '>=', Carbon::createFromFormat('Y-m-d', $filters['from'], 'UTC')->startOfDay()))
            ->when(isset($filters['to']), fn (Builder $query) => $query->where('platform_audit_log.created_at', '<', Carbon::createFromFormat('Y-m-d', $filters['to'], 'UTC')->startOfDay()->addDay()))
            ->select(['platform_audit_log.*', 'users.name as actor_name', 'tenants.name as tenant_name', 'tenants.status as tenant_status']);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(object $row, PlatformStaff $viewer, string $locale): array
    {
        return [
            'id' => (string) $row->id,
            'created_at' => Carbon::parse($row->created_at)->toJSON(),
            'actor' => $row->actor_staff_id === null ? null : ['id' => (string) $row->actor_staff_id, 'name' => $row->actor_name ?? match ($locale) {
                'ru' => 'Удалён', 'de' => 'Gelöscht', default => 'Deleted'
            }],
            'actor_kind' => $row->actor_kind,
            'action' => $row->action,
            'target_type' => $row->target_type,
            'target_id' => $row->target_id,
            'tenant' => $row->target_tenant_id === null || ! PlatformCapabilities::allows($viewer, PlatformCapabilities::TENANTS_READ) ? null : ['id' => (string) $row->target_tenant_id, 'name' => $row->tenant_status === 'deleted' || $row->tenant_name === null ? match ($locale) {
                'ru' => 'Удалён',
                'de' => 'Gelöscht',
                default => 'Deleted',
            } : $row->tenant_name],
            'result' => $row->result,
            'presentation' => $this->presentation->present($row, $viewer, $locale),
        ];
    }
}
