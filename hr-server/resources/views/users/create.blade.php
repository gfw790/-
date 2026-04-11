@extends('layouts.app')
@section('title', '계정 추가')
@section('page-title', '계정 추가')
@section('content')
<div class="card" style="max-width:480px;">
    <form method="POST" action="{{ route('users.store') }}">
        @csrf
        <div class="form-group">
            <label>아이디 <span class="required">*</span></label>
            <input type="text" name="username" class="form-control" value="{{ old('username') }}" autofocus>
            @error('username')<div class="form-error">{{ $message }}</div>@enderror
        </div>
        <div class="form-group">
            <label>이름 <span class="required">*</span></label>
            <input type="text" name="name" class="form-control" value="{{ old('name') }}">
            @error('name')<div class="form-error">{{ $message }}</div>@enderror
        </div>
        <div class="form-group">
            <label>비밀번호 <span class="required">*</span></label>
            <input type="password" name="password" class="form-control">
            @error('password')<div class="form-error">{{ $message }}</div>@enderror
        </div>
        <div class="form-group">
            <label>비밀번호 확인 <span class="required">*</span></label>
            <input type="password" name="password_confirmation" class="form-control">
        </div>
        <div class="flex gap-2">
            <button type="submit" class="btn btn-primary">추가</button>
            <a href="{{ route('users.index') }}" class="btn btn-secondary">취소</a>
        </div>
    </form>
</div>
@endsection
