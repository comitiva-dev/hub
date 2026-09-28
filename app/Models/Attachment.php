<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['workspace_id', 'uploaded_by', 'name', 'media_type', 'size', 'sha256', 'path', 'created_at'])]
class Attachment extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $dateFormat = 'Y-m-d H:i:s.vP';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['size' => 'integer', 'created_at' => 'datetime'];
    }
}
