<?php

namespace App\Http\Requests\Basic;

use Illuminate\Http\Request as HttpRequest;

/**
 * استدعاء داخلي لخدمات الـ CRUD. لا تستخدموه عبر HTTP.
 * ممنوع إنشاءه بـ new — استخدم factory make() حصراً.
 */
class InternalArrayRequest extends BasicRequest
{
    protected array $payload = [];

    /**
     * Factory: يجهّز الحقائب (headers/server/params) بشكل صحيح
     */
    public static function make(array $payload): self
    {
        // POST افتراضيًا، يضبط headers/server/… داخليًا
        $base = HttpRequest::create('/', 'POST', $payload);

        /** @var self $form */
        $form = self::createFromBase($base);   // أهم سطر
        $form->setContainer(app());
        $form->setRedirector(app('redirect'));

        // خزّن الـ payload لنعيده في validated()
        $form->payload = $payload;

        // حتى تعمل input()/all()
        $form->replace($payload);

        return $form;
    }

    // لا قواعد هنا — التحقق تم سابقًا
    public function rules(): array
    {
        return [];
    }

    // BasicCrudService يعتمد على validated()
    public function validated($key = null, $default = null): array
    {
        return $this->payload;
    }
}
