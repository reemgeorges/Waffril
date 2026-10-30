<?php

/*
 * WAFFRLI P0 (2026-10-30). Only P0 keys are defined here; any other
 * messages.* key used elsewhere keeps resolving to its key exactly as
 * before this file existed.
 */
return [
    'db_check_violation' => 'البيانات المرسلة تخالف إحدى قواعد سلامة البيانات.',
    'currency_default_required_first' => 'يجب تهيئة العملة الافتراضية قبل إضافة عملات أخرى.',
    'currency_default_already_initialized' => 'توجد عملة افتراضية بالفعل. تغيير العملة الافتراضية غير متاح حالياً.',
    'currency_default_rate_fixed' => 'سعر صرف العملة الافتراضية يساوي 1 دائماً.',
    'currency_default_cannot_deactivate' => 'لا يمكن تعطيل العملة الافتراضية.',
    'delivery_fee_rule_overlap' => 'نطاق المسافة هذا يتداخل مع نطاق فعّال آخر في النطاق نفسه.',
    'delivery_fee_rule_area_city_mismatch' => 'يجب أن تتبع منطقة التوصيل المدينة المحددة.',
    'delivery_fee_rule_invalid_band' => 'يجب أن تكون distance_to_km أكبر من distance_from_km.',
    'setting_value_required' => 'القيمة مطلوبة.',
    'setting_monetary_value_invalid' => 'يجب أن يكون الإعداد المالي رقماً غير سالب بأربع منازل عشرية كحد أقصى.',
    'city_not_served' => 'هذا الموقع خارج جميع مدن الخدمة.',
];
