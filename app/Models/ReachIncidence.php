<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReachIncidence extends Model
{
    use HasFactory;
    protected $table='reach_incidence';
    
    public function agerange(){
        return $this->belongsTo(ReachAgeRange::class,"ageRangeCode","ageRangeCode");
    }

}
