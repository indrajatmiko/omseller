<?php

namespace App\Models\Indonesia;

use Laravolt\Indonesia\Models\City as LaravoltCity;

class City extends LaravoltCity
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'cities';
}