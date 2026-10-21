# Mirsys CRM — نصب روی Laragon (شبکهٔ داخلی، با لایسنس)

این نسخه به‌جای PHP و MariaDB همراه بسته، از **Laragon** استفاده می‌کند: Apache (مناسب کاربران
هم‌زمان بیشتر)، PHP و MySQL/MariaDB خود Laragon. لایسنس و سقف کاربران مثل نسخهٔ آفلاین است
(همان دروازهٔ `windows-offline/router.php` جلوی CRM قرار می‌گیرد).

## پیش‌نیاز
| مورد | توضیح |
|---|---|
| Laragon (Full) | با PHP **8.1 یا بالاتر** و MySQL/MariaDB؛ رمز root دیتابیس خالی (پیش‌فرض Laragon). شرایط لایسنس نسخهٔ Laragon را برای استفادهٔ تجاری بررسی کنید |
| پوشهٔ `vendor\` | کتابخانهٔ PDF، کنار پوشهٔ `app\` |
| دسترسی مدیر ویندوز | یک بار، برای `install.bat` |

CRM را در پوشه‌ای **خارج از** `C:\laragon\www` بگذارید (مثلاً `C:\mirsys-crm`) تا Laragon برایش سایت خودکار نسازد.

## نصب
۱. Laragon را باز کنید و **Start All** بزنید.
۲. روی `windows-laragon\install.bat` کلیک راست → **Run as administrator**.
   اگر Laragon در `C:\laragon` نیست: `install.bat D:\laragon`.
   کارها: بررسی PHP و افزونه‌ها، ساخت دیتابیس `mirsys_crm` با رمز تصادفی، ساخت `.env`، ساخت جدول‌ها،
   ساخت سایت Apache روی پورت **8080** (`etc\apache2\sites-enabled\mirsys-crm.conf`)، باز کردن فایروال، کارهای پس‌زمینه.
۳. در Laragon: **Menu → Apache → Reload** (یا Stop و Start All).
۴. `http://localhost:8080` را باز کنید: **کد نصب** را برای فروشنده بفرستید و فایل لایسنس را همان‌جا بارگذاری کنید.
۵. ثبت‌نام کنید و `windows-laragon\make-superadmin.bat your@email` را اجرا کنید.
۶. بقیهٔ کامپیوترها: `http://IP-سرور:8080`.

## کار روزانه
- اجرا/توقف از خود Laragon (Start All / Stop). گزینهٔ «اجرای Laragon با ویندوز» و «Start All خودکار» را در تنظیمات Laragon روشن کنید.
- کارهای پس‌زمینه خودکارند (Task Scheduler: `MirsysCRM-Cron`)، فقط وقتی لایسنس معتبر است.

## پشتیبان‌گیری
از Laragon → Database، دیتابیس `mirsys_crm` را خروجی بگیرید (یا `mysqldump`)، به‌علاوهٔ پوشهٔ `storage\` و فایل `.env`
و پوشهٔ `runtime\license\` (فایل لایسنس و کد نصب).

## لایسنس
همان روش نسخهٔ آفلاین — `windows-offline\README.md` بخش «لایسنس».
