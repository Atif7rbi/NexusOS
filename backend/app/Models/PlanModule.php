<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanModule extends Model
{
    use HasUlids;

    protected $table = 'plan_modules';

    protected $fillable = [
        'plan_id',
        'module_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    public function scopeForPlan(Builder $query, string $planId): Builder
    {
        return $query->where('plan_id', $planId);
    }

    public function scopeForModule(Builder $query, string $moduleId): Builder
    {
        return $query->where('module_id', $moduleId);
    }
}
