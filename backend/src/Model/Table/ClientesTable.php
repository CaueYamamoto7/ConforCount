<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

final class ClientesTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('clientes');
        $this->setPrimaryKey('id');
        $this->belongsTo('Escritorios', ['foreignKey' => 'escritorio_id']);
    }
}
