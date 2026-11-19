<?php

/*
|--------------------------------------------------------------------------
| Source by jhonroot
|--------------------------------------------------------------------------
| * Facebook : https://fb.me/jhonroott  
| * Whatsapp : https://wa.me/6285349680016  
| * Telegram : https://t.me/jhondelton   
| * Web      : www.jhonroot.dev
|--------------------------------------------------------------------------
*/

namespace App\Models\Website;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WebsiteBlog extends Model
{
    use HasFactory, SoftDeletes;
    public $timestamps = false;
    protected $guarded = [];
    protected $hidden = [
        'id',
        'sort_number',
        'updated_at',
        'deleted_at',
    ];
    // Sitemap web dihapus (backend API murni, tanpa halaman) — hook dinonaktifkan.
    protected static function booted()
    {
    }
}
