# دليل أوامر التيرمنال — Laravel API Starter Kit

> الأوامر التالية مخصصة لـ Windows PowerShell وLaravel Starter Kit الذي طورناه. كل أمر تحته شرح مباشر لوظيفته. نفّذي الأوامر التي تنشئ أو تعدّل بيانات على مشروع تجريبي أولاً.

## 1) الدخول إلى المشروع الأساسي

```powershell
cd C:\laragon\www\base
```
**شو بيعمل؟** يدخل على مجلد الـ Starter Kit الأصلي، الذي يحتوي على أوامر التوليد.

```powershell
php artisan list
```
**شو بيعمل؟** يعرض جميع أوامر Artisan المسجلة؛ للتأكد أن أوامر المشروع متوفرة.

```powershell
php artisan starter:doctor
```
**شو بيعمل؟** يفحص إعدادات المشروع والـ Guards والـ Middleware والـ OTP وأوامر التوليد واتصال قاعدة البيانات من دون تعديلها. يمكن أن يُظهر فشل الاتصال إذا قاعدة بيانات التطوير غير شغالة.

## 2) إنشاء مشروع جديد من الـ Starter

```powershell
php artisan starter:new ../my-new-shop --name="My New Shop"
```
**شو بيعمل؟** ينشئ نسخة مستقلة داخل `C:\laragon\www\my-new-shop` مع `.env` و`APP_KEY` جديدين. يرفض الكتابة فوق مجلد غير فارغ، ولا يشغّل Composer أو Migrations تلقائياً.

```powershell
cd C:\laragon\www\my-new-shop
```
**شو بيعمل؟** ينتقل إلى المشروع الجديد. الأوامر التالية تُنفّذ فيه وليس في `base`.

```powershell
composer install
```
**شو بيعمل؟** يثبت اعتماديات PHP وفق `composer.lock` إذا كان موجوداً. يحتاج Composer واتصالاً بالإنترنت.

```powershell
php artisan key:generate
```
**شو بيعمل؟** يولّد مفتاح تطبيق جديد عند الحاجة فقط. **غالباً غير مطلوب** بعد `starter:new` لأنه يولّد `APP_KEY` بالفعل؛ لا تنفذيه على تطبيق يعمل ببيانات مشفرة أو جلسات مستخدمين من دون دراسة تأثير تغيير المفتاح.

```powershell
php artisan migrate:status
```
**شو بيعمل؟** يعرض حالة الـ Migrations دون تشغيلها. تأكدي أولاً من إعداد الاتصال بقاعدة البيانات في `.env`.

```powershell
php artisan migrate
```
**شو بيعمل؟** ينفّذ الـ Migrations غير المنفذة على قاعدة البيانات المحددة في `.env`. **يغيّر قاعدة البيانات**، لذا استخدميه على قاعدة محلية أو اختبارية بعد التأكد من الاتصال الصحيح.

```powershell
php artisan serve
```
**شو بيعمل؟** يشغّل خادم Laravel المحلي للتجربة؛ العنوان الافتراضي عادةً `http://127.0.0.1:8000`.

## 3) توليد CRUD كامل

```powershell
php artisan make:module Category --fields="name:string:required;description:text:nullable" --guard=sanctum --permissions --with-tests --with-openapi --dry-run
```
**شو بيعمل؟** يعرض خطة إنشاء Module باسم `Category` **دون كتابة ملفات**. يضيف حقول الاسم والوصف، ويجهّز حماية وصلاحيات واختبارات وتوثيق API.

```powershell
php artisan make:module Category --fields="name:string:required;description:text:nullable" --guard=sanctum --permissions --with-tests --with-openapi
```
**شو بيعمل؟** ينشئ ملفات Category فعلياً: Migration وModel وRequest وResource وService وController وRoutes وPolicy واختبارات وجزء OpenAPI. لا ينفذ Migration تلقائياً. يجب أن يكون الـ Guard المختار مهيأً في المشروع.

```powershell
php artisan make:module Product --fields="title:string:required;price:decimal:8,2:required;description:text:nullable" --relations="category:belongsTo:Category" --images="cover:single;gallery:multiple:5" --guard=sanctum --permissions --stats --with-tests --with-openapi --dry-run
```
**شو بيعمل؟** يعرض معاينة لتوليد Product مرتبط بـ Category، مع صورة غلاف واحدة ومعرض حتى خمس صور وإحصائيات وصلاحيات واختبارات وتوثيق.

```powershell
php artisan make:module Product --fields="title:string:required;price:decimal:8,2:required;description:text:nullable" --relations="category:belongsTo:Category" --images="cover:single;gallery:multiple:5" --guard=sanctum --permissions --stats --with-tests --with-openapi
```
**شو بيعمل؟** ينفذ التوليد فعلياً. الصور خاصة افتراضياً، وللمعرض حدود رفع، ومسارات الصور خلف الـ Guard. لا تستعملي `--force` إلا بعد مراجعة الملفات التي ستُستبدل.

```powershell
php artisan make:module --help
```
**شو بيعمل؟** يعرض خيارات المولّد، ومنها `--relations` و`--images` و`--stats-group-by` و`--with-seeder` و`--interactive` و`--crud` و`--manifest`.

## 4) توليد نظام تسجيل ودخول مستقل

```powershell
php artisan make:auth-module Customer --login=email --registration --otp --enable-routes --with-tests --with-openapi --dry-run
```
**شو بيعمل؟** يعرض معاينة لإنشاء Auth باسم Customer مع تسجيل بالبريد وتحقق OTP ومسارات مفعلة واختبارات وتوثيق، دون كتابة ملفات.

```powershell
php artisan make:auth-module Customer --login=email --registration --otp --enable-routes --with-tests --with-openapi
```
**شو بيعمل؟** ينشئ نظام Customer Auth مع Model وMigration وService وController وPolicy وRoutes وغيرها. يحتاج قناة OTP حقيقية مهيأة للتشغيل خارج بيئة الاختبار؛ لا يضبط SMTP أو SMS بنفسه.

```powershell
php artisan make:auth-module --help
```
**شو بيعمل؟** يعرض خيارات Auth مثل `--guard` و`--login` و`--otp-actions` و`--registration` و`--enable-routes`. المسارات تكون معطلة افتراضياً ما لم يُستخدم خيار التفعيل.

## 5) توليد Postman وOpenAPI وفحص التوثيق

```powershell
php artisan openapi:build --postman
```
**شو بيعمل؟** يجمع ملفات OpenAPI المولّدة داخل `docs/openapi/openapi.yaml`، ويولد مجموعة Postman وقالب Environment من التعريفات نفسها.

```powershell
php artisan docs:check-drift
```
**شو بيعمل؟** يقارن التوثيق المجمّع مع مسارات API المسجلة ويبلّغ عن الاختلافات. لا يثبت صحة الصلاحيات الأمنية وحده.

```powershell
php artisan route:list --path=api
```
**شو بيعمل؟** يعرض مسارات API الفعلية وأسماء الـ Controllers والـ Middleware للتحقق من تسجيلها.

## 6) الاختبارات والجودة

```powershell
php artisan test
```
**شو بيعمل؟** يشغّل الاختبارات الآلية للمشروع. آخر نتيجة معلنة من Claude بعد Phase 11 والتنظيف هي 225 اختباراً ناجحاً؛ النتيجة الجديدة تعتمد على البيئة والكود الحاليين.

```powershell
php artisan test --filter=StarterE2EScenariosTest
```
**شو بيعمل؟** يشغّل سيناريوهات E2E الخاصة بالموديلات المولدة والمصادقة دون تشغيل كل الاختبارات.

```powershell
php artisan test --filter=StarterDoctorCommandTest
```
**شو بيعمل؟** يشغّل اختبارات أمر فحص البنية.

```powershell
vendor\bin\pint --test
```
**شو بيعمل؟** يفحص تنسيق كود PHP دون تعديله. ذكر Claude وجود مخالفات تنسيق قديمة في بعض الملفات، لذلك الفشل هنا لا يعني وحده فشل اختبارات السلوك.

## 7) الطوابير وتنظيف الرموز

```powershell
php artisan queue:work
```
**شو بيعمل؟** يشغّل عامل معالجة المهام المؤجلة، ومنها رسائل OTP إذا كانت القناة تستخدم Queue. يحتاج إعداد `QUEUE_CONNECTION` وما يلزمها من اتصال صحيح.

```powershell
php artisan otp:cleanup-expired
```
**شو بيعمل؟** يحذف سجلات OTP المنتهية منذ أكثر من يوم وفق تنفيذ الخدمة. **هذا أمر حذف بيانات من جدول OTP**؛ شغّليه على قاعدة البيانات الصحيحة فقط.

```powershell
php artisan tokens:cleanup-expired
```
**شو بيعمل؟** يشغّل تنظيف رموز الوصول المنتهية بحسب منطق المشروع. **يغيّر بيانات قاعدة البيانات**.

## 8) Git ومراجعة التغييرات

```powershell
git status
```
**شو بيعمل؟** يعرض الملفات المعدلة وغير المتتبعة في المشروع دون تغييرها.

```powershell
git log --oneline -5
```
**شو بيعمل؟** يعرض آخر خمس نقاط حفظ Git لتتبع مراحل التطوير والتنظيف.

## 9) إنشاء نسخة ZIP آمنة للمشاركة — PowerShell

```powershell
cd C:\laragon\www\base
```
**شو بيعمل؟** يعود إلى المشروع الأساسي قبل إنشاء النسخة.

```powershell
$files = git ls-files --cached --others --exclude-standard
```
**شو بيعمل؟** يجمع أسماء الملفات المتتبعة وغير المتتبعة التي لا يستثنيها Git. راجعي القائمة قبل النسخ؛ هذا ليس تدقيقاً أمنياً للأسرار داخل الملفات المتتبعة.

```powershell
$files | Select-Object -First 30
```
**شو بيعمل؟** يعرض عينة من الملفات التي قد تدخل الأرشيف، ولا ينشئ ملفاً بعد.

**لإنشاء ZIP نظيف، الأفضل استخدام نسخة من ملفات Git المتتبعة بعد التأكد أنها لا تحتوي أسراراً؛ لا تضغطي مجلد `base` بالكامل لأن ملفات `.env` و`.git` قد تدخل الأرشيف.**

```powershell
git archive --format=zip --output="..\base-starter-safe.zip" HEAD
```
**شو بيعمل؟** ينشئ أرشيفاً من الملفات الموجودة في آخر Commit فقط، ويستثني الملفات غير المتتبعة مثل `.env` و`.git` تلقائياً. أي ملفات جديدة لم يتم Commit لها لن تظهر في الأرشيف. راجعي المحتوى قبل مشاركته.

## 10) أوامر المساعدة

```powershell
php artisan help make:module
```
**شو بيعمل؟** يعرض شرح أمر توليد CRUD وخياراته من الكود الحالي مباشرة.

```powershell
php artisan help starter:new
```
**شو بيعمل؟** يعرض خيارات إنشاء مشروع مستقل، ومنها `--name` و`--with-git-history`.

```powershell
php artisan help starter:doctor
```
**شو بيعمل؟** يعرض معلومات أمر التشخيص.

---

**ملاحظات مهمة:**
- أمثلة `--guard=sanctum` تحتاج أن يكون هذا الـ Guard مناسباً ومهيأً للـ Module؛ لا تنسخيها تلقائياً إلى كل المشاريع.
- التوليد لا ينفذ Migrations أو Seeders بنفسه، والمسارات غير المحمية ليست الوضع الافتراضي.
- اختبار `starter:doctor` على قاعدة بيانات تطوير غير متاحة قد يعرض FAIL رغم نجاح PHPUnit على SQLite منفصلة.
- ما زال تدقيق دورة حياة ملفات الصور في النسخة `base(2).zip` يحتاج متابعة للإصلاح النهائي الذي ناقشناه.
