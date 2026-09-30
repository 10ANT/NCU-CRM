@echo OFF
REM Opens a shell where PHP 8.4 is the active PHP, for THIS window only.
REM Nothing global changes. XAMPP's PHP 8.2 stays the default everywhere else.
REM Close this window and the machine is back to normal.

set "PHP84_DIR=F:\dev\php84"

if not exist "%PHP84_DIR%\php.exe" (
    echo.
    echo   PHP 8.4 was not found at %PHP84_DIR%\php.exe
    echo.
    echo   Download the PHP 8.4 VS17 x64 Non Thread Safe zip from
    echo   https://windows.php.net/download  and extract it to %PHP84_DIR%
    echo.
    echo   See LOCAL_SETUP_NO_DOCKER.md section 4.1 for the full steps.
    echo.
    pause
    exit /b 1
)

REM Put 8.4 first on PATH so both php and composer use it in this window.
set "PATH=%PHP84_DIR%;%PATH%"

cd /d "%~dp0"

echo.
echo   ====================================================
echo    Advising App shell - PHP 8.4 active in this window
echo   ====================================================
echo.
"%PHP84_DIR%\php.exe" -v
echo.
echo   Composer will use this PHP too (composer.bat calls bare 'php').
echo   Other terminals are unaffected and still use XAMPP PHP 8.2.
echo.
echo   Useful commands:
echo     composer install
echo     php artisan key:generate
echo     php artisan migrate --database=landlord
echo     php artisan serve --host=advisingapp.local --port=8000
echo     php artisan queue:work
echo.

cmd /k
