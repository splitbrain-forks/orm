<?php

namespace ORM\Dbal\Type;

use ORM\Dbal\Type;

class Double extends Type
{
    protected static $dataTypes = [
        'decimal',
        'float',
        'double',
    ];
}
