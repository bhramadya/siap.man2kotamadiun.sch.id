<#
.SYNOPSIS
    Membuka tunnel NGROK untuk aplikasi SIAP (lokal) agar bisa diakses dari HP
    melalui HTTPS. Kamera (scan QR) dan GPS TIDAK bisa jalan di halaman HTTP,
    jadi tunnel ini wajib dipakai saat menguji dari HP / aplikasi Android.

.DESCRIPTION
    Script ini menjalankan:
        ngrok http <Port>

    Request diteruskan ke Apache tanpa mengubah header Host, sehingga aplikasi
    tetap memakai URL publik (https://<domain-ngrok>/<subfolder-aplikasi>/...)
    dan QR Code yang dicetak selalu benar.

    Perintah:
        start    buka tunnel (default)
        stop     tutup tunnel
        status   tampilkan URL tunnel yang sedang aktif
        restart  tutup lalu buka lagi

.PARAMETER Action
    start | stop | status | restart

.PARAMETER Port
    Port Apache lokal yang ditunnelkan. Default 80.

.PARAMETER NgrokPath
    Lokasi ngrok.exe. Kosong = cari otomatis.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File tools\ngrok-siap.ps1
    powershell -ExecutionPolicy Bypass -File tools\ngrok-siap.ps1 stop

.NOTES
    - Tunnel ini membuat aplikasi TERBUKA untuk siapa pun yang tahu URL-nya.
      Pastikan password admin bawaan (admin/admin123) sudah diganti.
    - Free plan ngrok: 20.000 request/bulan, 1 GB transfer/bulan, maks 3 endpoint.
    - Halaman pertama dari tiap perangkat akan menampilkan peringatan
      "Visit Site" dari ngrok (tekan tombolnya sekali; cookie berlaku 7 hari).
#>
[CmdletBinding()]
param(
    [ValidateSet('start', 'stop', 'status', 'restart')]
    [string] $Action = 'start',

    [int]    $Port = 80,

    [string] $NgrokPath = ''
)

$ErrorActionPreference = 'Stop'

# ---------------------------------------------------------------------------
# Helper
# ---------------------------------------------------------------------------
function Resolve-NgrokPath {
    param([string] $Given)

    if ($Given -ne '') {
        if (Test-Path $Given) { return (Resolve-Path $Given).Path }
        throw "ngrok.exe tidak ditemukan di: $Given"
    }

    $cmd = Get-Command ngrok -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }

    $kandidat = @(
        'D:\laragon\bin\ngrok\ngrok.exe',
        'D:\laragon\www\ngrok.exe',
        "$env:ProgramFiles\ngrok\ngrok.exe"
    )
    foreach ($k in $kandidat) {
        if (Test-Path $k) { return $k }
    }
    throw "ngrok.exe tidak ditemukan. Pasang ngrok v3 atau jalankan: ngrok config add-authtoken <token>"
}

function Get-NgrokProcess {
    Get-CimInstance Win32_Process -Filter "Name = 'ngrok.exe'" -ErrorAction SilentlyContinue |
        Select-Object ProcessId, CommandLine
}

function Get-TunnelUrl {
    try {
        $api = Invoke-RestMethod -Uri 'http://127.0.0.1:4040/api/tunnels' -TimeoutSec 5
    } catch {
        return $null
    }
    foreach ($t in $api.tunnels) {
        if ($t.public_url) { return $t.public_url }
    }
    return $null
}

function Test-PortListening {
    param([int] $Port)
    $conn = Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue
    return [bool] $conn
}

function Get-AppBasePathLocal {
    <#
      Menentukan subfolder aplikasi pada server lokal, mis. '/siap' (bila
      dilayani lewat default vhost Laragon) atau '' (bila di root vhost).
      Dicek langsung ke Apache memakai folder aplikasi sebagai kandidat.
    #>
    param([string] $AppDir)

    $nama = Split-Path (Resolve-Path $AppDir).Path -Leaf
    foreach ($kandidat in @("/$nama", '')) {
        try {
            $r = Invoke-WebRequest -Uri "http://127.0.0.1$kandidat/" -UseBasicParsing -TimeoutSec 10
            # 'device.js' hanya ada di halaman aplikasi SIAP (bukan index Laragon).
            if ($r.Content -match 'device\.js') { return $kandidat }
        } catch {
            # kandidat tidak cocok, coba berikutnya
        }
    }
    return ''
}

function Stop-Ngrok {
    $proses = Get-NgrokProcess
    if (-not $proses) {
        Write-Host "Tidak ada proses ngrok yang berjalan." -ForegroundColor Yellow
        return
    }
    foreach ($p in $proses) {
        Stop-Process -Id $p.ProcessId -Force -ErrorAction SilentlyContinue
        Write-Host "  proses ngrok (PID $($p.ProcessId)) dihentikan." -ForegroundColor DarkGray
    }
    Start-Sleep -Seconds 1
}

function Test-Tunnel {
    param([string] $Url)

    # Header ini membuat ngrok melewati halaman peringatan "Visit Site".
    try {
        $r = Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec 20 `
            -Headers @{ 'ngrok-skip-browser-warning' = '1' }
        return ($r.StatusCode -ge 200 -and $r.StatusCode -lt 400)
    } catch {
        return $false
    }
}

function Get-AppBasePath {
    <#
      Mengambil subfolder aplikasi pada URL publik (mis. '/siap' atau '').
      Cara paling andal: baca BASE_URL yang dipanggil aplikasi sendiri dari
      halaman beranda (asset-nya memuat base URL lengkap).
    #>
    param([string] $Url)

    try {
        $r = Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec 20 `
            -Headers @{ 'ngrok-skip-browser-warning' = '1' }
        $m = [regex]::Match($r.Content, 'https?://[A-Za-z0-9\.\-]+(/[A-Za-z0-9\-_/]*)?/assets/')
        if ($m.Success) {
            $base = $m.Value -replace '/assets/$', ''
            $base = $base -replace '^https?://[A-Za-z0-9\.\-]+', ''
            return $base
        }
    } catch {
        # diabaikan: kembali ke deteksi standar
    }
    return ''
}
# ---------------------------------------------------------------------------
# Aksi
# ---------------------------------------------------------------------------
switch ($Action) {

    'status' {
        $url = Get-TunnelUrl
        if ($url) {
            Write-Host ""
            Write-Host "Tunnel AKTIF" -ForegroundColor Green
            Write-Host "  URL publik : $url"
            Write-Host ""
        } else {
            Write-Host "Tunnel tidak aktif." -ForegroundColor Yellow
        }
        break
    }

    'stop' {
        Stop-Ngrok
        Write-Host "Tunnel ditutup." -ForegroundColor Green
        break
    }

    { $_ -in @('start', 'restart') } {

        if ($Action -eq 'restart') {
            Write-Host "Menghentikan tunnel lama..." -ForegroundColor DarkGray
            Stop-Ngrok
        }

        if (-not (Test-PortListening -Port $Port)) {
            throw "Tidak ada server web yang listen di port $Port. Jalankan Apache Laragon dulu."
        }

        # Subfolder aplikasi di server lokal (mis. '/siap' atau '' bila di root vhost).
        $base = Get-AppBasePathLocal -AppDir (Join-Path $PSScriptRoot '..')
        if ($base -eq '') {
            $base = '/' + (Split-Path (Resolve-Path (Join-Path $PSScriptRoot '..')).Path -Leaf)
            Write-Warning "Aplikasi tidak terdeteksi di root vhost; dicoba subfolder '$base'." -ForegroundColor DarkGray
        }

        $ngrok = Resolve-NgrokPath -Given $NgrokPath

        # Sudah ada tunnel? Tampilkan saja, jangan buat duplikat.
        $existing = Get-TunnelUrl
        if ($existing) {
            Write-Host ""
            Write-Host "Tunnel sudah berjalan." -ForegroundColor Yellow
            Write-Host "  URL publik : $existing"
            Write-Host "  (gunakan 'stop' lalu 'start' bila ingin membuat tunnel baru)"
            Write-Host ""
            break
        }

        Write-Host "Membuka tunnel ngrok -> http://localhost:$Port ..." -ForegroundColor Cyan
        $logFile = Join-Path $PSScriptRoot 'ngrok.log'
        Start-Process -FilePath $ngrok `
            -ArgumentList @('http', "$Port", '--log', 'stdout', '--log-level', 'info') `
            -RedirectStandardOutput $logFile `
            -RedirectStandardError "$logFile.err" `
            -WindowStyle Hidden | Out-Null

        # Tunggu sampai tunnel benar-benar siap (maks 30 detik).
        $url = $null
        for ($i = 0; $i -lt 30; $i++) {
            Start-Sleep -Seconds 1
            $url = Get-TunnelUrl
            if ($url) { break }
        }

        if (-not $url) {
            throw "Tunnel tidak siap dalam 30 detik. Lihat log: $logFile"
        }

        # Pastikan aplikasinya benar-benar menjawab lewat tunnel.
        $ok = $false
        for ($i = 0; $i -lt 10; $i++) {
            if (Test-Tunnel -Url $url) { $ok = $true; break }
            Start-Sleep -Seconds 1
        }

        # Subfolder aplikasi pada URL publik (mis. '/siap' bila aplikasi
        # dilayani lewat default vhost Laragon).
        $baseJaringan = Get-AppBasePath -Url "$url$base/"
        if ($baseJaringan -ne '' -and $baseJaringan -ne $base) {
            $base = $baseJaringan
        }

        Write-Host ""
        if ($ok) {
            Write-Host "  TUNNEL SIAP" -ForegroundColor Green
        } else {
            Write-Host "  TUNNEL TERBUKA (aplikasi belum merespon, cek log)" -ForegroundColor Yellow
        }
        Write-Host "  URL publik  : $url$base" -ForegroundColor White
        Write-Host ""
        Write-Host "  Halaman yang bisa dibuka dari HP:" -ForegroundColor Cyan
        Write-Host "    Beranda        : $url$base/"
        Write-Host "    Presensi (QR)  : $url$base/presensi/"
        Write-Host "    Login petugas : $url$base/login"
        Write-Host "    QR Gerbang     : $url$base/qr/gerbang   (setelah login)"
        Write-Host ""
        Write-Host "  Catatan:" -ForegroundColor DarkGray
        Write-Host "   - Halaman pertama di tiap perangkat akan minta klik 'Visit Site' (peringatan ngrok)." -ForegroundColor DarkGray
        Write-Host "   - Tunnel ini membuat aplikasi terbuka untuk siapa pun yang tahu URL-nya." -ForegroundColor DarkGray
        Write-Host "   - Hentikan dengan:  powershell -ExecutionPolicy Bypass -File tools\ngrok-siap.ps1 stop" -ForegroundColor DarkGray
        Write-Host ""
        break
    }
}
