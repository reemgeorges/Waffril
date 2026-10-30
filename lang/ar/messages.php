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

    // WAFFRLI driver API (2026-11-02).
    'driver' => [
        'invalid_credentials' => 'رقم الهاتف أو كلمة المرور غير صحيحة.',
        'account_inactive' => 'حساب السائق غير نشط. تواصل مع فريق العمليات.',
        'logged_out' => 'تم تسجيل الخروج.',
        'password_changed' => 'تم تغيير كلمة المرور وتسجيل الخروج من الجلسات الأخرى.',
        'current_password_wrong' => 'كلمة المرور الحالية غير صحيحة.',
        'profile_updated' => 'تم تحديث الملف الشخصي.',
        'availability_updated' => 'تم تحديث حالة التوفر.',
        'cannot_go_offline_with_active_delivery' => 'لا يمكنك التحول إلى غير متصل أثناء وجود توصيل نشط. أكمل التوصيل أو تواصل مع فريق العمليات.',
        'order_not_found' => 'الطلب غير موجود.',
        'already_delivered' => 'تم توصيل هذا الطلب مسبقاً.',
        'cash_amount_mismatch' => 'يجب أن يساوي النقد المحصل (:collected) المبلغ المستحق (:due :currency). إذا تعذر الدفع فأبلغ عن مشكلة.',
        'cash_currency_mismatch' => 'يجب تحصيل النقد بعملة :currency المحددة عند إتمام الطلب.',
        'cash_not_due' => 'لا يوجد مبلغ نقدي مستحق لهذا الطلب؛ لا تقم بتحصيل نقد.',
        'no_active_delivery' => 'يتم قبول تحديثات الموقع فقط أثناء وجود توصيل مُسند إليك أو تم استلامه.',
        'location_updated' => 'تم تحديث الموقع.',
        'problem_reported' => 'تم إبلاغ فريق العمليات بالمشكلة.',
        'problem_requires_active_order' => 'يمكن الإبلاغ عن المشاكل فقط للطلبات المسندة إليك أو المستلمة.',
        'notification_marked_read' => 'تم تعليم الإشعار كمقروء.',
        'notifications_marked_read' => 'تم تعليم :count إشعارات كمقروءة.',
        'delivered' => 'تم إكمال التوصيل.',
        'picked_up' => 'تم استلام الطلب.',
        'problem_types' => [
            'customer_unreachable' => 'تعذر الوصول إلى العميل',
            'wrong_address' => 'عنوان خاطئ أو ناقص',
            'customer_refused' => 'رفض العميل الطلب',
            'branch_delay' => 'انتظار طويل في الفرع',
            'order_issue' => 'مشكلة في محتويات الطلب',
            'vehicle_issue' => 'مشكلة في المركبة',
            'safety_issue' => 'مخاوف تتعلق بالسلامة',
            'other' => 'أخرى',
        ],
    ],
];
