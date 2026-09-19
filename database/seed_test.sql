-- Dev App Pro - Seed data (test)
-- Admin heslo: test123 (bcrypt cost 12)

-- ============================================================
-- Uživatel: admin
-- ============================================================
INSERT INTO `users` (`username`, `name`, `password_hash`, `password_hint`, `email`, `theme`) VALUES
('admin', 'Administrátor', '$2y$12$iwb5tPl/15W52Yxg5D4LiOmvuHPTjrM54QDTfRfnQSOh/Ip7uzDPq', 'Jméno mého prvního psa', 'admin@devapppro.local', 'dark');

-- ============================================================
-- Nastavení aplikace
-- ============================================================
INSERT INTO `settings` (`key`, `value`) VALUES
('invoice_number_format', '{year}{seq:03d}'),
('invoice_seq_year', '2026'),
('invoice_seq', '0'),
('default_vat_rate', '21'),
('default_due_days', '14'),
('timezone', 'Europe/Prague'),
('first_day_of_week', '1'),
('fiscal_year_start', '01-01'),
('currency', 'CZK'),
('currency_decimals', '0');

-- ============================================================
-- Profil firmy (výchozí - fyzická osoba)
-- ============================================================
INSERT INTO `company_profile` (`id`, `type`) VALUES
(1, 'individual');
