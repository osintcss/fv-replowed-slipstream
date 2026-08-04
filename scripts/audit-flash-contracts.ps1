param(
    [string] $SwfPath = 'public/farmville/embeds/Flash/v855037.855026/FarmGame-10.swf',
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

function Get-CallbackFieldReads {
    param([string] $Source)

    $fields = [System.Collections.Generic.HashSet[string]]::new([StringComparer]::Ordinal)
    $methodPattern = '(?m)(?:override\s+)?(?:public|protected|private)?\s*function\s+(?:onComplete|on[A-Z][A-Za-z0-9_]*)\s*\(\s*(?<param>[A-Za-z_][A-Za-z0-9_]*)\s*:\s*Object[^)]*\)[^{]*\{'

    foreach ($methodMatch in [regex]::Matches($Source, $methodPattern)) {
        $parameter = $methodMatch.Groups['param'].Value
        $bodyStart = $methodMatch.Index + $methodMatch.Length
        $depth = 1
        $position = $bodyStart

        while ($position -lt $Source.Length -and $depth -gt 0) {
            switch ($Source[$position]) {
                '{' { $depth++ }
                '}' { $depth-- }
            }
            $position++
        }

        $bodyLength = [Math]::Max(0, $position - $bodyStart - 1)
        $body = $Source.Substring($bodyStart, $bodyLength)
        $escapedParameter = [regex]::Escape($parameter)
        $patterns = @(
            "\b$escapedParameter\s*\.\s*(?<field>[A-Za-z_][A-Za-z0-9_]*)",
            ('\b{0}\s*\[\s*["''](?<field>[^"'']+)["'']\s*\]' -f $escapedParameter),
            ('\b{0}\s*\.\s*hasOwnProperty\s*\(\s*["''](?<field>[^"'']+)["'']' -f $escapedParameter)
        )

        foreach ($pattern in $patterns) {
            foreach ($fieldMatch in [regex]::Matches($body, $pattern)) {
                $field = $fieldMatch.Groups['field'].Value
                if ($field -ne 'hasOwnProperty') {
                    [void] $fields.Add($field)
                }
            }
        }
    }

    return $fields
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
# that runs the game stack. Batch class names to stay below Windows' command-
# line length limit on clients with hundreds of transaction classes.
$exportBatchSize = 60
for ($offset = 0; $offset -lt $transactionClasses.Count; $offset += $exportBatchSize) {
    $lastIndex = [Math]::Min($offset + $exportBatchSize - 1, $transactionClasses.Count - 1)
    $batch = $transactionClasses[$offset..$lastIndex]
    Write-Host "  Batch $($offset + 1)-$($lastIndex + 1)..."
    & $FfdecPath -config parallelSpeedUp=false -selectclass ($batch -join ',') -export script $workingRoot $SwfPath
    if ($LASTEXITCODE -ne 0) {
        throw "JPEXS export failed for transaction classes $($offset + 1)-$($lastIndex + 1) with exit code $LASTEXITCODE"
    }
}

$clientCalls = @{}
$callbackFields = @{}
Get-ChildItem -LiteralPath $scriptExport -Filter '*.as' -File -Recurse | ForEach-Object {
    $relativePath = $_.FullName.Substring($scriptExport.Length).TrimStart('\', '/')
    $source = Get-Content -LiteralPath $_.FullName -Raw
    $sourceCalls = @(Get-LiteralAmfCalls -Source $source)
    $sourceFields = @(Get-CallbackFieldReads -Source $source)
    $sourceCalls | ForEach-Object {
        $call = $_
        if (-not $clientCalls.ContainsKey($call)) {
            $clientCalls[$call] = [System.Collections.Generic.List[string]]::new()
        }
        $clientCalls[$call].Add($relativePath)

        if (-not $callbackFields.ContainsKey($call)) {
            $callbackFields[$call] = [System.Collections.Generic.HashSet[string]]::new([StringComparer]::Ordinal)
        }
        foreach ($field in $sourceFields) {
            [void] $callbackFields[$call].Add($field)
        }
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
$flashServicePath = Join-Path $repoRoot 'public/farmville/flashservices/amfphp/Services/FlashService.php'
$flashServiceSource = Get-Content -LiteralPath $flashServicePath -Raw
$registeredFunctionFiles = [System.Collections.Generic.HashSet[string]]::new([StringComparer]::OrdinalIgnoreCase)
[regex]::Matches($flashServiceSource, 'Functions/(?<file>[A-Za-z_][A-Za-z0-9_]*\.php)') | ForEach-Object {
    [void] $registeredFunctionFiles.Add($_.Groups['file'].Value)
}

Get-ChildItem -LiteralPath $functionsDir -Filter '*.php' -File | ForEach-Object {
    $source = Get-Content -LiteralPath $_.FullName -Raw
    $classMatch = [regex]::Match($source, '\bclass\s+(?<class>[A-Za-z_][A-Za-z0-9_]*)')
    if (-not $classMatch.Success) {
        return
    }

    $service = $classMatch.Groups['class'].Value
    $methods = [regex]::Matches($source, '\bpublic\s+(?:static\s+)?function\s+(?<method>[A-Za-z_][A-Za-z0-9_]*)') |
        ForEach-Object { $_.Groups['method'].Value }
    $serverMethods[$service] = [PSCustomObject]@{
        Methods = @($methods)
        Registered = $registeredFunctionFiles.Contains($_.Name)
    }
}

$rows = foreach ($call in $clientCalls.Keys | Sort-Object) {
    $parts = $call.Split('.', 2)
    $service = $parts[0]
    $method = $parts[1]
    $handlerPresent = $serverMethods.ContainsKey($service) -and $serverMethods[$service].Methods -contains $method
    $handlerRegistered = $handlerPresent -and $serverMethods[$service].Registered
    $fields = if ($callbackFields.ContainsKey($call)) {
        @($callbackFields[$call] | Sort-Object)
    } else {
        @()
    }
    [PSCustomObject]@{
        Contract = $call
        Status = if (-not $handlerPresent) {
            'no PHP handler found'
        } elseif (-not $handlerRegistered) {
            'handler file not registered'
        } else {
            'handler present'
        }
        Caller = ($clientCalls[$call] | Sort-Object -Unique | Select-Object -First 3) -join '<br>'
        CallbackFields = if ($fields.Count -gt 0) { ($fields -join ', ') } else { 'none detected' }
    }
}

$implementedCount = @($rows | Where-Object Status -eq 'handler present').Count
$missingCount = @($rows | Where-Object Status -eq 'no PHP handler found').Count
$unregisteredCount = @($rows | Where-Object Status -eq 'handler file not registered').Count
$callbackFieldCount = @($callbackFields.Values | ForEach-Object { $_ } | Sort-Object -Unique).Count
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
$markdown.Add("- Handlers whose files are not registered: **$unregisteredCount**")
$markdown.Add("- Distinct direct callback fields detected: **$callbackFieldCount**")
$markdown.Add('')
$markdown.Add('This is an inventory, not proof that every missing call blocks gameplay. It includes literal service/method strings and direct callback-field reads from the transaction layer. Dynamic calls, non-transaction callers, nested/aliased fields, and the semantic meaning of each response still need focused tracing.')
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
$markdown.Add('| Client contract | PHP coverage | Direct callback fields | First caller(s) |')
$markdown.Add('| --- | --- | --- | --- |')
foreach ($row in $rows) {
    $markdown.Add("| ``$($row.Contract)`` | $($row.Status) | $($row.CallbackFields) | $($row.Caller) |")
}

Set-Content -LiteralPath $outputAbsolute -Value $markdown -Encoding utf8
Write-Host "Wrote $outputAbsolute"
