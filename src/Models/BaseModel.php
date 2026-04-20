<?php

namespace Isg\LoadBalancer\Models;

use Illuminate\Database\Eloquent\Model;

abstract class BaseModel extends Model
{
    /**
     * Get the current connection name for the model dynamically from the configuration.
     *
     * @return string|null
     */
    public function getConnectionName()
    {
        return config('load-balancer.database_connection') ?? parent::getConnectionName();
    }
}
