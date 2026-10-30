<?php

namespace App\Models\Concerns;

use LogicException;

/**
 * WAFFRLI (2026-10-31) — makes a model's rows immutable once written
 * (ledgers, histories, audit logs, cancellation records). Corrections
 * are made by appending new rows, never by editing or deleting old ones.
 */
trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        static::updating(function ($model) {
            throw new LogicException(class_basename($model).' records are append-only and cannot be modified.');
        });

        static::deleting(function ($model) {
            throw new LogicException(class_basename($model).' records are append-only and cannot be deleted.');
        });
    }
}
