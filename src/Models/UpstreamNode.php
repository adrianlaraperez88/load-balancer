<?php

namespace Isg\LoadBalancer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class UpstreamNode extends Model
{
    protected $fillable = [
        'name',
        'url',
        'weight',
        'is_active',
        'is_primary',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_primary' => 'boolean',
        'weight' => 'integer',
    ];

    protected static function booted()
    {
        static::saved(function () {
            Cache::forget('isg_load_balancer_active_nodes');
        });

        static::deleted(function () {
            Cache::forget('isg_load_balancer_active_nodes');
        });
    }

    public function ipRules(): HasMany
    {
        return $this->hasMany(IpRule::class);
    }

    public function requestMetrics(): HasMany
    {
        return $this->hasMany(RequestMetric::class);
    }
}
