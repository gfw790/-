$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$templateDir = Join-Path $projectRoot 'templates'
$templatePath = Join-Path $templateDir 'unit_ra_excel_template_v3.xlsm'
$tempTemplatePath = Join-Path $templateDir 'unit_ra_excel_template_v3.tmp.xlsm'

if (-not (Test-Path $templateDir)) {
    New-Item -ItemType Directory -Path $templateDir | Out-Null
}

$macroCode = @'
Option Explicit

Private Function GetMainWorksheet() As Worksheet
    On Error Resume Next
    Set GetMainWorksheet = ThisWorkbook.Worksheets("RiskAssessment")
    If GetMainWorksheet Is Nothing Then
        Dim ws As Worksheet
        For Each ws In ThisWorkbook.Worksheets
            If ws.Visible = xlSheetVisible And ws.Name <> "__config" Then
                Set GetMainWorksheet = ws
                Exit Function
            End If
        Next ws
    End If
    On Error GoTo 0
End Function

Public Sub BindUploadButton()
    On Error Resume Next
    Dim ws As Worksheet
    Set ws = GetMainWorksheet()
    If ws Is Nothing Then Exit Sub
    Dim buttonShape As Shape
    Set buttonShape = ws.Shapes("btnUploadRiskAssessment")
    If buttonShape Is Nothing Then
        RefreshUploadButton
    Else
        buttonShape.OnAction = "UploadRiskAssessment"
    End If
    On Error GoTo 0
End Sub

Public Sub RefreshUploadButton()
    On Error Resume Next
    Dim ws As Worksheet
    Set ws = GetMainWorksheet()
    If ws Is Nothing Then Exit Sub

    ws.Shapes("btnUploadRiskAssessment").Delete
    Err.Clear

    Dim buttonShape As Shape
    Set buttonShape = ws.Shapes.AddShape(1, 12, 10, 120, 32)
    buttonShape.Name = "btnUploadRiskAssessment"
    buttonShape.TextFrame.Characters.Text = "UPLOAD"
    buttonShape.TextFrame.HorizontalAlignment = -4108
    buttonShape.TextFrame.VerticalAlignment = -4108
    buttonShape.Fill.ForeColor.RGB = RGB(230, 126, 34)
    buttonShape.Line.ForeColor.RGB = RGB(160, 64, 0)
    buttonShape.OnAction = "UploadRiskAssessment"
    On Error GoTo 0
End Sub

Public Sub Auto_Open()
    RefreshUploadButton
End Sub

Public Sub UploadRiskAssessment()
    On Error GoTo ErrorHandler

    Dim uploadUrl As String
    Dim boundary As String
    Dim tempFilePath As String
    Dim fileBytes() As Byte
    Dim headerText As String
    Dim footerText As String
    Dim requestBody() As Byte
    Dim http As Object
    Dim responseText As String

    uploadUrl = Trim$(ThisWorkbook.Worksheets("__config").Range("A1").Value)
    If uploadUrl = "" Then
        MsgBox "Upload URL is missing.", vbExclamation
        Exit Sub
    End If

    If ThisWorkbook.Path = "" Then
        MsgBox "Save the workbook before upload.", vbExclamation
        Exit Sub
    End If

    Application.StatusBar = "Preparing upload..."
    ThisWorkbook.Save

    boundary = "----RiskAssessment" & Format$(Now, "yyyymmddhhnnss")
    tempFilePath = Environ$("TEMP") & "\" & Format$(Now, "yyyymmddhhnnss") & "_" & Dir$(ThisWorkbook.FullName)
    ThisWorkbook.SaveCopyAs tempFilePath
    fileBytes = ReadBinaryFile(tempFilePath)

    headerText = "--" & boundary & vbCrLf & _
        "Content-Disposition: form-data; name=""excel_file""; filename=""" & Dir$(ThisWorkbook.FullName) & """" & vbCrLf & _
        "Content-Type: application/vnd.ms-excel.sheet.macroEnabled.12" & vbCrLf & vbCrLf

    footerText = vbCrLf & "--" & boundary & "--" & vbCrLf
    requestBody = CombineBytes(StrConv(headerText, vbFromUnicode), fileBytes, StrConv(footerText, vbFromUnicode))

    Application.StatusBar = "Uploading..."

    Set http = CreateObject("WinHttp.WinHttpRequest.5.1")
    http.Open "POST", uploadUrl, False
    http.SetRequestHeader "Content-Type", "multipart/form-data; boundary=" & boundary
    http.Send requestBody

    On Error Resume Next
    If Len(tempFilePath) > 0 Then Kill tempFilePath
    On Error GoTo ErrorHandler

    Application.StatusBar = False
    responseText = CStr(http.ResponseText)

    If InStr(1, responseText, """success"":true", vbTextCompare) > 0 Then
        MsgBox "Upload completed.", vbInformation
    Else
        MsgBox "Upload failed." & vbCrLf & responseText, vbExclamation
    End If
    Exit Sub

ErrorHandler:
    Application.StatusBar = False
    On Error Resume Next
    If Len(tempFilePath) > 0 Then Kill tempFilePath
    On Error GoTo 0
    MsgBox "Upload error." & vbCrLf & Err.Description, vbCritical
End Sub

Private Function ReadBinaryFile(ByVal filePath As String) As Byte()
    Dim stream As Object
    Set stream = CreateObject("ADODB.Stream")
    stream.Type = 1
    stream.Open
    stream.LoadFromFile filePath
    ReadBinaryFile = stream.Read
    stream.Close
End Function

Private Function CombineBytes(ByRef firstBytes() As Byte, ByRef secondBytes() As Byte, ByRef thirdBytes() As Byte) As Byte()
    Dim totalLength As Long
    totalLength = ByteLength(firstBytes) + ByteLength(secondBytes) + ByteLength(thirdBytes)

    Dim result() As Byte
    ReDim result(0 To totalLength - 1) As Byte

    Dim offset As Long
    offset = CopyBytes(result, offset, firstBytes)
    offset = CopyBytes(result, offset, secondBytes)
    offset = CopyBytes(result, offset, thirdBytes)

    CombineBytes = result
End Function

Private Function CopyBytes(ByRef target() As Byte, ByVal startIndex As Long, ByRef source() As Byte) As Long
    Dim i As Long
    For i = LBound(source) To UBound(source)
        target(startIndex) = source(i)
        startIndex = startIndex + 1
    Next i
    CopyBytes = startIndex
End Function

Private Function ByteLength(ByRef source() As Byte) As Long
    On Error GoTo EmptyArray
    ByteLength = UBound(source) - LBound(source) + 1
    Exit Function
EmptyArray:
    ByteLength = 0
End Function
'@

$workbookCode = @'
Option Explicit

Private Sub Workbook_Open()
    RefreshUploadButton
End Sub

Private Sub Workbook_Activate()
    BindUploadButton
End Sub

Private Sub Workbook_SheetActivate(ByVal Sh As Object)
    BindUploadButton
End Sub

Private Sub Workbook_SheetSelectionChange(ByVal Sh As Object, ByVal Target As Object)
    BindUploadButton
End Sub
'@

$excel = $null
$workbook = $null
$mainSheet = $null
$configSheet = $null
$module = $null
$thisWorkbookModule = $null

try {
    $excel = New-Object -ComObject Excel.Application
    $excel.Visible = $false
    $excel.DisplayAlerts = $false

    $workbook = $excel.Workbooks.Add()
    $mainSheet = $workbook.Worksheets.Item(1)
    $mainSheet.Name = 'RiskAssessment'

    $configSheet = $workbook.Worksheets.Add()
    $configSheet.Name = '__config'
    $configSheet.Range('A1').Value2 = ''
    $configSheet.Visible = 0

    $module = $workbook.VBProject.VBComponents.Add(1)
    $module.Name = 'UploadModule'
    $module.CodeModule.AddFromString($macroCode)

    $thisWorkbookModule = $workbook.VBProject.VBComponents.Item(1)
    $thisWorkbookModule.CodeModule.AddFromString($workbookCode)

    if (Test-Path $tempTemplatePath) {
        Remove-Item -LiteralPath $tempTemplatePath -Force
    }
    $workbook.SaveAs($tempTemplatePath, 52)
    $workbook.Close($false)
    $excel.Quit()

    if (Test-Path $templatePath) {
        Remove-Item -LiteralPath $templatePath -Force -ErrorAction SilentlyContinue
    }
    Move-Item -LiteralPath $tempTemplatePath -Destination $templatePath -Force
} finally {
    if ($thisWorkbookModule) { [void][System.Runtime.InteropServices.Marshal]::ReleaseComObject($thisWorkbookModule) }
    if ($module) { [void][System.Runtime.InteropServices.Marshal]::ReleaseComObject($module) }
    if ($configSheet) { [void][System.Runtime.InteropServices.Marshal]::ReleaseComObject($configSheet) }
    if ($mainSheet) { [void][System.Runtime.InteropServices.Marshal]::ReleaseComObject($mainSheet) }
    if ($workbook) { [void][System.Runtime.InteropServices.Marshal]::ReleaseComObject($workbook) }
    if ($excel) { [void][System.Runtime.InteropServices.Marshal]::ReleaseComObject($excel) }
    [GC]::Collect()
    [GC]::WaitForPendingFinalizers()
}
