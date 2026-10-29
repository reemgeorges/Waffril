<?php

namespace App\Services\Basic;

class ModelColumnsService {

    private static $instance;
    private static $model;
    private static $modelsFlyWight = [];
    private function __construct(){}

    public static function getServiceFor($model): self {
        self::$model = isset(self::$modelsFlyWight[$model])
            ? self::$modelsFlyWight[$model]
            : self::$modelsFlyWight[$model] = new $model;

        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function getColumn(string $col): string {
        return self::$model->getFillable()[$col];
    }

    public function getColumns(): array {
        return self::$model->getFillable();
    }

    public function getAttributes(): array {
        return self::$model->getAppends();
    }

    public function getTranslationAttributes(): array {
        return self::$model->getTranslatableAttributes();
    }


    public function getHiddens(): array {
        return collect(self::$model->getHidden())
            ->mapWithKeys(fn($hidden) => [$hidden => true])
            ->toArray();
    }

    public function getExcel(): array {
        return self::$model->getExcel();
    }
}
