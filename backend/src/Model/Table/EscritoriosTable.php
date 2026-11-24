<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

final class EscritoriosTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('escritorios');
        $this->setPrimaryKey('id');
    }
}
