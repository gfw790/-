@extends('layouts.app')
@section('title', '대시보드')
@section('page-title', '대시보드')
@section('content')
    <div class="stats-grid">
        <div class="stat-card"><div class="stat-icon blue"><i class="fas fa-users"></i></div><div class="stat-info"><div class="stat-value">{{ $stats['total'] }}</div><div class="stat-label">전체 직원</div></div></div>
        <div class="stat-card"><div class="stat-icon green"><i class="fas fa-user-check"></i></div><div class="stat-info"><div class="stat-value">{{ $stats['active'] }}</div><div class="stat-label">재직 중</div></div></div>
        <div class="stat-card"><div class="stat-icon yellow"><i class="fas fa-user-clock"></i></div><div class="stat-info"><div class="stat-value">{{ $stats['on_leave'] }}</div><div class="stat-label">휴직 중</div></div></div>
        <div class="stat-card"><div class="stat-icon red"><i class="fas fa-user-minus"></i></div><div class="stat-info"><div class="stat-value">{{ $stats['resigned'] }}</div><div class="stat-label">퇴직</div></div></div>
        <div class="stat-card"><div class="stat-icon purple"><i class="fas fa-sitemap"></i></div><div class="stat-info"><div class="stat-value">{{ $stats['dept_count'] }}</div><div class="stat-label">활성 부서</div></div></div>
    </div>
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:24px;">
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-chart-bar"></i> 부서별 재직 인원</h3></div>
            <div class="table-wrap"><table><thead><tr><th>부서</th><th style="text-align:right">인원</th><th style="width:50%">비율</th></tr></thead><tbody>
                @foreach($byDepartment as $dept)
                    <tr>
                        <td>{{ $dept->name }}</td>
                        <td style="text-align:right; font-weight:600;">{{ $dept->employees_count }}명</td>
                        <td>@php $pct = $stats['active'] > 0 ? round($dept->employees_count / $stats['active'] * 100) : 0; @endphp
                            <div style="background:#e2e8f0; border-radius:4px; height:20px; overflow:hidden;"><div style="background:var(--primary); height:100%; width:{{ $pct }}%; border-radius:4px;"></div></div>
                        </td>
                    </tr>
                @endforeach
            </tbody></table></div>
        </div>
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-user-plus"></i> 최근 등록 직원</h3><a href="{{ route('employees.index') }}" class="btn btn-secondary btn-sm">전체보기</a></div>
            <div class="table-wrap"><table><thead><tr><th>사번</th><th>이름</th><th>부서</th><th>입사일</th></tr></thead><tbody>
                @forelse($recentEmployees as $emp)
                    <tr>
                        <td class="text-light">{{ $emp->employee_number }}</td>
                        <td><a href="{{ route('employees.show', $emp) }}" style="color:var(--primary); text-decoration:none; font-weight:500;">{{ $emp->name }}</a></td>
                        <td>{{ $emp->department?->name ?? '-' }}</td>
                        <td class="text-light">{{ $emp->hire_date->format('Y-m-d') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-center text-light">등록된 직원이 없습니다.</td></tr>
                @endforelse
            </tbody></table></div>
        </div>
    </div>
@endsection
