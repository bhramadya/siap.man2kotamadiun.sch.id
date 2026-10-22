<#
.SYNOPSIS
    Helper bersama: menyalakan server lokal (Apache + MySQL) untuk aplikasi SIAP.

.DESCRIPTION
    File ini di-dot-source oleh:
      - tools\ngrok-siap.ps1   (action 'ensure-server', dipakai ngrok.bat)
      - tools\ngrok-watchdog.ps1 (penjaga tunnel otomatis)

    Fungsinya:
      - Test-SiapPort        : cek apakah sebuah port LISTENING
      - Start-SiapApache     : nyalakan Apache Laragon (port 80)
      - Start-SiapMySQL      : nyalakan MySQL Laragon (port 3306)
      - Ensure-SiapServer    : pastikan keduanya hidup (hanya menyalakan yang mati)

    Catatan penting MySQL: beberapa versi MySQL bisa terpasang berdampingan
    (mis. 8.4 dan 9.6). Hanya my.ini yang DATADIR-nya benar-benar ada di disk
    yang boleh dipakai -- memakai my.ini versi lain berisiko merusak data.

.EXAMPLE
    . (Join-Path $PSScriptRoot 'server-siap.ps1')
    Ensure-SiapServer
#>

function Get-SiapLaragonRoot {
    <#
      Lokasi instalasi Laragon. Default D:\laragon, fallback C:\laragon.
    #>
    foreach ($r in @('D:\laragon', 'C:\laragon')) {
        if (Test-Path (Join-Path $r 'bin')) { return $r }
    }
    return 'D:\laragon'
}

function Test-SiapPort {
    <#
      True bila ada proses yang LISTENING di port tersebut.
    #>
    param([int] $Port)

    if ($Port -le 0) { return $false }
    return [bool](Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue)
}

function Start-SiapApache {
    <#
      Menjalankan httpd.exe dari instalasi Apache Laragon (mode console,
      di-detach lewat Start-Process supaya tetap hidup setelah pemanggil selesai).
      Mengembalikan $true bila port web sudah LISTENING setelah dijalankan.
    #>
    param(
        [string] $LaragonRoot = '',
        [int]    $WebPort = 80
    )

    if (-not $LaragonRoot) { $LaragonRoot = Get-SiapLaragonRoot }

    try {
        $dirApache = Get-ChildItem (Join-Path $LaragonRoot 'bin\apache') -Directory -ErrorAction Stop |
            Sort-Object Name -Descending | Select-Object -First 1
        if (-not $dirApache) {
            Write-Host "  Apache: folder instalasi tidak ditemukan di $LaragonRoot\bin\apache" -ForegroundColor Red
            return $false
        }

        $httpd = Join-Path $dirApache.FullName 'bin\httpd.exe'
        $conf  = Join-Path $dirApache.FullName 'conf\httpd.conf'
        if (-not (Test-Path $httpd) -or -not (Test-Path $conf)) {
            Write-Host "  Apache: httpd.exe/httpd.conf tidak lengkap di $($dirApache.FullName)" -ForegroundColor Red
            return $false
        }

        $logDir = Join-Path $LaragonRoot 'data\apache'
        New-Item -ItemType Directory -Force -Path $logDir | Out-Null
        $out = Join-Path $logDir 'httpd-stdout.log'

        Start-Process -FilePath $httpd -ArgumentList '-f', "`"$conf`"" `
            -WindowStyle Hidden -RedirectStandardOutput $out -RedirectStandardError "$out.err" | Out-Null

        for ($i = 0; $i -lt 10; $i++) {
            Start-Sleep -Seconds 1
            if (Test-SiapPort -Port $WebPort) {
                Write-Host "  Apache  : hidup di port $WebPort ($($dirApache.Name))" -ForegroundColor Green
                return $true
            }
        }

        Write-Host "  Apache  : GAGAL start, cek log: $out.err" -ForegroundColor Red
        return $false
    } catch {
        Write-Host "  Apache  : error -- $($_.Exception.Message)" -ForegroundColor Red
        return $false
    }
}

function Start-SiapMySQL {
    <#
      Menjalankan mysqld.exe memakai my.ini yang datadir-nya tersedia.
      Mengembalikan $true bila port DB sudah LISTENING.
    #>
    param(
        [string] $LaragonRoot = '',
        [int]    $DbPort = 3306
    )

    if (-not $LaragonRoot) { $LaragonRoot = Get-SiapLaragonRoot }

    try {
        $opsi = @()
        foreach ($dir in (Get-ChildItem (Join-Path $LaragonRoot 'bin\mysql') -Directory -ErrorAction SilentlyContinue)) {
            $myIni  = Join-Path $dir.FullName 'my.ini'
            $mysqld = Join-Path $dir.FullName 'bin\mysqld.exe'
            if (-not (Test-Path $myIni) -or -not (Test-Path $mysqld)) { continue }

            $isi = Get-Content $myIni -Raw
            if ($isi -notmatch 'datadir\s*=\s*"?([^"\r\n]+)"?') { continue }
            $dataDir = $Matches[1] -replace '/', '\'
            if (-not (Test-Path $dataDir)) { continue }

            $opsi += [pscustomobject]@{
                Mysqld  = $mysqld
                MyIni   = $myIni
                DataDir = $dataDir
                Versi   = ($dir.Name -replace '^mysql-', '')
            }
        }

        if ($opsi.Count -eq 0) {
            Write-Host "  MySQL   : tidak ada my.ini dengan datadir yang tersedia" -ForegroundColor Red
            return $false
        }

        # Pilih versi tertinggi yang datadir-nya benar-benar ada.
        $pilih = $opsi |
            Sort-Object { try { [version]$_.Versi } catch { [version]'0.0.0' } } -Descending |
            Select-Object -First 1

        Start-Process -FilePath $pilih.Mysqld `
            -ArgumentList "--defaults-file=`"$($pilih.MyIni)`"" -WindowStyle Hidden | Out-Null

        for ($i = 0; $i -lt 15; $i++) {
            Start-Sleep -Seconds 1
            if (Test-SiapPort -Port $DbPort) {
                Write-Host "  MySQL   : hidup di port $DbPort (versi $($pilih.Versi), datadir $($pilih.DataDir))" -ForegroundColor Green
                return $true
            }
        }

        Write-Host "  MySQL   : GAGAL start (datadir $($pilih.DataDir))" -ForegroundColor Red
        return $false
    } catch {
        Write-Host "  MySQL   : error -- $($_.Exception.Message)" -ForegroundColor Red
        return $false
    }
}

function Ensure-SiapServer {
    <#
      Pastikan Apache + MySQL lokal hidup. Hanya menyalakan yang memang mati.
      Mengembalikan $true bila keduanya siap.
    #>
    param(
        [int]    $WebPort = 80,
        [int]    $DbPort = 3306,
        [string] $LaragonRoot = ''
    )

    if (-not $LaragonRoot) { $LaragonRoot = Get-SiapLaragonRoot }
    $ok = $true

    if (Test-SiapPort -Port $DbPort) {
        Write-Host "  MySQL   : sudah hidup di port $DbPort" -ForegroundColor DarkGray
    } else {
        if (-not (Start-SiapMySQL -LaragonRoot $LaragonRoot -DbPort $DbPort)) { $ok = $false }
    }

    if (Test-SiapPort -Port $WebPort) {
        Write-Host "  Apache  : sudah hidup di port $WebPort" -ForegroundColor DarkGray
    } else {
        if (-not (Start-SiapApache -LaragonRoot $LaragonRoot -WebPort $WebPort)) { $ok = $false }
    }

    return $ok
}
