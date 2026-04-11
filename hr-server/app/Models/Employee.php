<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    protected $fillable = [
        'employee_number',
        'name',
        'birth_date',
        'phone',
        'email',
        'address',
        'hire_date',
        'resign_date',
        'status',
        'department_id',
        'job_title',
        'notes',
        'blood_type',
        'shoe_size',
        'top_size',
        'bottom_size',
    ];

    protected $casts = [
        'hire_date'   => 'date',
        'resign_date' => 'date',
        'birth_date'  => 'date',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', '재직');
    }

    public function scopeSearch($query, ?string $keyword)
    {
        if (!$keyword) return $query;

        return $query->where(function ($q) use ($keyword) {
            $q->where('name', 'like', "%{$keyword}%")
              ->orWhere('employee_number', 'like', "%{$keyword}%")
              ->orWhere('email', 'like', "%{$keyword}%");
        });
    }
}
