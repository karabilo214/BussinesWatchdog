<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One stage of the plugin connection funnel (ADR 0084); never holds customer data, codes or addresses. */
class PluginFunnelEvent extends Model
{
    use HasUuids;

    public const STAGE_CLICK = 'click';

    public const STAGE_SIGNUP_VIEW = 'signup_view';

    public const STAGE_SIGNUP = 'signup';

    public const STAGE_CODE = 'code';

    public const STAGE_CONNECTED = 'connected';

    public const STAGES = [self::STAGE_CLICK, self::STAGE_SIGNUP_VIEW, self::STAGE_SIGNUP, self::STAGE_CODE, self::STAGE_CONNECTED];

    public const PLACEMENTS = ['plugins_card', 'plugins_list', 'plugin_page', 'plugin_description', 'wordpress_org'];

    public const PATH_ONBOARDING = 'onboarding';

    public const PATH_CONNECT = 'connect';

    public $timestamps = false;

    protected $fillable = ['stage', 'journey', 'placement', 'path', 'locale', 'tenant_id', 'store_id', 'dedupe_key', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
