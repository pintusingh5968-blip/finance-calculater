CREATE DATABASE IF NOT EXISTS personal_finance_manager
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE personal_finance_manager;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  name VARCHAR(60) NOT NULL,
  type ENUM('income','expense') NOT NULL,
  color CHAR(7) NOT NULL DEFAULT '#6c63ff',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_categories_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY unique_user_category (user_id, name, type),
  INDEX idx_categories_type (type)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS incomes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  description VARCHAR(255) NOT NULL DEFAULT '',
  occurred_on DATE NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_incomes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_incomes_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
  INDEX idx_incomes_user_date (user_id, occurred_on)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS expenses (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  description VARCHAR(255) NOT NULL DEFAULT '',
  occurred_on DATE NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_expenses_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_expenses_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
  INDEX idx_expenses_user_date (user_id, occurred_on),
  INDEX idx_expenses_user_category_date (user_id, category_id, occurred_on)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS budgets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  month_start DATE NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_budgets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_budgets_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
  UNIQUE KEY unique_monthly_budget (user_id, category_id, month_start)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS savings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(120) NOT NULL,
  target_amount DECIMAL(12,2) NOT NULL,
  current_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  target_date DATE NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_savings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_savings_user (user_id)
) ENGINE=InnoDB;

INSERT INTO categories (user_id, name, type, color)
SELECT NULL, defaults.name, defaults.type, defaults.color
FROM (
  SELECT 'Salary' AS name, 'income' AS type, '#27a879' AS color UNION ALL
  SELECT 'Freelance', 'income', '#4a8fe7' UNION ALL
  SELECT 'Investment', 'income', '#9b6be8' UNION ALL
  SELECT 'Other income', 'income', '#e9a23b' UNION ALL
  SELECT 'Food', 'expense', '#f08b68' UNION ALL
  SELECT 'Travel', 'expense', '#4a8fe7' UNION ALL
  SELECT 'Shopping', 'expense', '#9b6be8' UNION ALL
  SELECT 'Bills', 'expense', '#e9a23b' UNION ALL
  SELECT 'Health', 'expense', '#e46883' UNION ALL
  SELECT 'Entertainment', 'expense', '#31a9a1' UNION ALL
  SELECT 'Home', 'expense', '#9278d3' UNION ALL
  SELECT 'Other expense', 'expense', '#78849a'
) AS defaults
WHERE NOT EXISTS (
  SELECT 1 FROM categories existing
  WHERE existing.user_id IS NULL
    AND existing.name = defaults.name
    AND existing.type = defaults.type
);
