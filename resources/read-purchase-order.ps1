param(
    [Parameter(Mandatory = $true)][string]$ScanPath,
    [Parameter(Mandatory = $true)][ValidateSet('image', 'pdf')][string]$Format
)

$ErrorActionPreference = 'Stop'
$OutputEncoding = [Console]::OutputEncoding = [System.Text.UTF8Encoding]::new($false)
Add-Type -AssemblyName System.Runtime.WindowsRuntime

$storageFileType = [Windows.Storage.StorageFile, Windows.Storage, ContentType=WindowsRuntime]
$streamType = [Windows.Storage.Streams.IRandomAccessStream, Windows.Storage.Streams, ContentType=WindowsRuntime]
$memoryStreamType = [Windows.Storage.Streams.InMemoryRandomAccessStream, Windows.Storage.Streams, ContentType=WindowsRuntime]
$decoderType = [Windows.Graphics.Imaging.BitmapDecoder, Windows.Graphics.Imaging, ContentType=WindowsRuntime]
$bitmapType = [Windows.Graphics.Imaging.SoftwareBitmap, Windows.Graphics.Imaging, ContentType=WindowsRuntime]
$transformType = [Windows.Graphics.Imaging.BitmapTransform, Windows.Graphics.Imaging, ContentType=WindowsRuntime]
$ocrType = [Windows.Media.Ocr.OcrEngine, Windows.Foundation, ContentType=WindowsRuntime]
$ocrResultType = [Windows.Media.Ocr.OcrResult, Windows.Foundation, ContentType=WindowsRuntime]
$languageType = [Windows.Globalization.Language, Windows.Globalization, ContentType=WindowsRuntime]
$pdfType = [Windows.Data.Pdf.PdfDocument, Windows.Data.Pdf, ContentType=WindowsRuntime]
$pdfOptionsType = [Windows.Data.Pdf.PdfPageRenderOptions, Windows.Data.Pdf, ContentType=WindowsRuntime]
[void][Windows.Storage.FileAccessMode, Windows.Storage, ContentType=WindowsRuntime]
[void][Windows.Graphics.Imaging.BitmapPixelFormat, Windows.Graphics.Imaging, ContentType=WindowsRuntime]
[void][Windows.Graphics.Imaging.BitmapAlphaMode, Windows.Graphics.Imaging, ContentType=WindowsRuntime]
[void][Windows.Graphics.Imaging.ExifOrientationMode, Windows.Graphics.Imaging, ContentType=WindowsRuntime]
[void][Windows.Graphics.Imaging.ColorManagementMode, Windows.Graphics.Imaging, ContentType=WindowsRuntime]

$asTask = [System.WindowsRuntimeSystemExtensions].GetMethods() | Where-Object {
    $_.Name -eq 'AsTask' -and $_.IsGenericMethodDefinition -and $_.GetParameters().Count -eq 1 -and
    $_.GetParameters()[0].ParameterType.Name -eq 'IAsyncOperation`1'
} | Select-Object -First 1

$actionAsTask = [System.WindowsRuntimeSystemExtensions].GetMethods() | Where-Object {
    $_.Name -eq 'AsTask' -and !$_.IsGenericMethod -and $_.GetParameters().Count -eq 1 -and
    $_.GetParameters()[0].ParameterType.Name -eq 'IAsyncAction'
} | Select-Object -First 1

function Receive-Operation($operation, [type]$resultType) {
    $task = $asTask.MakeGenericMethod($resultType).Invoke($null, @($operation))
    return $task.GetAwaiter().GetResult()
}

function Read-OrderTable($lines) {
    $grandTotal = $lines | Where-Object Text -Match '^Grand\s+Total[.:\s]*$' | Select-Object -First 1
    if ($null -eq $grandTotal) {
        return
    }

    $totalLabel = ($grandTotal.Words | Select-Object -Last 1).BoundingRect
    $amount = $lines | Where-Object {
        if ($_.Text -notmatch '^(?:\p{Sc}|PHP\.?|P)?\s*\d[\d,]*\.\d{2}$') {
            return $false
        }

        $position = ($_.Words | Select-Object -First 1).BoundingRect
        $position.X -ge $totalLabel.Right -and [Math]::Abs($position.Y - $totalLabel.Y) -le $totalLabel.Height
    } | Select-Object -First 2
    if (@($amount).Count -eq 1) {
        "Grand Total: $($amount.Text)"
    }

    $description = $lines | Where-Object Text -Match '^Description[.:\s]*$' | Select-Object -First 1
    if ($null -eq $description) {
        return
    }

    $header = ($description.Words | Select-Object -First 1).BoundingRect
    $headerWords = foreach ($line in $lines) {
        $line.Words | Where-Object { [Math]::Abs($_.BoundingRect.Y - $header.Y) -le $header.Height }
    }
    $unit = $headerWords | Where-Object { $_.Text -eq 'Unit' -and $_.BoundingRect.X -lt $header.X } | Select-Object -Last 1
    $quantity = $headerWords | Where-Object { $_.Text -eq 'Quantity' -and $_.BoundingRect.X -gt $header.Right } | Select-Object -First 1
    if ($null -eq $unit -or $null -eq $quantity -or $header.Bottom -ge $totalLabel.Top) {
        return
    }

    $descriptionLines = foreach ($line in $lines) {
        $words = @($line.Words | Where-Object {
            $position = $_.BoundingRect
            $position.X -ge $unit.BoundingRect.Right -and $position.X -lt $quantity.BoundingRect.X -and
                $position.Top -gt $header.Bottom -and $position.Bottom -lt $totalLabel.Top
        })
        if ($words.Count -gt 0) {
            [pscustomobject]@{ Top = $words[0].BoundingRect.Top; Text = ($words.Text -join ' ') }
        }
    }
    if ($descriptionLines) {
        "Description: $(($descriptionLines | Sort-Object Top | ForEach-Object Text) -join ' ')"
    }
}

function Read-ScanText($stream) {
    $decoder = Receive-Operation ($decoderType::CreateAsync($stream)) $decoderType
    $transform = $transformType::new()
    $longest = [Math]::Max($decoder.PixelWidth, $decoder.PixelHeight)
    if ($longest -gt 3200) {
        $transform.ScaledWidth = [uint32][Math]::Max(1, [Math]::Round($decoder.PixelWidth * 3200 / $longest))
        $transform.ScaledHeight = [uint32][Math]::Max(1, [Math]::Round($decoder.PixelHeight * 3200 / $longest))
    }

    $bitmap = Receive-Operation ($decoder.GetSoftwareBitmapAsync(
        [Windows.Graphics.Imaging.BitmapPixelFormat]::Bgra8,
        [Windows.Graphics.Imaging.BitmapAlphaMode]::Premultiplied,
        $transform,
        [Windows.Graphics.Imaging.ExifOrientationMode]::RespectExifOrientation,
        [Windows.Graphics.Imaging.ColorManagementMode]::DoNotColorManage
    )) $bitmapType

    try {
        $result = Receive-Operation ($engine.RecognizeAsync($bitmap)) $ocrResultType
        Read-OrderTable $result.Lines
        return ($result.Lines | ForEach-Object { $_.Text }) -join "`n"
    } finally {
        $bitmap.Dispose()
    }
}

$engine = $ocrType::TryCreateFromLanguage($languageType::new('en-US'))
if ($null -eq $engine) {
    throw 'Windows English OCR is unavailable.'
}

$file = Receive-Operation ($storageFileType::GetFileFromPathAsync($ScanPath)) $storageFileType
if ($Format -eq 'image') {
    $stream = Receive-Operation ($file.OpenAsync([Windows.Storage.FileAccessMode]::Read)) $streamType
    try {
        Read-ScanText $stream
    } finally {
        $stream.Dispose()
    }
    exit
}

$pdf = Receive-Operation ($pdfType::LoadFromFileAsync($file)) $pdfType
if ($pdf.PageCount -gt 8) {
    throw 'The PDF exceeds the eight-page limit.'
}

for ($index = 0; $index -lt $pdf.PageCount; $index++) {
    $page = $pdf.GetPage($index)
    $stream = $memoryStreamType::new()
    try {
        $options = $pdfOptionsType::new()
        $options.DestinationWidth = [uint32][Math]::Max(1, [Math]::Round(2600 * $page.Size.Width / [Math]::Max($page.Size.Width, $page.Size.Height)))
        $render = $actionAsTask.Invoke($null, @($page.RenderToStreamAsync($stream, $options)))
        [void]$render.GetAwaiter().GetResult()
        $stream.Seek(0)
        Read-ScanText $stream
    } finally {
        $stream.Dispose()
        $page.Dispose()
    }
}
