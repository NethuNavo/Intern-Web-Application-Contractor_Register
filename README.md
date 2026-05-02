# Contractor Register PHP App

This is a PHP web application for managing contractors and contact persons.

## Vercel Deployment

This project can be deployed to Vercel using the PHP runtime, but it requires an external MySQL database.

### Steps

1. Create or provision a remote MySQL database accessible from Vercel.
2. Import `contractor_management (9).sql` into that database.
3. In the Vercel dashboard, add these Environment Variables:
   - `DB_HOST`
   - `DB_USER`
   - `DB_PASS`
   - `DB_NAME`
   - `DB_PORT` (optional, default: 3306)
4. Connect your GitHub repository to Vercel and deploy.

> Note: Vercel does not provide a MySQL database. The application cannot use `localhost` or a local XAMPP database on Vercel; it must connect to an external MySQL host.
