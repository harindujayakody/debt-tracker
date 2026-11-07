# 💰 Debt Tracker (PHP + SQLite)

A lightweight single-file PHP app to track debts and repayments.  
Built with PHP, SQLite, TailwindCSS, and Chart.js.

---

## ✨ Features

- Track debts (per person, with optional labels).
- Record payments with date + note.
- Summary cards with progress bars.
- Per-person overview with quick actions:
  - Add payment inline
  - Edit person name
  - Add new debt
- Charts:
  - Remaining by person (bar chart)
  - Payments per month (doughnut)
- Single-column layout for All Debts & Payments.
- Payments are shown in a single list; pagination is not currently available.
- CSRF protection built in.
- All data stored locally in `debt_tracker.sqlite`.

---

> **Security:** This app has no login, and the database is stored in the web-accessible directory. Run it only on a trusted local computer bound to `localhost`; do not expose it to a network or public web server. Other local users or processes may still be able to access the data.

## 🚀 Quick Start

1. Clone this repo:

   git clone https://github.com/harindujayakody/debt-tracker.git
   cd debt-tracker

2. Start PHP’s built-in server:

   php -S localhost:8000

3. Open http://localhost:8000 in your browser.

4. Done ✅ — start adding debts & payments.

---

## 📂 Data Storage

All data is stored in a local SQLite file:

   debt_tracker.sqlite

To back up, just copy this file.

---



## 📸 Screenshots

<img width="1209" height="583" alt="image" src="https://github.com/user-attachments/assets/8354b88f-ef81-401f-838e-afa25e305079" />

<img width="1154" height="522" alt="image" src="https://github.com/user-attachments/assets/7c0e3ff6-d7f8-4e4b-9d70-205707cd0fef" />

<img width="1128" height="391" alt="image" src="https://github.com/user-attachments/assets/10c92d66-865e-4ca3-bf49-e899d464bb20" />


---

## ❤️ Credits

Built with PHP, SQLite, TailwindCSS, and Chart.js.  
Author: Harindu Jayakody (https://github.com/harindujayakody)

---

## 📜 License

MIT License — you are free to use, modify, and distribute this project.

