<?php

/*
| WAFFRLI (2026-11-04) — نصوص الإشعارات المرسلة إلى العملاء والسائقين
| والمشرفين. يُخزَّن كل إشعار باللغتين، وتُرسل إشعارات push والإشعارات
| الفورية بلغة المستلم.
*/

return [
    'customer' => [
        'order_title' => 'الطلب :number',
        'order' => [
            'accepted' => 'تم قبول طلبك :number.',
            'assigned' => 'السائق في طريقه لاستلام طلبك :number.',
            'picked_up' => 'طلبك :number في الطريق إليك.',
            'delivered' => 'تم توصيل طلبك :number. بالهناء والعافية!',
            'cancelled' => 'تم إلغاء طلبك :number.',
            'cancellation_rejected' => 'تعذّرت الموافقة على طلب إلغاء :number.',
            'refunded' => 'تم تسجيل مبلغ مسترد لطلبك :number.',
        ],
        'plus_title' => 'وفرلي بلس',
        'plus' => [
            'approved' => 'تمت الموافقة على طلبك في وفرلي بلس.',
            'rejected' => 'تعذّرت الموافقة على طلبك في وفرلي بلس.',
        ],
    ],
    'driver' => [
        'order_title' => 'توصيل وفرلي',
        'order' => [
            'assigned' => 'تم إسناد توصيل جديد إليك: :number',
            'unassigned' => 'تمت إعادة إسناد التوصيل :number.',
            'cancelled' => 'تم إلغاء التوصيل :number.',
        ],
    ],
    'admin' => [
        'problem_reported' => 'أبلغ السائق :driver عن مشكلة في :number: :problem',
    ],
];
