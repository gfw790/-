@extends('layouts.app')
@section('title', $employee->name . ' - 직원 상세')
@section('page-title', '직원 상세')
@section('top-actions')
    <div class="flex gap-2">
        <a href="{{ route('employees.edit', $employee) }}" class="btn btn-primary btn-sm"><i class="fas fa-edit"></i> 수정</a>
        <a href="{{ route('employees.index') }}" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> 목록</a>
    </div>
@endsection
@section('content')
    <div class="card" style="max-width:800px;">
        <div style="display:flex; align-items:center; gap:20px; margin-bottom:24px; padding-bottom:20px; border-bottom:1px solid var(--border);">
            <div style="width:64px; height:64px; border-radius:50%; background:var(--primary-light); display:flex; align-items:center; justify-content:center; font-size:24px; font-weight:700; color:var(--primary);">{{ mb_substr($employee->name, 0, 1) }}</div>
            <div>
                <h3 style="font-size:20px; font-weight:600;">{{ $employee->name }}</h3>
                <div class="text-light" style="margin-top:4px;">{{ $employee->department?->name ?? '미배정' }} · {{ $employee->job_title }}@if($employee->job_title) · {{ $employee->job_title }}@endif</div>
            </div>
            <div style="margin-left:auto;">
                @php $bc = match($employee->status) { '재직'=>'badge-active', '휴직'=>'badge-leave', '퇴직'=>'badge-resign', default=>'' }; @endphp
                <span class="badge {{ $bc }}" style="font-size:14px; padding:6px 14px;">{{ $employee->status }}</span>
            </div>
        </div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">
            <div>
                <h4 style="font-size:14px; font-weight:600; color:var(--text-light); margin-bottom:12px;">기본 정보</h4>
                <table style="font-size:14px;">
                    <tr><td style="padding:6px 16px 6px 0; color:var(--text-light); width:80px;">사번</td><td style="padding:6px 0; font-weight:500;">{{ $employee->employee_number }}</td></tr>
                    <tr><td style="padding:6px 16px 6px 0; color:var(--text-light);">이메일</td><td style="padding:6px 0;">{{ $employee->email }}</td></tr>
                    <tr><td style="padding:6px 16px 6px 0; color:var(--text-light);">연락처</td><td style="padding:6px 0;">{{ $employee->phone ?? '-' }}</td></tr>
                    <tr><td style="padding:6px 16px 6px 0; color:var(--text-light);">생년월일</td><td style="padding:6px 0;">{{ $employee->birth_date?->format('Y-m-d') ?? '-' }}</td></tr>
                    <tr><td style="padding:6px 16px 6px 0; color:var(--text-light);">주소</td><td style="padding:6px 0;">{{ $employee->address ?? '-' }}</td></tr>
                </table>
            </div>
            <div>
                <h4 style="font-size:14px; font-weight:600; color:var(--text-light); margin-bottom:12px;">근무 정보</h4>
                <table style="font-size:14px;">
                    <tr><td style="padding:6px 16px 6px 0; color:var(--text-light); width:80px;">부서</td><td style="padding:6px 0; font-weight:500;">{{ $employee->department?->name ?? '미배정' }}</td></tr>
                    <tr><td style="padding:6px 16px 6px 0; color:var(--text-light);">직급</td><td style="padding:6px 0;">{{ $employee->job_title }}</td></tr>
                    <tr><td style="padding:6px 16px 6px 0; color:var(--text-light);">직책</td><td style="padding:6px 0;">{{ $employee->job_title ?? '-' }}</td></tr>
                    <tr><td style="padding:6px 16px 6px 0; color:var(--text-light);">입사일</td><td style="padding:6px 0;">{{ $employee->hire_date->format('Y-m-d') }}</td></tr>
                    <tr><td style="padding:6px 16px 6px 0; color:var(--text-light);">퇴사일</td><td style="padding:6px 0;">{{ $employee->resign_date?->format('Y-m-d') ?? '-' }}</td></tr>
                </table>
            </div>
        </div>
        @if($employee->blood_type || $employee->shoe_size || $employee->top_size || $employee->bottom_size)
            <div style="margin-top:24px; padding-top:20px; border-top:1px solid var(--border);">
                <h4 style="font-size:14px; font-weight:600; color:var(--text-light); margin-bottom:12px;">신체 정보</h4>
                <table style="font-size:14px;">
                    <tr><td style="padding:6px 16px 6px 0; color:var(--text-light); width:80px;">혈액형</td><td style="padding:6px 0;">{{ $employee->blood_type ? $employee->blood_type.'형' : '-' }}</td></tr>
                    <tr><td style="padding:6px 16px 6px 0; color:var(--text-light);">신발</td><td style="padding:6px 0;">{{ $employee->shoe_size ?? '-' }}</td></tr>
                    <tr><td style="padding:6px 16px 6px 0; color:var(--text-light);">상의</td><td style="padding:6px 0;">{{ $employee->top_size ?? '-' }}</td></tr>
                    <tr><td style="padding:6px 16px 6px 0; color:var(--text-light);">하의</td><td style="padding:6px 0;">{{ $employee->bottom_size ?? '-' }}</td></tr>
                </table>
            </div>
        @endif
        @if($employee->notes)
            <div style="margin-top:24px; padding-top:20px; border-top:1px solid var(--border);">
                <h4 style="font-size:14px; font-weight:600; color:var(--text-light); margin-bottom:8px;">비고</h4>
                <p style="font-size:14px; line-height:1.7; white-space:pre-line;">{{ $employee->notes }}</p>
            </div>
        @endif
    </div>

    {{-- 입사 서류 --}}
    @php $uploadedDocs = $employee->documents->keyBy('document_type'); @endphp
    <div class="card" style="max-width:800px; margin-top:24px;">
        <h4 style="font-size:14px; font-weight:600; color:var(--text-light); margin-bottom:16px;"><i class="fas fa-folder-open"></i> 입사 서류</h4>

        @if(session('success'))
            <div style="background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; padding:10px 14px; border-radius:6px; margin-bottom:16px; font-size:14px;">
                {{ session('success') }}
            </div>
        @endif

        <table style="width:100%; font-size:14px; border-collapse:collapse;">
            <thead>
                <tr style="border-bottom:2px solid var(--border);">
                    <th style="padding:10px 12px; text-align:left; color:var(--text-light); font-weight:600;">서류명</th>
                    <th style="padding:10px 12px; text-align:center; width:80px; color:var(--text-light); font-weight:600;">상태</th>
                    <th style="padding:10px 12px; text-align:left; color:var(--text-light); font-weight:600;">파일명</th>
                    <th style="padding:10px 12px; text-align:left; color:var(--text-light); font-weight:600;">등록일</th>
                    <th style="padding:10px 12px; text-align:center; width:160px; color:var(--text-light); font-weight:600;">관리</th>
                </tr>
            </thead>
            <tbody>
                @foreach(\App\Models\EmployeeDocument::$types as $type)
                    @php $doc = $uploadedDocs->get($type); @endphp
                    <tr style="border-bottom:1px solid var(--border);">
                        <td style="padding:12px;">{{ $type }}</td>
                        <td style="padding:12px; text-align:center;">
                            @if($doc)
                                <span style="color:#16a34a; font-size:16px;">✅</span>
                            @else
                                <span style="color:#9ca3af; font-size:16px;">⬜</span>
                            @endif
                        </td>
                        <td style="padding:12px; color:var(--text-light); font-size:13px;">
                            {{ $doc?->original_name ?? '-' }}
                        </td>
                        <td style="padding:12px; color:var(--text-light); font-size:13px;">
                            {{ $doc?->created_at->format('Y-m-d') ?? '-' }}
                        </td>
                        <td style="padding:12px; text-align:center;">
                            <div class="flex gap-2" style="justify-content:center;">
                                @if($doc)
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="openPreview('{{ route('employee-documents.preview', [$employee, $doc]) }}', '{{ $doc->original_name }}')"><i class="fas fa-eye"></i> 미리보기</button>
                                    <a href="{{ route('employee-documents.download', [$employee, $doc]) }}" class="btn btn-secondary btn-sm"><i class="fas fa-download"></i></a>
                                    <form action="{{ route('employee-documents.destroy', [$employee, $doc]) }}" method="POST" onsubmit="return confirm('삭제하시겠습니까?')">@csrf @method('DELETE')
                                        <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
                                    </form>
                                @else
                                    <form action="{{ route('employee-documents.store', $employee) }}" method="POST" enctype="multipart/form-data" style="display:flex; gap:6px; align-items:center;">
                                        @csrf
                                        <input type="hidden" name="document_type" value="{{ $type }}">
                                        <input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png,.gif,.webp" style="font-size:12px; width:160px;" required>
                                        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-upload"></i></button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div style="margin-top:10px; font-size:12px; color:var(--text-light);">* PDF 또는 이미지 파일 (최대 10MB)</div>
    </div>

{{-- 미리보기 모달 --}}
<div id="previewModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:1000; align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:10px; width:90vw; max-width:900px; height:88vh; display:flex; flex-direction:column; overflow:hidden; box-shadow:0 20px 60px rgba(0,0,0,0.3);">
        <div style="display:flex; align-items:center; justify-content:space-between; padding:14px 20px; border-bottom:1px solid var(--border); flex-shrink:0;">
            <span id="previewTitle" style="font-size:14px; font-weight:600; color:var(--text);"></span>
            <button onclick="closePreview()" style="background:none; border:none; font-size:20px; cursor:pointer; color:var(--text-light); line-height:1;">&times;</button>
        </div>
        <div id="previewBody" style="flex:1; overflow:auto; display:flex; align-items:center; justify-content:center; background:#f8fafc;">
        </div>
    </div>
</div>

<script>
function openPreview(url, filename) {
    document.getElementById('previewTitle').textContent = filename;
    const body = document.getElementById('previewBody');
    const ext = filename.split('.').pop().toLowerCase();
    if (['jpg','jpeg','png','gif','webp'].includes(ext)) {
        body.innerHTML = '<img src="' + url + '" style="max-width:100%; max-height:100%; object-fit:contain; padding:16px;">';
    } else {
        body.innerHTML = '<iframe src="' + url + '" style="width:100%; height:100%; border:none;"></iframe>';
    }
    const modal = document.getElementById('previewModal');
    modal.style.display = 'flex';
}
function closePreview() {
    document.getElementById('previewModal').style.display = 'none';
    document.getElementById('previewBody').innerHTML = '';
}
document.getElementById('previewModal').addEventListener('click', function(e) {
    if (e.target === this) closePreview();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closePreview();
});
</script>
@endsection
