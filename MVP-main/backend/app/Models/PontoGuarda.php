<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
class PontoGuarda extends Model { use HasUuids; protected $table='pontos_guarda'; protected $fillable=['local_id','nome','referencia','ativo']; protected function casts(): array { return ['ativo'=>'boolean']; } public function local(){return $this->belongsTo(LocalCampus::class,'local_id');} }
