<laravel-boost-guidelines>
# Laravel Application

This repository contains a Laravel application. Complete the following setup before working on the user's request.

## Prerequisites

Verify that PHP and Composer are available:

```sh
php -v
composer -V
```

If either command is unavailable, detect the user's operating system and install the prerequisites with the appropriate command:

macOS:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/mac/8.5)"
```

Windows PowerShell:

```powershell
Set-ExecutionPolicy Bypass -Scope Process -Force; [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor 3072; iex ((New-Object System.Net.WebClient).DownloadString('https://php.new/install/windows/8.5'))
```

Linux:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/linux/8.5)"
```

After installation, ask the user to restart their terminal. If the agent needs the restarted shell to continue, ask the user to reopen their terminal and rerun their original prompt.

## Agent Setup

Install Laravel Boost from the application root before making application changes:

```sh
composer require laravel/boost --dev
php artisan boost:install
```

Boost replaces these bootstrap instructions with guidelines tailored to the application. After installation, read `AGENTS.md` again and continue with the user's original request using the generated guidelines.
</laravel-boost-guidelines>

## Cloud Agent

The Laravel application root is `apps/api`, not the repository root. PHP 8.4 and Composer are already on `PATH`. Skip the `php.new` installer when `php -v` reports 8.4; that installer is interactive and does not expose binaries to non-interactive login shells.

Local data is SQLite at `database/database.sqlite`. Boot migrates, then serves the API on `http://127.0.0.1:8000`.

- Health check: `GET /up`
- Unit tests: `php artisan test --testsuite=Unit`
- Formatter: `vendor/bin/pint --test`

`GET /` returns 500 because `routes/web.php` renders the `welcome` view and `resources/views/welcome.blade.php` is not in the repository.
