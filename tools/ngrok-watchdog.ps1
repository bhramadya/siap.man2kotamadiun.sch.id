<#
.SYNOPSIS
    Penjaga tunnel ngrok untuk aplikasi SIAP.

.DESCRIPTION
    Script ini berjalan terus-menerus dan melakukan tiga hal:

      1. Memastikan Apache + MySQL lokal hidup (mulai otomatis bila mati).
      2. Memastikan tunnel ngrok masih terhubung.
      3. Jika tunnel mati (komputer tidur, ngrok crash, jaringan putus),
         membukanya kembali memakai tools\ngrok-siap.ps1.

    Mode tunnel NORMAL untuk aplikasi ini adalah on-demand lewat ngrok.bat
    (klik 2x). Script ini bersifat opsional: pakai bila ingin tunnel tetap
    hidup tanpa harus klik ngrok.bat. Untuk menjalankannya otomatis saat login
    Windows, daftarkan sebagai Task Scheduler:

      $a = New-ScheduledTaskAction -Execute 'powershell.exe' `
             -Argument '-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "<path>\tools\ngrok-watchdog.ps1"'
      Register-ScheduledTask -TaskName 'SIAP-Ngrok-Tunnel' -Action $a `
             -Trigger (New-ScheduledTaskTrigger -AtLogOn -User $env:USERNAME)

    Tidak ada data yang diubah selain menyalakan service lokal dan membuka tunnel.

.PARAMETER IntervalSeconds
    Jarak antar pemeriksaan. Default 60 detik.

.PARAMETER Port
    Port Apache lokal yang harus hidup. Default 80.

.PARAMETER NoServerStart
    Jangan menyalakan Apache/MySQL otomatis (hanya menjaga tunnel).

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File tools\ngrok-watchdog.ps1

.NOTES
    Log ditulis ke tools\ngrok-watchdog.log
#>
[CmdletBinding()]
param(
    [int]    $IntervalSeconds = 60,
    [int]    $Port = 80,
    [switch] $NoServerStart
)

$ErrorActionPreference = 'Continue'

$scriptDir  = Split-Path -Parent $MyInvocation.MyCommand.Path
$startScript = Join-Path $scriptDir 'ngrok-siap.ps1'
$logFile    = Join-Path $scriptDir 'ngrok-watchdog.log'
$laragonRoot = 'D:\laragon'

# Helper server lokal (Apache + MySQL) -- sama dengan yang dipakai ngrok.bat.
. (Join-Path $scriptDir 'server-siap.ps1')

function Write-Log {
    param([string] $Message, [string] $Level = 'INFO')
    $baris = '{0} [{1}] {2}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Level, $Message
    try { Add-Content -Path $logFile -Value $baris -Encoding UTF8 } catch { }
    Write-Host $baris
}

function Test-TunnelAlive {
    try {
        $api = Invoke-RestMethod -Uri 'http://127.0.0.1:4040/api/tunnels' -TimeoutSec 5
        foreach ($t in $api.tunnels) {
            if ($t.public_url) { return $true }
        }
    } catch { }
    return $false
}

Write-Log "Watchdog ngrok SIAP mulai (interval ${IntervalSeconds}d)."
if ($NoServerStart) { Write-Log "Mode: hanya menjaga tunnel (server lokal tidak dinyalakan otomatis)." }

while ($true) {
    try {
        if (-not $NoServerStart) {
            if (-not (Test-SiapPort -Port 3306)) { Start-SiapMySQL -LaragonRoot $laragonRoot | Out-Null }
            if (-not (Test-SiapPort -Port $Port))  { Start-SiapApache -LaragonRoot $laragonRoot -WebPort $Port | Out-Null }
        }

        if (-not (Test-TunnelAlive)) {
            Write-Log "Tunnel ngrok tidak aktif -> membuka ulang."
            if (Test-Path $startScript) {
                $hasil = & $startScript -Action start 2>&1 | Out-String
                foreach ($baris in ($hasil -split "`r?`n")) {
                    if ($baris.Trim()) { Write-Log ("  " + $baris.Trim()) 'INFO' }
                }
            } else {
                Write-Log "Script tidak ditemukan: $startScript" 'ERROR'
            }
        }
    } catch {
        Write-Log "Error pada siklus watchdog: $($_.Exception.Message)" 'ERROR'
    }

    Start-Sleep -Seconds $IntervalSeconds
}
