<?php

namespace App\Models\Indonesia;

use Laravolt\Indonesia\Models\Province as LaravoltProvince;

class Province extends LaravoltProvince
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'provinces';
}