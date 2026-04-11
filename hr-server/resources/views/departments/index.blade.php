@extends('layouts.app')
@section('title', '부서 관리')
@section('page-title', '부서 관리')
@section('top-actions')
    <a href="{{ route('departments.create') }}" class="btn btn-primary"><i class="fas fa-plus"></i> 부서 추가</a>
@endsection
@section('content')
    <div class="card"><div class="table-wrap"><table><thead><tr>
        <th>부서코드</th><th>부서명</th><th>상위 부서</th><th>재직 인원</th><th>정렬순서</th><th>상태</th><th style="width:120px">관리</th>
    </tr></thead><tbody>
        @forelse($departments as $dept)
            <tr>
                <td><code style="background:#f1f5f9; padding:2px 8px; border-radius:4px; font-size:13px;">{{ $dept->code }}</code></td>
                <td style="font-weight:500;">{{ $dept->name }}</td>
                <td class="text-light">{{ $dept->parent?->name ?? '-' }}</td>
                <td>{{ $dept->employees_count }}명</td>
                <td class="text-light">{{ $dept->sort_order }}</td>
                <td><span class="badge {{ $dept->is_active ? 'badge-active' : 'badge-resign' }}">{{ $dept->is_active ? '활성' : '비활성' }}</span></td>
                <td><div class="flex gap-2">
                    <a href="{{ route('departments.edit', $dept) }}" class="btn btn-secondary btn-sm"><i class="fas fa-edit"></i></a>
                    <form action="{{ route('departments.destroy', $dept) }}" method="POST" onsubmit="return confirm('{{ $dept->name }} 부서를 비활성화하시겠습니까?')">@csrf @method('DELETE')
                        <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-ban"></i></button>
                    </form>
                </div></td>
            </tr>
        @empty
            <tr><td colspan="7" class="empty-state"><i class="fas fa-sitemap"></i>등록된 부서가 없습니다.</td></tr>
        @endforelse
    </tbody></table></div></div>
@endsection
