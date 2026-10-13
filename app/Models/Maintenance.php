<?php

namespace App\Models;

use App\Enums\MaintenanceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Maintenance extends Model
{
    // "maintenance" is uncountable, so Laravel's pluralizer won't add an "s".
    // The migration also creates the table as "maintenance".
    protected $table = 'maintenance';

    protected $fillable = [
        'room_id',
        'reported_by',
        'assigned_to',
        'title',
        'description',
        'priority',
        'status',
        'resolution_notes',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'status'      => MaintenanceStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
