<?php

namespace App\Http\Resources\Basic;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Enums\Language;

class BasicResource extends JsonResource
{
    protected array $result = [];
    protected bool $isLight = false;
    protected array $lightKeys = ['id', 'name'];

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return parent::toArray($request);
    }

    /**
     * Set resource to light mode (returns only specific keys)
     *
     * @param ?array $keys Custom keys to include in light mode
     * @return $this
     */
    public function light(?array $keys = null): self
    {
        $this->isLight = true;
        if ($keys !== null) {
            $this->lightKeys = $keys;
        }
        return $this;
    }

    /**
     * Initialize a full resource using ModelColumnsService
     */
    protected function initResource($modelColumnsService) : array
    {
        if ($this->isLight) {
            return $this->initLightResource();
        }

        $cols          = (array) $modelColumnsService->getColumns();
        $hiddens       = (array) $modelColumnsService->getHiddens();
        $appends       = (array) $modelColumnsService->getAttributes();
        $translations  = (array) $modelColumnsService->getTranslationAttributes();

        // أعمدة الجدول (باستثناء المخفية)
        foreach ($cols as $col) {
            if (!in_array($col, $hiddens, true)) {
                // استخدم getAttribute حتى مع الـ accessors
                $this->result[$col] = $this->resource->getAttribute($col);
            }
        }

        // Attributes (Appends)
        foreach ($appends as $append) {
            $this->result[$append] = $this->{$append};
        }

        // Translations
        if (!empty($translations) && method_exists($this->resource, 'getTranslation') && class_exists(Language::class)) {
            foreach ($translations as $translate) {
                foreach (Language::values() as $lang) {
                    $this->result[$translate . '_' . $lang] = $this->getTranslation($translate, $lang);
                }
            }
        }

        // id + is_deleted دائماً
        $this->result['id']         = $this->id;
        $this->result['is_deleted'] = (bool) $this->deleted_at;
        $this->result['created_at'] = $this->created_at;

        return $this->result;
    }

    /**
     * Initialize a light resource with only specific keys
     *
     * @return array
     */
    protected function initLightResource(): array
    {
        $result = [];

        // ضمان وجود id دائماً
        $keys = array_values(array_unique(array_merge(['id'], $this->lightKeys)));

        foreach ($keys as $key) {
            // لو القيمة null، نخليها null بدل ما نسقط الحقل
            $result[$key] = $this->{$key} ?? null;
        }

        $result['is_deleted'] = (bool) $this->deleted_at;

        return $result;
    }
}
