@extends('layouts.app')
@section('title', '부서 추가')
@section('page-title', '부서 추가')
@section('content')
    <div class="card" style="max-width:640px;">
        <form action="{{ route('departments.store') }}" method="POST">@csrf
            <div class="form-row">
                <div class="form-group"><label>부서명 <span class="required">*</span></label><input type="text" name="name" class="form-control" value="{{ old('name') }}" required></div>
                <div class="form-group"><label>부서코드 <span class="required">*</span></label><input type="text" name="code" class="form-control" value="{{ old('code') }}" placeholder="예: DEV, HR" required></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>상위 부서</label><select name="parent_id" class="form-control"><option value="">없음 (최상위)</option>@foreach($parents as $p)<option value="{{ $p->id }}" {{ old('parent_id') == $p->id ? 'selected' : '' }}>{{ $p->name }} ({{ $p->code }})</option>@endforeach</select></div>
                <div class="form-group"><label>정렬 순서</label><input type="number" name="sort_order" class="form-control" value="{{ old('sort_order', 0) }}" min="0"></div>
            </div>
            <div class="form-group"><label>부서 설명</label><textarea name="description" class="form-control">{{ old('description') }}</textarea></div>
            <div class="flex gap-2"><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> 저장</button><a href="{{ route('departments.index') }}" class="btn btn-secondary">취소</a></div>
        </form>
    </div>
@endsection
