<?php

/*
 * Mobile API vocabulary (ar). The human labels the customer and driver
 * APIs return next to stable machine values ("status": "picked_up",
 * "status_label": "…"), and the account-deletion blockers.
 *
 * Kept apart from lang/ar/admin.php on purpose (API audit, 2026-11-13):
 * the admin panel wording review of 2026-11-11 rewrote shared keys and so
 * changed text the Flutter apps already show. These values are the API text
 * as released before that review. Change them only as a deliberate app
 * change; panel wording lives in admin.php. A key missing here falls back to
 * the admin.php key of the same name.
 */

return [
    'branches' => [
        'kind_closed' => 'مغلق',
        'kind_open' => 'فتح استثنائي',
    ],
    'customers' => [
        'blockers' => [
            'already_anonymized' => 'تم إخفاء الهوية مسبقاً.',
            'not_deleted' => 'لم يطلب العميل حذف الحساب.',
            'open_cancellation_requests' => 'توجد طلبات إلغاء مفتوحة.',
            'open_orders' => 'توجد طلبات قيد التنفيذ.',
            'pending_payments' => 'توجد مدفوعات معلقة.',
            'recovery_window' => 'ما زال ضمن فترة الاستعادة (حتى :until).',
            'wallet_balance' => 'يوجد رصيد غير صفري في المحفظة (سياسة التسوية غير محددة بعد).',
        ],
        'status_active' => 'نشط',
        'status_suspended' => 'موقوف',
    ],
    'dispatch' => [
        'status_accepted' => 'مقبول',
        'status_cancelled' => 'مسحوب',
        'status_expired' => 'منتهي',
        'status_pending' => 'بانتظار رد السائق',
        'status_rejected' => 'مرفوض',
    ],
    'drivers' => [
        'availability_available' => 'متاح',
        'availability_offline' => 'غير متصل',
    ],
    'order_status' => [
        'accepted' => 'مقبول',
        'assigned' => 'تم إسناد سائق',
        'cancelled' => 'ملغى',
        'delivered' => 'تم التوصيل',
        'pending' => 'معلق',
        'picked_up' => 'تم الاستلام من الفرع',
    ],
    'orders' => [
        'actor_admin' => 'مشرف',
        'actor_customer' => 'عميل',
        'actor_driver' => 'سائق',
        'actor_system' => 'النظام',
        'reason_branch_unavailable' => 'الفرع غير متاح',
        'reason_customer_refused' => 'رفض العميل',
        'reason_customer_request' => 'طلب العميل',
        'reason_customer_unreachable' => 'تعذر الوصول للعميل',
        'reason_other' => 'أخرى',
        'reason_product_unavailable' => 'منتج غير متوفر',
        'type_immediate' => 'فوري',
        'type_scheduled' => 'مجدول',
    ],
    'payments' => [
        'kind_cash' => 'الدفع عند الاستلام',
        'kind_online' => 'إلكتروني (مستقبلاً)',
        'kind_wallet' => 'المحفظة',
        'status_cancelled' => 'ملغى',
        'status_failed' => 'فشل',
        'status_paid' => 'مدفوع',
        'status_pending' => 'معلق',
        'status_reversed' => 'معكوس',
    ],
    'plus' => [
        'cycle_active' => 'نشطة',
        'cycle_cancelled' => 'ملغاة',
        'cycle_expired' => 'منتهية',
        'cycle_scheduled' => 'مجدولة',
        'membership_active' => 'نشطة',
        'membership_cancelled' => 'ملغاة (بلا تجديد)',
        'membership_ended' => 'منتهية',
        'policy_custom' => 'مدة مخصصة',
        'policy_monthly' => 'شهري',
        'policy_one_time' => 'مرة واحدة',
        'request_cancel' => 'إلغاء',
        'request_change' => 'تغيير الخطة',
        'request_custom' => 'مخصص',
        'request_new' => 'جديد',
        'request_renew' => 'تجديد',
        'source_customer' => 'العميل',
        'source_system' => 'النظام (تذكير تجديد)',
    ],
    'requests' => [
        'status_approved' => 'موافق عليه',
        'status_pending' => 'معلق',
        'status_rejected' => 'مرفوض',
        'status_withdrawn' => 'مسحوب',
    ],
    'settlements' => [
        'status_disputed' => 'متنازع عليها',
        'status_open' => 'مفتوحة',
        'status_settled' => 'مسوّاة',
    ],
    'wallets' => [
        'direction_credit' => 'إيداع',
        'direction_debit' => 'خصم',
        'type_order_payment' => 'دفع طلب',
        'type_order_payment_reversal' => 'عكس دفعة',
        'type_order_refund' => 'استرداد',
    ],
];
