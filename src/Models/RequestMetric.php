<?php

namespace Isg\LoadBalancer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequestMetric extends Model
{
    const UPDATED_AT = null; // We only need created_at for metrics

    protected $fillable = [
        'incoming_ip',
        'upstream_node_id',
        'method',
        'uri',
        'status_code',
        'response_time_ms',
    ];

    protected $casts = [
        'status_code' => 'integer',
        'response_time_ms' => 'integer',
    ];

    public function upstreamNode(): BelongsTo
    {
        return $this->belongsTo(UpstreamNode::class);
    }
}
