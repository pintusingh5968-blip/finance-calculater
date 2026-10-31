# Pennywise — Personal Finance Manager

A responsive PHP and MySQL application for tracking personal income, expenses, budgets, savings goals, and financial reports.

## Features

- Account registration and sign-in with hashed passwords
- Add, edit, search, filter, and delete income and expense records
- Built-in and custom income/expense categories
- Monthly category budgets with spending progress
- Savings goals with target dates and contributions
- Monthly cash-flow chart and yearly summaries
- Responsive dashboard and profile settings
- Per-user data access, prepared database statements, and CSRF protection

## Run with XAMPP

1. Install and open XAMPP. Start **Apache** and **MySQL**.
2. Copy this `personal-finance-manager` folder into XAMPP's `htdocs` directory.
3. Open `http://localhost/phpmyadmin` in your browser.
4. Select **Import**, choose this project's `database.sql`, and import it. The script creates the `personal_finance_manager` database and tables.
5. If your MySQL credentials differ from a standard XAMPP setup, update `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASS` in `config.php`.
6. Visit `http://localhost/personal-finance-manager/` and create an account.

The default XAMPP MySQL settings in `config.php` are host `127.0.0.1`, user `root`, and an empty password. The app requires PHP 8.1 or newer, plus PHP's PDO MySQL driver and `mbstring` extension, which are commonly enabled in XAMPP.

## Project structure

```text
personal-finance-manager/
├── assets/
│   ├── app.js
│   └── style.css
├── config.php
├── database.sql
├── functions.php
├── index.php
└── README.md
```

## Notes

- Amounts are displayed in Indian rupees (₹).
- Reports are calculated from the user's income and expense records; they do not require a separate reports table.
- Savings contributions update the goal's saved amount and are capped at its target.
- Default categories are shared read-only options. Each account can add and remove its own custom categories.
