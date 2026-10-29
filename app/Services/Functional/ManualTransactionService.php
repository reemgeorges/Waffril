<?php

namespace App\Services\Functional;

use App\Models\Expense;
use App\Models\FinancialCategory;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

class ManualTransactionService
{
    protected FinancialService $financialService;

    public function __construct(FinancialService $financialService)
    {
        $this->financialService = $financialService;
    }

    /**
     * Create manual income transaction
     */
    public function createManualIncome(array $data): FinancialTransaction
    {
        return DB::transaction(function () use ($data) {
            return $this->financialService->createTransaction([
                'financial_account_id' => $data['financial_account_id'],
                'financial_category_id' => $data['financial_category_id'] ?? null,
                'currency_id' => $data['currency_id'] ?? null,
                'type' => 'income',
                'amount' => $data['amount'],
                'transaction_date' => $data['transaction_date'] ?? now(),
                'payment_method' => $data['payment_method'] ?? 'cash',
                'reference_number' => $data['reference_number'] ?? null,
                'description' => $data['description'] ?? 'Manual income entry',
                'created_by' => $data['created_by'] ?? auth()->id(),
            ]);
        });
    }

    /**
     * Create manual expense transaction
     */
    public function createManualExpense(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $expense = Expense::create([
                'expense_number' => $this->generateExpenseNumber(),
                'financial_account_id' => $data['financial_account_id'],
                'financial_category_id' => $data['financial_category_id'] ?? null,
                'currency_id' => $data['currency_id'] ?? null,
                'amount' => $data['amount'],
                'expense_date' => $data['expense_date'] ?? now(),
                'payment_method' => $data['payment_method'] ?? 'cash',
                'supplier_name' => $data['supplier_name'] ?? null,
                'reference_number' => $data['reference_number'] ?? null,
                'description' => $data['description'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'] ?? auth()->id(),
            ]);

            $transaction = $this->financialService->createTransaction([
                'financial_account_id' => $data['financial_account_id'],
                'financial_category_id' => $data['financial_category_id'] ?? null,
                'currency_id' => $data['currency_id'] ?? null,
                'type' => 'expense',
                'source_type' => Expense::class,
                'source_id' => $expense->id,
                'amount' => $data['amount'],
                'transaction_date' => $data['expense_date'] ?? now(),
                'payment_method' => $data['payment_method'] ?? 'cash',
                'reference_number' => $data['reference_number'] ?? null,
                'description' => $data['description'] ?? 'Manual expense entry',
                'created_by' => $data['created_by'] ?? auth()->id(),
            ]);

            return [
                'expense' => $expense,
                'transaction' => $transaction,
            ];
        });
    }

    /**
     * Create manual service fee transaction

    public function createServiceFee(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $financialAccountId = $data['financial_account_id'] ?? $this->financialService->getDefaultAccountId();
            $financialCategoryId = $data['financial_category_id'] ?? $this->getServiceCategoryId();

            $transaction = $this->financialService->createTransaction([
                'financial_account_id' => $financialAccountId,
                'financial_category_id' => $financialCategoryId,
                'type' => 'income',
                'amount' => $data['amount'],
                'transaction_date' => $data['transaction_date'] ?? now(),
                'payment_method' => $data['payment_method'] ?? 'cash',
                'reference_number' => $data['reference_number'] ?? null,
                'description' => $data['description'] ?? 'Service fee',
                'created_by' => $data['created_by'] ?? auth()->id(),
            ]);

            $invoice = null;

            if (!empty($data['user_id'])) {
                $invoice = $this->financialService->createInvoice([
                    'user_id' => $data['user_id'],
                    'invoice_type' => 'other', // because invoices enum does not contain "service"
                    'subtotal' => $data['amount'],
                    'discount' => 0,
                    'total' => $data['amount'],
                    'paid' => 0,
                    'due' => $data['amount'],
                    'status' => 'pending',
                    'notes' => $data['description'] ?? 'Service fee',
                    'items' => [
                        [
                            'item_type' => 'service',
                            'item_id' => null,
                            'item_name' => $data['service_name'] ?? 'Service Fee',
                            'qty' => 1,
                            'unit_price' => $data['amount'],
                            'total_price' => $data['amount'],
                        ]
                    ],
                ]);

                $this->financialService->createPayment([
                    'invoice_id' => $invoice->id,
                    'payable_type' => Invoice::class,
                    'payable_id' => $invoice->id,
                    'financial_account_id' => $financialAccountId,
                    'financial_category_id' => $financialCategoryId,
                    'amount' => $data['amount'],
                    'paid_at' => $data['transaction_date'] ?? now(),
                    'payment_method' => $data['payment_method'] ?? 'cash',
                    'reference_number' => $data['reference_number'] ?? null,
                    'notes' => $data['description'] ?? 'Service fee payment',
                ]);

                $invoice->refresh();
            }

            return [
                'transaction' => $transaction,
                'invoice' => $invoice,
            ];
        });
    }
    */
    /**
     * Get manual transactions with filters
     */
    public function getManualTransactions(array $filters = []): array
    {
        $query = FinancialTransaction::with(['account', 'category', 'source', 'creator', 'currency'])
            ->where(function ($q) {
                $q->whereNull('source_type')
                    ->orWhere('source_type', Expense::class);
            });

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (!empty($filters['start_date'])) {
            $query->where('transaction_date', '>=', $filters['start_date']);
        }
        if (!empty($filters['end_date'])) {
            $query->where('transaction_date', '<=', $filters['end_date']);
        }
        if (!empty($filters['financial_account_id'])) {
            $query->where('financial_account_id', $filters['financial_account_id']);
        }
        if (!empty($filters['financial_category_id'])) {
            $query->where('financial_category_id', $filters['financial_category_id']);
        }
        if (!empty($filters['currency_id'])) {
            $query->where('currency_id', $filters['currency_id']);
        }

        $transactions = $query->orderBy('transaction_date', 'desc')->get();

        $grouped = $transactions->groupBy('currency_id')->map(function ($items) {
            $first = $items->first();
            $currency = $first?->currency;

            return [
                'currency_id' => $first?->currency_id,
                'currency' => [
                    'id' => $currency?->id,
                    'code' => $currency?->code,
                    'name' => $currency?->name,
                    'symbol' => $currency?->symbol,
                    'decimals' => $currency?->decimals,
                ],
                'transactions' => $items->values(),
                'summary' => [
                    'total_income' => $items->where('type', 'income')->sum('amount'),
                    'total_expense' => $items->where('type', 'expense')->sum('amount'),
                    'count' => $items->count(),
                ],
            ];
        })->values();

        return [
            'by_currency' => $grouped,
            'total_transactions' => $transactions->count(),
        ];
    }

    /**
     * Generate unique expense number
     */
    protected function generateExpenseNumber(): string
    {
        $prefix = 'EXP';
        $date = now()->format('Ymd');

        $last = Expense::where('expense_number', 'like', "{$prefix}{$date}%")
            ->orderBy('expense_number', 'desc')
            ->first();

        if ($last) {
            $lastNumber = (int) substr($last->expense_number, -4);
            $newNumber = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '0001';
        }

        return "{$prefix}{$date}{$newNumber}";
    }

    /**
     * Get service category
     */
    protected function getServiceCategoryId(): ?int
    {
        $category = FinancialCategory::where('type', 'income')
            ->where('name', 'like', '%service%')
            ->first();

        return $category?->id;
    }
}
