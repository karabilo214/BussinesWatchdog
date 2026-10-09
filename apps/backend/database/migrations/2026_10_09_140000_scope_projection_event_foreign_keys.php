<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE order_revisions DROP CONSTRAINT order_revisions_event_id_foreign');
        DB::statement('ALTER TABLE order_revisions ADD CONSTRAINT order_revisions_scoped_event_foreign FOREIGN KEY (tenant_id, store_id, event_id) REFERENCES event_inbox(tenant_id, store_id, id) ON DELETE SET NULL (event_id)');

        DB::statement('ALTER TABLE financial_transactions DROP CONSTRAINT financial_transactions_source_event_id_foreign');
        DB::statement('ALTER TABLE financial_transactions ADD CONSTRAINT financial_transactions_scoped_event_foreign FOREIGN KEY (tenant_id, store_id, source_event_id) REFERENCES event_inbox(tenant_id, store_id, id) ON DELETE SET NULL (source_event_id)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE order_revisions DROP CONSTRAINT order_revisions_scoped_event_foreign');
        DB::statement('ALTER TABLE order_revisions ADD CONSTRAINT order_revisions_event_id_foreign FOREIGN KEY (event_id) REFERENCES event_inbox(id) ON DELETE SET NULL');

        DB::statement('ALTER TABLE financial_transactions DROP CONSTRAINT financial_transactions_scoped_event_foreign');
        DB::statement('ALTER TABLE financial_transactions ADD CONSTRAINT financial_transactions_source_event_id_foreign FOREIGN KEY (source_event_id) REFERENCES event_inbox(id) ON DELETE SET NULL');
    }
};
