param(
    [string] $SwfPath = 'public/farmville/embeds/Flash/v855037.855026/FarmGame.855037.855026.swf',
    [Parameter(Mandatory = $true)]
    [string] $FfdecPath,
    [string] $OutputPath = 'storage/app/flash-contract-audit.md'
)

$ErrorActionPreference = 'Stop'

function Get-LiteralAmfCalls {
    param([string] $Source)

    $calls = [System.Collections.Generic.List[string]]::new()
    $patterns = @(
        # Most non-world AMF requests are constructed this way.
        'new\s+TGenericTransaction\s*\(\s*"(?<service>[A-Za-z0-9_]+)"\s*,\s*"(?<method>[A-Za-z0-9_]+)"',
        # A few transaction classes call a literal fully-qualified service.
        'signedCall\s*\(\s*"(?<service>[A-Za-z0-9_]+)\.(?<method>[A-Za-z0-9_]+)"'
    )

    foreach ($pattern in $patterns) {
        foreach ($match in [regex]::Matches($Source, $pattern)) {
            $calls.Add("$($match.Groups['service'].Value).$($match.Groups['method'].Value)")
        }
    }

    return $calls
}

if (-not (Test-Path -LiteralPath $SwfPath -PathType Leaf)) {
    throw "SWF not found: $SwfPath"
}

if (-not (Test-Path -LiteralPath $FfdecPath -PathType Leaf)) {
    throw "JPEXS CLI not found: $FfdecPath"
}

$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$workingRoot = Join-Path $repoRoot '.cache/flash-contract-audit'
$scriptExport = Join-Path $workingRoot 'scripts'

if (Test-Path -LiteralPath $workingRoot) {
    Remove-Item -LiteralPath $workingRoot -Recurse -Force
}
New-Item -ItemType Directory -Path $workingRoot -Force | Out-Null

# Exporting all ~6,000 scripts needs several gigabytes of Java heap and is
# unnecessary for this first-pass audit. The transaction layer contains the
# normal AMF call sites, so get its class names from JPEXS first and export
# only those classes.
$as3Index = & $FfdecPath -dumpAS3 $SwfPath
if ($LASTEXITCODE -ne 0) {
    throw "JPEXS AS3 index failed with exit code $LASTEXITCODE"
}

$transactionClasses = $as3Index |
    ForEach-Object {
        $match = [regex]::Match($_, '^(?<class>.+)\s+\d+$')
        if ($match.Success) { $match.Groups['class'].Value }
    } |
    Where-Object { $_ -like 'Transactions.*' -or $_ -like 'Engine.Transactions.*' } |
    Sort-Object -Unique

if ($transactionClasses.Count -eq 0) {
    throw 'No transaction classes found in the SWF'
}

Write-Host "Exporting $($transactionClasses.Count) transaction classes from the SWF..."
# Parallel export inflates JPEXS' heap substantially on large game SWFs. A
# serial export is slower but makes the audit usable on the same modest VM
# that runs the game stack.
& $FfdecPath -config parallelSpeedUp=false -selectclass ($transactionClasses -join ',') -export script $workingRoot $SwfPath
if ($LASTEXITCODE -ne 0) {
    throw "JPEXS export failed with exit code $LASTEXITCODE"
}

$clientCalls = @{}
Get-ChildItem -LiteralPath $scriptExport -Filter '*.as' -File -Recurse | ForEach-Object {
    $relativePath = $_.FullName.Substring($scriptExport.Length).TrimStart('\', '/')
    $source = Get-Content -LiteralPath $_.FullName -Raw
    Get-LiteralAmfCalls -Source $source | ForEach-Object {
        if (-not $clientCalls.ContainsKey($_)) {
            $clientCalls[$_] = [System.Collections.Generic.List[string]]::new()
        }
        $clientCalls[$_].Add($relativePath)
    }
}

# World actions are dynamically routed through TWorldState, so add their
# stable outer contract even though action names themselves are subclasses.
if (-not $clientCalls.ContainsKey('WorldService.performAction')) {
    $clientCalls['WorldService.performAction'] = [System.Collections.Generic.List[string]]::new()
}
$clientCalls['WorldService.performAction'].Add('Transactions/TWorldState.as')

$serverMethods = @{}
$functionsDir = Join-Path $repoRoot 'public/farmville/flashservices/amfphp/Functions'
Get-ChildItem -LiteralPath $functionsDir -Filter '*.php' -File | ForEach-Object {
    $source = Get-Content -LiteralPath $_.FullName -Raw
    $classMatch = [regex]::Match($source, '\bclass\s+(?<class>[A-Za-z_][A-Za-z0-9_]*)')
    if (-not $classMatch.Success) {
        return
    }

    $service = $classMatch.Groups['class'].Value
    $methods = [regex]::Matches($source, '\bpublic\s+(?:static\s+)?function\s+(?<method>[A-Za-z_][A-Za-z0-9_]*)') |
        ForEach-Object { $_.Groups['method'].Value }
    $serverMethods[$service] = @($methods)
}

$rows = foreach ($call in $clientCalls.Keys | Sort-Object) {
    $parts = $call.Split('.', 2)
    $service = $parts[0]
    $method = $parts[1]
    $implemented = $serverMethods.ContainsKey($service) -and $serverMethods[$service] -contains $method
    [PSCustomObject]@{
        Contract = $call
        Status = if ($implemented) { 'handler present' } else { 'no PHP handler found' }
        Caller = ($clientCalls[$call] | Sort-Object -Unique | Select-Object -First 3) -join '<br>'
    }
}

$implementedCount = @($rows | Where-Object Status -eq 'handler present').Count
$missingCount = @($rows | Where-Object Status -eq 'no PHP handler found').Count
$missingByService = $rows |
    Where-Object Status -eq 'no PHP handler found' |
    ForEach-Object {
        [PSCustomObject]@{ Service = $_.Contract.Split('.', 2)[0] }
    } |
    Group-Object Service |
    Sort-Object -Property @{ Expression = 'Count'; Descending = $true }, @{ Expression = 'Name'; Descending = $false }
$outputAbsolute = Join-Path $repoRoot $OutputPath
New-Item -ItemType Directory -Path (Split-Path -Parent $outputAbsolute) -Force | Out-Null

$markdown = [System.Collections.Generic.List[string]]::new()
$markdown.Add('# Flash contract audit')
$markdown.Add('')
$markdown.Add("Generated from $([IO.Path]::GetFileName($SwfPath)) on $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss K').")
$markdown.Add('')
$markdown.Add("- Literal client AMF calls found: **$($rows.Count)**")
$markdown.Add("- PHP handlers present: **$implementedCount**")
$markdown.Add("- Calls without a PHP handler: **$missingCount**")
$markdown.Add('')
$markdown.Add('This is an inventory, not proof that every missing call blocks gameplay. It includes literal service/method strings from the transaction layer; dynamic calls, non-transaction callers, and callback-field requirements still need individual contract tracing.')
$markdown.Add('')
$markdown.Add('## Missing-handler triage by service')
$markdown.Add('')
$markdown.Add('Use this grouping to select a feature family for investigation. Do not add blanket success responses: each selected method still needs its callback and persistence contract traced.')
$markdown.Add('')
$markdown.Add('| Service | Missing literal calls |')
$markdown.Add('| --- | ---: |')
foreach ($service in $missingByService) {
    $markdown.Add("| ``$($service.Name)`` | $($service.Count) |")
}
$markdown.Add('')
$markdown.Add('| Client contract | PHP coverage | First caller(s) |')
$markdown.Add('| --- | --- | --- |')
foreach ($row in $rows) {
    $markdown.Add("| ``$($row.Contract)`` | $($row.Status) | $($row.Caller) |")
}

Set-Content -LiteralPath $outputAbsolute -Value $markdown -Encoding utf8
Write-Host "Wrote $outputAbsolute"
