-- Dev App Pro - Seed data (produkční)
-- Admin heslo: admin123 (bcrypt cost 12)

-- ============================================================
-- Uživatel: admin
-- ============================================================
INSERT INTO `users` (`username`, `name`, `password_hash`, `email`, `theme`) VALUES
('admin', 'Administrátor', '$2y$12$Zm8eZixypGG1E3FaWUH8FO9kZ3yimA4fAHP7cyjinC7az8FdvZZs2', 'admin@devapppro.local', 'dark');

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
