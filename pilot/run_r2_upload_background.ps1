$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$privateDirectory = Join-Path $env:LOCALAPPDATA 'Pollmedia'
$settingsPath = Join-Path $privateDirectory 'r2-upload-settings.json'
$credentialPath = Join-Path $privateDirectory 'r2-upload-credential.xml'
if (-not (Test-Path -LiteralPath $settingsPath) -or -not (Test-Path -LiteralPath $credentialPath)) {
    throw 'Pollmedia R2 upload settings or private credential are missing.'
}

$settings = Get-Content -LiteralPath $settingsPath -Raw | ConvertFrom-Json
$credential = Import-Clixml -LiteralPath $credentialPath
if ($credential -isnot [System.Management.Automation.PSCredential]) {
    throw 'Pollmedia R2 credential could not be opened by this Windows user.'
}

$env:POLLMEDIA_R2_ENDPOINT = $settings.endpoint
$env:POLLMEDIA_R2_BUCKET = $settings.bucket
$env:POLLMEDIA_R2_PREFIX = $settings.prefix
$env:POLLMEDIA_R2_ACCESS_KEY_ID = $credential.UserName
$env:POLLMEDIA_R2_SECRET_ACCESS_KEY = $credential.GetNetworkCredential().Password
try {
    $log = Join-Path $root 'exports\polling-r2-upload.log'
    & $settings.python (Join-Path $PSScriptRoot 'sync_polling_pdfs_r2.py') --progress-every 100 *>> $log
    if ($LASTEXITCODE -ne 0) {
        throw "R2 uploader exited with code $LASTEXITCODE. See $log."
    }
} finally {
    Remove-Item Env:\POLLMEDIA_R2_ACCESS_KEY_ID -ErrorAction SilentlyContinue
    Remove-Item Env:\POLLMEDIA_R2_SECRET_ACCESS_KEY -ErrorAction SilentlyContinue
}
