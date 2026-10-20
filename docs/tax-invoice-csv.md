# CSV صورتحساب برای سامانه مودیان (CRM → مالیاتی)

خروجی: «صورتحساب‌ها ← خروجی CSV برای سامانه مودیان» (چند صورتحساب) یا دکمهٔ
«خروجی CSV مالیاتی» در صفحهٔ یک صورتحساب. فقط صورتحساب **صادرشده**.
کد: `modules/Finance/Services/TaxInvoiceExport.php`.

- UTF-8 با BOM، جداکننده `,`، سطر اول نام ستون‌ها.
- **هر قلم یک سطر**؛ ستون‌های سرآمد در همهٔ سطرهای یک صورتحساب تکرار می‌شوند و
  `crm_invoice_number` سطرها را به صورتحساب گروه می‌کند.
- مبالغ به **ریال صحیح** (گرد به نزدیک‌ترین). ارز غیر ریال فقط هشدار می‌دهد و تبدیل نمی‌شود.
- هیچ‌چیز در CRM تغییر نمی‌کند (فقط خواندن).

| ستون | فیلد مالیاتی | منبع در CRM |
|---|---|---|
| crm_invoice_number | — | `custom_number` یا `invoice_number` |
| issue_date | indatim | `issue_date` (میلادی `YYYY-MM-DD`) |
| invoice_type | inty (1/2/3) | `tax_invoice_type`؛ اگر خالی: 1 وقتی خریدار شناسه دارد، وگرنه 2 (با هشدار) |
| payment_type | setm | همیشه `cash` (نقدی) |
| buyer_type | tob | 2 اگر مخاطب شرکت دارد، وگرنه 1؛ بی‌خریدار: خالی |
| buyer_name | — | نام مخاطب |
| buyer_national_id | bid | `contacts.national_id` |
| buyer_economic_id | tinb | `contacts.economic_code` |
| buyer_postal_code | bpc | `contacts.postal_code` |
| row | — | شمارهٔ قلم |
| product_code | sstid | کد ردیف اگر ۱۳ رقم است، وگرنه `products.tax_product_code` |
| description | sstt | نام قلم یا شرح |
| quantity | am | مقدار |
| unit_code / unit | mu | کد رسمی از نام واحد کالا (`config/tax_units.php`) / نام |
| unit_price | fee | فی (در قیمت‌گذاری «با مالیات»، بدون مالیات) |
| price_before_discount | prdis | مقدار × فی |
| discount | dis | تخفیف ردیف + سهم ردیف از تخفیف کل فاکتور (به نسبت مبلغ) |
| price_after_discount | adis | |
| tax_rate | vra | نرخ ذخیره‌شده روی ردیف (`invoice_items.tax_rate`)؛ وگرنه از کالا: معاف → 0، وگرنه `products.vat_rate` (نبود: 0 با هشدار) |
| tax_amount | vam | adis × vra ÷ 100 |
| total_amount | tsstam | adis + vam |
| note2 | nti2 | شمارهٔ صورتحساب CRM («یادداشت ۲») |

شمارهٔ صورتحساب و شمارهٔ مالیاتی را سامانهٔ مالیاتی خودش می‌سازد.

هشدارها (فایل را متوقف نمی‌کنند؛ بعد از تأیید کاربر فایل ساخته می‌شود): ارز غیر ریال،
تخفیف کل فاکتور، نوع تعیین‌نشده، نوع اول بدون شناسهٔ خریدار، نبود شناسهٔ ۱۳ رقمی،
واحد خارج از فهرست رسمی، نبود نرخ ارزش افزوده، و تفاوت جمع ارزش افزودهٔ ردیف‌ها با
فاکتور CRM (فاکتورهایی که هیچ کالای دارای نرخ ندارند هنوز مالیات را یک‌جا با پروفایل مالیاتی حساب می‌کنند).
