<?php

namespace ORM\Test\Entity\Examples;

class Snake_Ucfirst extends TestEntity
{
    protected static $primaryKey = 'My_Key';
    protected static $autoIncrementSequence = 'snake_ucfirst_seq';
}