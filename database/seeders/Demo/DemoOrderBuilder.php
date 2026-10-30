<?php

namespace Database\Seeders\Demo;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\BranchProductOption;
use App\Models\Currency;
use App\Models\Order;
use App\Models\User;
use App\Models\UserAddress;
use App\Models\Wallet;
use App\Services\Order\CheckoutService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * WAFFRLI demo — submits demo orders through the REAL checkout core
 * (App\Services\Order\CheckoutService::placeOrder): the same validation,
 * pricing (branch prices, offers, PLUS snapshot discount with priority over
 * offers, free delivery, platform fee quote, order currency), snapshots,
 * wallet debit through the ledger and status history as a customer
 * checkout. Only the line description (product keys) and the demo-only
 * choices (fixed order number, deterministic checkout key, wallet amount
 * capped at the balance) are added here.
 *
 * Status changes after submission are NOT made here: DemoOrderSeeder moves
 * orders through OrderAdminService / the state machine.
 */
class DemoOrderBuilder
{
    public function __construct(protected CheckoutService $checkout) {}

    /**
     * @param  list<array{0: string, 1: int, 2: list<string>}>  $lines  [product key, quantity, option keys]
     * @param  array{currency?: string, wallet?: string, free_delivery?: bool, scheduled_for?: ?\DateTimeInterface, note?: ?string, address?: int}  $options
     */
    public function submit(string $number, User $user, Branch $branch, string $storeSlug, array $lines, array $options = []): Order
    {
        return DB::transaction(function () use ($number, $user, $branch, $storeSlug, $lines, $options) {
            $currency = isset($options['currency']) ? Currency::query()->where('code', $options['currency'])->firstOrFail() : Currency::query()->default()->firstOrFail();
            $addresses = UserAddress::query()->where('user_id', $user->id)->orderByDesc('is_default')->orderBy('id')->get();
            $address = $addresses[$options['address'] ?? 0] ?? $addresses->first();
            if (! $address) {
                throw new RuntimeException("Demo customer {$user->email} has no address.");
            }

            $checkoutLines = [];
            foreach ($lines as [$productKey, $quantity, $optionKeys]) {
                $product = DemoCatalogSeeder::findProduct($storeSlug, $productKey);
                $branchProduct = BranchProduct::query()->where('branch_id', $branch->id)->where('product_id', $product->id)->firstOrFail();
                $optionIds = [];
                foreach ($optionKeys as $optionKey) {
                    $optionEn = $this->optionName($optionKey);
                    $optionIds[BranchProductOption::query()->where('branch_product_id', $branchProduct->id)
                        ->whereHas('option', fn ($query) => $query->where('name->en', $optionEn))->valueOrFail('product_option_id')] = 1;
                }
                $checkoutLines[] = ['branch_product_id' => $branchProduct->id, 'quantity' => $quantity, 'options' => $optionIds, 'notes' => null];
            }

            // Demo choice: use at most the wallet balance the customer really has.
            $wallet = null;
            if (isset($options['wallet'])) {
                $balance = (string) (Wallet::query()->where('user_id', $user->id)->where('currency_id', $currency->id)->value('balance') ?? '0');
                $wallet = BigDecimal::of($options['wallet'])->isGreaterThan($balance) ? $balance : $options['wallet'];
            }

            return $this->checkout->placeOrder($user, $branch, $checkoutLines, [
                'checkout_key' => $this->uuid('order:'.$number),
                'order_number' => $number,
                'address_id' => $address->id,
                'currency_id' => $currency->id,
                'wallet_amount' => $wallet,
                'use_free_delivery' => (bool) ($options['free_delivery'] ?? false),
                'scheduled_for' => isset($options['scheduled_for']) ? $options['scheduled_for']->toIso8601String() : null,
                'notes' => $options['note'] ?? null,
                'history_note' => 'Demo order submitted',
            ]);
        });
    }

    protected function optionName(string $key): string
    {
        foreach (DemoCatalogSeeder::OPTION_PRESETS as [, , , , , $options]) {
            if (isset($options[$key])) {
                return $options[$key][1];
            }
        }

        throw new RuntimeException("Unknown demo option {$key}.");
    }

    /** Deterministic UUID-shaped id so re-runs never create a second copy. */
    public function uuid(string $seed): string
    {
        $h = md5('waffrli-demo:'.$seed);

        return sprintf('%s-%s-4%s-a%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3), substr($h, 17, 3), substr($h, 20, 12));
    }
}
