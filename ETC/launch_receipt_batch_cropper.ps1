$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$launcherLogPath = Join-Path $PSScriptRoot 'receipt_batch_cropper_launcher.log'

function Write-LauncherLog {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Message
    )

    $timestamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    Add-Content -LiteralPath $launcherLogPath -Value "[$timestamp] $Message" -Encoding UTF8
}

try {
    $receiptCropperPath = Join-Path $PSScriptRoot 'receipt_batch_cropper.py'
    if (-not (Test-Path -LiteralPath $receiptCropperPath -PathType Leaf)) {
        throw "receipt_batch_cropper.py 파일을 찾을 수 없습니다: $receiptCropperPath"
    }

    $pythonCandidates = @(
        'C:\Users\gfw79\AppData\Local\Python\pythoncore-3.14-64\pythonw.exe',
        'C:\Users\gfw79\AppData\Local\Python\pythoncore-3.14-64\python.exe',
        'C:\Users\gfw79\AppData\Local\Microsoft\WindowsApps\pythonw.exe',
        'C:\Users\gfw79\AppData\Local\Microsoft\WindowsApps\python.exe'
    )

    $pythonExecutable = $pythonCandidates | Where-Object {
        Test-Path -LiteralPath $_ -PathType Leaf
    } | Select-Object -First 1

    if ([string]::IsNullOrWhiteSpace($pythonExecutable)) {
        throw '실행 가능한 Python 파일을 찾을 수 없습니다.'
    }

    $sessionId = [System.Diagnostics.Process]::GetCurrentProcess().SessionId
    $existingProcess = Get-CimInstance Win32_Process -Filter "name = 'pythonw.exe' OR name = 'python.exe'" | Where-Object {
        $_.ProcessId -ne $PID -and
        $_.SessionId -eq $sessionId -and
        $_.CommandLine -like '*receipt_batch_cropper.py*'
    } | Select-Object -First 1

    if ($null -ne $existingProcess) {
        Write-LauncherLog("Receipt cropper is already running. ExistingProcessId=$($existingProcess.ProcessId) SessionId=$sessionId")
        exit 0
    }

    Write-LauncherLog("Launching receipt cropper. User=$env:USERNAME SessionId=$sessionId Interactive=$([Environment]::UserInteractive) Python=$pythonExecutable")

    Start-Process -FilePath $pythonExecutable `
        -ArgumentList @($receiptCropperPath) `
        -WorkingDirectory $PSScriptRoot | Out-Null

    Write-LauncherLog('Launch request submitted successfully.')
}
catch {
    Write-LauncherLog("Launch failed: $($_.Exception.Message)")
    exit 1
}
