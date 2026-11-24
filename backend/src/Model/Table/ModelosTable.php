<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

final class ModelosTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('modelos');
        $this->setPrimaryKey('id');
        $this->belongsTo('Escritorios', ['foreignKey' => 'escritorio_id']);
        $this->belongsTo('Departamentos', ['foreignKey' => 'departamento_id']);
        $this->belongsTo('Caracteristicas', ['foreignKey' => 'caracteristica_id']);
    }
}
