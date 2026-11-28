<?php

use App\Core\Config\Config;
use App\Core\Rules\Rules;

return Config::make()
    ->paths(['src'])
    // Generated code: each tagged union is a cycle between the parent, whose fromArray()
    // picks the variant, and its variants. Still counted in the metrics, and a cycle
    // reaching a class outside Schema is still reported.
    ->ignore(['Acp\Schema\*'])
    ->rules([
        Rules::noCycles(),
    ])
    ->baseline('dependencies-baseline.json');
