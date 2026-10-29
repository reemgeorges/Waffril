<?php

namespace App\Services\Functional\Excel;

use App\Exports\GenericExport;
use App\Imports\GenericImport;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;


class ExcelService
{
    /**
     * تصدير البيانات إلى ملف Excel بصيغة base64
     */
    public function export(Collection $data, string $model): string
    {
        $export = new GenericExport($data, $model);

        $excelContent = Excel::raw($export, \Maatwebsite\Excel\Excel::XLSX);

        return base64_encode($excelContent);
    }

    /**
     * استيراد ملف Excel وتحويله إلى بيانات بناءً على الموديل
     */
    public function import(string $model, Request $request): bool
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        if ($validator->fails()) {
            throw new \Illuminate\Validation\ValidationException($validator);
        }

        Excel::import(new GenericImport($model), $request->file('file'));

        return true;
    }

    /**
     * إنشاء ملف Excel فارغ يحتوي على الأعمدة المتوقعة فقط
     */
    public function exportEmptyTemplate(string $model): string
    {
        $fillable = (new $model())->getFillable();

        // نولّد صف واحد فارغ يحتوي على الأعمدة فقط
        $template = collect([array_fill_keys($fillable, '')]);

        $export = new GenericExport($template, $model);

        $excelContent = Excel::raw($export, \Maatwebsite\Excel\Excel::XLSX);

        return base64_encode($excelContent);
    }
}
