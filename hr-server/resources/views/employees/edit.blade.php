@extends('layouts.app')
@section('title', '직원 수정')
@section('page-title', '직원 수정 — ' . $employee->name)
@section('content')
    <div class="card" style="max-width:720px;">
        <form action="{{ route('employees.update', $employee) }}" method="POST">@csrf @method('PUT')
            <h4 style="font-size:14px; font-weight:600; color:var(--text-light); margin-bottom:16px;"><i class="fas fa-id-card"></i> 기본 정보</h4>
            <div class="form-row">
                <div class="form-group"><label>사번</label><input type="text" class="form-control" value="{{ $employee->employee_number }}" disabled style="background:#f1f5f9; color:var(--text-light);"></div>
                <div class="form-group"><label>이름 <span class="required">*</span></label><input type="text" name="name" class="form-control" value="{{ old('name', $employee->name) }}" required></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>이메일</label><input type="email" name="email" class="form-control" value="{{ old('email', $employee->email) }}">@error('email')<div class="form-error">{{ $message }}</div>@enderror</div>
                <div class="form-group"><label>연락처 <span class="required">*</span></label><input type="text" name="phone" class="form-control" value="{{ old('phone', $employee->phone) }}" required>@error('phone')<div class="form-error">{{ $message }}</div>@enderror</div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>생년월일</label><input type="date" name="birth_date" class="form-control" value="{{ old('birth_date', $employee->birth_date?->format('Y-m-d')) }}"></div>
                <div class="form-group"><label>주소</label><input type="text" name="address" class="form-control" value="{{ old('address', $employee->address) }}"></div>
            </div>
            <hr style="border:none; border-top:1px solid var(--border); margin:24px 0;">
            <h4 style="font-size:14px; font-weight:600; color:var(--text-light); margin-bottom:16px;"><i class="fas fa-briefcase"></i> 근무 정보</h4>
            <div class="form-row">
                <div class="form-group"><label>소속 부서</label><select name="department_id" class="form-control"><option value="">선택하세요</option>@foreach($departments as $dept)<option value="{{ $dept->id }}" {{ old('department_id', $employee->department_id) == $dept->id ? 'selected' : '' }}>{{ $dept->name }}</option>@endforeach</select></div>
                <div class="form-group"><label>직급 <span class="required">*</span></label><select name="job_title" class="form-control" required>@foreach(['대표','이사','부장','차장','과장','대리','주임','사원'] as $pos)<option value="{{ $pos }}" {{ old('job_title', $employee->job_title) === $pos ? 'selected' : '' }}>{{ $pos }}</option>@endforeach</select></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>직책</label><input type="text" name="job_title" class="form-control" value="{{ old('job_title', $employee->job_title) }}"></div>
                <div class="form-group"><label>재직 상태</label><select name="status" class="form-control">@foreach(['재직','휴직','퇴직'] as $s)<option value="{{ $s }}" {{ old('status', $employee->status) === $s ? 'selected' : '' }}>{{ $s }}</option>@endforeach</select></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>입사일 <span class="required">*</span></label><input type="date" name="hire_date" class="form-control" value="{{ old('hire_date', $employee->hire_date->format('Y-m-d')) }}" required></div>
                <div class="form-group"><label>퇴사일</label><input type="date" name="resign_date" class="form-control" value="{{ old('resign_date', $employee->resign_date?->format('Y-m-d')) }}"></div>
            </div>
            <div class="form-group"><label>비고</label><textarea name="notes" class="form-control">{{ old('notes', $employee->notes) }}</textarea></div>
            <hr style="border:none; border-top:1px solid var(--border); margin:24px 0;">
            <h4 style="font-size:14px; font-weight:600; color:var(--text-light); margin-bottom:16px;"><i class="fas fa-tshirt"></i> 신체 정보</h4>
            <div class="form-row">
                <div class="form-group"><label>혈액형</label><select name="blood_type" class="form-control"><option value="">선택하세요</option>@foreach(['A','B','O','AB'] as $bt)<option value="{{ $bt }}" {{ old('blood_type', $employee->blood_type) === $bt ? 'selected' : '' }}>{{ $bt }}형</option>@endforeach</select></div>
                <div class="form-group"><label>신발 사이즈</label><input type="text" name="shoe_size" class="form-control" value="{{ old('shoe_size', $employee->shoe_size) }}" placeholder="예: 270"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>상의 사이즈</label><input type="text" name="top_size" class="form-control" value="{{ old('top_size', $employee->top_size) }}" placeholder="예: 105, XL"></div>
                <div class="form-group"><label>하의 사이즈</label><input type="text" name="bottom_size" class="form-control" value="{{ old('bottom_size', $employee->bottom_size) }}" placeholder="예: 32, L"></div>
            </div>
            <div class="flex gap-2"><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> 저장</button><a href="{{ route('employees.show', $employee) }}" class="btn btn-secondary">취소</a></div>
        </form>
    </div>
@endsection
