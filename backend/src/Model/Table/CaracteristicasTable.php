<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

final class CaracteristicasTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('caracteristicas');
        $this->setPrimaryKey('id');
        $this->belongsTo('Escritorios', ['foreignKey' => 'escritorio_id']);
    }
}
