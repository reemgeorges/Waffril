<?php

namespace App\Http\Traits;

use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use Exception;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

trait FileExportReportTrait
{

    public function exportData($data, $filename = 'Report.xlsx')
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->setCellValue('A1', 'Report');
        $sheet->getStyle('A1')->getFont()->setBold(true);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal('center');
        $sheet->mergeCells('A1:H1');

        $headerRow = 2;
        $rowNumber = $headerRow + 1;

        if (!empty($data) && is_array($data)) {

            $columnCount = count(array_keys($data[0]));
            $lastColumn = Coordinate::stringFromColumnIndex($columnCount); // الحصول على العمود الأخير بناءً على عدد الأعمدة


            $sheet->fromArray(array_keys($data[0]), null, 'A' . $headerRow);
            $sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")->getFont()->setBold(true);
            $sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

            foreach ($data as $row) {
                $sheet->fromArray(array_values($row), null, 'A' . $rowNumber++);
                $sheet->getStyle("A" . ($rowNumber - 1) . ":{$lastColumn}" . ($rowNumber - 1))
                    ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            }


            for ($col = 1; $col <= $columnCount; $col++) {
                $columnLetter = Coordinate::stringFromColumnIndex($col);
                $sheet->getColumnDimension($columnLetter)->setAutoSize(true);
            }
        } else {
            $sheet->setCellValue('A2', 'No data available.');
        }


        $writer = new Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        $excelData = ob_get_contents();
        ob_end_clean();

        Storage::put("uploads/exports/{$filename}", $excelData);


        $fileContents = Storage::get("uploads/exports/{$filename}");
        return base64_encode($fileContents);
    }


    public function exportDataPdf($data, $filename = 'Report.pdf')
    {
        $directory = 'uploads/exports/';


        if (!file_exists($directory)) {
            mkdir($directory, 0777, true);
        }

        $options = new Options();
        $options->set('isRemoteEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->set_paper('a4', 'portrait');


        $html = '<div style="text-align: center; margin-top: 20px;">';
        $html .= '<table border="1" style="border-collapse: collapse; margin: 0 auto;">';

        $headers = array_keys(reset($data));
        $html .= '<tr>';
        foreach ($headers as $header) {
            $html .= '<th style="padding: 10px; border: 1px solid #ddd; background-color: #f2f2f2; font-weight: bold;">' . htmlspecialchars($header) . '</th>';
        }
        $html .= '</tr>';


        foreach ($data as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td style="padding: 10px; border: 1px solid #ddd; text-align: center;">' . htmlspecialchars($cell) . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</table>';
        $html .= '</div>';


        $dompdf->loadHtml($html);
        $dompdf->render();
        $output = $dompdf->output();

        file_put_contents($directory . $filename, $output);


        $fileContents = file_get_contents($directory . $filename);
        return base64_encode($fileContents);
    }



    public function exportDynamicTemplate($data, $filename = 'dynamic_template.xlsx', $columns, $title = 'Dynamic Template')
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // إعداد العنوان الديناميكي
        $sheet->setCellValue('A1', $title);
        $sheet->getStyle('A1')->getFont()->setBold(true);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal('center');
        $sheet->mergeCells('A1:' . Coordinate::stringFromColumnIndex(count($columns)) . '1');

        // كتابة أسماء الأعمدة
        $sheet->fromArray($columns, null, 'A2');
        $sheet->getStyle('A2:' . Coordinate::stringFromColumnIndex(count($columns)) . '2')->getFont()->setBold(true);
        $sheet->getStyle('A2:' . Coordinate::stringFromColumnIndex(count($columns)) . '2')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        // كتابة البيانات
        $rowNumber = 3; // يبدأ من الصف الثالث بعد العنوان وأسماء الأعمدة
        foreach ($data as $row) {
            $sheet->fromArray($row, null, 'A' . $rowNumber);
            $sheet->getStyle('A' . $rowNumber . ':' . Coordinate::stringFromColumnIndex(count($columns)) . $rowNumber)
                ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            $rowNumber++;
        }

        // جعل عرض الأعمدة تلقائيًا
        foreach (range('A', Coordinate::stringFromColumnIndex(count($columns))) as $columnID) {
            $sheet->getColumnDimension($columnID)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);

        // إنشاء المجلد إذا لم يكن موجودًا
        $directory = storage_path('app/public/exports');
        if (!file_exists($directory)) {
            mkdir($directory, 0755, true);
        }

        $filePath = $directory . '/' . $filename;
        $writer->save($filePath);

        $fileContents = file_get_contents($filePath);
        return base64_encode($fileContents);
    }

    public function exportStoreTemplate(Request $request)
    {
        ini_set('memory_limit', '512M');
        try {
            $validator = Validator::make($request->all(), [
                'company_id' => 'nullable|integer|exists:companies,id',
            ]);

            $validator->validate();

            $companyId = $request->input('company_id');
            $company = Company::findOrFail($companyId);

            $shiftTypes = ['breakfast', 'lunch', 'dinner', 'overnight'];
            $regions = $company->regions()->where('status', 1)->get();
            $brands = $company->brands()->where('status', 1)->get();

            $columns = ['Store Name', 'Country', 'State', 'Time Zone', 'District Manager', 'Service Provider'];
            foreach ($regions as $region) {
                $columns[] = $region->name;
            }
            foreach ($shiftTypes as $shift) {
                $columns[] = $shift;
            }
            foreach ($brands as $brand) {
                $columns[] = $brand->name;
            }

            $dataToExport = [];
            $totalRows = $company->number_of_stores;


            $dataToExport[] = array_fill(0, count($columns), '');
            for ($i = 1; $i < $totalRows; $i++) {
                $dataToExport[] = array_fill(0, count($columns), '');
            }


            $title = 'Stores Template';
            $file = $this->exportDynamicTemplate($dataToExport, 'store_template.xlsx', $columns, $title);

            if ($file) {
                return $this->apiResponse(['file' => $file]);
            } else {
                return $this->apiResponse([], false, 'Error exporting data.', 500);
            }
        } catch (\Exception $ex) {
            return $this->handleException($ex);
        }
    }

        public function exportStore(Request $request)
    {
        ini_set('memory_limit', '512M');

        try {
            $validator = Validator::make($request->all(), [
                'company_id' => 'nullable|integer|exists:companies,id',
            ]);

            $validator->validate();

            $search = $request->input('search');
            $query = Store::query();

            if ($request->filled('company_id')) {
                $query->where('company_id', $request->company_id);
            }

            if ($search) {
                $query->where('name',  'like', "%{$search}%");
            }


            $dataToExport = $query->get()->map(function ($store) {
                return [
                    'Company' => $store->company->name,
                    'Name Store' => $store->name,
                    'Brand' => $store->brand?->name??'N/A',
                    'Country' => $store->country,
                    'State' => $store->state,
                    'Time Zone' => $store->time_zone,
                    'Region' => $store->region?->name ?? 'N/A',
                    'District Manager' => $store->district_manager,
                    'Service Provider' => $store->service_provider,
                    'Shift Type' => implode(', ', $store->shift_type ?? []),
                    'Status' => $store->status == 1 ? 'Active' : 'Inactive',
                ];
            })->toArray();

            $file = $this->exportData($dataToExport, 'stores.xlsx');

            if ($file) {
                return $this->apiResponse(['file' => $file], true, null, 200);
            } else {
                return $this->apiResponse([], false, 'Error exporting data.', 500);
            }
        } catch (\Exception $ex) {
            return $this->handleException($ex);
        }
    }

public function importStore(Request $request)
{
    try {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:xlsx,xls',
            'company_id' => 'required|integer|exists:companies,id,status,1',
        ]);

        $validator->validate();

        $companyId = $request->company_id;
        $company = Company::findOrFail($companyId);


        $currentStoreCount = $company->stores()->where('status', 1)->count();
        $allowedStoreCount = $company->number_of_stores;


        $remainingStores = $allowedStoreCount - $currentStoreCount;


        $file = $request->file('file');
        $spreadsheet = IOFactory::load($file);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray();

        if (empty($rows) || count($rows) <= 1) {
            return $this->apiResponse([], false, 'The uploaded file is empty or has invalid content.', 400);
        }




            $fileStoreCount = 0;
            foreach ($rows as $index => $row) {
                if ($index == 0 || $index == 1) continue;

                $name = $row[0];
                if (!empty($name)) {
                    $fileStoreCount++;
                }
            }


            if ($fileStoreCount > $remainingStores) {
                return $this->apiResponse([], false, 'The number of stores in the file exceeds the allowed limit.', 400);
            }


            $regions = $company->regions()->where('status', 1)->get()->keyBy('name');
            $brands = $company->brands()->where('status', 1)->get()->keyBy('name');
            $shiftTypes = ['breakfast', 'lunch', 'dinner', 'overnight'];

            $brandColumnStartIndex = 6+ $regions->count() + count($shiftTypes); // بداية أعمدة العلامات التجارية
            $data = [];
            $data = [];
            $storesAdded = 0;

            foreach ($rows as $index => $row) {
                if ($index == 0 || $index == 1) continue;

                if (empty(array_filter($row))) {
                    continue;
                }


                $name = $row[0];
                $country = $row[1];
                $state = $row[2];
                $timeZone=$row[3];
                $districtManager = $row[4];
                $service_provider = $row[5];
                $shiftTypeData = [];
                $regionData = [];
                $brandId = null;

                for ($i = 6; $i < 6 + $regions->count(); $i++) {
                    if (isset($row[$i]) && $row[$i] == 1) {
                        $regionName = array_keys($regions->toArray())[$i - 6] ?? null;

                        if ($regionName && isset($regions[$regionName])) {
                            $regionData[] = $regions[$regionName]->id;
                        }
                    }
                }


                $shiftColumnsStartIndex = 6 + $regions->count();
                for ($i = $shiftColumnsStartIndex; $i < $shiftColumnsStartIndex + count($shiftTypes); $i++) {
                    if (isset($row[$i]) && $row[$i] == 1) {
                        $shiftType = $this->getShiftTypeByIndex($i - $shiftColumnsStartIndex);
                        if ($shiftType) {
                            $shiftTypeData[] = $shiftType;
                        }
                    }
                }



                $selectedBrands = [];
                for ($i = $brandColumnStartIndex; $i < $brandColumnStartIndex + $brands->count(); $i++) {

                    if (isset($row[$i]) && $row[$i] == 1) {
                        $brandName = array_keys($brands->toArray())[$i - $brandColumnStartIndex] ?? null;

                        if ($brandName && isset($brands[$brandName])) {
                            $selectedBrands[] = $brands[$brandName]->id;
                        }
                    }
                }

                if (count($selectedBrands) !== 1) {
                    return $this->apiResponse([], false, "Each store must be assigned exactly one brand at row {$index}.", 400);
                }


                $brandId = $selectedBrands[0];


                $store = Store::updateOrCreate(
                    [
                        'company_id' => $companyId,
                        'name' => $name,
                        'region_id' => $regionData[0] ?? null,
                    ],
                    [
                        'state' => $state,
                        'country' => $country,
                        'district_manager' => $districtManager,
                        'service_provider' => $service_provider,
                        'shift_type' => $shiftTypeData,
                        'time_zone'=>$timeZone,
                        'brand_id' => $brandId,
                        'status' => 1,
                    ]
                );

                $storesAdded++;
                $data[] = new StoreResource($store);
                // }
            }

            return $this->apiResponse($data);
        } catch (\Exception $ex) {
        return $this->handleException($ex);
    }
}


    private function getShiftTypeByIndex($index)
    {
        $shiftTypes = ['breakfast', 'lunch', 'dinner', 'overnight'];
        return $shiftTypes[$index] ?? null;
    }


}
