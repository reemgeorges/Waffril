<?php

namespace App\Models;

use App\Support\HasDynamicFiltering;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Translatable\HasTranslations;
use Illuminate\Support\Facades\Storage;

class BaseAuthModel extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes, HasTranslations;
    use HasDynamicFiltering;

    /**
     * Translatable attributes (Spatie).
     */
    protected $translatable = [];

    /**
     * Columns used for global LIKE search (?search=...).
     */
    protected $search = [];

    /**
     * Columns to be exported to Excel (optional).
     */
    protected $excel = [];
    protected bool $enableDynamicFilters = true;
    protected array $dynamicFilterColumns = [];
    protected array $sortableColumns = [];

    /**
     * Hide sensitive attributes.
     */
    protected $hidden = [
        'password',
    ];

    /**
     * ===================== File management (GLOBAL) =====================
     * Declare file attributes for automatic cleanup:
     *  - key   : attribute name
     *  - value : 'single' (string path) or 'array' (array of paths)
     *
     * Example in child models:
     * protected array $fileAttributes = [
     *     'avatar'  => 'single',
     *     'gallery' => 'array',
     * ];
     */
    protected array $fileAttributes = [];

    /**
     * Default filesystem disk used to delete files.
     */
    protected string $fileDisk = 'public';

    /**
     * If true → delete files on soft delete.
     * If false → delete files only on forceDelete().
     */
    protected bool $deleteFilesOnSoftDelete = false;


    protected array $allowedOps = [
        'eq',
        'neq',
        'like',
        'nlike',
        'in',
        'nin',
        'gt',
        'gte',
        'lt',
        'lte',
        'between',
        'bool',
        'null',
    ];

    /**
     * Register model hooks for file cleanup.
     */
    protected static function booted(): void
    {
        // On update: delete replaced files (old file paths no longer referenced)
        static::updating(function (self $model) {
            $model->cleanupReplacedFiles();
        });

        // On delete:
        //  - if model doesn't use SoftDeletes → delete files immediately
        //  - if using SoftDeletes and deleteFilesOnSoftDelete = true → delete files on soft delete
        static::deleting(function (self $model) {
            if (!$model->usesSoftDeletes() || $model->deleteFilesOnSoftDelete) {
                $model->cleanupAllFiles();
            }
        });

        // On force delete: always delete files
        static::forceDeleted(function (self $model) {
            $model->cleanupAllFiles();
        });
    }

    /**
     * Determine if this model uses the SoftDeletes trait.
     */
    protected function usesSoftDeletes(): bool
    {
        foreach (class_uses_recursive(static::class) as $trait) {
            if ($trait === SoftDeletes::class) {
                return true;
            }
        }
        return false;
    }

    /**
     * Remove files that were replaced during an update.
     * - For 'single': if the path changed, delete the old file.
     * - For 'array' : delete files present in old value but not in new value.
     */
    protected function cleanupReplacedFiles(): void
    {
        if (empty($this->fileAttributes)) return;

        foreach ($this->fileAttributes as $attr => $type) {
            $disk = $this->fileDisk;

            if ($type === 'single') {
                $old = $this->normalizeStoragePath($this->getOriginal($attr));
                $new = $this->normalizeStoragePath($this->{$attr});

                if ($old && $old !== $new) {
                    $this->deleteFileQuietly($old, $disk);
                }
            } elseif ($type === 'array') {
                $oldArr = $this->toArrayOfPaths($this->getOriginal($attr));
                $newArr = $this->toArrayOfPaths($this->{$attr});

                $toDelete = array_diff($oldArr, $newArr);
                foreach ($toDelete as $path) {
                    $this->deleteFileQuietly($path, $disk);
                }
            }
        }
    }

    /**
     * Delete all declared file attributes for this model instance.
     * Called on delete/forceDelete depending on configuration above.
     */
    protected function cleanupAllFiles(): void
    {
        if (empty($this->fileAttributes)) return;

        foreach ($this->fileAttributes as $attr => $type) {
            $disk = $this->fileDisk;

            if ($type === 'single') {
                $path = $this->normalizeStoragePath($this->{$attr});
                $this->deleteFileQuietly($path, $disk);
            } elseif ($type === 'array') {
                $arr = $this->toArrayOfPaths($this->{$attr});
                foreach ($arr as $path) {
                    $this->deleteFileQuietly($path, $disk);
                }
            }
        }
    }

    /**
     * Normalize to a storage-relative path.
     * Examples:
     *  - https://domain.com/storage/folder/x.png → folder/x.png
     *  - storage/folder/x.png                   → folder/x.png
     *  - folder/x.png                           → folder/x.png
     */
    protected function normalizeStoragePath(?string $path): ?string
    {
        if (!$path) return null;
        $path = trim($path);

        // Full URL
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            $parsed = parse_url($path);
            $p = $parsed['path'] ?? '';
            // Strip leading /storage/
            $p = preg_replace('#^/storage/#', '', ltrim($p, '/'));
            return $p ?: null;
        }

        // Starts with storage/
        if (str_starts_with($path, 'storage/')) {
            return preg_replace('#^storage/#', '', $path);
        }

        return ltrim($path, '/');
    }

    /**
     * Convert a value into an array of normalized storage paths.
     * Accepts: null | string | array<string>
     */
    protected function toArrayOfPaths($value): array
    {
        if (!$value) return [];
        if (is_string($value)) {
            $rel = $this->normalizeStoragePath($value);
            return $rel ? [$rel] : [];
        }
        if (is_array($value)) {
            return array_values(
                array_filter(
                    array_map(fn($v) => $this->normalizeStoragePath((string)$v), $value)
                )
            );
        }
        return [];
    }

    /**
     * ✅ Renamed to avoid clashing with Eloquent's public Model::deleteQuietly()
     */
    protected function deleteFileQuietly(?string $relativePath, string $disk = 'public'): void
    {
        if (!$relativePath) return;
        try {
            Storage::disk($disk)->delete($relativePath);
        } catch (\Throwable $e) {
            // Intentionally ignore
        }
    }

    /**
     * ===================== Existing utilities & scopes =====================
     */

    public function getExcel() : array
    {
        return $this->excel;
    }

    /**
     * Phase 1 (2026-10-25): optional convenience scopes for the new
     * `is_active` lifecycle column (kept separate from SoftDeletes'
     * `deleted_at`). Not applied automatically (no global scope) — a no-op
     * opt-in, safe to add even though not every consuming table has an
     * `is_active` column yet.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    /**
     * Scope: apply simple filters from the current request.
     * Supports:
     *  - Global LIKE search on $this->search columns via ?search=
     *  - SoftDeletes status via ?status=0|1|2 (0: onlyTrashed, 2: withTrashed)
     *  - Active status via ?active=0|1 (assumes a 'status' column)
     */
    /*
    public function scopeWithFilters($query)
    {
        $request = request();


        $query = $query->when($request->filled('search') && !empty($this->search), function ($q) use ($request) {
            $term = trim((string)$request->search);
            $first = true;
            foreach ($this->search as $column) {
                if ($first) {
                    $q->where($column, 'like', "%{$term}%");
                    $first = false;
                } else {
                    $q->orWhere($column, 'like', "%{$term}%");
                }
            }
        });


        if ($request->filled('status')) {
            $status = (int) $request->input('status');
            if ($status === 0) {
                $query = $query->onlyTrashed();
            } elseif ($status === 2) {
                $query = $query->withTrashed();
            }
        }


        if ($request->filled('active')) {
            $active = (int) $request->input('active');
            $query = $query->where('status', $active);
        }


        if ($this->enableDynamicFilters) {
            $this->applyDynamicFilters($query);
            $this->applyDynamicSorting($query);
        }

        return $query;
    }
*/
    /**
     * Polymorphic relations (kept as-is).

    public function deviceTokens()
    {
        return $this->morphMany(DeviceToken::class, 'device_able');
    }

    public function otps()
    {
        return $this->morphMany(Otp::class, 'verifiable');
    }
         */
}
