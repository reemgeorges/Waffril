<?php

namespace App\Services\Push;

use App\Exceptions\BusinessRuleException;
use App\Models\DeviceToken;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WAFFRLI (2026-11-03) — FCM device tokens of customers and drivers.
 *
 * - A token identifies an app installation, so it belongs to whoever is
 *   signed in on it NOW: registering a token another account held moves it
 *   (the previous owner stops receiving pushes on that device).
 * - Customers: one active device (same rule as their sessions): registering
 *   deactivates every other token of the account.
 * - Drivers: at most waffrli.push.max_driver_devices active tokens; the
 *   least recently seen are deactivated.
 * - Update / remove only the caller's own rows (others are 404).
 * - Token values are never logged or returned in full.
 */
class DeviceTokenService
{
    public function register(Model $owner, array $data): DeviceToken
    {
        return DB::transaction(function () use ($owner, $data) {
            $existing = DeviceToken::query()->where('token', $data['token'])->lockForUpdate()->first();
            if ($existing && ($existing->tokenable_type !== $owner->getMorphClass() || (int) $existing->tokenable_id !== (int) $owner->getKey())) {
                Log::info('push.token_moved', ['token_id' => $existing->id, 'from' => $existing->tokenable_type.':'.$existing->tokenable_id, 'to' => $owner->getMorphClass().':'.$owner->getKey()]);
            }

            $token = $existing ?? new DeviceToken(['token' => $data['token']]);
            $token->forceFill([
                'tokenable_type' => $owner->getMorphClass(),
                'tokenable_id' => $owner->getKey(),
                'platform' => $data['platform'],
                'device_id' => $data['device_id'] ?? null,
                'device_name' => $data['device_name'] ?? null,
                'is_active' => true,
                'revoked_at' => null,
                'last_seen_at' => now(),
            ])->save();

            $this->enforceDeviceLimit($owner, $token);

            return $token->fresh();
        });
    }

    public function update(Model $owner, int|string $id, array $data): DeviceToken
    {
        return DB::transaction(function () use ($owner, $id, $data) {
            $token = $this->own($owner, $id, lock: true);
            if (isset($data['token']) && $data['token'] !== $token->token) {
                // The new value may still be attached to an older row (re-install): it is replaced.
                DeviceToken::query()->where('token', $data['token'])->whereKeyNot($token->id)->delete();
                $token->token = $data['token'];
            }
            $token->forceFill(array_filter([
                'platform' => $data['platform'] ?? null,
                'device_name' => $data['device_name'] ?? null,
            ], fn ($v) => $v !== null) + ['is_active' => true, 'revoked_at' => null, 'last_seen_at' => now()])->save();

            $this->enforceDeviceLimit($owner, $token);

            return $token->fresh();
        });
    }

    public function remove(Model $owner, int|string $id): void
    {
        $this->own($owner, $id)->delete();
    }

    /** @return Collection<int, DeviceToken> */
    public function list(Model $owner): Collection
    {
        return $this->ownedBy($owner)->orderByDesc('is_active')->orderByDesc('last_seen_at')->get();
    }

    /** @return Collection<int, DeviceToken> active tokens a push may go to */
    public function activeFor(Model $owner): Collection
    {
        return $this->ownedBy($owner)->where('is_active', true)->get();
    }

    public function deactivate(DeviceToken $token, string $reason): void
    {
        DeviceToken::query()->whereKey($token->id)->update([
            'is_active' => false,
            'revoked_at' => now(),
            'meta' => json_encode(array_merge((array) $token->meta, ['deactivated_reason' => $reason, 'deactivated_at' => now()->toIso8601String()])),
        ]);
    }

    protected function enforceDeviceLimit(Model $owner, DeviceToken $current): void
    {
        $others = $this->ownedBy($owner)->where('is_active', true)->whereKeyNot($current->id);
        if ($owner instanceof User) {
            $others->update(['is_active' => false, 'revoked_at' => now()]);

            return;
        }
        if ($owner instanceof Driver) {
            $keep = max(1, (int) config('waffrli.push.max_driver_devices', 3)) - 1;
            $stale = $others->orderByDesc('last_seen_at')->orderByDesc('id')->get()->slice($keep)->pluck('id');
            if ($stale->isNotEmpty()) {
                DeviceToken::query()->whereIn('id', $stale)->update(['is_active' => false, 'revoked_at' => now()]);
            }
        }
    }

    protected function own(Model $owner, int|string $id, bool $lock = false): DeviceToken
    {
        $token = ctype_digit((string) $id)
            ? $this->ownedBy($owner)->whereKey((int) $id)->when($lock, fn ($q) => $q->lockForUpdate())->first()
            : null;
        if (! $token) {
            throw new BusinessRuleException(__('messages.push.device_not_found'), 404);
        }

        return $token;
    }

    protected function ownedBy(Model $owner)
    {
        return DeviceToken::query()->where('tokenable_type', $owner->getMorphClass())->where('tokenable_id', $owner->getKey());
    }
}
