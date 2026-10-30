<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * WAFFRLI (2026-10-31). Cart line; selected options live in the
 * cart_item_options pivot with their quantities.
 */
class CartItem extends BasePlatformModel
{
    protected $fillable = [
        'cart_id' => 'cart_id',
        'branch_product_id' => 'branch_product_id',
        'quantity' => 'quantity',
        'configuration_hash' => 'configuration_hash',
        'notes' => 'notes',
    ];

    protected $casts = ['quantity' => 'integer'];

    /** sha256 of the sorted "option_id:qty" pairs (empty selection allowed). */
    public static function configurationHash(array $optionQuantities): string
    {
        ksort($optionQuantities);

        return hash('sha256', collect($optionQuantities)->map(fn ($q, $id) => $id.':'.$q)->implode(','));
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function branchProduct(): BelongsTo
    {
        return $this->belongsTo(BranchProduct::class);
    }

    public function options(): BelongsToMany
    {
        return $this->belongsToMany(ProductOption::class, 'cart_item_options')->withPivot('quantity');
    }
}
