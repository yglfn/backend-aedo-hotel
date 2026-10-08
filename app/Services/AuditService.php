<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditService
{
    public static function log(
        string $action,
        ?Model $model = null,
        array $oldValues = [],
        array $newValues = [],
        ?Request $request = null
    ): void {
        $userId = auth()->id();
        $ip = $request?->ip() ?? request()->ip();

        AuditLog::create([
            'user_id'        => $userId,
            'action'         => $action,
            'auditable_type' => $model ? get_class($model) : null,
            'auditable_id'   => $model?->getKey(),
            'old_values'     => $oldValues ?: null,
            'new_values'     => $newValues ?: null,
            'ip_address'     => $ip,
            'created_at'     => now(),
        ]);
    }
}
