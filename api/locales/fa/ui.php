<?php

/**
 * VELORA — Persian Translation File
 * 
 * This file contains all static UI translations for Persian language.
 * Organized by category for easy maintenance.
 * 
 * Usage:
 *   $locale->t('common.save')        // Returns: ذخیره
 *   $locale->t('dashboard.title')    // Returns: داشبورد
 */

return [
    // =========================================================================
    // COMMON - Shared across all pages
    // =========================================================================
    'common' => [
        // Actions
        'save' => 'ذخیره',
        'cancel' => 'لغو',
        'delete' => 'حذف',
        'edit' => 'ویرایش',
        'create' => 'ایجاد',
        'update' => 'بروزرسانی',
        'submit' => 'ارسال',
        'confirm' => 'تأیید',
        'close' => 'بستن',
        'back' => 'بازگشت',
        'next' => 'ادامه',
        'previous' => 'قبلی',
        'finish' => 'پایان',
        'retry' => 'تلاش مجدد',
        'refresh' => 'بروزرسانی',
        'search' => 'جستجو',
        'filter' => 'فیلتر',
        'sort' => 'مرتب‌سازی',
        'export' => 'خروجی',
        'import' => 'ورودی',
        'download' => 'دانلود',
        'upload' => 'آپلود',
        'share' => 'اشتراک‌گذاری',
        'copy' => 'کپی',
        'paste' => 'چسباندن',
        'print' => 'چاپ',
        
        // Navigation
        'home' => 'صفحه اصلی',
        'dashboard' => 'داشبورد',
        'login' => 'ورود',
        'logout' => 'خروج',
        'register' => 'ثبت‌نام',
        'profile' => 'پروفایل',
        'settings' => 'تنظیمات',
        'help' => 'راهنما',
        'support' => 'پشتیبانی',
        'blog' => 'وبلاگ',
        'faq' => 'سوالات متداول',
        'about' => 'درباره ما',
        'contact' => 'تماس با ما',
        'privacy' => 'حریم خصوصی',
        'terms' => 'شرایط استفاده',
        
        // Status
        'active' => 'فعال',
        'inactive' => 'غیرفعال',
        'online' => 'آنلاین',
        'offline' => 'آفلاین',
        'connected' => 'متصل',
        'disconnected' => 'قطع',
        'pending' => 'در انتظار',
        'loading' => 'در حال بارگذاری',
        'success' => 'موفق',
        'error' => 'خطا',
        'warning' => 'هشدار',
        'info' => 'اطلاعات',
        
        // Time
        'today' => 'امروز',
        'yesterday' => 'دیروز',
        'this_week' => 'این هفته',
        'this_month' => 'این ماه',
        'this_year' => 'این سال',
        'last_week' => 'هفته گذشته',
        'last_month' => 'ماه گذشته',
        'last_year' => 'سال گذشته',
        
        // Messages
        'confirm_delete' => 'آیا از حذف اطمینان دارید؟',
        'confirm_logout' => 'آیا می‌خواهید خارج شوید?',
        'no_data' => 'داده‌ای یافت نشد',
        'no_results' => 'نتیجه‌ای یافت نشد',
        'required_field' => 'این فیلد الزامی است',
        'invalid_email' => 'ایمیل نامعتبر است',
        'password_mismatch' => 'رمزهای عبور مطابقت ندارند',
        
        // Misc
        'yes' => 'بله',
        'no' => 'خیر',
        'ok' => 'باشه',
        'or' => 'یا',
        'and' => 'و',
        'all' => 'همه',
        'none' => 'هیچ',
        'other' => 'سایر',
        'optional' => 'اختیاری',
        'required' => 'الزامی',
    ],
    
    // =========================================================================
    // AUTH - Authentication related
    // =========================================================================
    'auth' => [
        'login_title' => 'ورود به حساب',
        'login_subtitle' => 'برای دسترسی به داشبورد معاملاتی خود وارد شوید',
        'register_title' => 'ساخت حساب',
        'register_subtitle' => 'ثبت‌نام سریع و امن',
        'forgot_password' => 'فراموشی رمز عبور',
        'reset_password' => 'بازنشانی رمز عبور',
        'verify_email' => 'تأیید ایمیل',
        'email' => 'ایمیل',
        'password' => 'رمز عبور',
        'confirm_password' => 'تأیید رمز عبور',
        'current_password' => 'رمز فعلی',
        'new_password' => 'رمز جدید',
        'full_name' => 'نام و نام خانوادگی',
        'remember_me' => 'مرا به خاطر بسپار',
        'forgot_password_link' => 'فراموشی رمز؟',
        'no_account' => 'حساب ندارید?',
        'has_account' => 'قبلاً حساب دارید?',
        'create_account' => 'ساخت حساب',
        'login_button' => 'ورود به حساب',
        'register_button' => 'ثبت‌نام',
        'logout_button' => 'خروج از حساب',
        'welcome_back' => 'خوش برگشتید',
        'welcome_message' => 'به ولورا خوش آمدید',
        'password_requirements' => 'حداقل 8 کاراکتر، شامل یک حرف انگلیسی و یک عدد',
        'email_sent' => 'ایمیل ارسال شد',
        'check_email' => 'صندوق ورودی خود را چک کنید',
        'resend_email' => 'ارسال مجدد ایمیل',
        'verification_sent' => 'لینک تأیید ارسال شد',
        'account_created' => 'حساب شما با موفقیت ایجاد شد',
        'login_success' => 'ورود موفق',
        'logout_success' => 'خروج موفق',
        'password_changed' => 'رمز عبور تغییر کرد',
        'password_reset_sent' => 'لینک بازیابی رمز ارسال شد',
    ],
    
    // =========================================================================
    // DASHBOARD - Main dashboard
    // =========================================================================
    'dashboard' => [
        'title' => 'داشبورد',
        'subtitle' => 'خلاصه عملکرد معاملاتی شما',
        'welcome' => 'خوش آمدید',
        'overview' => 'نمای کلی',
        'trades' => 'معاملات',
        'win_rate' => 'نرخ برد',
        'net_pnl' => 'سود خالص',
        'profit_factor' => 'فاکتور سود',
        'equity_curve' => 'منحنی سرمایه',
        'recent_trades' => 'معاملات اخیر',
        'view_all' => 'مشاهده همه',
        'new_trade' => 'ثبت معامله',
        'refresh' => 'بروزرسانی',
        'loading' => 'در حال بارگذاری...',
        'no_trades' => 'هنوز معامله‌ای ثبت نشده',
        'performance_summary' => 'خلاصه عملکرد',
        'ai_score' => 'امتیاز هوش مصنوعی',
        'risk_level' => 'سطح ریسک',
        'consistency' => 'ثبات عملکرد',
        'strategy_performance' => 'عملکرد استراتژی‌ها',
        'broker_accounts' => 'حساب‌های بروکر',
        'journal_timeline' => 'گاه‌شمار ژورنال',
        'trading_psychology' => 'روان‌شناسی معاملاتی',
    ],
    
    // =========================================================================
    // TRADES - Trade journal
    // =========================================================================
    'trades' => [
        'title' => 'ژورنال معاملات',
        'new_trade' => 'ثبت معامله',
        'edit_trade' => 'ویرایش معامله',
        'delete_trade' => 'حذف معامله',
        'symbol' => 'نماد',
        'direction' => 'جهت',
        'buy' => 'خرید',
        'sell' => 'فروش',
        'entry_price' => 'قیمت ورود',
        'exit_price' => 'قیمت خروج',
        'volume' => 'حجم',
        'stop_loss' => 'حد ضرر',
        'take_profit' => 'حد سود',
        'strategy' => 'استراتژی',
        'notes' => 'یادداشت',
        'date' => 'تاریخ',
        'time' => 'زمان',
        'open_time' => 'زمان باز',
        'close_time' => 'زمان بسته',
        'pnl' => 'سود/زیان',
        'result' => 'نتیجه',
        'win' => 'برد',
        'loss' => 'باخت',
        'breakeven' => 'سر به سر',
        'r_multiple' => 'R-Multiple',
        'commission' => 'کمیسیون',
        'swap' => 'سوآپ',
        'net_pnl' => 'سود خالص',
        'emotions' => 'احساسات',
        'setup' => 'ستاپ',
        'context' => 'کانتکست',
        'lesson' => 'درس آموخته',
        'mistakes' => 'اشتباهات',
        'tags' => 'برچسب‌ها',
        'search_symbol' => 'جستجوی نماد...',
        'no_trades' => 'هنوز معامله‌ای ثبت نشده',
        'trade_saved' => 'معامله ذخیره شد',
        'trade_deleted' => 'معامله حذف شد',
        'trade_updated' => 'معامله بروزرسانی شد',
    ],
    
    // =========================================================================
    // ACCOUNTS - Trading accounts
    // =========================================================================
    'accounts' => [
        'title' => 'حساب‌های معاملاتی',
        'connect' => 'اتصال حساب',
        'disconnect' => 'قطع اتصال',
        'sync' => 'همگام‌سازی',
        'account_number' => 'شماره حساب',
        'investor_password' => 'رمز اینوستور',
        'server' => 'سرور',
        'broker' => 'بروکر',
        'platform' => 'پلتفرم',
        'balance' => 'موجودی',
        'equity' => 'اکویتی',
        'leverage' => 'اهرم',
        'currency' => 'ارز',
        'status' => 'وضعیت',
        'connected' => 'متصل',
        'disconnected' => 'قطع',
        'syncing' => 'در حال همگام‌سازی',
        'last_sync' => 'آخرین همگام‌سازی',
        'auto_detect' => 'شناسایی خودکار سرور',
        'connect_success' => 'اتصال موفق',
        'connect_error' => 'خطا در اتصال',
        'sync_success' => 'همگام‌سازی موفق',
        'sync_error' => 'خطا در همگام‌سازی',
        'no_accounts' => 'هیچ حساب متاتریدری متصل نیست',
        'add_account' => 'افزودن حساب',
        'mt4' => 'MetaTrader 4',
        'mt5' => 'MetaTrader 5',
    ],
    
    // =========================================================================
    // MARKETS - Market data
    // =========================================================================
    'markets' => [
        'title' => 'بازارها',
        'watchlist' => 'واچ‌لیست',
        'forex' => 'فارکس',
        'crypto' => 'کریپتو',
        'indices' => 'شاخص‌ها',
        'commodities' => 'کالاها',
        'price' => 'قیمت',
        'change' => 'تغییر',
        'high' => 'بالاترین',
        'low' => 'پایین‌ترین',
        'volume' => 'حجم',
        'spread' => 'اسپرد',
        'live' => 'زنده',
        'delayed' => 'تأخیری',
    ],
    
    // =========================================================================
    // PERFORMANCE - Performance analytics
    // =========================================================================
    'performance' => [
        'title' => 'عملکرد',
        'overview' => 'نمای کلی',
        'win_rate' => 'نرخ برد',
        'profit_factor' => 'فاکتور سود',
        'max_drawdown' => 'حداکثر دراودان',
        'sharpe_ratio' => 'نسبت شارپ',
        'total_trades' => 'کل معاملات',
        'winning_trades' => 'معاملات سودده',
        'losing_trades' => 'معاملات زیان‌ده',
        'avg_win' => 'میانگین سود',
        'avg_loss' => 'میانگین زیان',
        'largest_win' => 'بزرگترین سود',
        'largest_loss' => 'بزرگترین زیان',
        'consecutive_wins' => 'بردهای متوالی',
        'consecutive_losses' => 'باخت‌های متوالی',
        'risk_reward' => 'نسبت ریسک به ریوارد',
        'expectancy' => 'امانت ریاضی',
        'daily_pnl' => 'سود/زیان روزانه',
        'weekly_pnl' => 'سود/زیان هفتگی',
        'monthly_pnl' => 'سود/زیان ماهانه',
        'yearly_pnl' => 'سود/زیان سالانه',
    ],
    
    // =========================================================================
    // INTELLIGENCE - AI features
    // =========================================================================
    'intelligence' => [
        'title' => 'هوش معاملاتی',
        'ai_coach' => 'مربی هوش مصنوعی',
        'ask_question' => 'سؤال بپرسید',
        'insights' => 'بینش‌ها',
        'patterns' => 'الگوها',
        'recommendations' => 'پیشنهادات',
        'behavior_analysis' => 'تحلیل رفتار',
        'setup_scoring' => 'امتیازدهی ستاپ',
        'risk_warnings' => 'هشدارهای ریسک',
        'weekly_report' => 'گزارش هفتگی',
        'monthly_report' => 'گزارش ماهانه',
        'ai_analysis' => 'تحلیل هوش مصنوعی',
        'confidence' => 'اعتماد',
        'pattern_detected' => 'الگو شناسایی شد',
        'overtrading_warning' => 'هشدار اورتریدینگ',
        'revenge_trading_warning' => 'هشدار انتقام‌جویی',
    ],
    
    // =========================================================================
    // WALLET - Wallet and financial
    // =========================================================================
    'wallet' => [
        'title' => 'کیف پول',
        'balance' => 'موجودی',
        'equity' => 'اکویتی',
        'deposit' => 'واریز',
        'withdraw' => 'برداشت',
        'transfer' => 'انتقال',
        'transactions' => 'تراکنش‌ها',
        'history' => 'تاریخچه',
        'total_balance' => 'موجودی کل',
        'available' => 'قابل برداشت',
        'in_use' => 'در حال استفاده',
        'margin' => 'مارجین',
        'free_margin' => 'مارجین آزاد',
    ],
    
    // =========================================================================
    // PROFILE - User profile
    // =========================================================================
    'profile' => [
        'title' => 'پروفایل',
        'personal_info' => 'اطلاعات شخصی',
        'security' => 'امنیت',
        'preferences' => 'تنظیمات',
        'notifications' => 'اعلان‌ها',
        'name' => 'نام',
        'email' => 'ایمیل',
        'phone' => 'تلفن',
        'timezone' => 'منطقه زمانی',
        'language' => 'زبان',
        'change_password' => 'تغییر رمز عبور',
        'two_factor' => 'احراز هویت دو مرحله‌ای',
        'sessions' => 'نشست‌ها',
        'devices' => 'دستگاه‌ها',
        'connected_accounts' => 'حساب‌های متصل',
        'delete_account' => 'حذف حساب',
        'profile_updated' => 'پروفایل بروزرسانی شد',
        'password_changed' => 'رمز عبور تغییر کرد',
    ],
    
    // =========================================================================
    // ADMIN - Admin panel
    // =========================================================================
    'admin' => [
        'title' => 'پنل مدیریت',
        'users' => 'کاربران',
        'user_management' => 'مدیریت کاربران',
        'roles' => 'نقش‌ها',
        'permissions' => 'مجوزها',
        'logs' => 'لاگ‌ها',
        'settings' => 'تنظیمات سیستم',
        'statistics' => 'آمار',
        'active_users' => 'کاربران فعال',
        'total_users' => 'کل کاربران',
        'new_users' => 'کاربران جدید',
        'search_users' => 'جستجوی کاربران',
        'user_details' => 'جزئیات کاربر',
        'ban_user' => 'مسدود کردن کاربر',
        'unban_user' => 'رفع مسدودیت',
        'change_role' => 'تغییر نقش',
    ],
    
    // =========================================================================
    // SUPPORT - Help and support
    // =========================================================================
    'support' => [
        'title' => 'پشتیبانی',
        'help_center' => 'مرکز راهنما',
        'contact_us' => 'تماس با ما',
        'faq' => 'سوالات متداول',
        'documentation' => 'مستندات',
        'ticket' => 'تیکت',
        'new_ticket' => 'تیکت جدید',
        'my_tickets' => 'تیکت‌های من',
        'subject' => 'موضوع',
        'message' => 'پیام',
        'priority' => 'اولویت',
        'status' => 'وضعیت',
        'open' => 'باز',
        'closed' => 'بسته',
        'pending' => 'در انتظار',
        'answered' => 'پاسخ داده شده',
        'submit_ticket' => 'ارسال تیکت',
        'ticket_submitted' => 'تیکت ارسال شد',
    ],
    
    // =========================================================================
    // NEWS - News and events
    // =========================================================================
    'news' => [
        'title' => 'اخبار',
        'latest_news' => 'آخرین اخبار',
        'breaking' => 'فوری',
        'analysis' => 'تحلیل',
        'calendar' => 'تقویم اقتصادی',
        'events' => 'رویدادها',
        'high_impact' => 'تأثیر بالا',
        'medium_impact' => 'تأثیر متوسط',
        'low_impact' => 'تأثیر پایین',
        'actual' => 'واقعی',
        'forecast' => 'پیش‌بینی',
        'previous' => 'قبلی',
        'time' => 'زمان',
        'currency' => 'ارز',
        'event' => 'رویداد',
    ],
    
    // =========================================================================
    // BLOG - Blog and articles
    // =========================================================================
    'blog' => [
        'title' => 'وبلاگ',
        'articles' => 'مقالات',
        'categories' => 'دسته‌بندی‌ها',
        'tags' => 'برچسب‌ها',
        'read_more' => 'بیشتر بخوانید',
        'share' => 'اشتراک‌گذاری',
        'related_articles' => 'مقالات مرتبط',
        'latest_articles' => 'آخرین مقالات',
        'search' => 'جستجوی مقالات',
        'no_articles' => 'مقاله‌ای یافت نشد',
        'min_read' => 'دقیقه مطالعه',
        'author' => 'نویسنده',
        'published' => 'تاریخ انتشار',
        'updated' => 'آخرین بروزرسانی',
    ],
    
    // =========================================================================
    // ERRORS - Error messages
    // =========================================================================
    'errors' => [
        'not_found' => 'صفحه یافت نشد',
        'unauthorized' => 'دسترسی غیرمجاز',
        'forbidden' => 'دسترسی ممنوع',
        'server_error' => 'خطای سرور',
        'bad_request' => 'درخواست نامعتبر',
        'timeout' => 'زمان اتصال تمام شد',
        'network_error' => 'خطای شبکه',
        'validation_error' => 'خطای اعتبارسنجی',
        'rate_limit' => 'تعداد درخواست‌ها زیاد است',
        'try_again' => 'لطفاً دوباره تلاش کنید',
        'contact_support' => 'با پشتیبانی تماس بگیرید',
        'go_home' => 'بازگشت به صفحه اصلی',
        'go_back' => 'بازگشت',
    ],
    
    // =========================================================================
    // NOTIFICATIONS - System notifications
    // =========================================================================
    'notifications' => [
        'trade_saved' => 'معامله ذخیره شد',
        'trade_deleted' => 'معامله حذف شد',
        'account_connected' => 'حساب متصل شد',
        'account_disconnected' => 'حساب قطع شد',
        'sync_complete' => 'همگام‌سازی کامل شد',
        'sync_failed' => 'همگام‌سازی ناموفق',
        'password_changed' => 'رمز عبور تغییر کرد',
        'profile_updated' => 'پروفایل بروزرسانی شد',
        'settings_saved' => 'تنظیمات ذخیره شد',
        'new_login' => 'ورود جدید به حساب شما',
        'suspicious_activity' => 'فعالیت مشکوک شناسایی شد',
        'maintenance' => 'تعمیرات سیستم',
        'update_available' => 'بروزرسانی موجود است',
    ],
    
    // =========================================================================
    // FORMS - Form labels and placeholders
    // =========================================================================
    'forms' => [
        'required_field' => 'فیلد الزامی',
        'optional_field' => 'فیلد اختیاری',
        'select_option' => 'انتخاب کنید...',
        'enter_value' => 'مقدار را وارد کنید...',
        'search' => 'جستجو...',
        'no_results' => 'نتیجه‌ای یافت نشد',
        'loading' => 'در حال بارگذاری...',
        'submitting' => 'در حال ارسال...',
        'validation_required' => 'این فیلد الزامی است',
        'validation_email' => 'ایمیل معتبر وارد کنید',
        'validation_min' => 'حداقل :min کاراکتر',
        'validation_max' => 'حداکثر :max کاراکتر',
        'validation_pattern' => 'فرمت نامعتبر',
        'validation_match' => 'مقدارها مطابقت ندارند',
        'validation_number' => 'عدد معتبر وارد کنید',
        'validation_positive' => 'عدد مثبت وارد کنید',
        'validation_date' => 'تاریخ معتبر وارد کنید',
    ],
    
    // =========================================================================
    // UNITS - Measurement units
    // =========================================================================
    'units' => [
        'pips' => 'پیپ',
        'points' => 'پوینت',
        'lots' => 'لات',
        'micro_lots' => 'میکرو لات',
        'mini_lots' => 'مینی لات',
        'percent' => 'درصد',
        'usd' => 'دلار',
        'eur' => 'یورو',
        'gbp' => 'پوند',
        'jpy' => 'ین',
        'seconds' => 'ثانیه',
        'minutes' => 'دقیقه',
        'hours' => 'ساعت',
        'days' => 'روز',
        'weeks' => 'هفته',
        'months' => 'ماه',
        'years' => 'سال',
    ],
    
    // =========================================================================
    // AI - AI-related content
    // =========================================================================
    'ai' => [
        'coach' => 'مربی هوش مصنوعی',
        'analysis' => 'تحلیل هوش مصنوعی',
        'insights' => 'بینش‌های هوش مصنوعی',
        'recommendations' => 'پیشنهادات هوش مصنوعی',
        'patterns' => 'الگوهای شناسایی شده',
        'behavior' => 'تحلیل رفتار',
        'overtrading' => 'اورتریدینگ',
        'revenge_trading' => 'انتقام‌جویی',
        'emotional_trading' => 'معاملات احساسی',
        'risk_management' => 'مدیریت ریسک',
        'consistency' => 'ثبات',
        'discipline' => 'انضباط',
        'patience' => 'صبر',
        'setup_quality' => 'کیفیت ستاپ',
        'entry_timing' => 'زمان‌بندی ورود',
        'exit_strategy' => 'استراتژی خروج',
        'position_sizing' => 'اندازه پوزیشن',
        'daily_report' => 'گزارش روزانه',
        'weekly_report' => 'گزارش هفتگی',
        'monthly_report' => 'گزارش ماهانه',
    ],
];
