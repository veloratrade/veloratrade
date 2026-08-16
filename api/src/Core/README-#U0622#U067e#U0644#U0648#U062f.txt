رفع خطای Exim: Message lines too long

فقط این فایل را با Overwrite جایگزین کنید:
src/Core/Mailer.php → public_html/api/src/Core/Mailer.php

این اصلاح قالب‌های HTML با CSS inline را به quoted-printable تبدیل می‌کند تا طول هیچ خط SMTP از حد مجاز عبور نکند.
