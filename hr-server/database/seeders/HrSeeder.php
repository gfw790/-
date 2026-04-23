<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class HrSeeder extends Seeder
{
    public function run(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('employee_documents')->truncate();
        DB::table('employees')->truncate();
        DB::table('departments')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        // 부서
        $departments = [
            ['id' => 1, 'code' => 'HQ',  'name' => '대표이사실', 'parent_id' => null, 'sort_order' => 1, 'is_active' => true],
            ['id' => 2, 'code' => 'CS',  'name' => '공사팀',     'parent_id' => 1,    'sort_order' => 1, 'is_active' => true],
            ['id' => 3, 'code' => 'GAS', 'name' => 'GAS팀',      'parent_id' => 1,    'sort_order' => 2, 'is_active' => true],
            ['id' => 4, 'code' => 'MFG', 'name' => '제조팀',     'parent_id' => 1,    'sort_order' => 3, 'is_active' => true],
            ['id' => 5, 'code' => 'ADM', 'name' => '경영지원',     'parent_id' => 1,    'sort_order' => 4, 'is_active' => true],
        ];

        foreach ($departments as $dept) {
            DB::table('departments')->insert(array_merge($dept, [
                'created_at' => now(), 'updated_at' => now(),
            ]));
        }

        // 직원 (입사일 미확인분은 추후 수정)
        $employees = [
            // 대표이사실
            ['employee_number' => 'HD-001', 'name' => '정연탁', 'phone' => '010-6374-6680', 'job_title' => '대표이사', 'department_id' => 1],
            ['employee_number' => 'HD-002', 'name' => '정주랑', 'phone' => '010-5360-2316', 'job_title' => '실장',     'department_id' => 5],
            // 공사팀
            ['employee_number' => 'HD-003', 'name' => '진종철', 'phone' => '010-2058-7204', 'job_title' => '부장', 'department_id' => 2],
            ['employee_number' => 'HD-004', 'name' => '윤택천', 'phone' => '010-5366-6209', 'job_title' => '반장', 'department_id' => 2],
            ['employee_number' => 'HD-005', 'name' => '엄기준', 'phone' => '010-7550-1280', 'job_title' => '사원', 'department_id' => 2],
            ['employee_number' => 'HD-006', 'name' => '윤순형', 'phone' => '010-3072-3092', 'job_title' => '사원', 'department_id' => 2],
            ['employee_number' => 'HD-007', 'name' => '이정민', 'phone' => '010-2314-0979', 'job_title' => '사원', 'department_id' => 2],
            ['employee_number' => 'HD-008', 'name' => '한지민', 'phone' => '010-2339-0185', 'job_title' => '사원', 'department_id' => 2],
            ['employee_number' => 'HD-009', 'name' => '이돈희', 'phone' => '010-7384-0639', 'job_title' => '사원', 'department_id' => 2],
            ['employee_number' => 'HD-010', 'name' => '김종훈', 'phone' => '010-2876-1117', 'job_title' => '반장', 'department_id' => 2],
            ['employee_number' => 'HD-011', 'name' => '조한봉', 'phone' => '010-6370-2559', 'job_title' => '사원', 'department_id' => 2],
            // GAS팀
            ['employee_number' => 'HD-012', 'name' => '김도담', 'phone' => '010-4093-9247', 'job_title' => '반장', 'department_id' => 3],
            ['employee_number' => 'HD-013', 'name' => '김동훈', 'phone' => '010-2957-8985', 'job_title' => '사원', 'department_id' => 3],
            ['employee_number' => 'HD-014', 'name' => '장진혁', 'phone' => '010-9277-8466', 'job_title' => '사원', 'department_id' => 3],
            ['employee_number' => 'HD-015', 'name' => '정재호', 'phone' => '010-5189-2313', 'job_title' => '사원', 'department_id' => 3],
            ['employee_number' => 'HD-016', 'name' => '이윤성', 'phone' => '010-3948-4456', 'job_title' => '사원', 'department_id' => 3],
            ['employee_number' => 'HD-017', 'name' => '박재민', 'phone' => '010-7352-4879', 'job_title' => '사원', 'department_id' => 3],
            ['employee_number' => 'HD-018', 'name' => '최정섭', 'phone' => '010-8917-5349', 'job_title' => '사원', 'department_id' => 3],
            // 제조팀
            ['employee_number' => 'HD-019', 'name' => '우홍기', 'phone' => '010-7308-0911', 'job_title' => '부장', 'department_id' => 4],
            ['employee_number' => 'HD-020', 'name' => '김남군', 'phone' => '010-9383-5878', 'job_title' => '과장', 'department_id' => 4],
            ['employee_number' => 'HD-021', 'name' => '정민교', 'phone' => '010-5316-9460', 'job_title' => '사원', 'department_id' => 4],
            ['employee_number' => 'HD-022', 'name' => '김성용', 'phone' => '010-6687-6434', 'job_title' => '사원', 'department_id' => 4],
            ['employee_number' => 'HD-023', 'name' => '김진환', 'phone' => '010-9205-4340', 'job_title' => '사원', 'department_id' => 4],
            ['employee_number' => 'HD-024', 'name' => '정영교', 'phone' => '010-3440-9244', 'job_title' => '사원', 'department_id' => 4],
        ];

        foreach ($employees as $emp) {
            DB::table('employees')->insert(array_merge($emp, [
                'email'     => null,
                'hire_date' => '2020-01-01',
                'status'    => '재직',
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }
}
