<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function index()
    {
        $users = User::orderBy('created_at')->get();
        return view('users.index', compact('users'));
    }

    public function create()
    {
        return view('users.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'username' => 'required|unique:users,username|max:50',
            'name'     => 'required|max:50',
            'password' => 'required|min:4|confirmed',
        ], [
            'username.required' => '아이디를 입력하세요.',
            'username.unique'   => '이미 사용 중인 아이디입니다.',
            'name.required'     => '이름을 입력하세요.',
            'password.required' => '비밀번호를 입력하세요.',
            'password.min'      => '비밀번호는 4자 이상이어야 합니다.',
            'password.confirmed'=> '비밀번호 확인이 일치하지 않습니다.',
        ]);

        User::create([
            'username' => $request->username,
            'name'     => $request->name,
            'password' => Hash::make($request->password),
        ]);

        return redirect()->route('users.index')->with('success', '계정이 추가되었습니다.');
    }

    public function edit(User $user)
    {
        return view('users.edit', compact('user'));
    }

    public function update(Request $request, User $user)
    {
        $request->validate([
            'username' => 'required|unique:users,username,' . $user->id . '|max:50',
            'name'     => 'required|max:50',
            'password' => 'nullable|min:4|confirmed',
        ], [
            'username.required' => '아이디를 입력하세요.',
            'username.unique'   => '이미 사용 중인 아이디입니다.',
            'name.required'     => '이름을 입력하세요.',
            'password.min'      => '비밀번호는 4자 이상이어야 합니다.',
            'password.confirmed'=> '비밀번호 확인이 일치하지 않습니다.',
        ]);

        $data = [
            'username' => $request->username,
            'name'     => $request->name,
        ];
        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $user->update($data);

        return redirect()->route('users.index')->with('success', '계정이 수정되었습니다.');
    }

    public function destroy(User $user)
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', '현재 로그인 중인 계정은 삭제할 수 없습니다.');
        }
        $user->delete();
        return redirect()->route('users.index')->with('success', '계정이 삭제되었습니다.');
    }
}
