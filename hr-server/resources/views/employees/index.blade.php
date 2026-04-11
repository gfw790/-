@extends('layouts.app')
@section('title', '직원 관리')
@section('page-title', '직원 관리')
@section('top-actions')
    <a href="{{ route('employees.create') }}" class="btn btn-primary"><i class="fas fa-user-plus"></i> 직원 등록</a>
@endsection
@section('content')
    <div class="card">
        <form method="GET" action="{{ route('employees.index') }}" class="filter-bar">
            <input type="text" name="search" class="form-control search-input" placeholder="이름, 사번, 이메일 검색..." value="{{ request('search') }}">
            <select name="department_id" class="form-control"><option value="">전체 부서</option>@foreach($departments as $dept)<option value="{{ $dept->id }}" {{ request('department_id') == $dept->id ? 'selected' : '' }}>{{ $dept->name }}</option>@endforeach</select>
            <select name="status" class="form-control"><option value="">전체 상태</option><option value="재직" {{ request('status') === '재직' ? 'selected' : '' }}>재직</option><option value="휴직" {{ request('status') === '휴직' ? 'selected' : '' }}>휴직</option><option value="퇴직" {{ request('status') === '퇴직' ? 'selected' : '' }}>퇴직</option></select>
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> 검색</button>
            <a href="{{ route('employees.index') }}" class="btn btn-secondary btn-sm">초기화</a>
        </form>
        <div class="table-wrap"><table><thead><tr>
            <th>사번</th><th>이름</th><th>부서</th><th>직급</th><th>직책</th><th>입사일</th><th>상태</th><th style="width:120px">관리</th>
        </tr></thead><tbody>
            @forelse($employees as $emp)
                <tr>
                    <td><code style="background:#f1f5f9; padding:2px 8px; border-radius:4px; font-size:13px;">{{ $emp->employee_number }}</code></td>
                    <td><a href="{{ route('employees.show', $emp) }}" style="color:var(--primary); text-decoration:none; font-weight:500;">{{ $emp->name }}</a></td>
                    <td>{{ $emp->department?->name ?? '-' }}</td>
                    <td>{{ $emp->job_title }}</td>
                    <td class="text-light">{{ $emp->job_title ?? '-' }}</td>
                    <td class="text-light">{{ $emp->hire_date->format('Y-m-d') }}</td>
                    <td>@php $bc = match($emp->status) { '재직'=>'badge-active', '휴직'=>'badge-leave', '퇴직'=>'badge-resign', default=>'' }; @endphp<span class="badge {{ $bc }}">{{ $emp->status }}</span></td>
                    <td><div class="flex gap-2">
                        <a href="{{ route('employees.edit', $emp) }}" class="btn btn-secondary btn-sm"><i class="fas fa-edit"></i></a>
                        <form action="{{ route('employees.destroy', $emp) }}" method="POST" onsubmit="return confirm('{{ $emp->name }} 직원을 삭제하시겠습니까?')">@csrf @method('DELETE')<button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button></form>
                    </div></td>
                </tr>
            @empty
                <tr><td colspan="8" class="empty-state"><i class="fas fa-users"></i>등록된 직원이 없습니다.</td></tr>
            @endforelse
        </tbody></table></div>
        @if($employees->hasPages())<div class="pagination-wrap">{{ $employees->links() }}</div>@endif
        <div class="text-light" style="font-size:13px; padding-top:8px;">총 {{ $employees->total() }}명 중 {{ $employees->firstItem() }}~{{ $employees->lastItem() }}</div>
    </div>
@endsection
