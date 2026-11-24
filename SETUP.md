# Ducks In A Row: setup on IONOS

Everything runs on your IONOS web hosting plan: the pages, the PHP files in `api/`, and your IONOS MariaDB database.
Clerk handles sign in / sign up.

## 1. Upload the files
Upload **everything in this folder** to your website's main folder (the one that holds `index.html`),
keeping the folders: `api/` and `assets/`. (File Manager in your IONOS account, or SFTP.)
The `api/.htaccess` file is hidden on some computers. Make sure it uploads too: it keeps your settings file private.

## 2. Create the database tables
1. IONOS account: **Hosting, MySQL/MariaDB**. Create a database (or use the one you have).
2. Click **Display details** and note the **host name, database name, user name and password**.
3. Open **phpMyAdmin** for that database, choose the **Import** tab, pick `ionos-schema.sql`, and run it.
   You should see five tables: `profiles`, `courses`, `tasks`, `classes`, `events`.

## 3. Tell the API about your database
Open `api/config.php` and fill in `db_host`, `db_name`, `db_user` and `db_pass`.
Your Clerk key and website addresses are already filled in. Keep this file private and never send it to anyone.

## 4. Clerk
- `clerk-config.js` already has your live Publishable Key.
- The site must be opened over **https://** on `jashducksinarow.com` (or `www.`). Live Clerk keys refuse anything else.

## 5. Check that it works
1. Open `https://jashducksinarow.com/api/index.php?action=me`
   You should see `{"error":"Please sign in."}`. That means PHP and the API are running.
   (A blank page, a 500 error, or a download prompt means PHP isn't running: see Troubleshooting.)
2. Open `signup.html`, create an account, finish onboarding, and you land on the dashboard.

## Changing the greetings and quotes
- **`greetings.js`**: one list per weekday, plus `earlyMorning` (before 8:00) and `lateNight` (22:00 and later).
  Thursday, Friday, Saturday and early morning are empty for now (they fall back to the weekday list or the `fallback` list).
  Type `{name}` inside a greeting to choose where the person's name goes.
- **`quotes.js`**: the quote list (text, duck pun, person). The ones in there are **samples**: replace them with yours.

## Troubleshooting
| What you see | Likely cause |
|---|---|
| "The website could not find its database service" | The `api` folder wasn't uploaded next to `index.html`. |
| "The server did not answer correctly (500)" | `api/config.php` database details are blank or wrong. |
| "The database is not set up yet" | Same: fill in `api/config.php`. |
| "Your sign-in could not be verified" | `api/config.php` has a different Clerk key from `clerk-config.js`, or the site isn't on `https://jashducksinarow.com`. |
| "Could not reach Clerk to verify your sign-in" | Your hosting couldn't download Clerk's public keys. Try again; if it keeps happening, tell me and I'll add a manual option. |
| Tables missing (SQL error) | Re-run the import in step 2. |

## What's where
- `index.html` home, `signin.html` / `signup.html`, `onboarding.html`, `dashboard.html`
- `auth.js` (Clerk), `db.js` (talks to the API), `greetings.js`, `quotes.js`, `clerk-config.js`, `auth.css`
- `api/index.php` (the API), `api/lib.php` (sign-in check + helpers), `api/config.php` (your settings)
- `ionos-schema.sql` (the database tables)
- Pages in the account menu (`calendar.html`, `tasks.html`, `grades.html`, `account.html`) don't exist yet and will be added later.
