<?php

namespace App\Console\Commands;

use App\Models\Expenses;
use App\Models\ExpensesRecurring;
use App\Models\GlobalSubscription;
use App\Scopes\BranchScope;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ProcessRecurringExpenses extends Command
{
    protected $signature = 'app:process-recurring-expenses';

    protected $description = 'Create expenses from due recurring expense templates and advance next_expense_date.';

    public function handle(): int
    {
        $today = Carbon::today()->toDateString();

        $totalDue = ExpensesRecurring::withoutGlobalScope(BranchScope::class)
            ->where('status', 'active')
            ->whereNotNull('next_expense_date')
            ->whereDate('next_expense_date', '<=', $today)
            ->count();

        if ($totalDue === 0) {
            $this->info('No recurring expense templates due for processing.');
        } else {
            $this->info("Found {$totalDue} recurring template(s) with next_expense_date on or before {$today}.");

            $created = 0;
            $skipped = 0;
            $advancedOnly = 0;
            $deactivated = 0;

            ExpensesRecurring::withoutGlobalScope(BranchScope::class)
                ->where('status', 'active')
                ->whereNotNull('next_expense_date')
                ->whereDate('next_expense_date', '<=', $today)
                ->orderBy('id')
                ->chunkById(50, function ($recurrings) use (&$created, &$skipped, &$advancedOnly, &$deactivated, $today) {
                    foreach ($recurrings as $recurring) {
                        DB::transaction(function () use ($recurring, $today, &$created, &$skipped, &$advancedOnly, &$deactivated) {
                            /** @var \App\Models\ExpensesRecurring|null $locked */
                            $locked = ExpensesRecurring::withoutGlobalScope(BranchScope::class)
                                ->whereKey($recurring->id)
                                ->lockForUpdate()
                                ->first();

                            if (!$locked || $locked->status !== 'active' || !$locked->next_expense_date) {
                                $skipped++;

                                return;
                            }

                            $maxCatchUp = 366;
                            $iterations = 0;

                            while (
                                $locked->status === 'active'
                                && $locked->next_expense_date
                                && $locked->next_expense_date->toDateString() <= $today
                                && $iterations < $maxCatchUp
                            ) {
                                $iterations++;

                                if (!$this->canGenerateAnother($locked)) {
                                    $locked->status = 'inactive';
                                    $locked->next_expense_date = null;
                                    $locked->saveQuietly();
                                    $deactivated++;

                                    break;
                                }

                                $dueDate = $locked->next_expense_date->toDateString();

                                $alreadyExists = Expenses::withoutGlobalScope(BranchScope::class)
                                    ->where('expenses_recurring_id', $locked->id)
                                    ->whereDate('expense_date', $dueDate)
                                    ->exists();

                                if ($alreadyExists) {
                                    $locked->next_expense_date = $locked->computeNextExpenseDate($locked->next_expense_date);
                                    $locked->saveQuietly();
                                    $advancedOnly++;
                                    $locked->refresh();

                                    continue;
                                }

                                Expenses::withoutGlobalScope(BranchScope::class)->create([
                                    'expense_title' => $locked->item_name,
                                    'expense_category_id' => $locked->expense_category_id,
                                    'amount' => $locked->amount,
                                    'expense_date' => $dueDate,
                                    'payment_status' => 'pending',
                                    'payment_date' => $dueDate,
                                    'payment_due_date' => $dueDate,
                                    'payment_method' => $locked->payment_method,
                                    'description' => $locked->description,
                                    'expenses_recurring_id' => $locked->id,
                                    'branch_id' => $locked->branch_id,
                                ]);

                                $locked->next_expense_date = $locked->computeNextExpenseDate(Carbon::parse($dueDate));
                                $locked->saveQuietly();
                                $created++;
                                $locked->refresh();

                                if (!$locked->unlimited_recurring && $locked->billing_cycle) {
                                    $generated = Expenses::withoutGlobalScope(BranchScope::class)
                                        ->where('expenses_recurring_id', $locked->id)
                                        ->count();

                                    if ($generated >= (int) $locked->billing_cycle) {
                                        $locked->status = 'inactive';
                                        $locked->next_expense_date = null;
                                        $locked->saveQuietly();
                                        $deactivated++;

                                        break;
                                    }
                                }
                            }

                            if ($iterations >= $maxCatchUp) {
                                $this->warn("Stopped catch-up for recurring id {$locked->id} after {$maxCatchUp} iterations (safety cap).");
                            }
                        });
                    }
                });

            $this->info("Created {$created} expense(s), advanced next date only {$advancedOnly} time(s), deactivated {$deactivated} template(s), skipped {$skipped}.");
        }

        $this->processSubscriptionEndExpenses($today);

        return Command::SUCCESS;
    }

    private function processSubscriptionEndExpenses(string $today): void
    {
        $subs = GlobalSubscription::with(['restaurant.branches', 'package'])
            ->where('subscription_status', 'active')
            ->whereNotNull('ends_at')
            ->whereDate('ends_at', '=', $today)
            ->get();

        if ($subs->isEmpty()) {
            $this->info("No global subscriptions ending on {$today}.");
            return;
        }

        $created = 0;
        $skipped = 0;

        foreach ($subs as $sub) {
            $restaurant = $sub->restaurant;
            $branchId = $restaurant?->branches?->sortBy('id')?->first()?->id;

            if (!$restaurant || !$branchId) {
                $skipped++;
                continue;
            }

            $amount = null;
            if ($sub->package) {
                $amount = $sub->package_type === 'annual'
                    ? ($sub->package->annual_price ?? null)
                    : ($sub->package->monthly_price ?? null);
            }

            $amount = is_numeric($amount) ? (float) $amount : null;

            if (!$amount || $amount <= 0) {
                $skipped++;
                continue;
            }

            $marker = "global_subscription_id:{$sub->id}";

            $exists = Expenses::withoutGlobalScope(BranchScope::class)
                ->where('branch_id', $branchId)
                ->whereDate('expense_date', $today)
                ->where('description', 'like', '%' . $marker . '%')
                ->exists();

            if ($exists) {
                $skipped++;
                continue;
            }

            $title = 'Subscription renewal';
            if (!empty($sub->name)) {
                $title .= ' - ' . $sub->name;
            }

            $desc = trim(
                ($sub->description ? $sub->description . "\n" : '') .
                "Auto-generated from global subscription end date.\n" .
                $marker .
                "\nends_at: " . ($sub->ends_at?->toDateTimeString() ?? $today)
            );

            Expenses::withoutGlobalScope(BranchScope::class)->create([
                'expense_title' => $title,
                'expense_category_id' => null,
                'amount' => $amount,
                'expense_date' => $today,
                'payment_status' => 'pending',
                'payment_date' => $today,
                'payment_due_date' => $today,
                'payment_method' => null,
                'description' => $desc,
                'expenses_recurring_id' => null,
                'branch_id' => $branchId,
            ]);

            $created++;
        }

        $this->info("Global subscription end-date expenses: created {$created}, skipped {$skipped}.");
    }

    private function canGenerateAnother(ExpensesRecurring $recurring): bool
    {
        if ($recurring->unlimited_recurring) {
            return true;
        }

        if (!$recurring->billing_cycle || (int) $recurring->billing_cycle < 1) {
            return true;
        }

        $currentCount = Expenses::withoutGlobalScope(BranchScope::class)
            ->where('expenses_recurring_id', $recurring->id)
            ->count();

        return $currentCount < (int) $recurring->billing_cycle;
    }
}
