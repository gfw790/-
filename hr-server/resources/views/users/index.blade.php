@extends('layouts.app')
@section('title', '계정 관리')
@section('page-title', '계정 관리')
@section('top-actions')
    <a href="{{ route('users.create') }}" class="btn btn-primary"><i class="fas fa-plus"></i> 계정 추가</a>
@endsection
@section('content')

@if(session('error'))
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> {{ session('error') }}</div>
@endif

<div class="card">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>아이디</th>
                    <th>이름</th>
                    <th>등록일</th>
                    <th style="width:140px;"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($users as $user)
                <tr>
                    <td>{{ $user->username }}
                        @if($user->id === auth()->id())
                            <span class="badge" style="background:#dbeafe;color:#1d4ed8;margin-left:6px;">나</span>
                        @endif
                    </td>
                    <td>{{ $user->name }}</td>
                    <td>{{ $user->created_at->format('Y-m-d') }}</td>
                    <td>
                        <div class="flex gap-2">
                            <a href="{{ route('users.edit', $user) }}" class="btn btn-secondary btn-sm"><i class="fas fa-edit"></i> 수정</a>
                            @if($user->id !== auth()->id())
                            <form method="POST" action="{{ route('users.destroy', $user) }}"
                                  onsubmit="return confirm('{{ $user->name }} 계정을 삭제할까요?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> 삭제</button>
                            </form>
                            @endif
                        </div>
                    </td>
                </tr>
                @empty
                <tr><td colspan="4" class="text-center text-light" style="padding:32px;">등록된 계정이 없습니다.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
