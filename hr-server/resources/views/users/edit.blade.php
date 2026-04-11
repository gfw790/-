@extends('layouts.app')
@section('title', '계정 수정')
@section('page-title', '계정 수정')
@section('content')
<div class="card" style="max-width:480px;">
    <form method="POST" action="{{ route('users.update', $user) }}">
        @csrf @method('PUT')
        <div class="form-group">
            <label>아이디 <span class="required">*</span></label>
            <input type="text" name="username" class="form-control" value="{{ old('username', $user->username) }}">
            @error('username')<div class="form-error">{{ $message }}</div>@enderror
        </div>
        <div class="form-group">
            <label>이름 <span class="required">*</span></label>
            <input type="text" name="name" class="form-control" value="{{ old('name', $user->name) }}">
            @error('name')<div class="form-error">{{ $message }}</div>@enderror
        </div>
        <div class="form-group">
            <label>새 비밀번호 <span class="text-light" style="font-weight:400;">(변경 시에만 입력)</span></label>
            <input type="password" name="password" class="form-control">
            @error('password')<div class="form-error">{{ $message }}</div>@enderror
        </div>
        <div class="form-group">
            <label>새 비밀번호 확인</label>
            <input type="password" name="password_confirmation" class="form-control">
        </div>
        <div class="flex gap-2">
            <button type="submit" class="btn btn-primary">저장</button>
            <a href="{{ route('users.index') }}" class="btn btn-secondary">취소</a>
        </div>
    </form>
</div>
@endsection
