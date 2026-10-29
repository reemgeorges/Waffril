<?php

namespace App\Services\Functional;

use App\Models\FinancialTransaction;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\Currency;
use Illuminate\Support\Collection;

class FinancialService
{
    /**
     * Create a financial transaction
     */

    public const PAYMENT_METHODS = [
        'cash',
        'card',
        'bank_transfer',
        'wallet',
        'check',
        'online',
        'other',
    ];
    public function createTransaction(array $data): FinancialTransaction
    {
        return DB::transaction(function () use ($data) {
            $paymentMethod = $data['payment_method'] ?? 'cash';
            if (!in_array($paymentMethod, self::PAYMENT_METHODS, true)) {
                $paymentMethod = 'cash';
            }

            $currencyId = $this->resolveCurrencyId($data);

            $transaction = FinancialTransaction::create([
                'transaction_number' => $this->generateTransactionNumber(),
                'financial_account_id' => $data['financial_account_id'],
                'financial_category_id' => $data['financial_category_id'] ?? null,
                'currency_id' => $currencyId,
                'type' => $data['type'],
                'source_type' => $data['source_type'] ?? null,
                'source_id' => $data['source_id'] ?? null,
                'amount' => $data['amount'],
                'transaction_date' => $data['transaction_date'] ?? now(),
                'payment_method' => $paymentMethod,
                'reference_number' => $data['reference_number'] ?? null,
                'description' => $data['description'] ?? null,
                'created_by' => $data['created_by'] ?? auth()->id(),
            ]);

            $this->updateAccountBalance($transaction);

            return $transaction;
        });
    }

    /**
     * Create an invoice with items
     */
    public function createInvoice(array $data): Invoice
    {
        return DB::transaction(function () use ($data) {
            $paid = (float) ($data['paid'] ?? 0);
            $total = (float) $data['total'];
            $due = isset($data['due']) ? (float) $data['due'] : max(0, $total - $paid);
            $currencyId = $this->resolveCurrencyId($data);

            $invoice = Invoice::create([
                'invoice_number' => $this->generateInvoiceNumber(),
                'user_id' => $data['user_id'] ?? null,
                'currency_id' => $currencyId,
                'invoice_type' => $data['invoice_type'],
                'subtotal' => $data['subtotal'],
                'discount' => $data['discount'] ?? 0,
                'total' => $total,
                'paid' => $paid,
                'due' => $due,
                'status' => $data['status'] ?? ($due <= 0 ? 'paid' : ($paid > 0 ? 'partially_paid' : 'pending')),
                'notes' => $data['notes'] ?? null,
            ]);

            if (!empty($data['items'])) {
                foreach ($data['items'] as $item) {
                    InvoiceItem::create([
                        'invoice_id' => $invoice->id,
                        'item_type' => $item['item_type'],
                        'item_id' => $item['item_id'] ?? null,
                        'item_name' => $item['item_name'],
                        'qty' => $item['qty'],
                        'unit_price' => $item['unit_price'],
                        'total_price' => $item['total_price'],
                        'notes' => $item['notes'] ?? null,
                    ]);
                }
            }

            return $invoice;
        });
    }


    /**
     * Create a payment record
     */
   public function createPayment(array $data): Payment
    {
        return DB::transaction(function () use ($data) {
            $paymentMethod = in_array($data['payment_method'] ?? 'cash', self::PAYMENT_METHODS, true)
                ? $data['payment_method']
                : 'cash';

            $currencyId = $this->resolveCurrencyId($data);

            $invoiceId = $data['invoice_id'] ?? (
                ($data['payable_type'] ?? null) === Invoice::class ? $data['payable_id'] : null
            );

            $payment = Payment::create([
                'invoice_id' => $invoiceId,
                'payable_type' => $data['payable_type'],
                'payable_id' => $data['payable_id'],
                'financial_account_id' => $data['financial_account_id'],
                'currency_id' => $currencyId,
                'amount' => (float) $data['amount'],
                'paid_at' => $data['paid_at'] ?? now(),
                'payment_method' => $paymentMethod,
                'reference_number' => $data['reference_number'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'] ?? auth()->id(),
            ]);

            $transaction = $payment->transaction()->create([
                'transaction_number' => $this->generateTransactionNumber(),
                'financial_account_id' => $data['financial_account_id'],
                'financial_category_id' => $data['financial_category_id'] ?? null,
                'currency_id' => $currencyId,
                'type' => 'income',
                'amount' => (float) $data['amount'],
                'transaction_date' => $data['paid_at'] ?? now(),
                'payment_method' => $paymentMethod,
                'reference_number' => $data['reference_number'] ?? null,
                'description' => $data['notes'] ?? 'Payment transaction',
                'created_by' => $data['created_by'] ?? auth()->id(),
            ]);

            $this->updateAccountBalance($transaction);

            if ($invoiceId) {
                $invoice = Invoice::with('payments')->findOrFail($invoiceId);
                $totalPaid = (float) $invoice->payments()->sum('amount');

                $invoice->paid = $totalPaid;
                $invoice->due = max(0, $invoice->total - $totalPaid);
                $invoice->status = $invoice->due <= 0
                    ? 'paid'
                    : ($totalPaid > 0 ? 'partially_paid' : 'pending');
                $invoice->save();
            }

            return $payment->load('transaction');
        });
    }


    /**
     * Create complete subscription financial records
     */
    public function createSubscriptionFinancials(Subscription $subscription, array $data): array
    {
        return DB::transaction(function () use ($subscription, $data) {
           $financialAccountId = $data['financial_account_id'] ?? throw new \InvalidArgumentException('financial_account_id is required.');
            $financialCategoryId = $data['financial_category_id'] ?? $this->getSubscriptionCategoryId();

            $currencyId = $data['currency_id']
                ?? $subscription->currency_id
                ?? $subscription->package?->currency_id
                ?? $this->getDefaultCurrencyId();

            $invoice = $this->createInvoice([
                'user_id' => $subscription->user_id,
                'currency_id' => $currencyId,
                'invoice_type' => 'subscription',
                'subtotal' => $subscription->total_amount,
                'discount' => $data['discount'] ?? 0,
                'total' => $subscription->total_amount,
                'paid' => 0,
                'due' => $subscription->total_amount,
                'status' => 'pending',
                'notes' => 'Subscription: ' . ($subscription->package->name ?? 'N/A'),
                'items' => [
                    [
                        'item_type' => 'subscription',
                        'item_id' => $subscription->id,
                        'item_name' => 'Subscription: ' . ($subscription->package->name ?? 'N/A'),
                        'qty' => 1,
                        'unit_price' => $subscription->total_amount,
                        'total_price' => $subscription->total_amount,
                    ]
                ]
            ]);

            $subscription->invoice_id = $invoice->id;
            $subscription->currency_id = $currencyId;
            $subscription->save();

            $payment = null;
            if (!empty($data['paid_amount']) && $data['paid_amount'] > 0) {
                $payment = $this->createPayment([
                    'invoice_id' => $invoice->id,
                    'payable_type' => Invoice::class,
                    'payable_id' => $invoice->id,
                    'financial_account_id' => $financialAccountId,
                    'financial_category_id' => $financialCategoryId,
                    'currency_id' => $currencyId,
                    'amount' => $data['paid_amount'],
                    'paid_at' => $data['paid_at'] ?? now(),
                    'payment_method' => $data['payment_method'] ?? 'cash',
                    'reference_number' => $data['reference_number'] ?? null,
                    'notes' => 'Payment for subscription #' . $subscription->id,
                ]);
            }

            return [
                'invoice' => $invoice->fresh(['items', 'payments']),
                'payment' => $payment,
            ];
        });
    }

    /**
     * Create product sale financial records
     */
    public function createProductSaleFinancials(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $financialAccountId = $data['financial_account_id'] ?? $this->getDefaultAccountId();
            $financialCategoryId = $data['financial_category_id'] ?? $this->getProductSaleCategoryId();

            $userId = $data['user_id'] ?? null;
            $currencyId = $data['currency_id'] ?? $this->getDefaultCurrencyId();

            $subtotal = 0;
            $items = [];

            foreach ($data['products'] as $productData) {
                $product = Product::find($productData['product_id']);
                if (!$product) {
                    continue;
                }

                if ((int) $product->currency_id !== (int) $currencyId) {
                    throw new \RuntimeException("Product {$product->name} currency mismatch.");
                }

                $qty = (int) $productData['qty'];
                $unitPrice = (float) $product->sale_price;
                $totalPrice = $qty * $unitPrice;

                $subtotal += $totalPrice;

                $items[] = [
                    'item_type' => 'product',
                    'item_id' => $product->id,
                    'item_name' => $product->name,
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                    'total_price' => $totalPrice,
                ];

                $product->stock_qty -= $qty;
                $product->save();
            }

            $discount = $data['discount'] ?? 0;
            $total = $subtotal - $discount;
            $paid = $data['paid'] ?? $total;
            $due = $total - $paid;

            $invoice = $this->createInvoice([
                'user_id' => $userId,
                'currency_id' => $currencyId,
                'invoice_type' => 'product',
                'subtotal' => $subtotal,
                'discount' => $discount,
                'total' => $total,
                'paid' => $paid,
                'due' => $due,
                'status' => $due <= 0 ? 'paid' : 'partially_paid',
                'notes' => $data['notes'] ?? 'Product sale',
                'items' => $items,
            ]);

            $payment = null;
            if ($paid > 0) {
                $payment = $this->createPayment([
                    'invoice_id' => $invoice->id,
                    'payable_type' => Invoice::class,
                    'payable_id' => $invoice->id,
                    'financial_account_id' => $financialAccountId,
                    'financial_category_id' => $financialCategoryId,
                    'currency_id' => $currencyId,
                    'amount' => $paid,
                    'paid_at' => $data['paid_at'] ?? now(),
                    'payment_method' => $data['payment_method'] ?? 'cash',
                    'reference_number' => $data['reference_number'] ?? null,
                    'notes' => 'Payment for product sale #' . $invoice->id,
                ]);
            }

            return [
                'invoice' => $invoice,
                'payment' => $payment,
            ];
        });
    }

    /**
     * Get financial summary
     */
    public function getFinancialSummary(array $filters = []): array
    {
        $query = FinancialTransaction::with(['account', 'category', 'currency']);

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

        $transactions = $query->get();

        return [
            'by_currency' => $this->groupRecordsByCurrency($transactions),
            'total_transactions' => $transactions->count(),
        ];
    }

    /**
     * Get detailed financial report
     */
    public function getDetailedReport(array $filters = []): array
    {
        $query = FinancialTransaction::with(['account', 'category', 'source', 'creator', 'currency']);

        if (!empty($filters['start_date'])) {
            $query->where('transaction_date', '>=', $filters['start_date']);
        }
        if (!empty($filters['end_date'])) {
            $query->where('transaction_date', '<=', $filters['end_date']);
        }
        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
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

        return [
            'summary' => $this->getFinancialSummary($filters),
            'by_currency' => $this->groupRecordsByCurrency($transactions),
        ];
    }

    /**
     * Generate unique transaction number
     */
    protected function generateTransactionNumber(): string
    {
        $prefix = 'TRX';
        $date = now()->format('Ymd');

        $last = FinancialTransaction::where('transaction_number', 'like', "{$prefix}{$date}%")
            ->orderBy('transaction_number', 'desc')
            ->first();

        if ($last) {
            $lastNumber = (int) substr($last->transaction_number, -4);
            $newNumber = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '0001';
        }

        return "{$prefix}{$date}{$newNumber}";
    }

    /**
     * Generate unique invoice number
     */
    protected function generateInvoiceNumber(): string
    {
        $prefix = 'INV';
        $date = now()->format('Ymd');

        $last = Invoice::where('invoice_number', 'like', "{$prefix}{$date}%")
            ->orderBy('invoice_number', 'desc')
            ->first();

        if ($last) {
            $lastNumber = (int) substr($last->invoice_number, -4);
            $newNumber = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '0001';
        }

        return "{$prefix}{$date}{$newNumber}";
    }


    /**
     * Update account balance
     */
    protected function updateAccountBalance(FinancialTransaction $transaction): void
    {
        $account = FinancialAccount::find($transaction->financial_account_id);

        if ($account) {
            if ($transaction->type === 'income') {
                $account->current_balance += $transaction->amount;
            } else {
                $account->current_balance -= $transaction->amount;
            }

            $account->save();
        }
    }


    /**
     * Get default financial account
     */
    public function getDefaultAccountId(): int
    {
        $account = FinancialAccount::where('is_active', true)->first();

        if (!$account) {
            throw new \RuntimeException('No active financial account found.');
        }

        return $account->id;
    }

    /**
     * Get subscription category
     */
    public function getSubscriptionCategoryId(): ?int
    {
        $category = FinancialCategory::where('type', 'income')
            ->where('name', 'like', '%subscription%')
            ->first();

        return $category?->id;
    }

    /**
     * Get product sale category
     */
    public function getProductSaleCategoryId(): ?int
    {
        $category = FinancialCategory::where('type', 'income')
            ->where('name', 'like', '%product%')
            ->first();

        return $category?->id;
    }


    public function getDefaultCurrencyId(): int
    {
        $currency = Currency::where('is_active', true)->first();

        if (!$currency) {
            throw new \RuntimeException('No active currency found.');
        }

        return $currency->id;
    }

    protected function resolveCurrencyId(array $data, ?int $fallback = null): int
    {
        return (int) ($data['currency_id'] ?? $fallback ?? $this->getDefaultCurrencyId());
    }

    protected function groupRecordsByCurrency(Collection $records): array
    {
        $grouped = $records->groupBy('currency_id');

        return $grouped->map(function ($items, $currencyId) {
            $first = $items->first();
            $currency = $first?->currency;

            return [
                'currency_id' => $currencyId,
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
                    'net_balance' => $items->where('type', 'income')->sum('amount') - $items->where('type', 'expense')->sum('amount'),
                    'transaction_count' => $items->count(),
                ],
                'by_category' => $items->groupBy('financial_category_id')->map(function ($group) {
                    $firstItem = $group->first();

                    return [
                        'category_id' => $firstItem?->financial_category_id,
                        'category_name' => $firstItem?->category?->name ?? 'Uncategorized',
                        'total' => $group->sum('amount'),
                        'count' => $group->count(),
                    ];
                })->values(),
                'by_account' => $items->groupBy('financial_account_id')->map(function ($group) {
                    $firstItem = $group->first();

                    return [
                        'account_id' => $firstItem?->financial_account_id,
                        'account_name' => $firstItem?->account?->name ?? 'Unknown',
                        'total' => $group->sum('amount'),
                        'count' => $group->count(),
                    ];
                })->values(),
            ];
        })->values()->all();
    }

    public function syncPaymentTransactionAmount(Payment $payment, float $newAmount): ?FinancialTransaction
    {
        return DB::transaction(function () use ($payment, $newAmount) {
            $transaction = $payment->transaction;

            if (!$transaction) {
                return null;
            }

            $oldAmount = (float) $transaction->amount;
            $delta = $newAmount - $oldAmount;

            if ((float) $delta === 0.0) {
                return $transaction;
            }

            $transaction->amount = $newAmount;
            $transaction->save();

            $account = FinancialAccount::find($transaction->financial_account_id);

            if ($account) {
                if ($transaction->type === 'income') {
                    $account->current_balance += $delta;
                } else {
                    $account->current_balance -= $delta;
                }

                $account->save();
            }

            return $transaction->fresh();
        });
    }
}
