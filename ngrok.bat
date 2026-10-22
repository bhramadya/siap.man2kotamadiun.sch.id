@echo off
setlocal
title SIAP - Tunnel ngrok (tutup jendela ini untuk menghentikan tunnel)
cd /d "%~dp0"

set "APPDIR=%~dp0"
set "PORT=80"
set "URL=https://magma-nautical-dividing.ngrok-free.dev"
set "NGROKCFG=%LOCALAPPDATA%\ngrok\ngrok.yml"

rem Subfolder aplikasi di URL publik (mis. /siap)
rem (backslash terakhir dibuang dulu agar parsing CMD tidak rusak)
for %%f in ("%APPDIR:~0,-1%") do set "BASE=%%~nxf"

rem --- cari ngrok.exe ---
set "NGROK="
if exist "D:\laragon\www\ngrok.exe"        set "NGROK=D:\laragon\www\ngrok.exe"
if not defined NGROK if exist "C:\laragon\www\ngrok.exe" set "NGROK=C:\laragon\www\ngrok.exe"
if not defined NGROK if exist "D:\laragon\bin\ngrok\ngrok.exe" set "NGROK=D:\laragon\bin\ngrok\ngrok.exe"
if not defined NGROK for %%i in (ngrok.exe) do if not "%%~$PATH:i"=="" set "NGROK=%%~$PATH:i"

echo ==============================================================
echo   SIAP - Tunnel ngrok
echo   URL publik : %URL%/%BASE%/
echo ==============================================================
echo.

if not defined NGROK (
  echo [SIAP] ngrok.exe tidak ditemukan.
  echo [SIAP] Pasang ngrok v3 ^(unduh dari https://ngrok.com/download^), lalu:
  echo [SIAP]     ngrok config add-authtoken TOKEN_ANDA
  echo.
  pause
  exit /b 1
)

rem --- authtoken sudah tersimpan? ---
if not exist "%NGROKCFG%" goto :token_kosong
findstr /C:"authtoken:" "%NGROKCFG%" >nul 2>&1
if errorlevel 1 goto :token_kosong
goto :token_ada

:token_kosong
echo [SIAP] Authtoken ngrok belum diatur.
echo [SIAP] Jalankan sekali:
echo [SIAP]     "%NGROK%" config add-authtoken TOKEN_ANDA
echo.
pause
exit /b 1

:token_ada
rem --- 1) pastikan server lokal (Apache + MySQL) hidup ---
echo [SIAP] Menyiapkan server lokal ^(Apache + MySQL^)... 
powershell -NoProfile -ExecutionPolicy Bypass -File "%APPDIR%tools\ngrok-siap.ps1" ensure-server
if errorlevel 1 (
  echo.
  echo [SIAP] Server lokal gagal disiapkan.
  echo [SIAP] Buka Laragon, pastikan Apache + MySQL jalan, lalu coba lagi.
  echo.
  pause
  exit /b 1
)
echo.

rem --- 2) cek tunnel yang bentrok ---
set "AKTIF="
for /f "usebackq delims=" %%u in (`powershell -NoProfile -ExecutionPolicy Bypass -File "%APPDIR%tools\ngrok-siap.ps1" status-url`) do set "AKTIF=%%u"
if defined AKTIF (
  echo [SIAP] Tunnel SUDAH aktif di: %AKTIF%
  echo [SIAP] Tidak perlu dibuka lagi -- jendela pertama yang mengendalikannya.
  echo [SIAP] Untuk menutup tunnel: tools\ngrok-siap.ps1 stop
  echo.
  pause
  exit /b 0
)

rem --- 3) jalankan ngrok di foreground ---
echo [SIAP] Membuka tunnel ngrok ke http://localhost:%PORT% ...
echo [SIAP] Website bisa diakses orang lain di:
echo [SIAP]     %URL%/%BASE%/
echo [SIAP] Tutup jendela ini ^(atau tekan Ctrl+C^) untuk menghentikan tunnel.
echo.
"%NGROK%" http %PORT% --url %URL%
echo.
echo [SIAP] Tunnel ditutup. Website tidak bisa diakses lagi sampai tunnel dibuka kembali.
pause
