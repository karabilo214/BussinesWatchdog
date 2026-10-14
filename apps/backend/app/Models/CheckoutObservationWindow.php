<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Counters of the checkout observation script and render beacon of one store for one 5-minute window (ADR 0057).
 * Nothing about a visitor is kept: no IP, no user agent, no identifiers.
 */
class CheckoutObservationWindow extends Model
{
    use HasUuids;

    public const COUNTERS = ['confirm_rendered', 'confirm_rendered_noscript', 'finish_rendered', 'script_loaded', 'form_ready', 'returned_with_error', 'submit_clicked', 'submit_stalled', 'finish_reached', 'js_errors'];

    public const STAGES = ['script_loaded', 'form_ready', 'returned_with_error', 'submit_clicked', 'submit_stalled', 'finish_reached'];

    public $timestamps = false;

    protected $fillable = ['tenant_id', 'store_id', 'integration_id', 'window_start', ...self::COUNTERS, 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return ['window_start' => 'datetime', 'created_at' => 'datetime', 'updated_at' => 'datetime', ...array_fill_keys(self::COUNTERS, 'integer')];
    }
}
