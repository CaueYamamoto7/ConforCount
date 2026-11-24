<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Model\Entity\Usuario;
use Cake\ORM\Table;

final class UsuariosTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('usuarios');
        $this->setPrimaryKey('id');
        $this->setEntityClass(Usuario::class);
    }
}
