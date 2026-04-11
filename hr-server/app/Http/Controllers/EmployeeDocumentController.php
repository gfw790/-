<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class EmployeeDocumentController extends Controller
{
    public function store(Request $request, Employee $employee)
    {
        $request->validate([
            'document_type' => 'required|in:' . implode(',', EmployeeDocument::$types),
            'file'          => 'required|file|max:10240|mimes:pdf,jpg,jpeg,png,gif,webp',
        ], [
            'file.max'   => '파일 크기는 10MB 이하여야 합니다.',
            'file.mimes' => 'PDF 또는 이미지 파일만 업로드 가능합니다.',
        ]);

        $existing = $employee->documents()->where('document_type', $request->document_type)->first();
        if ($existing) {
            Storage::delete($existing->file_path);
            $existing->delete();
        }

        $path = $request->file('file')->store("employee-documents/{$employee->id}", 'local');

        $employee->documents()->create([
            'document_type' => $request->document_type,
            'file_path'     => $path,
            'original_name' => $request->file('file')->getClientOriginalName(),
        ]);

        return back()->with('success', $request->document_type . ' 서류가 등록되었습니다.');
    }

    public function preview(Employee $employee, EmployeeDocument $document)
    {
        abort_if($document->employee_id !== $employee->id, 404);

        $mime = Storage::disk('local')->mimeType($document->file_path);

        return response(Storage::disk('local')->get($document->file_path), 200)
            ->header('Content-Type', $mime)
            ->header('Content-Disposition', 'inline; filename="' . $document->original_name . '"');
    }

    public function download(Employee $employee, EmployeeDocument $document)
    {
        abort_if($document->employee_id !== $employee->id, 404);

        return Storage::disk('local')->download($document->file_path, $document->original_name);
    }

    public function destroy(Employee $employee, EmployeeDocument $document)
    {
        abort_if($document->employee_id !== $employee->id, 404);

        Storage::delete($document->file_path);
        $document->delete();

        return back()->with('success', $document->document_type . ' 서류가 삭제되었습니다.');
    }
}
