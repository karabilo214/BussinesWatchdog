<?php

namespace App\Support\Platform;

use App\Models\PlatformStaff;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Turns stored audit metadata into a safe, localized view at read time. */
class AuditPresentation
{
    /** @var array<string, array<string, mixed>> */
    private array $catalogs = [];

    /** @var array<string, array<string, mixed>> */
    private array $valueCatalogs = [];

    /** @var array<string, array{label: string, href: ?string}> */
    private array $objects = [];

    private const OBJECTS = [
        'tenant' => ['tenants', 'name', PlatformCapabilities::TENANTS_READ, '/owner/tenants/%s'],
        'store' => ['stores', 'name', PlatformCapabilities::TENANTS_READ, '/owner/tenants/%s/stores/%s'],
        'contact_request' => ['contact_requests', 'email', PlatformCapabilities::CONTACTS_READ, '/owner/contact-requests/%s'],
        'staff' => ['platform_staff', 'user_id', PlatformCapabilities::STAFF_MANAGE, '/owner/staff'],
        'public_page' => ['public_pages', 'path', PlatformCapabilities::CONTENT_MANAGE, '/owner/site/pages/%s'],
        'page_block' => ['page_blocks', 'name', PlatformCapabilities::CONTENT_MANAGE, '/owner/site/blocks/%s'],
        'billing_plan' => ['billing_plans', 'code', PlatformCapabilities::PLANS_READ, '/owner/plans'],
        'billing_plan_version' => ['billing_plan_versions', 'version', PlatformCapabilities::PLANS_READ, '/owner/plans/versions/%s'],
        'billing_discount' => ['billing_discounts', 'name', PlatformCapabilities::PLANS_READ, '/owner/discounts'],
        'support_grant' => ['support_access_grants', 'case_reference', PlatformCapabilities::TENANTS_READ, '/owner/tenants/%s?tab=support'],
        'user' => ['users', 'name', PlatformCapabilities::TENANTS_READ, '/owner/tenants/%s?tab=members'],
        'invitation' => ['invitations', 'email', PlatformCapabilities::TENANTS_READ, '/owner/tenants/%s?tab=members'],
        'staff_invitation' => ['platform_staff_invitations', 'email', PlatformCapabilities::STAFF_MANAGE, '/owner/staff'],
        'site_media' => ['site_media', 'original_name', PlatformCapabilities::CONTENT_MANAGE, '/owner/site'],
        'maintenance_notice' => ['maintenance_notices', 'version', PlatformCapabilities::SERVICE_READ, '/owner/settings'],
        'domain_outbox' => ['domain_outbox', 'topic', PlatformCapabilities::SERVICE_READ, '/owner/service'],
        'billing_refund' => ['billing_refunds', 'currency', PlatformCapabilities::BILLING_READ, '/owner/payments'],
    ];

    private const WORDS = [
        'ru' => ['deleted' => 'Удалён', 'restricted' => 'Доступ ограничен', 'object' => 'Объект', 'organization' => 'Организация', 'store' => 'Магазин', 'to' => 'Кому', 'template' => 'Шаблон', 'scopes' => 'Области', 'hours' => 'Срок, ч', 'reason' => 'Причина', 'changed' => 'изменено на', 'status' => 'Статус', 'role' => 'Роль', 'state' => 'Состояние', 'access' => 'Доступ', 'menu' => 'Меню', 'sort' => 'Порядок', 'name' => 'Название', 'category' => 'Категория', 'trial_days' => 'Пробный период, дней', 'usage_limit' => 'Лимит использований', 'starts_at' => 'Начало', 'expires_at' => 'Окончание', 'capabilities' => 'Разрешения', 'entitlements' => 'Возможности тарифа', 'localized_content' => 'Тексты', 'body' => 'Текст', 'subject' => 'Тема', 'plan_ids' => 'Тарифы', 'tenant_ids' => 'Организации', 'topic' => 'Тема', 'subscription' => 'Подписка', 'payment' => 'Оплата', 'other' => 'Другое', 'locale' => 'Язык', 'version' => 'Версия', 'previous_version' => 'Прежняя версия', 'platform' => 'Платформа', 'base_url' => 'Адрес магазина', 'scope' => 'Область', 'duration_hours' => 'Срок, ч', 'currency' => 'Валюта', 'amount_minor' => 'Сумма в малых единицах', 'interval' => 'Интервал', 'interval_count' => 'Число интервалов', 'kind' => 'Вид', 'audience' => 'Аудитория', 'purge_after' => 'Удаление после', 'cancelled' => 'Отменено', 'requested' => 'Запрошено', 'rejected' => 'Отклонено', 'submitting' => 'Отправляется', 'refunded' => 'Возвращено', 'declined' => 'Отклонено провайдером', 'failed' => 'Ошибка', 'unknown' => 'Неизвестно', 'draft' => 'Черновик', 'published' => 'Опубликовано', 'retired' => 'Снято с продажи', 'dead_letter' => 'Ошибка доставки', 'leased' => 'В обработке', 'staff_hidden_at' => 'Скрыто сотрудником', 'mfa_enabled' => 'MFA', 'revoked_at' => 'Отозвано', 'archived_at' => 'Удалено', 'deleted_at' => 'Удалено', 'cancelled_at' => 'Отменено', 'policy_type' => 'Тип документа', 'title' => 'Заголовок', 'description' => 'Описание', 'deleting' => 'Удаляется', 'paused_manual' => 'Приостановлено вручную', 'open' => 'Открыто', 'acknowledged' => 'Принято', 'resolved' => 'Закрыто', 'disabled' => 'Отключён', 'admin' => 'Администратор', 'operator' => 'Оператор', 'viewer' => 'Наблюдатель', 'owner' => 'Владелец', 'none' => 'Без меню', 'header' => 'Верхнее меню', 'footer' => 'Нижнее меню', 'service_terms' => 'Условия сервиса', 'privacy_notice' => 'Политика конфиденциальности', 'recurring_payment' => 'Условия подписки', 'answer' => 'Ответ', 'more_details' => 'Запрос деталей', 'resolved' => 'Решено', 'bodies' => 'Содержимое', 'locales' => 'Языки', 'label' => 'Подпись', 'percent_bps' => 'Скидка, базисных пунктов', 'duration' => 'Длительность', 'duration_periods' => 'Периодов', 'tax_behavior' => 'Налоги', 'contact_sales' => 'Связаться с продажами', 'order_volume_grace_days' => 'Льготный срок, дней', 'faster_check_interval_minutes' => 'Частые проверки, минут', 'trial_ends_at' => 'Пробный период до', 'attempts' => 'Попытки', 'next_attempt_at' => 'Следующая попытка', 'lease_until' => 'Аренда до', 'published_at' => 'Отправлено', 'error_code' => 'Код ошибки', 'handled_by' => 'Ответственный', 'read_diagnostics' => 'Диагностика', 'read_artifacts' => 'Материалы', 'request_recheck' => 'Повторная проверка', 'new' => 'Новое', 'in_progress' => 'В работе', 'closed' => 'Закрыто', 'active' => 'Активно', 'suspended' => 'Приостановлено', 'pending' => 'Ожидает', 'approved' => 'Одобрено', 'denied' => 'Отклонено'],
        'en' => ['deleted' => 'Deleted', 'restricted' => 'Access restricted', 'object' => 'Object', 'organization' => 'Organization', 'store' => 'Store', 'to' => 'To', 'template' => 'Template', 'scopes' => 'Scopes', 'hours' => 'Duration, hours', 'reason' => 'Reason', 'changed' => 'changed to', 'status' => 'Status', 'role' => 'Role', 'state' => 'State', 'access' => 'Access', 'menu' => 'Menu', 'sort' => 'Order', 'name' => 'Name', 'category' => 'Category', 'trial_days' => 'Trial days', 'usage_limit' => 'Usage limit', 'starts_at' => 'Starts', 'expires_at' => 'Expires', 'capabilities' => 'Permissions', 'entitlements' => 'Plan features', 'localized_content' => 'Content', 'body' => 'Body', 'subject' => 'Subject', 'plan_ids' => 'Plans', 'tenant_ids' => 'Organizations', 'topic' => 'Topic', 'subscription' => 'Subscription', 'payment' => 'Payment', 'other' => 'Other', 'locale' => 'Language', 'version' => 'Version', 'previous_version' => 'Previous version', 'platform' => 'Platform', 'base_url' => 'Store address', 'scope' => 'Scope', 'duration_hours' => 'Duration, hours', 'currency' => 'Currency', 'amount_minor' => 'Amount in minor units', 'interval' => 'Interval', 'interval_count' => 'Interval count', 'kind' => 'Type', 'audience' => 'Audience', 'purge_after' => 'Delete after', 'cancelled' => 'Cancelled', 'requested' => 'Requested', 'rejected' => 'Rejected', 'submitting' => 'Submitting', 'refunded' => 'Refunded', 'declined' => 'Declined by provider', 'failed' => 'Failed', 'unknown' => 'Unknown', 'draft' => 'Draft', 'published' => 'Published', 'retired' => 'Retired', 'dead_letter' => 'Delivery failed', 'leased' => 'Processing', 'staff_hidden_at' => 'Hidden by staff', 'mfa_enabled' => 'MFA', 'revoked_at' => 'Revoked', 'archived_at' => 'Archived', 'deleted_at' => 'Deleted', 'cancelled_at' => 'Cancelled', 'policy_type' => 'Document type', 'title' => 'Title', 'description' => 'Description', 'deleting' => 'Deleting', 'paused_manual' => 'Manually suspended', 'open' => 'Open', 'acknowledged' => 'Acknowledged', 'resolved' => 'Resolved', 'disabled' => 'Disabled', 'admin' => 'Administrator', 'operator' => 'Operator', 'viewer' => 'Viewer', 'owner' => 'Owner', 'none' => 'No menu', 'header' => 'Header', 'footer' => 'Footer', 'service_terms' => 'Service terms', 'privacy_notice' => 'Privacy notice', 'recurring_payment' => 'Subscription terms', 'answer' => 'Reply', 'more_details' => 'Request details', 'resolved' => 'Resolved', 'bodies' => 'Content', 'locales' => 'Languages', 'label' => 'Label', 'percent_bps' => 'Discount, basis points', 'duration' => 'Duration', 'duration_periods' => 'Periods', 'tax_behavior' => 'Tax handling', 'contact_sales' => 'Contact sales', 'order_volume_grace_days' => 'Grace days', 'faster_check_interval_minutes' => 'Faster checks, minutes', 'trial_ends_at' => 'Trial ends', 'attempts' => 'Attempts', 'next_attempt_at' => 'Next attempt', 'lease_until' => 'Lease until', 'published_at' => 'Published at', 'error_code' => 'Error code', 'handled_by' => 'Assigned staff', 'read_diagnostics' => 'Diagnostics', 'read_artifacts' => 'Artifacts', 'request_recheck' => 'Recheck', 'new' => 'New', 'in_progress' => 'In progress', 'closed' => 'Closed', 'active' => 'Active', 'suspended' => 'Suspended', 'pending' => 'Pending', 'approved' => 'Approved', 'denied' => 'Denied'],
        'de' => ['deleted' => 'Gelöscht', 'restricted' => 'Zugriff eingeschränkt', 'object' => 'Objekt', 'organization' => 'Organisation', 'store' => 'Shop', 'to' => 'An', 'template' => 'Vorlage', 'scopes' => 'Bereiche', 'hours' => 'Dauer, Stunden', 'reason' => 'Grund', 'changed' => 'geändert zu', 'status' => 'Status', 'role' => 'Rolle', 'state' => 'Zustand', 'access' => 'Zugriff', 'menu' => 'Menü', 'sort' => 'Reihenfolge', 'name' => 'Name', 'category' => 'Kategorie', 'trial_days' => 'Testtage', 'usage_limit' => 'Nutzungslimit', 'starts_at' => 'Beginn', 'expires_at' => 'Ende', 'capabilities' => 'Berechtigungen', 'entitlements' => 'Tariffunktionen', 'localized_content' => 'Texte', 'body' => 'Text', 'subject' => 'Betreff', 'plan_ids' => 'Tarife', 'tenant_ids' => 'Organisationen', 'topic' => 'Thema', 'subscription' => 'Abonnement', 'payment' => 'Zahlung', 'other' => 'Sonstiges', 'locale' => 'Sprache', 'version' => 'Version', 'previous_version' => 'Vorherige Version', 'platform' => 'Plattform', 'base_url' => 'Shop-Adresse', 'scope' => 'Bereich', 'duration_hours' => 'Dauer, Stunden', 'currency' => 'Währung', 'amount_minor' => 'Betrag in kleinsten Einheiten', 'interval' => 'Intervall', 'interval_count' => 'Anzahl Intervalle', 'kind' => 'Art', 'audience' => 'Zielgruppe', 'purge_after' => 'Löschen nach', 'cancelled' => 'Storniert', 'requested' => 'Angefragt', 'rejected' => 'Abgelehnt', 'submitting' => 'Wird gesendet', 'refunded' => 'Erstattet', 'declined' => 'Vom Anbieter abgelehnt', 'failed' => 'Fehler', 'unknown' => 'Unbekannt', 'draft' => 'Entwurf', 'published' => 'Veröffentlicht', 'retired' => 'Eingestellt', 'dead_letter' => 'Zustellung fehlgeschlagen', 'leased' => 'In Bearbeitung', 'staff_hidden_at' => 'Vom Mitarbeiter ausgeblendet', 'mfa_enabled' => 'MFA', 'revoked_at' => 'Widerrufen', 'archived_at' => 'Archiviert', 'deleted_at' => 'Gelöscht', 'cancelled_at' => 'Storniert', 'policy_type' => 'Dokumenttyp', 'title' => 'Titel', 'description' => 'Beschreibung', 'deleting' => 'Wird gelöscht', 'paused_manual' => 'Manuell ausgesetzt', 'open' => 'Offen', 'acknowledged' => 'Bestätigt', 'resolved' => 'Behoben', 'disabled' => 'Deaktiviert', 'admin' => 'Administrator', 'operator' => 'Operator', 'viewer' => 'Betrachter', 'owner' => 'Inhaber', 'none' => 'Kein Menü', 'header' => 'Kopfzeile', 'footer' => 'Fußzeile', 'service_terms' => 'Nutzungsbedingungen', 'privacy_notice' => 'Datenschutzerklärung', 'recurring_payment' => 'Abonnementbedingungen', 'answer' => 'Antwort', 'more_details' => 'Details anfordern', 'resolved' => 'Gelöst', 'bodies' => 'Inhalt', 'locales' => 'Sprachen', 'label' => 'Bezeichnung', 'percent_bps' => 'Rabatt, Basispunkte', 'duration' => 'Dauer', 'duration_periods' => 'Zeiträume', 'tax_behavior' => 'Steuerbehandlung', 'contact_sales' => 'Vertrieb kontaktieren', 'order_volume_grace_days' => 'Kulanzfrist, Tage', 'faster_check_interval_minutes' => 'Häufige Prüfungen, Minuten', 'trial_ends_at' => 'Testphase endet', 'attempts' => 'Versuche', 'next_attempt_at' => 'Nächster Versuch', 'lease_until' => 'Lease bis', 'published_at' => 'Veröffentlicht um', 'error_code' => 'Fehlercode', 'handled_by' => 'Zuständiger Mitarbeiter', 'read_diagnostics' => 'Diagnose', 'read_artifacts' => 'Materialien', 'request_recheck' => 'Erneute Prüfung', 'new' => 'Neu', 'in_progress' => 'In Bearbeitung', 'closed' => 'Geschlossen', 'active' => 'Aktiv', 'suspended' => 'Ausgesetzt', 'pending' => 'Ausstehend', 'approved' => 'Genehmigt', 'denied' => 'Abgelehnt'],
    ];

    /** @return array{title: string, description: string, result_label: string, object: array{label: string, href: ?string}, details: list<string>, links: list<array{label: string, href: string}>} */
    public function present(object $row, PlatformStaff $viewer, string $locale): array
    {
        $locale = in_array($locale, ['ru', 'en', 'de'], true) ? $locale : 'en';
        $changes = json_decode((string) $row->sanitized_changes, true);
        $changes = is_array($changes) ? $changes : [];
        $catalog = $this->catalogs[$locale] ??= json_decode((string) file_get_contents(base_path('lang/audit/'.$locale.'.json')), true);
        $title = data_get($catalog, $row->action);
        $title = is_string($title) ? $title : ['ru' => 'Другое действие', 'en' => 'Other action', 'de' => 'Andere Aktion'][$locale];
        $object = $this->object((string) $row->target_type, (string) $row->target_id, $row->target_tenant_id, $viewer, $locale);
        if ($row->action === 'support_access.opened' && is_string($changes['store_id'] ?? null) && PlatformCapabilities::allows($viewer, PlatformCapabilities::TENANTS_READ)) {
            $store = $this->object('store', $changes['store_id'], $row->target_tenant_id, $viewer, $locale);
            $object = ['label' => $store['label'], 'href' => $store['href']];
        }
        $details = [];
        $links = [];

        if ($row->action === 'site.contact.replied' && Str::isUuid((string) $row->target_id) && PlatformCapabilities::allows($viewer, PlatformCapabilities::CONTACTS_READ)) {
            $contact = DB::table('contact_requests')->where('id', $row->target_id)->first(['email']);
            if ($contact !== null) {
                $details[] = $this->word($locale, 'to').': '.$contact->email;
            }
            if (isset($changes['template']) && is_string($changes['template'])) {
                $details[] = $this->word($locale, 'template').': '.$this->word($locale, $changes['template']);
            }
        }

        if ($row->target_type === 'support_grant' && Str::isUuid((string) $row->target_id) && PlatformCapabilities::allows($viewer, PlatformCapabilities::TENANTS_READ)) {
            $grant = DB::table('support_access_grants')->where('id', $row->target_id)->first(['store_id', 'tenant_id', 'scopes', 'duration_hours']);
            if ($grant !== null) {
                $store = $this->object('store', $grant->store_id, $grant->tenant_id, $viewer, $locale);
                $details[] = $this->word($locale, 'store').': '.$store['label'];
                if ($store['href'] !== null) {
                    $links[] = ['label' => $store['label'], 'href' => $store['href']];
                }
                $scopes = json_decode((string) $grant->scopes, true);
                if (is_array($scopes)) {
                    $details[] = $this->word($locale, 'scopes').': '.implode(', ', array_map(fn ($scope) => $this->word($locale, (string) $scope), $scopes));
                }
                $details[] = $this->word($locale, 'hours').': '.$grant->duration_hours;
            }
        }

        if ($row->action === 'tenant.reminder_sent' && PlatformCapabilities::allows($viewer, PlatformCapabilities::TENANTS_READ)) {
            if (is_string($changes['topic'] ?? null)) {
                $details[] = $this->word($locale, 'topic').': '.$this->word($locale, $changes['topic']);
            }
            if (is_array($changes['recipient_user_ids'] ?? null)) {
                $ids = array_values(array_filter($changes['recipient_user_ids'], fn ($id) => is_string($id) && Str::isUuid($id)));
                $emails = DB::table('users')->whereIn('id', $ids)->orderBy('email')->pluck('email')->all();
                $details[] = $this->word($locale, 'to').': '.implode(', ', $emails);
            } elseif (is_array($changes['recipients'] ?? null)) {
                $details[] = $this->word($locale, 'to').': '.count($changes['recipients']);
            }
        }

        if ($row->target_tenant_id !== null && PlatformCapabilities::allows($viewer, PlatformCapabilities::TENANTS_READ)) {
            $tenant = $this->object('tenant', $row->target_tenant_id, $row->target_tenant_id, $viewer, $locale);
            $details[] = $this->word($locale, 'organization').': '.$tenant['label'];
            if ($tenant['href'] !== null && $row->target_type !== 'tenant') {
                $links[] = ['label' => $tenant['label'], 'href' => $tenant['href']];
            }
        }

        if ($object['label'] !== $this->word($locale, 'restricted')) {
            $details = [...$details, ...$this->changes($changes, $locale, $viewer, (string) $row->result)];
            foreach (['locale', 'version', 'previous_version', 'platform', 'base_url', 'scope', 'duration_hours', 'currency', 'amount_minor', 'interval', 'interval_count', 'kind', 'audience', 'purge_after'] as $field) {
                if ($field === 'purge_after' && $row->action !== 'tenant.deletion_requested') {
                    continue;
                }
                if (array_key_exists($field, $changes)) {
                    $value = $this->value($changes[$field], $locale);
                    if ($value !== null) {
                        $details[] = $this->word($locale, $field).': '.$value;
                    }
                }
            }
        }
        if ($object['label'] !== $this->word($locale, 'restricted') && $row->reason !== null && $row->reason !== '') {
            $details[] = $this->word($locale, 'reason').': '.$row->reason;
        }

        $description = $title.' · '.$object['label'];
        if ($details !== []) {
            $description .= ' · '.implode(' · ', $details);
        }

        $resultLabels = [
            'ru' => ['requested' => 'Запрошено', 'succeeded' => 'Успешно', 'failed' => 'Ошибка', 'denied' => 'Отказано', 'unknown' => 'Неизвестно'],
            'en' => ['requested' => 'Requested', 'succeeded' => 'Succeeded', 'failed' => 'Failed', 'denied' => 'Denied', 'unknown' => 'Unknown'],
            'de' => ['requested' => 'Angefragt', 'succeeded' => 'Erfolgreich', 'failed' => 'Fehler', 'denied' => 'Abgelehnt', 'unknown' => 'Unbekannt'],
        ];

        return ['title' => $title, 'description' => $description, 'result_label' => $resultLabels[$locale][$row->result] ?? $resultLabels[$locale]['unknown'], 'object' => $object, 'details' => $details, 'links' => $links];
    }

    /** @return array{label: string, href: ?string} */
    private function object(string $type, string $id, ?string $tenantId, PlatformStaff $viewer, string $locale): array
    {
        $key = implode('|', [$type, $id, $tenantId ?? '', $locale, $viewer->id ?? '', implode(',', PlatformCapabilities::forStaff($viewer))]);

        return $this->objects[$key] ??= $this->lookupObject($type, $id, $tenantId, $viewer, $locale);
    }

    /** @return array{label: string, href: ?string} */
    private function lookupObject(string $type, string $id, ?string $tenantId, PlatformStaff $viewer, string $locale): array
    {
        if (in_array($type, ['service', 'route', 'staff_login', 'platform_audit_log'], true)) {
            $labels = [
                'ru' => ['service' => 'Сервис', 'route' => 'Админка', 'staff_login' => 'Вход в админку', 'platform_audit_log' => 'Журнал аудита'],
                'en' => ['service' => 'Service', 'route' => 'Admin panel', 'staff_login' => 'Admin sign-in', 'platform_audit_log' => 'Audit journal'],
                'de' => ['service' => 'Service', 'route' => 'Adminbereich', 'staff_login' => 'Admin-Anmeldung', 'platform_audit_log' => 'Auditprotokoll'],
            ];

            return ['label' => $labels[$locale][$type], 'href' => null];
        }
        if (in_array($type, ['check_run', 'incident'], true)) {
            if (! PlatformCapabilities::allows($viewer, PlatformCapabilities::TENANTS_READ)) {
                return ['label' => $this->word($locale, 'restricted'), 'href' => null];
            }
            if (! Str::isUuid($id)) {
                return ['label' => $this->word($locale, 'object'), 'href' => null];
            }
            $item = DB::table($type === 'check_run' ? 'check_runs' : 'incidents')->where('id', $id)->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId))->first(['tenant_id', 'store_id']);
            if ($item === null) {
                return ['label' => $this->word($locale, 'deleted'), 'href' => null];
            }
            $store = $this->object('store', $item->store_id, $item->tenant_id, $viewer, $locale);
            $kind = $type === 'check_run' ? ['ru' => 'Проверка', 'en' => 'Check', 'de' => 'Prüfung'][$locale] : ['ru' => 'Инцидент', 'en' => 'Incident', 'de' => 'Vorfall'][$locale];
            $label = $kind.' · '.$store['label'];

            return ['label' => $label, 'href' => $store['href'] === null ? null : $store['href'].'?tab='.($type === 'check_run' ? 'checks' : 'incidents')];
        }
        if (in_array($type, ['reminder_template', 'contact_reply_template'], true)) {
            $capability = $type === 'reminder_template' ? PlatformCapabilities::TENANTS_READ : PlatformCapabilities::CONTACTS_READ;
            if (! PlatformCapabilities::allows($viewer, $capability)) {
                return ['label' => $this->word($locale, 'restricted'), 'href' => null];
            }
            [$topic, $language] = array_pad(explode(':', $id, 2), 2, '');
            $table = $type === 'contact_reply_template' ? 'contact_reply_templates' : 'reminder_templates';
            $column = $type === 'contact_reply_template' ? 'code' : 'topic';
            if (! DB::table($table)->where($column, $topic)->where('locale', $language)->exists()) {
                return ['label' => $this->word($locale, 'deleted'), 'href' => null];
            }

            return ['label' => $this->word($locale, $topic).' ('.$language.')', 'href' => $type === 'contact_reply_template' ? '/owner/contacts' : '/owner/settings'];
        }
        if ($type === 'billing_price') {
            if (! PlatformCapabilities::allows($viewer, PlatformCapabilities::PLANS_READ)) {
                return ['label' => $this->word($locale, 'restricted'), 'href' => null];
            }
            if (! Str::isUuid($id)) {
                return ['label' => $this->word($locale, 'object'), 'href' => null];
            }
            $price = DB::table('billing_prices')->where('id', $id)->first(['plan_version_id', 'currency', 'billing_interval']);
            if ($price === null) {
                return ['label' => $this->word($locale, 'deleted'), 'href' => null];
            }
            $version = DB::table('billing_plan_versions')->where('id', $price->plan_version_id)->first(['plan_id', 'version']);
            $code = $version === null ? null : DB::table('billing_plans')->where('id', $version->plan_id)->value('code');
            $label = ($code ?? $this->word($locale, 'deleted')).' v'.($version->version ?? '?').' · '.$price->currency.' · '.$price->billing_interval;

            return ['label' => $label, 'href' => '/owner/plans/versions/'.$price->plan_version_id];
        }
        $definition = self::OBJECTS[$type] ?? null;
        if ($definition === null) {
            return ['label' => $this->word($locale, 'object'), 'href' => null];
        }
        [$table, $column, $capability, $pattern] = $definition;
        if (! PlatformCapabilities::allows($viewer, $capability)) {
            return ['label' => $this->word($locale, 'restricted'), 'href' => null];
        }
        if (! Str::isUuid($id)) {
            return ['label' => $type === 'billing_discount' && $id !== '' ? Str::limit($id, 100) : $this->word($locale, 'object'), 'href' => null];
        }
        $query = DB::table($table)->where('id', $id);
        if ($tenantId !== null && in_array($type, ['store', 'support_grant', 'invitation', 'billing_refund'], true)) {
            $query->where('tenant_id', $tenantId);
        }
        $item = $query->first();
        if ($item === null) {
            return ['label' => $this->word($locale, 'deleted'), 'href' => null];
        }
        if ((in_array($type, ['tenant', 'store'], true) && $item->status === 'deleted') || (in_array($type, ['public_page', 'page_block', 'billing_discount'], true) && $item->archived_at !== null) || ($type === 'site_media' && $item->deleted_at !== null)) {
            return ['label' => $this->word($locale, 'deleted'), 'href' => null];
        }
        $label = (string) $item->{$column};
        if ($type === 'staff') {
            $label = (string) (DB::table('users')->where('id', $item->user_id)->value('name') ?? $this->word($locale, 'deleted'));
        }
        if ($type === 'contact_request') {
            $label = (['ru' => 'Обращение', 'en' => 'Contact request', 'de' => 'Kontaktanfrage'][$locale]).' · '.$item->name;
        }
        if ($type === 'public_page') {
            $label = '/'.ltrim($label, '/');
        }
        if ($type === 'billing_plan_version') {
            $label = 'v'.$label;
        }
        if ($type === 'maintenance_notice') {
            $label = 'v'.$label;
        }
        if ($type === 'billing_refund') {
            $invoice = DB::table('billing_transactions')->where('id', $item->transaction_id)->value('invoice_number');
            $label = (['ru' => 'Возврат', 'en' => 'Refund', 'de' => 'Erstattung'][$locale]).($invoice === null ? '' : ' · '.$invoice);
        }
        $href = $type === 'store' ? sprintf($pattern, $item->tenant_id, $id) : sprintf($pattern, in_array($type, ['support_grant', 'invitation', 'user'], true) ? $tenantId ?? $id : $id);

        return ['label' => $label, 'href' => $href];
    }

    /** @param array<string, mixed> $changes
     * @return list<string>
     */
    private function changes(array $changes, string $locale, PlatformStaff $viewer, string $result): array
    {
        if (is_scalar($changes['before'] ?? null) && is_scalar($changes['after'] ?? null)) {
            $label = ['ru' => 'Текст', 'en' => 'Text', 'de' => 'Text'][$locale];

            return [$label.': '.Str::limit((string) $changes['before'], 200).' → '.Str::limit((string) $changes['after'], 200)];
        }
        $before = is_array($changes['before'] ?? null) ? $changes['before'] : [];
        $after = is_array($changes['after'] ?? null) ? $changes['after'] : [];
        foreach ($changes as $key => $value) {
            if (str_ends_with((string) $key, '_before')) {
                $before[substr((string) $key, 0, -7)] = $value;
            } elseif (str_ends_with((string) $key, '_after')) {
                $after[substr((string) $key, 0, -6)] = $value;
            }
        }
        if ($result === PlatformAudit::RESULT_SUCCEEDED) {
            foreach (['status', 'state', 'role', 'access', 'menu', 'sort', 'policy_type', 'trial_days', 'name', 'category', 'usage_limit', 'starts_at', 'expires_at'] as $field) {
                if (array_key_exists($field, $changes) && ! array_key_exists($field, $after)) {
                    $after[$field] = $changes[$field];
                }
            }
        }
        $lines = [];
        foreach ($after as $field => $value) {
            if ($field === 'handled_by') {
                $name = function (mixed $staffId) use ($locale): string {
                    if ($staffId === null) {
                        return '—';
                    }
                    if (! is_string($staffId) || ! Str::isUuid($staffId)) {
                        return $this->word($locale, 'deleted');
                    }

                    return (string) (DB::table('platform_staff')->leftJoin('users', 'users.id', '=', 'platform_staff.user_id')->where('platform_staff.id', $staffId)->value('users.name') ?? $this->word($locale, 'deleted'));
                };
                $oldName = $name($before[$field] ?? null);
                $newName = $name($value);
                if ($oldName !== $newName) {
                    $lines[] = $this->word($locale, 'handled_by').': '.$oldName.' → '.$newName;
                }

                continue;
            }
            if (in_array($field, ['plan_ids', 'tenant_ids'], true)) {
                $capability = $field === 'plan_ids' ? PlatformCapabilities::PLANS_READ : PlatformCapabilities::TENANTS_READ;
                if (! PlatformCapabilities::allows($viewer, $capability)) {
                    continue;
                }
                $table = $field === 'plan_ids' ? 'billing_plans' : 'tenants';
                $column = $field === 'plan_ids' ? 'code' : 'name';
                $names = fn ($ids) => is_array($ids) ? implode(', ', DB::table($table)->whereIn('id', array_values(array_filter($ids, fn ($id) => is_string($id) && Str::isUuid($id))))->orderBy($column)->pluck($column)->all()) : '';
                $newNames = $names($value);
                $oldNames = $names($before[$field] ?? null);
                $lines[] = $this->word($locale, $field).': '.$oldNames.' → '.$newNames;

                continue;
            }
            if (preg_match('/(^|_)(id|hash|token|secret|password|key)(_|$)/', (string) $field) === 1 || $field === 'content_hash') {
                continue;
            }
            if ($field === 'body' && is_string($value)) {
                $value = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5));
            }
            $new = $this->value($value, $locale);
            if ($new === null) {
                continue;
            }
            $label = $this->word($locale, $field);
            $oldValue = $before[$field] ?? null;
            if ($field === 'body' && is_string($oldValue)) {
                $oldValue = trim(html_entity_decode(strip_tags($oldValue), ENT_QUOTES | ENT_HTML5));
            }
            $old = array_key_exists($field, $before) ? $this->value($oldValue, $locale) : null;
            if ($old !== null && $old === $new) {
                continue;
            }
            $lines[] = $old !== null ? $label.': '.$old.' → '.$new : match ($locale) {
                'ru' => 'Поле «'.$label.'» изменено на «'.$new.'»',
                'de' => $label.' geändert zu „'.$new.'“',
                default => $label.' changed to “'.$new.'”',
            };
        }

        return $lines;
    }

    private function value(mixed $value, string $locale): ?string
    {
        if (is_bool($value)) {
            return $value ? ['ru' => 'Да', 'en' => 'Yes', 'de' => 'Ja'][$locale] : ['ru' => 'Нет', 'en' => 'No', 'de' => 'Nein'][$locale];
        }
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $key => $item) {
                $readable = is_string($key) && $item === null && data_get($this->valueCatalog($locale), 'limit.'.$key) !== null
                    ? ['ru' => 'Без ограничений', 'en' => 'Unlimited', 'de' => 'Unbegrenzt'][$locale]
                    : $this->value($item, $locale);
                if ($readable !== null) {
                    $parts[] = is_string($key) ? $this->word($locale, $key).': '.$readable : $readable;
                }
            }

            return $parts === [] ? '—' : Str::limit(implode('; ', $parts), 400);
        }
        if ($value === null || $value === '') {
            return '—';
        }
        if (! is_scalar($value)) {
            return null;
        }
        $text = (string) $value;
        if (Str::isUuid($text) || preg_match('/^[a-f0-9]{64}$/i', $text) === 1 || str_starts_with($text, '{')) {
            return null;
        }
        if (preg_match('/<[^>]+>/', $text) === 1) {
            $text = trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5));
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $text) === 1) {
            try {
                return Carbon::parse($text)->locale($locale)->timezone('UTC')->translatedFormat('d M Y H:i').' UTC';
            } catch (Throwable) {
                return null;
            }
        }

        $catalog = $this->valueCatalog($locale);
        $translated = data_get($catalog, 'role.'.$text);
        if (! is_string($translated)) {
            $translated = data_get($catalog, 'capability.'.str_replace('.', '_', $text).'.label');
        }
        if (! is_string($translated)) {
            $translated = data_get($catalog, 'feature.'.$text);
        }

        return Str::limit(self::WORDS[$locale][$text] ?? (is_string($translated) ? $translated : $text), 200);
    }

    private function word(string $locale, string $key): string
    {
        $catalog = $this->valueCatalog($locale);

        return self::WORDS[$locale][$key] ?? data_get($catalog, 'limit.'.$key) ?? data_get($catalog, 'feature.'.$key) ?? Str::headline(str_replace('_', ' ', $key));
    }

    /** @return array<string, mixed> */
    private function valueCatalog(string $locale): array
    {
        return $this->valueCatalogs[$locale] ??= json_decode((string) file_get_contents(base_path('lang/audit_values/'.$locale.'.json')), true);
    }
}
