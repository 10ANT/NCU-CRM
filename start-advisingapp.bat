@echo OFF
setlocal EnableDelayedExpansion

REM ============================================================================
REM  Advising App: start everything.
REM
REM  Opens one window per service and leaves them open so you can see the logs.
REM  Closing a window stops that service.
REM
REM  XAMPP is never touched. Apache keeps port 80 and MariaDB keeps port 3306,
REM  so Solorion is unaffected. This app uses MySQL on 3307 and the web server
REM  on 8010.
REM ============================================================================

set "PROJECT=C:\xampp\htdocs\advisingapp"
set "PHP=F:\dev\php84"
set "MYSQL=F:\dev\mysql84"
set "APPURL=http://test.advisingapp.localtest.me:8010"

title Advising App launcher

echo.
echo   ===============================================================
echo    Advising App
echo   ===============================================================
echo.

REM --- checks -----------------------------------------------------------------

if not exist "%PHP%\php.exe" (
    echo   [X] PHP 8.4 not found at %PHP%\php.exe
    echo       Nothing can start without it.
    goto :fail
)

if not exist "%MYSQL%\bin\mysqld.exe" (
    echo   [X] MySQL not found at %MYSQL%\bin\mysqld.exe
    goto :fail
)

if not exist "%PROJECT%\vendor\autoload.php" (
    echo   [X] Dependencies are missing. Open php84-shell.bat and run: composer install
    goto :fail
)

cd /d "%PROJECT%"

REM Git on Windows cannot create the symlink this repository commits, so it writes
REM a text file instead and Livewire's javascript never loads. When that happens
REM nothing in the interface responds and logging in silently reloads the page.
if not exist "%PROJECT%\public\livewire\livewire.js" (
    echo   [!] Repairing the Windows symlinks ...
    "%PHP%\php.exe" fix-windows-symlinks.php >nul 2>&1
)

REM --- database ---------------------------------------------------------------

netstat -ano | findstr /C:":3307" | findstr /C:"LISTENING" >nul
if not errorlevel 1 (
    echo   [=] MySQL already running on 3307
) else (
    echo   [+] Starting MySQL on 3307 ...
    start "Advising App - MySQL 8.4 (port 3307)" cmd /k ""%MYSQL%\bin\mysqld.exe" --defaults-file="%MYSQL%\my.ini" --console"

    set "READY="
    for /L %%i in (1,1,30) do (
        if not defined READY (
            "%MYSQL%\bin\mysqladmin.exe" --port=3307 --protocol=tcp -u root ping >nul 2>&1
            if not errorlevel 1 set "READY=1"
            if not defined READY ping -n 2 127.0.0.1 >nul
        )
    )

    if not defined READY (
        echo   [X] MySQL did not come up. Check its window for the reason.
        goto :fail
    )
    echo   [=] MySQL is up
)

REM --- queue worker -----------------------------------------------------------

echo   [+] Starting the queue worker ...
start "Advising App - queue worker" cmd /k "set PATH=%PHP%;%PATH% && cd /d "%PROJECT%" && php artisan queue:work --tries=3"

REM --- realtime (optional) ----------------------------------------------------

netstat -ano | findstr /C:":8080" | findstr /C:"LISTENING" >nul
if not errorlevel 1 (
    echo   [=] Something already holds 8080, skipping Reverb
) else (
    echo   [+] Starting Reverb for live updates ...
    start "Advising App - Reverb (realtime)" cmd /k "set PATH=%PHP%;%PATH% && cd /d "%PROJECT%" && php artisan reverb:start"
)

REM --- web server -------------------------------------------------------------

netstat -ano | findstr /C:":8010" | findstr /C:"LISTENING" >nul
if not errorlevel 1 (
    echo   [=] Web server already running on 8010
) else (
    echo   [+] Starting the web server on 8010 ...
    start "Advising App - web server (port 8010)" cmd /k "set PATH=%PHP%;%PATH% && cd /d "%PROJECT%" && php artisan serve --host=127.0.0.1 --port=8010"
)

REM --- wait for the site, then open it ----------------------------------------

echo.
echo   Waiting for the site to answer ...

set "UP="
for /L %%i in (1,1,30) do (
    if not defined UP (
        "%PHP%\php.exe" -r "$c=@file_get_contents('%APPURL%/login');exit($c?0:1);" >nul 2>&1
        if not errorlevel 1 set "UP=1"
        if not defined UP ping -n 2 127.0.0.1 >nul
    )
)

echo.
echo   ===============================================================
if defined UP (
    echo    READY
) else (
    echo    Started, but the site did not answer yet. Give it a moment.
)
echo   ===============================================================
echo.
echo    Staff panel   %APPURL%
echo    Admin panel   http://advisingapp.localtest.me:8010
echo.
echo    Sign in       sampleadmin@advising.app
echo    Password      password
echo.
echo    To stop everything, close the other windows this opened.
echo   ===============================================================
echo.

if defined UP start "" "%APPURL%"

echo   This launcher window can be closed.
pause
exit /b 0

:fail
echo.
pause
exit /b 1
