<?php

namespace App\Services\Functional;

use App\Models\Invoice;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class ProductSaleService
{
    protected FinancialService $financialService;

    public function __construct(FinancialService $financialService)
    {
        $this->financialService = $financialService;
    }

    /**
     * Create product sale
     */
    public function createProductSale(array $data): array
    {
        return DB::transaction(function () use ($data) {

            $currencyId = (int) $data['currency_id'];

            $products = [];
            $subtotal = 0;
            $items = [];

            foreach ($data['products'] as $productData) {
                $product = Product::query()
                    ->whereKey($productData['product_id'])
                    ->lockForUpdate()
                    ->first();

                if (!$product) {
                    throw new \RuntimeException(
                        "المنتج رقم {$productData['product_id']} غير موجود."
                    );
                }

                if ((int) $product->currency_id !== $currencyId) {
                    throw new \RuntimeException(
                        "عملة المنتج \"{$product->name}\" لا تطابق عملة الفاتورة."
                    );
                }

                $qty = (int) $productData['qty'];

                if ($qty <= 0) {
                    throw new \RuntimeException(
                        "الكمية الخاصة بالمنتج \"{$product->name}\" يجب أن تكون أكبر من صفر."
                    );
                }

                if ($product->stock_qty < $qty) {
                    throw new \RuntimeException(
                        "الكمية المطلوبة من المنتج \"{$product->name}\" أكبر من الكمية المتوفرة في المخزون."
                    );
                }

                $unitPrice = (float) $product->sale_price;
                $totalPrice = $qty * $unitPrice;

                $subtotal += $totalPrice;

                $products[] = [
                    'product' => $product,
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                    'total_price' => $totalPrice,
                ];

                $items[] = [
                    'item_type' => 'product',
                    'item_id' => $product->id,
                    'item_name' => $product->name,
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                    'total_price' => $totalPrice,
                ];
            }

            $discount = (float) ($data['discount'] ?? 0);

            if ($discount < 0) {
                throw new \RuntimeException('قيمة الخصم لا يمكن أن تكون سالبة.');
            }

            if ($discount > $subtotal) {
                throw new \RuntimeException(
                    "قيمة الخصم ({$discount}) لا يمكن أن تتجاوز إجمالي المبلغ قبل الخصم ({$subtotal})."
                );
            }

            $total = $subtotal - $discount;

            $paid = array_key_exists('paid', $data)
                ? (float) $data['paid']
                : $total;

            if ($paid < 0) {
                throw new \RuntimeException('المبلغ المدفوع لا يمكن أن يكون سالبًا.');
            }

            if ($paid > $total) {
                throw new \RuntimeException(
                    "المبلغ المدفوع ({$paid}) لا يمكن أن يتجاوز إجمالي الفاتورة بعد الخصم ({$total})."
                );
            }

            $financialAccountId = $data['financial_account_id']
                ?? $this->financialService->getDefaultAccountId();

            $financialCategoryId = $data['financial_category_id']
                ?? $this->financialService->getProductSaleCategoryId();

            $invoice = $this->financialService->createInvoice([
                'user_id' => $data['user_id'] ?? null,
                'currency_id' => $currencyId,
                'invoice_type' => 'product',
                'subtotal' => $subtotal,
                'discount' => $discount,
                'total' => $total,
                'paid' => 0,
                'due' => $total,
                'status' => 'pending',
                'notes' => $data['notes'] ?? 'عملية بيع منتجات',
                'items' => $items,
            ]);

            foreach ($products as $productData) {
                $productData['product']->stock_qty -= $productData['qty'];
                $productData['product']->save();
            }

            $payment = null;

            if ($paid > 0) {
                $payment = $this->financialService->createPayment([
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
                    'notes' => 'دفعة مقابل فاتورة بيع المنتجات #' . $invoice->id,
                ]);
            }

            return [
                'invoice' => $invoice->fresh(['currency', 'items', 'payments']),
                'payment' => $payment,
                'products' => collect($products)->map(function ($row) {
                    return [
                        'product_id' => $row['product']->id,
                        'product_name' => $row['product']->name,
                        'currency_id' => $row['product']->currency_id,
                        'qty' => $row['qty'],
                        'unit_price' => $row['unit_price'],
                        'total_price' => $row['total_price'],
                        'remaining_stock' => $row['product']->stock_qty,
                    ];
                })->values(),
            ];
        });
    }

    /**
     * Get product sales report
     */
    public function getProductSalesReport(array $filters = []): array
    {
        $query = Invoice::with(['user', 'items', 'payments', 'currency'])
            ->where('invoice_type', 'product');

        if (!empty($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }
        if (!empty($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date']);
        }
        if (!empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['currency_id'])) {
            $query->where('currency_id', $filters['currency_id']);
        }

        $invoices = $query->orderBy('created_at', 'desc')->get();

        $grouped = $invoices->groupBy('currency_id')->map(function ($currencyInvoices) {
            $first = $currencyInvoices->first();
            $currency = $first?->currency;

            $productSales = [];

            foreach ($currencyInvoices as $invoice) {
                foreach ($invoice->items as $item) {
                    if ($item->item_type === 'product') {
                        $productId = $item->item_id;

                        if (!isset($productSales[$productId])) {
                            $productSales[$productId] = [
                                'product_id' => $productId,
                                'product_name' => $item->item_name,
                                'total_qty' => 0,
                                'total_revenue' => 0,
                            ];
                        }

                        $productSales[$productId]['total_qty'] += $item->qty;
                        $productSales[$productId]['total_revenue'] += $item->total_price;
                    }
                }
            }

            usort($productSales, function ($a, $b) {
                return $b['total_revenue'] <=> $a['total_revenue'];
            });

            return [
                'currency_id' => $first?->currency_id,
                'currency' => [
                    'id' => $currency?->id,
                    'code' => $currency?->code,
                    'name' => $currency?->name,
                    'symbol' => $currency?->symbol,
                    'decimals' => $currency?->decimals,
                ],
                'summary' => [
                    'total_sales' => $currencyInvoices->sum('total'),
                    'total_paid' => $currencyInvoices->sum('paid'),
                    'total_due' => $currencyInvoices->sum('due'),
                    'total_discount' => $currencyInvoices->sum('discount'),
                    'invoice_count' => $currencyInvoices->count(),
                    'paid_count' => $currencyInvoices->where('status', 'paid')->count(),
                    'partial_count' => $currencyInvoices->where('status', 'partially_paid')->count(),
                    'pending_count' => $currencyInvoices->where('status', 'pending')->count(),
                ],
                'invoices' => $currencyInvoices->values(),
                'top_products' => array_slice($productSales, 0, 10),
            ];
        })->values();

        return [
            'by_currency' => $grouped,
            'total_invoices' => $invoices->count(),
        ];
    }

    /**
     * Get product sales by date range
     */
    public function getSalesByDateRange(string $startDate, string $endDate): array
    {
        $invoices = Invoice::with('currency')
            ->where('invoice_type', 'product')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get();

        $grouped = $invoices->groupBy('currency_id')->map(function ($currencyInvoices) {
            $first = $currencyInvoices->first();
            $currency = $first?->currency;

            $dailySales = [];

            foreach ($currencyInvoices as $invoice) {
                $date = $invoice->created_at->format('Y-m-d');

                if (!isset($dailySales[$date])) {
                    $dailySales[$date] = [
                        'date' => $date,
                        'total_sales' => 0,
                        'total_paid' => 0,
                        'invoice_count' => 0,
                    ];
                }

                $dailySales[$date]['total_sales'] += $invoice->total;
                $dailySales[$date]['total_paid'] += $invoice->paid;
                $dailySales[$date]['invoice_count'] += 1;
            }

            return [
                'currency_id' => $first?->currency_id,
                'currency' => [
                    'id' => $currency?->id,
                    'code' => $currency?->code,
                    'name' => $currency?->name,
                    'symbol' => $currency?->symbol,
                    'decimals' => $currency?->decimals,
                ],
                'daily_sales' => array_values($dailySales),
                'total' => [
                    'sales' => array_sum(array_column($dailySales, 'total_sales')),
                    'paid' => array_sum(array_column($dailySales, 'total_paid')),
                    'invoices' => array_sum(array_column($dailySales, 'invoice_count')),
                ],
            ];
        })->values();

        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'by_currency' => $grouped,
            'total_invoices' => $invoices->count(),
        ];
    }
}
