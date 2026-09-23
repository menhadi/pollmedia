param(
    [string[]] $States = @('CHHATTISGARH', 'UTTARAKHAND', 'HARYANA', 'WEST BENGAL',
        'ANDHRA PRADESH', 'ODISHA', 'NCT OF DELHI', 'MADHYA PRADESH', 'ASSAM'),
    [string] $PackageDate = (Get-Date -Format 'yyyyMMdd'),
    [string] $Target = 'pollmedia@94.136.186.150',
    [string] $KeyPath = (Join-Path $env:USERPROFILE '.ssh\pollmedia_ed25519'),
    [switch] $CheckOnly
)

$ErrorActionPreference = 'Stop'
if ($PackageDate -notmatch '^20[0-9]{6}$') {
    throw 'PackageDate must be YYYYMMDD.'
}
$projectRoot = Split-Path -Parent $PSScriptRoot
$sourceRoot = Join-Path $projectRoot 'application\storage\app\private\polling-station-sources'
$exports = Join-Path $projectRoot 'exports'
$receiptPath = Join-Path $exports 'polling-db-import-receipts.jsonl'
$index = Get-Content -LiteralPath (Join-Path $sourceRoot 'index.json') -Raw | ConvertFrom-Json
$available = @($index.states | ForEach-Object { $_.state })
$key = $KeyPath
$target = $Target
$remoteStage = "/home/pollmedia/tmp/polling-import-$PackageDate"

foreach ($state in $States) {
    if ($state -notin $available) {
        throw "State is absent from the preserved source index: $state"
    }
}
if ($CheckOnly) {
    [pscustomobject]@{ states = $States; project = $projectRoot; key_exists = (Test-Path -LiteralPath $key) } |
        ConvertTo-Json -Compress
    exit 0
}
if (-not (Test-Path -LiteralPath $key)) {
    throw 'The existing pollmedia SSH key is unavailable.'
}

$mutex = [System.Threading.Mutex]::new($false, 'Local\PollmediaDatabaseImport')
$acquired = $false
try {
    try {
        $acquired = $mutex.WaitOne(0)
    } catch [System.Threading.AbandonedMutexException] {
        $acquired = $true
    }
    if (-not $acquired) {
        throw 'Another polling database importer is already running.'
    }

    $completed = @{}
    if (Test-Path -LiteralPath $receiptPath) {
        foreach ($line in [System.IO.File]::ReadLines($receiptPath)) {
            if ($line.Trim()) {
                $entry = $line | ConvertFrom-Json
                $completed["$($entry.state)|$($entry.package)"] = $entry
            }
        }
    }

    Push-Location $projectRoot
    try {
        foreach ($state in $States) {
            $slug = ($state.ToLowerInvariant() -replace '[^a-z0-9]+', '-').Trim('-')
            if ($slug -notmatch '^[a-z0-9-]+$') {
                throw "Unsafe state slug: $state"
            }
            $base = "pollmedia-polling-$slug-db-$PackageDate"
            $receiptKey = "$state|$base.zip"
            if ($completed.ContainsKey($receiptKey)) {
                Write-Output "Already imported: $state"
                continue
            }
            $zip = Join-Path $exports "$base.zip"
            $checksum = Join-Path $exports "$base.sha256"
            $partial = Join-Path $exports "$base.partial"

            while ($true) {
                $active = @(Get-CimInstance Win32_Process -Filter "Name='python.exe'" |
                    Where-Object { $_.CommandLine -like '*ocr_polling_sources.py*' -and $_.CommandLine -like "*$state*" })
                if ($active.Count -eq 0) { break }
                Write-Output "Waiting for active OCR to leave $state."
                Start-Sleep -Seconds 300
            }
            if ((Get-PSDrive -Name ([System.IO.Path]::GetPathRoot($projectRoot).Substring(0, 1))).Free -lt 10GB) {
                throw 'Local disk reserve fell below 10 GiB.'
            }
            if ((Test-Path -LiteralPath $partial) -or ((Test-Path -LiteralPath $zip) -xor (Test-Path -LiteralPath $checksum))) {
                throw "Incomplete existing package needs inspection: $base"
            }
            if (-not (Test-Path -LiteralPath $zip)) {
                Write-Output "Building verified data-only package: $state"
                & python -u (Join-Path $PSScriptRoot 'preserve_polling_sources.py') $zip --state $state --data-only
                if ($LASTEXITCODE -ne 0) { throw "Package creation failed for $state" }
            }
            $expected = ((Get-Content -LiteralPath $checksum -Raw).Trim() -split '\s+')[0]
            $actual = (Get-FileHash -LiteralPath $zip -Algorithm SHA256).Hash.ToLowerInvariant()
            if ($expected -ne $actual) { throw "Package checksum differs for $state" }

            $remoteDisk = @()
            for ($attempt = 1; $attempt -le 3; $attempt++) {
                $remoteDisk = @(& ssh -i $key -o IdentitiesOnly=yes -o BatchMode=yes -o ConnectTimeout=15 -o ConnectionAttempts=1 $target 'df -Pk /home/pollmedia | tail -n 1' 2>&1)
                if ($LASTEXITCODE -eq 0 -and $remoteDisk.Count -gt 0) { break }
                if ($attempt -eq 3) { throw 'Could not check server disk reserve after three SSH attempts.' }
                Start-Sleep -Seconds (5 * $attempt)
            }
            $fields = ($remoteDisk[-1].Trim() -split '\s+')
            if (([int64]$fields[3] * 1024) -lt 10GB) { throw 'Server disk reserve fell below 10 GiB.' }
            & ssh -i $key -o IdentitiesOnly=yes -o BatchMode=yes -o ConnectTimeout=15 $target "mkdir -p -m 700 $remoteStage"
            if ($LASTEXITCODE -ne 0) { throw 'Could not prepare the server staging directory.' }
            $remoteVerified = @(& ssh -i $key -o IdentitiesOnly=yes -o BatchMode=yes -o ConnectTimeout=15 $target "cd '$remoteStage' && sha256sum -c '$base.sha256' >/dev/null 2>&1" 2>&1)
            if ($LASTEXITCODE -ne 0) {
                $transferred = $false
                for ($attempt = 1; $attempt -le 3; $attempt++) {
                    Write-Output "Transferring $base (attempt $attempt of 3)."
                    & scp -q -i $key -o IdentitiesOnly=yes -o BatchMode=yes -o ServerAliveInterval=15 -o ServerAliveCountMax=5 "exports/$base.zip" "${target}:$remoteStage/$base.zip.part"
                    if ($LASTEXITCODE -eq 0) {
                        $remoteHash = @(& ssh -i $key -o IdentitiesOnly=yes -o BatchMode=yes -o ConnectTimeout=15 $target "sha256sum '$remoteStage/$base.zip.part'" 2>&1)
                        if ($LASTEXITCODE -eq 0 -and $remoteHash.Count -gt 0 -and ($remoteHash[-1].Trim() -split '\s+')[0] -eq $actual) {
                            & scp -q -i $key -o IdentitiesOnly=yes -o BatchMode=yes "exports/$base.sha256" "${target}:$remoteStage/$base.sha256"
                            if ($LASTEXITCODE -eq 0) {
                                & ssh -i $key -o IdentitiesOnly=yes -o BatchMode=yes -o ConnectTimeout=15 $target "mv -f '$remoteStage/$base.zip.part' '$remoteStage/$base.zip'"
                                if ($LASTEXITCODE -eq 0) { $transferred = $true; break }
                            }
                        }
                    }
                    if ($attempt -lt 3) { Start-Sleep -Seconds (5 * $attempt) }
                }
                if (-not $transferred) { throw "Could not transfer and verify $base after three attempts." }
            }

            $remoteCommand = 'set -eu; stage={0}; cd "$stage"; sha256sum -c {1}.sha256; mkdir -p -m 700 "$stage/{2}"; unzip -qn {1}.zip -d "$stage/{2}"; cd /home/pollmedia/app; git merge-base --is-ancestor 22fa20c HEAD; if php8.4 application/artisan polling:import --root="$stage/{2}/application/storage/app/private/polling-station-sources" --no-interaction > "$stage/{2}-import.log" 2>&1; then tail -n 1 "$stage/{2}-import.log"; else tail -n 12 "$stage/{2}-import.log"; exit 1; fi' -f $remoteStage, $base, $slug
            $result = & ssh -i $key -o IdentitiesOnly=yes -o BatchMode=yes -o ConnectTimeout=15 -o ServerAliveInterval=15 -o ServerAliveCountMax=8 $target $remoteCommand 2>&1
            if ($LASTEXITCODE -ne 0) {
                $result | Select-Object -Last 12 | ForEach-Object { Write-Output $_ }
                throw "Server import failed for $state"
            }
            $summary = @($result | Where-Object { $_ -match '^Imported [0-9]+ documents' } | Select-Object -Last 1)[0]
            if (-not $summary) { throw "Server import did not report a summary for $state" }
            $record = [pscustomobject]@{ state = $state; sha256 = $actual; package = "$base.zip";
                summary = $summary; imported_at = (Get-Date).ToUniversalTime().ToString('o') }
            Add-Content -LiteralPath $receiptPath -Encoding UTF8 -Value ($record | ConvertTo-Json -Compress)
            $completed[$receiptKey] = $record
            Write-Output "$state : $summary"
        }
    } finally {
        Pop-Location
    }
} finally {
    if ($acquired) { $mutex.ReleaseMutex() }
    $mutex.Dispose()
}
