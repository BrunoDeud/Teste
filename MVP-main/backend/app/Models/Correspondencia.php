<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
class Correspondencia extends Model { use HasUuids; protected $table='correspondencias'; protected $fillable=['item_perdido_id','item_achado_id','pontuacao']; }
