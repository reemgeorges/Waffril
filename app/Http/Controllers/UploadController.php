<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Traits\GeneralTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class UploadController extends Controller
{
    use GeneralTrait;

    public function single(Request $request, string $folder)
    {
        try {

            $rules = [
                'file' => [
                    'required',
                    'file',
                    'max:51200',
                    function ($attribute, $value, $fail) {

                        $allowedMime = [
                            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
                            'video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska', 'video/webm',
                            'application/pdf',
                            'application/msword',
                            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',

                        ];
                        if (!in_array($value->getMimeType(), $allowedMime)) {
                            $fail('الملف يجب أن يكون صورة أو فيديو بصيغة مدعومة.');
                        }
                    }
                ]
            ];

            $messages = [
                'file.required' => 'يرجى اختيار ملف للرفع.',
                'file.file'     => 'الملف المرفوع غير صالح.',
                'file.max'      => 'حجم الملف يجب ألا يتجاوز 50 ميجابايت.',
            ];

            $request->validate($rules, $messages);


            if (! preg_match('/^[A-Za-z0-9_-]+$/', $folder)) {
                return $this->requiredField('اسم المجلد غير صالح. يجب أن يحتوي على حروف أو أرقام أو شرطة سفلية أو وسطية فقط.');
            }


            $disk     = 'public';
            $ext      = $request->file('file')->getClientOriginalExtension();
            $filename = Str::uuid() . '.' . $ext;
            $path     = $request->file('file')->storeAs($folder, $filename, $disk);
            $url      = Storage::disk($disk)->url($path);

            return $this->apiResponse(
                ['path' => $path, 'url' => $url],
                true,
                null,
                201
            );
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }


    public function multiple(Request $request, string $folder)
    {
        try {
            $rules = [
                'files'   => ['required', 'array', 'min:1'],
                'files.*' => [
                    'required',
                    'file',
                    'max:51200',
                    function ($attribute, $value, $fail) {

                        $allowed = [
                            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
                            'video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska', 'video/webm',
                            'application/pdf',
                            'application/msword',
                            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        ];


                        $detected = $value->getMimeType();
                        if (! in_array($detected, $allowed, true)) {
                            $fail('أحد الملفات ليس صورة/فيديو بصيغة مدعومة. الصيغ المسموحة: JPEG, PNG, GIF, WEBP, MP4, MOV, AVI, MKV, WEBM.');
                        }
                    },
                ],
            ];

            $messages = [
                'files.required' => 'يرجى اختيار ملف واحد على الأقل.',
                'files.array'    => 'هيكلية الملفات غير صحيحة، يجب إرسالها كمصفوفة.',
                'files.min'      => 'يجب رفع ملف واحد على الأقل.',
                'files.*.required' => 'أحد عناصر الملفات مفقود.',
                'files.*.file'     => 'أحد العناصر ليس ملفاً صالحاً.',
                'files.*.max'      => 'أحد الملفات يتجاوز الحد الأقصى 50 ميجابايت.',
            ];


            $validator = Validator::make($request->all(), $rules, $messages);
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'فشل التحقق من صحة الملفات.',
                    'errors'  => $validator->errors(),
                ], 422);
            }

            if (! preg_match('/^[A-Za-z0-9_-]+$/', $folder)) {
                return $this->requiredField('اسم المجلد غير صالح. مسموح فقط الحروف والأرقام والشرطة (-) والشرطة السفلية (_).');
            }

            $disk  = 'public';
            $paths = [];
            $urls  = [];

            foreach ($request->file('files') as $file) {
                $ext      = $file->getClientOriginalExtension();
                $filename = Str::uuid() . ($ext ? ('.' . $ext) : '');
                $path     = $file->storeAs($folder, $filename, $disk);

                $paths[] = $path;
                $urls[]  = Storage::disk($disk)->url($path);
            }

            return $this->apiResponse(
                ['paths' => $paths, 'urls' => $urls],
                true,
                null,
                201
            );
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

}
