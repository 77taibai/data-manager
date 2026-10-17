[CmdletBinding()]
param(
    [string]$BaseUrl = 'http://127.0.0.1:8000',
    [Parameter(Mandatory = $true)][string]$Token,
    [string]$ReadOnlyToken = ''
)

$ErrorActionPreference = 'Stop'
$BaseUrl = $BaseUrl.TrimEnd('/')

$script:Pass = 0
$script:Fail = 0

function Write-Result {
    param([string]$Name, [bool]$Ok, [string]$Detail = '')
    if ($Ok) {
        $script:Pass++
        Write-Host ("PASS  " + $Name) -ForegroundColor Green
    } else {
        $script:Fail++
        $msg = "FAIL  " + $Name
        if ($Detail -ne '') { $msg += "  -> " + $Detail }
        Write-Host $msg -ForegroundColor Red
    }
}

function Invoke-Api {
    param(
        [string]$Method,
        [string]$Url,
        [string]$Token = '',
        [object]$Body = $null
    )
    $headers = @{}
    if ($Token -ne '') { $headers['Authorization'] = "Bearer $Token" }

    $params = @{
        Method           = $Method
        Uri              = $Url
        Headers          = $headers
        UseBasicParsing  = $true
        ErrorAction      = 'Stop'
    }
    if ($null -ne $Body) {
        $params['Body'] = ($Body | ConvertTo-Json -Depth 20 -Compress)
        $params['ContentType'] = 'application/json'
    }

    try {
        $resp = Invoke-WebRequest @params
        return [pscustomobject]@{ Status = [int]$resp.StatusCode; Body = [string]$resp.Content; Ok = $true }
    } catch {
        $response = $_.Exception.Response
        if ($null -ne $response) {
            $status = [int]$response.StatusCode
            $content = ''
            try {
                $stream = $response.GetResponseStream()
                $reader = New-Object System.IO.StreamReader($stream)
                $content = $reader.ReadToEnd()
                $reader.Close()
            } catch {
                $content = ''
            }
            return [pscustomobject]@{ Status = $status; Body = $content; Ok = $false }
        }
        return [pscustomobject]@{ Status = 0; Body = [string]$_.Exception.Message; Ok = $false }
    }
}

function Convert-JsonSafe {
    param([string]$Text)
    if ([string]::IsNullOrWhiteSpace($Text)) { return $null }
    try { return $Text | ConvertFrom-Json } catch { return $null }
}

function New-Attributes {
    param([object]$SchemaAttributes, [string]$Seed)
    $attrs = @{}
    foreach ($a in $SchemaAttributes) {
        if (-not $a.required) { continue }
        if ($a.constrained -and @($a.options).Count -gt 0) {
            $attrs[$a.key] = [string]@($a.options)[0]
        } elseif ($a.type -eq 'date') {
            $attrs[$a.key] = '2026-01-01 12:00'
        } elseif ($a.type -eq 'filesize') {
            $attrs[$a.key] = '1024'
        } else {
            $attrs[$a.key] = "smoke-$Seed"
        }
    }
    return $attrs
}

$seed = (Get-Date -Format 'yyyyMMddHHmmssfff') + '-' + [guid]::NewGuid().ToString('N').Substring(0, 6)
$urlCreated = "https://example.com/smoke/$seed.txt"
$urlBatch = "https://example.com/smoke/$seed-b.txt"
$urlPatched = "https://example.com/smoke/$seed-patched.txt"

Write-Host ("== API smoke test @ " + $BaseUrl + " ==") -ForegroundColor Cyan

$schemaAttributes = @()
$requiredAttributes = @()

$r = Invoke-Api -Method GET -Url "$BaseUrl/v1/links" -Token ''
Write-Result '1) 无 token 访问 /v1/links 返回 401' ($r.Status -eq 401) ("status=" + $r.Status)

$r = Invoke-Api -Method GET -Url "$BaseUrl/v1/links" -Token 'dlm_invalid_token_000000'
Write-Result '2) 错误 token 返回 401' ($r.Status -eq 401) ("status=" + $r.Status)

$r = Invoke-Api -Method GET -Url "$BaseUrl/v1/schema" -Token $Token
$schema = Convert-JsonSafe $r.Body
$schemaOk = ($r.Status -eq 200) -and ($null -ne $schema) -and ($schema.code -eq 0) -and ($null -ne $schema.data.repository)
Write-Result '3) 正确 token GET /v1/schema 成功 (code=0)' $schemaOk ("status=" + $r.Status)
if ($schemaOk) {
    $schemaAttributes = @($schema.data.attributes)
    $requiredAttributes = @($schemaAttributes | Where-Object { $_.required })
}
$attrs = New-Attributes $schemaAttributes $seed

$r = Invoke-Api -Method POST -Url "$BaseUrl/v1/links" -Token $Token -Body @{ url = $urlCreated; attributes = $attrs }
$created = Convert-JsonSafe $r.Body
$createdId = $null
$createOk = ($r.Status -eq 201) -and ($null -ne $created) -and ($created.code -eq 0)
if ($createOk) {
    $createdId = $created.data.id
    $createOk = ($created.data.url -eq $urlCreated) -and ($createdId -gt 0)
}
Write-Result '4) POST 新增单条链接成功 (201)' $createOk ("status=" + $r.Status)

$r = Invoke-Api -Method POST -Url "$BaseUrl/v1/links" -Token $Token -Body @{ url = $urlCreated; attributes = $attrs }
$dup = Convert-JsonSafe $r.Body
$dupOk = ($r.Status -eq 422) -and ($null -ne $dup) -and ($dup.code -eq 4220) -and ([string]$dup.message).Contains('已存在')
Write-Result '5) 重复 POST 同 URL 返回校验错误 (422)' $dupOk ("status=" + $r.Status + " msg=" + $(if ($dup) { $dup.message } else { '' }))

$batchBody = @(
    @{ url = $urlBatch; attributes = $attrs },
    @{ url = 'not-a-valid-url'; attributes = @{} }
)
$r = Invoke-Api -Method POST -Url "$BaseUrl/v1/links" -Token $Token -Body $batchBody
$batch = Convert-JsonSafe $r.Body
$batchOk = ($r.Status -eq 200) -and ($null -ne $batch) -and ($batch.code -eq 0) -and
    ($batch.data.created -eq 1) -and ($batch.data.failed -eq 1) -and
    (@($batch.data.results).Count -eq 2) -and
    ($batch.data.results[0].ok -eq $true) -and ($batch.data.results[0].id -gt 0) -and
    ($batch.data.results[1].ok -eq $false) -and (@($batch.data.results[1].errors).Count -ge 1)
Write-Result '6) 批量 POST（1 合法 + 1 非法）返回逐条明细' $batchOk ("status=" + $r.Status)
$batchCreatedId = $null
if ($batchOk) { $batchCreatedId = $batch.data.results[0].id }

$filterParam = ''
$filterAttr = @($requiredAttributes | Select-Object -First 1)
if ($filterAttr.Count -gt 0) {
    $fa = $filterAttr[0]
    $k = $fa.key
    if ($fa.constrained -and @($fa.options).Count -gt 0) {
        $filterParam = "filter[$k]=" + [System.Uri]::EscapeDataString([string]@($fa.options)[0])
    } elseif ($fa.type -eq 'date') {
        $filterParam = "filter[" + $k + "_gte]=" + [System.Uri]::EscapeDataString('1970-01-01')
    } elseif ($fa.type -eq 'filesize') {
        $filterParam = "filter[" + $k + "_gte]=" + [System.Uri]::EscapeDataString('0')
    } else {
        $filterParam = "filter[$k]=" + [System.Uri]::EscapeDataString([string]$attrs[$k])
    }
}
$listParts = @('page=1', 'page_size=5', 'sort=-created_at', 'q=' + [System.Uri]::EscapeDataString($seed))
if ($filterParam -ne '') { $listParts += $filterParam }
$listUrl = "$BaseUrl/v1/links?" + ($listParts -join '&')

$r = Invoke-Api -Method GET -Url $listUrl -Token $Token
$list = Convert-JsonSafe $r.Body
$listOk = ($r.Status -eq 200) -and ($null -ne $list) -and ($list.code -eq 0) -and
    ($list.data.page -eq 1) -and ($list.data.page_size -eq 5) -and
    ($list.data.total -ge 1) -and (@($list.data.items).Count -le 5) -and
    (@($list.data.items | Where-Object { $_.url -like "*$seed*" }).Count -ge 1)
Write-Result '7) GET /v1/links 带 filter/q/分页' $listOk ("status=" + $r.Status + " filter=" + $filterParam)

$patchFailed = $false
$patchDetail = ''
if ($null -eq $createdId) {
    $patchFailed = $true
    $patchDetail = 'no created id from step 4'
} else {
    $r = Invoke-Api -Method PATCH -Url "$BaseUrl/v1/links/$createdId" -Token $Token -Body @{ url = $urlPatched }
    $patched = Convert-JsonSafe $r.Body
    $patchOk = ($r.Status -eq 200) -and ($null -ne $patched) -and ($patched.code -eq 0) -and ($patched.data.url -eq $urlPatched)
    if (-not $patchOk) {
        $patchFailed = $true
        $patchDetail = "patch status=" + $r.Status
    } else {
        $g = Invoke-Api -Method GET -Url "$BaseUrl/v1/links/$createdId" -Token $Token
        $got = Convert-JsonSafe $g.Body
        $verifyOk = ($g.Status -eq 200) -and ($null -ne $got) -and ($got.data.url -eq $urlPatched)
        if (-not $verifyOk) {
            $patchFailed = $true
            $patchDetail = "verify status=" + $g.Status
        }
    }
}
Write-Result '8) PATCH 修改并校验' (-not $patchFailed) $patchDetail

$deleteOk = $false
$deleteDetail = 'no created id from step 4'
if ($null -ne $createdId) {
    $r = Invoke-Api -Method DELETE -Url "$BaseUrl/v1/links/$createdId" -Token $Token
    $del = Convert-JsonSafe $r.Body
    $firstOk = ($r.Status -eq 200) -and ($null -ne $del) -and ($del.code -eq 0)
    $g = Invoke-Api -Method GET -Url "$BaseUrl/v1/links/$createdId" -Token $Token
    $deleteOk = $firstOk -and ($g.Status -eq 404)
    $deleteDetail = "delete status=" + $r.Status + " get-after=" + $g.Status
}
Write-Result '9) DELETE 删除成功且后续 GET 返回 404' $deleteOk $deleteDetail

if ($batchCreatedId -ne $null) {
    Invoke-Api -Method DELETE -Url "$BaseUrl/v1/links/$batchCreatedId" -Token $Token | Out-Null
}

if ($ReadOnlyToken -ne '') {
    $r = Invoke-Api -Method POST -Url "$BaseUrl/v1/links" -Token $ReadOnlyToken -Body @{ url = "https://example.com/smoke/$seed-ro.txt"; attributes = $attrs }
    $ro = Convert-JsonSafe $r.Body
    $roOk = ($r.Status -eq 403) -and ($null -ne $ro) -and ($ro.code -eq 4030)
    Write-Result '10) 只读 KEY 写操作返回 403' $roOk ("status=" + $r.Status)
} else {
    Write-Host 'SKIP  10) 只读 KEY 写操作 403（未提供 -ReadOnlyToken）' -ForegroundColor Yellow
}

Write-Host ''
Write-Host ("总结：PASS=" + $script:Pass + "  FAIL=" + $script:Fail) -ForegroundColor Cyan
if ($script:Fail -gt 0) { exit 1 }
exit 0
