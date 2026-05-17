<?php

namespace App\Common\Helpers;

use App\Common\Traits\FruitWineCalculationsTrait;
use App\Common\Traits\MeadCalculationsTrait;
use App\Common\Traits\SugarCaneWineCalculationsTrait;

class FermentFormula
{
    use MeadCalculationsTrait;
    use SugarCaneWineCalculationsTrait;
    use FruitWineCalculationsTrait;
}
