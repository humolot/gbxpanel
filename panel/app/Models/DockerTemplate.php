<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DockerTemplate extends Model
{
    protected $fillable = ['name', 'remark', 'content', 'env'];
}
