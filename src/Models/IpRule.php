<?php

namespace Isg\LoadBalancer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class IpRule extends Model
{
    protected $fillable = [
        'ip_address',
        'upstream_node_id',
    ];

    protected static function booted()
    {
        static::saved(function () {
            Cache::forget('isg_load_balancer_ip_rules');
        });

        static::deleted(function () {
            Cache::forget('isg_load_balancer_ip_rules');
        });
    }

    public function upstreamNode(): BelongsTo
    {
        return $this->belongsTo(UpstreamNode::class);
    }
}
