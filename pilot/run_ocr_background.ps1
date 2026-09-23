param(
    [switch] $CheckOnly,
    [ValidateSet('primary', 'secondary')]
    [string] $Lane = 'primary'
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$archiveRoot = Join-Path $projectRoot 'application\storage\app\private\polling-station-sources'
$logPath = Join-Path $archiveRoot 'background-ocr.log'
$python = Join-Path $env:LOCALAPPDATA 'Programs\Python\Python311\python.exe'
$ocrScript = Join-Path $PSScriptRoot 'ocr_polling_sources.py'
$tessdata = 'C:\Program Files\Tesseract-OCR\tessdata'
$states = if ($Lane -eq 'primary') {
    @('TAMIL NADU', 'ASSAM', 'ODISHA', 'ANDHRA PRADESH', 'WEST BENGAL', 'MADHYA PRADESH')
} else {
    @('HARYANA', 'CHHATTISGARH', 'UTTARAKHAND', 'BIHAR', 'NCT OF DELHI')
}

function Write-Checkpoint([string] $message) {
    Add-Content -LiteralPath $logPath -Encoding UTF8 -Value ((Get-Date).ToString('o') + ' ' + $message)
}

function Get-OtherOcrProcess {
    @(Get-CimInstance Win32_Process -Filter "Name='python.exe'" |
        Where-Object { $_.CommandLine -like '*ocr_polling_sources.py*' })
}

if ($CheckOnly) {
    [pscustomobject]@{
        project = $projectRoot
        lane = $Lane
        python = Test-Path -LiteralPath $python
        ocr_script = Test-Path -LiteralPath $ocrScript
        tessdata = Test-Path -LiteralPath $tessdata
        states = $states.Count
        other_ocr_processes = @(Get-OtherOcrProcess).Count
    } | ConvertTo-Json -Compress
    exit 0
}

$mutex = New-Object System.Threading.Mutex($false, ('Local\PollmediaOcrWorker' + $Lane))
$acquired = $false
try {
    try {
        $acquired = $mutex.WaitOne(0)
    } catch [System.Threading.AbandonedMutexException] {
        $acquired = $true
    }
    if (-not $acquired) { exit 0 }
    if (-not (Test-Path -LiteralPath $python) -or -not (Test-Path -LiteralPath $ocrScript)) {
        throw 'Python or the OCR script is missing.'
    }

    Write-Checkpoint "Background OCR worker started: $Lane."
    foreach ($state in $states) {
        while ($true) {
            if (Test-Path -LiteralPath (Join-Path $archiveRoot 'stop-background-ocr.txt')) {
                Write-Checkpoint 'Stop marker found; worker exited.'
                exit 0
            }
            $otherWorkers = @(Get-OtherOcrProcess).Count
            if ($otherWorkers -ge 2) {
                Start-Sleep -Seconds 60
                continue
            }

            $projectDrive = [System.IO.Path]::GetPathRoot($projectRoot)
            $freeBytes = (New-Object System.IO.DriveInfo($projectDrive)).AvailableFreeSpace
            $freeMemoryKb = (Get-CimInstance Win32_OperatingSystem).FreePhysicalMemory
            $requiredMemoryKb = if ($Lane -eq 'secondary' -or $otherWorkers -gt 0) { 3145728 } else { 1310720 }
            if ($freeBytes -lt 10GB -or $freeMemoryKb -lt $requiredMemoryKb) {
                Write-Checkpoint "Lane=$Lane waiting for resources: disk=$freeBytes bytes; memory=$freeMemoryKb KiB; other_ocr=$otherWorkers."
                Start-Sleep -Seconds 300
                continue
            }

            $output = @(& $python $ocrScript --state $state --limit 500 --language eng --tessdata $tessdata --progress-every 500 2>&1)
            if ($LASTEXITCODE -ne 0) {
                Write-Checkpoint "Lane=$Lane OCR failed for $state with exit code $LASTEXITCODE; $($output[-1])"
                exit 1
            }
            $summary = $output[-1] | ConvertFrom-Json
            Write-Checkpoint "Lane=$Lane state=$state processed=$($summary.processed_pages) failures=$($summary.ocr_failures)."
            if ($summary.processed_pages -eq 0) { break }
        }
    }
    Write-Checkpoint "All configured state OCR batches finished: $Lane."
} catch {
    Write-Checkpoint "Worker stopped: $($_.Exception.Message)"
    exit 1
} finally {
    if ($acquired) { $mutex.ReleaseMutex() }
    $mutex.Dispose()
}
