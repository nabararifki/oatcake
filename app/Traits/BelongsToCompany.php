<?php

namespace App\Traits;

use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToCompany
{
    /**
     * Boot the BelongsToCompany trait for a model.
     */
    protected static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope());

        static::creating(function (Model $model): void {
            if (empty($model->company_id) && auth()->check()) {
                $model->company_id = auth()->user()->company_id;
            }
        });
    }

    /**
     * Get the company that owns the model.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
