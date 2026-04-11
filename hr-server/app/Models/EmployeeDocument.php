<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeDocument extends Model
{
    protected $fillable = [
        'employee_id',
        'document_type',
        'file_path',
        'original_name',
    ];

    public static array $types = [
        '주민등록등본',
        '채용신체검사기록부',
        '기초건설안전보건교육 이수증',
        '통장사본',
        '이력서',
        '근로계약서',
        '운전면허증',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
