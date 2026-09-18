<?php

namespace App\Models;

use App\Traits\HasBranch;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class ExpensesRecurring extends BaseModel
{
    use HasBranch;

    protected $table = 'expenses_recurring';

    protected $guarded = ['id'];

    protected $casts = [
        'issue_date' => 'date',
        'next_expense_date' => 'date',
        'amount' => 'decimal:2',
        'unlimited_recurring' => 'boolean',
        'immediate_expense' => 'boolean',
        'day_of_month' => 'integer',
        'day_of_week' => 'integer',
        'billing_cycle' => 'integer',
    ];

    public const ROTATIONS = [
        'daily',
        'monthly',
        'weekly',
        'bi_weekly',
        'quarterly',
        'yearly',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expenses::class, 'expenses_recurring_id');
    }

    /**
     * Next occurrence after $from (typically issue_date or last generated date).
     */
    public function computeNextExpenseDate(CarbonInterface $from): Carbon
    {
        $date = Carbon::parse($from);

        return match ($this->rotation) {
            'daily' => $date->copy()->addDay(),
            'weekly' => $date->copy()->addWeek(),
            'bi_weekly' => $date->copy()->addWeeks(2),
            'quarterly' => $date->copy()->addMonths(3),
            'yearly' => $date->copy()->addYear(),
            default => $date->copy()->addMonth(),
        };
    }
}
