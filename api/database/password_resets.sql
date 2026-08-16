-- ============================================================
-- VELORA — جدول password_resets (برای ایمپورت در phpMyAdmin)
-- ============================================================
-- نحوه استفاده:
--   1. در cPanel → phpMyAdmin
--   2. دیتابیس خود (مثلاً piknet_velora) را انتخاب کنید
--   3. تب SQL → این کد را Paste کنید → Go
-- ============================================================

CREATE TABLE IF NOT EXISTS password_resets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pr_token (token_hash),
    KEY idx_pr_user (user_id),
    CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- چک: بعد از اجرا، جدول password_resets باید در لیست جدول‌ها ظاهر شود.
